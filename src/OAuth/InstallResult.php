<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

final readonly class InstallResult
{
    private function __construct(
        public InstallStatus $status,
        public ?Installation $installation = null,
        public ?InstallRequest $request = null,
        public ?\Throwable $error = null,
    ) {}

    public static function installed(Installation $installation, ?InstallRequest $request = null): self
    {
        return new self(InstallStatus::Installed, $installation, $request);
    }

    public static function denied(?InstallRequest $request = null): self
    {
        return new self(InstallStatus::Denied, request: $request);
    }

    public static function invalidState(): self
    {
        return new self(InstallStatus::InvalidState);
    }

    public static function missingCode(?InstallRequest $request = null): self
    {
        return new self(InstallStatus::MissingCode, request: $request);
    }

    public static function failed(\Throwable $error, ?InstallRequest $request = null): self
    {
        return new self(InstallStatus::Failed, request: $request, error: $error);
    }

    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'installation' => $this->installation,
            'request' => $this->request,
            'error' => $this->error === null ? null : $this->error::class,
        ];
    }
}
