<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

final readonly class InstallResult
{
    private function __construct(
        public InstallStatus $status,
        public ?Installation $installation = null,
    ) {}

    public static function installed(Installation $installation): self
    {
        return new self(InstallStatus::Installed, $installation);
    }

    public static function denied(): self
    {
        return new self(InstallStatus::Denied);
    }

    public static function invalidState(): self
    {
        return new self(InstallStatus::InvalidState);
    }

    public static function missingCode(): self
    {
        return new self(InstallStatus::MissingCode);
    }
}
