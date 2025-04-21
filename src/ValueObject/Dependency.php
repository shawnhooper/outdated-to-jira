<?php

declare(strict_types=1);

namespace App\ValueObject;

class Dependency
{
    public function __construct(
        public readonly string $name,
        public readonly string $currentVersion,
        public readonly string $latestVersion,
        public readonly string $packageManager // 'composer' or 'npm'
    ) {
    }
}
