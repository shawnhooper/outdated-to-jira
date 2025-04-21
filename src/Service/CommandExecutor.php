<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class CommandExecutor
{
    private LoggerInterface $logger;
    private $processFactory;

    public function __construct(?LoggerInterface $logger = null, ?callable $processFactory = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->processFactory = $processFactory ?? function (array $command, string $cwd) {
            return new Process($command, $cwd, null, null, 300.0);
        };
    }

    /**
     * Executes a command and returns its output.
     *
     * @param array<string> $command The command and its arguments as an array.
     * @param string $workingDirectory The directory to run the command in.
     * @return string The standard output of the command.
     * @throws ProcessFailedException If the command fails.
     * @throws \RuntimeException If execution fails for other reasons.
     */
    public function execute(array $command, string $workingDirectory): string
    {
        $commandString = implode(' ', $command);
        $this->logger->debug(sprintf('Executing command: "%s" in %s', $commandString, $workingDirectory));

        try {
            $process = ($this->processFactory)($command, $workingDirectory);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->logger->error('Command failed', [
                    'command' => $commandString,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => $process->getErrorOutput(),
                    'stdout' => $process->getOutput(),
                ]);
                throw new ProcessFailedException($process);
            }

            $output = $process->getOutput();
            $this->logger->debug('Command successful', ['command' => $commandString, 'output_length' => strlen($output)]);
            return $output;
        } catch (ProcessFailedException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Exception during command execution', [
                'command' => $commandString,
                'exception_message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(sprintf('Failed to execute command "%s": %s', $commandString, $e->getMessage()), 0, $e);
        }
    }
}
