<?php

declare(strict_types=1);

namespace DbSweep\DTO;

final class DatabaseTelemetry
{
    public function __construct(
        public readonly string $name,
        public readonly int $tableCount,
        public readonly float $sizeMb,
        public readonly string $lastActive,
        public readonly int $activeLocks,
    ) {
    }
}
