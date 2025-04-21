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

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Executes a command in a specific directory and returns its output.
     *
     * @param array<string> $command The command and its arguments as an array.
     * @param string $workingDirectory The directory to run the command in.
     * @return string The standard output of the command.
     * @throws ProcessFailedException If the command fails.
     * @throws \RuntimeException If the working directory does not exist.
     */
    public function execute(array $command, string $workingDirectory): string
    {
        $this->logger->debug('Executing command', ['command' => $command, 'cwd' => $workingDirectory]);

        if (!is_dir($workingDirectory)) {
            $this->logger->error('Working directory for command execution not found', ['path' => $workingDirectory]);
            throw new \RuntimeException("Working directory not found: {$workingDirectory}");
        }

        // Increase timeout for potentially long-running package manager commands
        $process = new Process($command, $workingDirectory, null, null, 300.0); // 5 minutes timeout
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Command execution failed', [
                'command' => $command,
                'cwd' => $workingDirectory,
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);
            throw new ProcessFailedException($process);
        }

        $output = $process->getOutput();
        $this->logger->debug('Command executed successfully', ['output_length' => strlen($output)]);
        return $output;
    }
} 