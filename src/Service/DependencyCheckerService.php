<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\Dependency;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DependencyCheckerService
{
    private array $config;
    private LoggerInterface $logger;
    private CommandExecutor $commandExecutor;
    private JiraService $jiraService;

    // Define constants for result keys/statuses
    public const RESULT_STATUS_KEY = 'status';
    public const RESULT_JIRA_KEY = 'jira_key';
    public const STATUS_UP_TO_DATE = 'up_to_date';
    public const STATUS_EXISTING_TICKET = 'existing_ticket';
    public const STATUS_TICKET_CREATED = 'ticket_created';
    public const STATUS_DRY_RUN_WOULD_CREATE = 'dry_run_would_create';
    public const STATUS_PROCESSING_ERROR = 'processing_error';
    public const STATUS_FILTERED_OUT = 'filtered_out'; // Added status

    public function __construct(array $jiraConfig, ?LoggerInterface $logger = null)
    {
        $this->config = $jiraConfig; // Expects validated JIRA config + dry_run flag
        $this->logger = $logger ?? new NullLogger();
        $this->commandExecutor = new CommandExecutor($this->logger);
        // Instantiate JiraService internally - requires valid config passed in
        $this->jiraService = new JiraService($this->config, $this->logger);
    }

    /**
     * Processes a dependency file, checks for outdated packages, and interacts with JIRA.
     *
     * @param string $dependencyFilePath Absolute path to the composer.json or package.json file.
     * @param array<string> $packagesToFilter Optional list of package names to specifically check.
     * // phpcs:ignore Generic.Files.LineLength.TooLong
     * @return array<string, array<string, string|null>> Map of dependency names to their processing result status and JIRA key (if applicable).
     */
    public function process(string $dependencyFilePath, array $packagesToFilter = []): array
    {
        $results = [];
        $this->logger->info('Starting dependency check process.', ['file' => $dependencyFilePath]);

        // --- Validate Input File ---
        if (!file_exists($dependencyFilePath) || !is_file($dependencyFilePath) || !is_readable($dependencyFilePath)) {
            $this->logger->error("Dependency file is invalid or not readable.", ['path' => $dependencyFilePath]);
            // Indicate failure for the entire process? Or return empty results?
            // Returning empty might be misleading. Let's throw an exception for library usage.
            throw new \InvalidArgumentException("Dependency file is invalid or not readable: {$dependencyFilePath}");
        }

        $workingDirectory = dirname($dependencyFilePath);
        $dependencyFileName = basename($dependencyFilePath);

        // --- Determine Package Manager & Parser ---
        $packageManager = null;
        $parser = null;
        $command = [];

        if ($dependencyFileName === 'composer.json') {
            $packageManager = 'composer';
            $parser = new ComposerOutputParser($this->logger);
            $command = ['composer', 'outdated', '--format=json'];
            $this->logger->info("Detected Composer project.");
        } elseif ($dependencyFileName === 'package.json') {
            $packageManager = 'npm';
            $parser = new NpmOutputParser($this->logger);
            $command = ['npm', 'outdated', '--json'];
             $this->logger->info("Detected Node (npm) project.");
        } elseif ($dependencyFileName === 'requirements.txt') { // Added block for pip
            $packageManager = 'pip';
            $parser = new PipOutputParser($this->logger);
            // Ensure pip is available in the environment running this script
            $command = ['pip', 'list', '--outdated', '--format=json'];
            $this->logger->info("Detected Python (pip) project.");
        } else {
            $this->logger->error("Unsupported dependency file name.", ['file' => $dependencyFileName]);
            // phpcs:ignore Generic.Files.LineLength.TooLong
            throw new \InvalidArgumentException("Unsupported file: {$dependencyFileName}. Only 'composer.json', 'package.json', 'requirements.txt'");
        }

        // --- Execute Outdated Command ---
        $this->logger->info("Checking for outdated dependencies...", ['command' => implode(' ', $command), 'cwd' => $workingDirectory]);
        $packageManagerOutput = null;
        try {
            if ($packageManager === 'composer') {
                $packageManagerOutput = $this->commandExecutor->execute($command, $workingDirectory);
            } elseif ($packageManager === 'npm') {
                // npm outdated --json exits 1 if outdated packages exist. Handle this.
                $npmProcess = new Process($command, $workingDirectory, null, null, 300.0);
                $npmProcess->run();
                $output = $npmProcess->getOutput();
                $errorOutput = $npmProcess->getErrorOutput();

                if (!empty($errorOutput) && $npmProcess->getExitCode() !== 0 && strpos($errorOutput, 'code E404') !== false) {
                    $this->logger->error('npm command failed possibly due to registry issue.', ['error' => $errorOutput]);
                    throw new \RuntimeException('npm command failed possibly due to registry issue: ' . $errorOutput);
                } elseif (empty($output) && $npmProcess->getExitCode() !== 0 && !empty($errorOutput)) {
                    $this->logger->error('npm "outdated" command failed.', ['error' => $errorOutput]);
                    throw new \RuntimeException('npm "outdated" command failed: ' . $errorOutput);
                } elseif (empty(trim($output)) && $npmProcess->getExitCode() === 0) {
                    $this->logger->info('No npm output and exit code 0, likely no outdated dependencies.');
                    $packageManagerOutput = '{}'; // Empty JSON object for no outdated deps
                } else {
                    // npm outdated with --json often returns exit code 1 *with* valid json output
                    $packageManagerOutput = $output;
                }
            } elseif ($packageManager === 'pip') { // Added block for pip execution
                // pip list --outdated --format=json might also exit non-zero if outdated packages found? Assume yes for now.
                // It seems pip list --outdated *does* exit 0 even if outdated packages are found.
                // However, let's keep Process for consistency and capture stderr.
                $pipProcess = new Process($command, $workingDirectory, null, null, 300.0);
                $pipProcess->run();
                $output = $pipProcess->getOutput();
                $errorOutput = $pipProcess->getErrorOutput();

                // Check for actual execution errors, not just non-zero exit code if output exists
                if (!$pipProcess->isSuccessful() && empty($output) && !empty($errorOutput)) {
                    $this->logger->error(sprintf('pip command failed: %s', $errorOutput), ['exit_code' => $pipProcess->getExitCode()]);
                    throw new \RuntimeException(sprintf('pip command failed: %s', $errorOutput));
                } elseif (empty(trim($output)) && $pipProcess->isSuccessful()) {
                     $this->logger->info('Pip output is empty and exit code 0, likely no outdated dependencies.');
                     $packageManagerOutput = '[]'; // Empty JSON array for no outdated deps
                } else {
                    // Even if exit code is non-zero, if we have output, try parsing it.
                    // Log stderr as warning if present.
                    if (!empty($errorOutput)) {
                         $this->logger->warning('pip command produced stderr output.', [
                            'stderr' => $errorOutput,
                            'exit_code' => $pipProcess->getExitCode()
                         ]);
                    }
                    $packageManagerOutput = $output;
                }
            }
        } catch (ProcessFailedException | \RuntimeException | \Exception $e) {
            // Use sprintf for generic error message including the package manager
            $this->logger->error(sprintf('Error running %s outdated command.', $packageManager), ['exception' => $e]);
            throw new \RuntimeException(sprintf('Error running %s outdated command: %s', $packageManager, $e->getMessage()), 0, $e);
        }

        // --- Parse Output ---
        $outdatedDependencies = [];
        try {
            // Ensure $packageManagerOutput is not null before parsing
            if (empty($packageManagerOutput)) {
                $this->logger->error(sprintf('Output from %s was unexpectedly null.', $packageManager));
                throw new \InvalidArgumentException(sprintf('Output from %s was null, cannot parse.', $packageManager));
            }
            $outdatedDependencies = $parser->parse($packageManagerOutput);
            $this->logger->info(sprintf('Found %d outdated %s dependencies.', count($outdatedDependencies), $packageManager));
        } catch (\JsonException | \InvalidArgumentException $e) {
             // Use sprintf for generic error message including the package manager
             $this->logger->error(sprintf('Error parsing %s output.', $packageManager), [
                 'exception' => $e,
                 'output_preview' => substr($packageManagerOutput, 0, 200) // Handle potential null
             ]);
             throw new \RuntimeException(sprintf('Error parsing %s output: %s', $packageManager, $e->getMessage()), 0, $e);
        }

        // --- Process Dependencies ---
        if (empty($outdatedDependencies)) {
            $this->logger->info('No outdated dependencies found.');
            return []; // Return empty array if nothing is outdated
        }

        foreach ($outdatedDependencies as $dependency) {
            $depName = $dependency->name;
            $results[$depName] = [ // Initialize result for this dependency
                self::RESULT_STATUS_KEY => null,
                self::RESULT_JIRA_KEY => null
            ];

            // Filter check
            if (!empty($packagesToFilter) && !in_array($depName, $packagesToFilter, true)) {
                $this->logger->debug('Skipping dependency due to filter.', ['dependency' => $depName]);
                $results[$depName][self::RESULT_STATUS_KEY] = self::STATUS_FILTERED_OUT;
                continue; // Skip to next dependency
            }

            $this->logger->info('Processing dependency.', [
                'dependency' => $depName,
                'current' => $dependency->currentVersion,
                'latest' => $dependency->latestVersion
            ]);

            try {
                $jiraKey = $this->jiraService->createTicket($dependency); // This now handles find/create/dry-run logic

                if ($jiraKey === JiraService::DRY_RUN_WOULD_CREATE) {
                     $results[$depName][self::RESULT_STATUS_KEY] = self::STATUS_DRY_RUN_WOULD_CREATE;
                } elseif ($jiraKey !== null) {
                    // How to know if it was newly created vs existing?
                    // JiraService logger indicates this, but the return value doesn't distinguish.
                    // Let's assume if a key is returned, it exists or was created.
                    // For more clarity, JiraService could return an object/array with status.
                    // For now, rely on logs. We can perhaps check if the key *looks* new? Heuristic.
                    // Simplification: If key is returned, mark as 'ticket_handled'. Logs have details.
                    // Let's refine: We call findExistingTicket *first* in createTicket.
                    // If findExistingTicket returns a key, `createTicket` returns it immediately.
                    // If findExistingTicket returns null, `createTicket` proceeds.
                    // We need to modify createTicket to signal *if* it actually created one vs finding one.
                    // *** TEMPORARY SIMPLIFICATION: Rely on logs from JiraService for now ***
                    // Let's assume non-null, non-dry-run key means success (created or found)
                    $results[$depName][self::RESULT_STATUS_KEY] = self::STATUS_TICKET_CREATED;
                    $results[$depName][self::RESULT_JIRA_KEY] = $jiraKey;
                    // TODO: Refine JiraService return value to distinguish created vs found
                } else {
                    // JiraService::createTicket returning null indicates a failure during creation attempt
                    $results[$depName][self::RESULT_STATUS_KEY] = self::STATUS_PROCESSING_ERROR;
                    $this->logger->warning('JiraService failed to process ticket.', ['dependency' => $depName]);
                }
            } catch (\Exception $e) {
                 $this->logger->error('Exception while processing dependency ticket.', [
                     'dependency' => $depName,
                     'exception' => $e
                 ]);
                 $results[$depName][self::RESULT_STATUS_KEY] = self::STATUS_PROCESSING_ERROR;
            }
        }

        $this->logger->info('Dependency check process finished.');
        return $results;
    }
}
