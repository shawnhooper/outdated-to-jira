<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\Dependency;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class NpmOutputParser
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Parses the JSON output from `npm outdated --json`.
     * Note: `npm outdated --json` returns non-zero exit code if outdated packages are found.
     * The command executor should ideally handle this, but the parser assumes valid JSON.
     *
     * @param string $jsonOutput The JSON string output from the command.
     * @return Dependency[] An array of Dependency objects.
     */
    public function parse(string $jsonOutput): array
    {
        $dependencies = [];
        // npm might output other stuff before the JSON, try to find the start of the JSON object
        $jsonStartPos = strpos($jsonOutput, '{');
        if ($jsonStartPos === false) {
             $this->logger->error('Could not find starting brace "{" in npm output.');
             return [];
        }
        $jsonStr = substr($jsonOutput, $jsonStartPos);

        $data = json_decode($jsonStr, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to decode npm JSON output: ' . json_last_error_msg(), ['raw_output' => $jsonOutput]);
            return [];
        }

        // The JSON structure is { "package-name": { "current": "1.0.0", "wanted": "1.0.1", "latest": "2.0.0", ... } }
        foreach ($data as $packageName => $packageData) {
            // Ensure the data is an array/object as expected
            if (!is_array($packageData)) {
                $this->logger->warning('Skipping non-array package entry in npm output.', [
                    'package' => $packageName,
                    'data_type' => gettype($packageData)
                ]);
                continue;
            }

            // Check for essential fields: wanted and latest are critical.
            if (!isset($packageData['wanted'], $packageData['latest'])) {
                 $this->logger->warning(
                     'Skipping package entry missing essential fields (wanted/latest) in npm output.',
                     ['package' => $packageName, 'data' => $packageData]
                 );
                 continue;
            }

            // Check for 'current'. If missing, silently skip (common case, not usually an error for outdated check).
            if (!isset($packageData['current'])) {
                 $this->logger->debug('Skipping package entry missing current version.', ['package' => $packageName]);
                 continue; // Silently skip
            }

            // If all required fields are present
            $dependencies[] = new Dependency(
                $packageName,
                $packageData['current'],
                $packageData['latest'], // Use 'latest' as the target version
                'npm'
            );
        }

        return $dependencies;
    }
}
