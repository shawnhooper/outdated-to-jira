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

        if (!isset($data['locked']) || !is_array($data['locked'])) {
             $this->logger->warning('Composer output does not contain a "locked" array.');
             return [];
        }

        foreach ($data['locked'] as $package) {
            // --- TEMP DEBUG ---
            // var_dump("Processing package:", $package);
            // $isMissingFields = !isset($package['name'], $package['version'], $package['latest']);
            // var_dump("Is Missing Fields:", $isMissingFields);
            // --- END TEMP DEBUG ---

            if (!isset($package['name'], $package['version'], $package['latest'])) {
                 $this->logger->warning('Skipping incomplete package entry in Composer "locked" output.', ['package' => $package]);
                 continue;
            }
            
            $dependencies[] = new Dependency(
                $package['name'],
                $package['version'],
                $package['latest'],
                'composer'
            );
        }

        return $dependencies;
    }
} 