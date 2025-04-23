<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\Dependency;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class PipOutputParser
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Parses the JSON output of 'pip list --outdated --format=json'.
     *
     * @param string $jsonOutput The JSON string output from the pip command.
     * @return Dependency[] An array of Dependency objects.
     * @throws JsonException If the JSON decoding fails.
     * @throws \InvalidArgumentException If the input is not valid JSON or missing required fields.
     */
    public function parse(string $jsonOutput): array
    {
        if (empty(trim($jsonOutput))) {
            $this->logger->info('Pip output is empty, assuming no outdated packages.');
            return [];
        }

        try {
            $data = json_decode($jsonOutput, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('Failed to decode pip JSON output.', ['exception' => $e, 'output_preview' => substr($jsonOutput, 0, 200)]);
            throw new JsonException('Failed to decode pip JSON output: ' . $e->getMessage(), $e->getCode(), $e);
        }

        if (!is_array($data)) {
             $this->logger->error('Decoded pip JSON output is not an array.', ['output_preview' => substr($jsonOutput, 0, 200)]);
             throw new \InvalidArgumentException('Decoded pip JSON is not an array.');
        }

        $dependencies = [];
        foreach ($data as $item) {
            if (!isset($item['name'], $item['version'], $item['latest_version'])) {
                $this->logger->warning('Skipping item due to missing fields.', ['item' => $item]);
                continue;
            }

            $dependencies[] = new Dependency(
                (string)$item['name'],
                (string)$item['version'], // 'version' maps to 'currentVersion'
                (string)$item['latest_version'],
                'pip' // Set package manager
            );
        }

        return $dependencies;
    }
} 