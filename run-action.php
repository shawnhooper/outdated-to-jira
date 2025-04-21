<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Service\DependencyCheckerService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

// --- Basic Error Handling ---
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($exception) {
    fwrite(STDERR, sprintf("[ERROR] Uncaught Exception: %s in %s:%d\n", $exception->getMessage(), $exception->getFile(), $exception->getLine()));
    // Optionally log stack trace to stderr
    // fwrite(STDERR, $exception->getTraceAsString() . "\n");
    exit(1); // Exit with error code
});

// --- Logger Setup (Output to stderr for Actions) ---
$logStream = 'php://stderr';
$logLevel = getenv('RUNNER_DEBUG') === '1' ? Logger::DEBUG : Logger::INFO; // Use DEBUG if Actions step debug is enabled
$formatter = new LineFormatter("[%datetime%] %channel%.%level_name%: %message% %context% %extra%\\n");
$handler = new StreamHandler($logStream, $logLevel);
$handler->setFormatter($formatter);
$logger = new Logger('GitHubAction');
$logger->pushHandler($handler);

$logger->info('Action runner script started.');

// --- Configuration Loading from Environment Variables ---
$requiredEnv = [
    'DEPENDENCY_FILE', // Path relative to workspace root
    'JIRA_URL',
    'JIRA_USER_EMAIL',
    'JIRA_API_TOKEN',
    'JIRA_PROJECT_KEY',
    'JIRA_ISSUE_TYPE',
];
$config = [];
$errors = [];
$isDryRun = strtolower((string)getenv('DRY_RUN')) === 'true';

$logger->debug('Loading configuration from environment variables.');

foreach ($requiredEnv as $var) {
    $value = getenv($var);
    if ($value === false || $value === '') {
        // Don't fail for missing JIRA creds if dry-run is enabled
        if (!($isDryRun && str_starts_with($var, 'JIRA_'))) {
            $errors[] = "Required environment variable {$var} is not set.";
        }
        $config[strtolower($var)] = null; // Set as null if missing
    } else {
        $config[strtolower($var)] = $value;
    }
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        $logger->error($error);
    }
    exit(1); // Exit with error code
}

// Add dry_run flag to config for JiraService
$config['dry_run'] = $isDryRun;

// Process package filter (space-separated string)
$packagesFilterString = getenv('PACKAGES') ?: '';
$packagesToFilter = $packagesFilterString ? explode(' ', $packagesFilterString) : [];

// --- Construct Absolute Dependency File Path ---
// GITHUB_WORKSPACE is the root of the checkout
$workspace = getenv('GITHUB_WORKSPACE');
if (!$workspace) {
    $logger->error('GITHUB_WORKSPACE environment variable not found.');
    exit(1);
}
$dependencyFilePathRelative = $config['dependency_file'];
$dependencyFilePathAbsolute = $workspace . '/' . ltrim($dependencyFilePathRelative, '/');

$logger->info('Configuration loaded.', ['file' => $dependencyFilePathAbsolute, 'dry_run' => $isDryRun, 'filter' => $packagesToFilter]);

// --- Instantiate and Run Service ---
$logger->debug('Instantiating DependencyCheckerService...');
$checkerService = new DependencyCheckerService($config, $logger);

$logger->info('Calling DependencyCheckerService process method...');

try {
    $results = $checkerService->process($dependencyFilePathAbsolute, $packagesToFilter);
    $logger->info('Processing complete. Results:', ['count' => count($results)]);

    // Log detailed results at debug level
    $logger->debug('Detailed results:', $results);

    // Simple summary log
    $summary = [];
    foreach ($results as $pkg => $res) {
        $status = $res[DependencyCheckerService::RESULT_STATUS_KEY] ?? 'unknown';
        $jiraKey = $res[DependencyCheckerService::RESULT_JIRA_KEY] ?? null;
        $summary[$pkg] = $status . ($jiraKey ? " ({$jiraKey})" : '');
    }
    $logger->info('Summary:', $summary);

    // You could potentially set GitHub Action outputs here if needed
    // echo "::set-output name=summary::" . json_encode($summary);

} catch (\Exception $e) {
    $logger->error('Service processing failed.', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    exit(1); // Exit with error code
}

$logger->info('Action runner script finished successfully.');
exit(0); // Explicitly exit with success code 