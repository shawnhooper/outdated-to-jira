<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ComposerOutputParser;
use App\ValueObject\Dependency;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ComposerOutputParserTest extends TestCase
{
    private ComposerOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        // Use NullLogger as we don't need to test logging output in these unit tests
        $this->parser = new ComposerOutputParser(new NullLogger());
    }

    public function testParseWithValidData(): void
    {
        $jsonInput = '{
            "installed": [],
            "abandoned": [],
            "locked": [
                {
                    "name": "psr/log",
                    "version": "1.1.4",
                    "description": "Common interface for logging libraries",
                    "latest": "3.0.0",
                    "latest-status": "semver-safe-update"
                },
                {
                    "name": "symfony/console",
                    "version": "v5.4.38",
                    "description": "Eases the creation of beautiful and testable command line interfaces",
                    "latest": "v6.4.6",
                    "latest-status": "update-possible"
                }
            ]
        }';

        $expectedDependencies = [
            new Dependency('psr/log', '1.1.4', '3.0.0', 'composer'),
            new Dependency('symfony/console', 'v5.4.38', 'v6.4.6', 'composer'),
        ];

        $actualDependencies = $this->parser->parse($jsonInput);

        // Use assertEquals for object comparison (checks property values)
        $this->assertEquals($expectedDependencies, $actualDependencies);
    }

    public function testParseWithNoOutdatedDependencies(): void
    {
        // Based on composer output when nothing is outdated
        $jsonInput = '{
            "installed": [],
            "abandoned": [],
            "locked": [] 
        }';

        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEmpty($actualDependencies);
        $this->assertIsArray($actualDependencies);
    }

    public function testParseWithEmptyJson(): void
    {
        $jsonInput = '{}';
        $actualDependencies = $this->parser->parse($jsonInput);
        // Expect empty array if 'locked' key is missing
        $this->assertEmpty($actualDependencies);
         $this->assertIsArray($actualDependencies);
    }

    public function testParseWithInvalidJson(): void
    {
        $jsonInput = '{'; // Intentionally invalid JSON
        
        // Expecting an empty array due to internal logging and error handling
        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEmpty($actualDependencies);
        $this->assertIsArray($actualDependencies);
    }

    public function testParseWithMissingFields(): void
    {
         // Define the correct JSON string for the test case
         $jsonInput = '{
            "locked": [
                {
                    "name": "psr/log",
                    "version": "1.1.4"
                },
                {
                    "name": "symfony/console",
                    "version": "v5.4.38",
                    "latest": "v6.4.6"
                }
            ]
        }';

        // --- Pre-check JSON structure ---
        $decodedData = json_decode($jsonInput, true);
        $this->assertIsArray($decodedData, 'JSON did not decode to an array.');
        $this->assertArrayHasKey('locked', $decodedData, 'Decoded JSON missing "locked" key.');
        $this->assertIsArray($decodedData['locked'], '"locked" key is not an array.');
        $this->assertCount(2, $decodedData['locked'], '"locked" array does not contain 2 elements.');
        $this->assertArrayNotHasKey('latest', $decodedData['locked'][0], 'First element should be missing "latest".');
        $this->assertArrayHasKey('latest', $decodedData['locked'][1], 'Second element should have "latest".');
        // --- End Pre-check ---

        $actualDependencies = $this->parser->parse($jsonInput);

        // Granular Assertions
        $this->assertCount(1, $actualDependencies, 'Expected exactly one dependency to be parsed.');
        $this->assertInstanceOf(Dependency::class, $actualDependencies[0], 'The parsed item should be a Dependency object.');
        
        /** @var Dependency $parsedDep */
        $parsedDep = $actualDependencies[0];
        $this->assertEquals('symfony/console', $parsedDep->name, 'Package name does not match.');
        $this->assertEquals('v5.4.38', $parsedDep->currentVersion, 'Current version does not match.');
        $this->assertEquals('v6.4.6', $parsedDep->latestVersion, 'Latest version does not match.');
        $this->assertEquals('composer', $parsedDep->packageManager, 'Package manager should be composer.');
    }
} 