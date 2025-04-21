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
        $this->parser = new ComposerOutputParser(new NullLogger());
    }

    public function testParseWithValidDataAndMixedStatus(): void
    {
        $jsonInput = '{
            "installed": [
                {
                    "name": "psr/log",
                    "version": "1.1.4",
                    "description": "Common interface for logging libraries",
                    "latest": "3.0.0",
                    "latest-status": "update-possible"
                },
                {
                    "name": "symfony/console",
                    "version": "v5.4.38",
                    "description": "Eases the creation of beautiful and testable command line interfaces",
                    "latest": "v6.4.6",
                    "latest-status": "semver-safe-update"
                },
                 {
                    "name": "another/package",
                    "version": "2.0.0",
                    "description": "Another one",
                    "latest": "2.1.0",
                    "latest-status": "update-possible"
                }
            ],
            "abandoned": []
        }';

        $expectedDependencies = [
            new Dependency('psr/log', '1.1.4', '3.0.0', 'composer'),
            new Dependency('another/package', '2.0.0', '2.1.0', 'composer'),
        ];

        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEquals($expectedDependencies, $actualDependencies);
    }

    public function testParseWithNoPackagesMarkedOutdated(): void
    {
        $jsonInput = '{
            "installed": [
                {
                    "name": "symfony/console",
                    "version": "v6.4.6",
                    "latest": "v6.4.6",
                    "latest-status": "up-to-date"
                }
            ],
            "abandoned": []
        }';

        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEmpty($actualDependencies);
        $this->assertIsArray($actualDependencies);
    }

    public function testParseWithEmptyInstalledArray(): void
    {
        $jsonInput = '{
            "installed": [],
            "abandoned": []
        }';
        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEmpty($actualDependencies);
         $this->assertIsArray($actualDependencies);
    }

    public function testParseWithMissingInstalledKey(): void
    {
        $jsonInput = '{"abandoned": []}'; // Missing "installed"
        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEmpty($actualDependencies);
         $this->assertIsArray($actualDependencies);
    }

    public function testParseWithInvalidJson(): void
    {
        $jsonInput = '{';
        $actualDependencies = $this->parser->parse($jsonInput);
        $this->assertEmpty($actualDependencies);
        $this->assertIsArray($actualDependencies);
    }
} 