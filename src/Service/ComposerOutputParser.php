<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\Dependency;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ComposerOutputParser
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Parses the JSON output from `composer outdated --format=json`.
     *
     * @param string $jsonOutput The JSON string output from the command.
     * @return Dependency[] An array of Dependency objects.
     */
    public function parse(string $jsonOutput): array
    {
        $dependencies = [];
        $data = json_decode($jsonOutput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to decode Composer JSON output: ' . json_last_error_msg());
            return [];
        }

        // Check for the 'installed' array (based on actual command output)
        if (!isset($data['installed']) || !is_array($data['installed'])) {
             $this->logger->warning('Composer output does not contain an "installed" array.');
             return [];
        }

        // Iterate over 'installed' and check 'latest-status'
        foreach ($data['installed'] as $package) {
            // Check if latest-status indicates an update is possible
            if (isset($package['latest-status']) && $package['latest-status'] === 'update-possible') {
                // Ensure essential fields are present for outdated packages
                if (!isset($package['name'], $package['version'], $package['latest'])) {
                     $this->logger->warning('Skipping incomplete package entry in Composer "installed" output.', ['package' => $package]);
                     continue;
                }
                $dependencies[] = new Dependency(
                    $package['name'],
                    $package['version'],
                    $package['latest'],
                    'composer'
                );
            }
        }

        return $dependencies;
    }
}
