<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\NpmOutputParser;
use App\ValueObject\Dependency;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NpmOutputParserTest extends TestCase
{
    private NpmOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NpmOutputParser(new NullLogger());
    }

    public function testParseWithValidData(): void
    {
        // Sample npm outdated --json output
        $jsonInput = '{
            "@babel/core": {
                "current": "7.23.0",
                "wanted": "7.24.7",
                "latest": "7.24.7",
                "dependent": "project",
                "location": "node_modules/@babel/core"
            },
            "react": {
                "current": "17.0.2",
                "wanted": "18.3.1",
                "latest": "18.3.1",
                "dependent": "project",
                "location": "node_modules/react"
            }
        }';

        $expectedDependencies = [
            new Dependency('@babel/core', '7.23.0', '7.24.7', 'npm'),
            new Dependency('react', '17.0.2', '18.3.1', 'npm'),
        ];

        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEquals($expectedDependencies, $actualDependencies);
    }

    public function testParseWithNoOutdatedDependencies(): void
    {
        // npm outputs empty JSON object when nothing is outdated
        $jsonInput = '{}';
        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEmpty($actualDependencies);
    }

    public function testParseWithEmptyString(): void
    {
        // Handle cases where command might output empty string
        $jsonInput = '';
        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEmpty($actualDependencies);
    }

    public function testParseWithInvalidJson(): void
    {
        $jsonInput = '{'; // Intentionally invalid JSON

        // Expecting an empty array due to internal logging and error handling
        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEmpty($actualDependencies);
    }

    public function testParseWithMissingFields(): void
    {
         // Corrected JSON Input
         $jsonInput = '{
            "@babel/core": {
                "current": "7.23.0",
                "wanted": "7.24.7"
            },
            "react": {
                "current": "17.0.2",
                "wanted": "18.3.1",
                "latest": "18.3.1"
            }
        }';

        // Expect only the valid entry to be parsed
         $expectedDependencies = [
            new Dependency('react', '17.0.2', '18.3.1', 'npm'),
         ];

         $actualDependencies = $this->parser->parse($jsonInput);
         $this->assertCount(1, $actualDependencies, 'Expected exactly one dependency to be parsed.');
         $this->assertInstanceOf(Dependency::class, $actualDependencies[0], 'The parsed item should be a Dependency object.');

         /** @var Dependency $parsedDep */
         $parsedDep = $actualDependencies[0];
         $this->assertEquals('react', $parsedDep->name);
         $this->assertEquals('17.0.2', $parsedDep->currentVersion);
         $this->assertEquals('18.3.1', $parsedDep->latestVersion);
         $this->assertEquals('npm', $parsedDep->packageManager);
    }
}
