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

        if (!isset($data['installed']) || !is_array($data['installed'])) {
             $this->logger->warning('Composer output does not contain an "installed" array.');
             return [];
        }

        foreach ($data['installed'] as $package) {
            // We only care about DIRECT packages that are actually outdated
            if (
                isset($package['direct-dependency']) && $package['direct-dependency'] === true &&
                isset($package['latest-status']) && $package['latest-status'] === 'update-possible'
            ) {
                 if (!isset($package['name'], $package['version'], $package['latest'])) {
                     $this->logger->warning('Skipping incomplete direct/outdated package entry in Composer output.', ['package' => $package]);
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