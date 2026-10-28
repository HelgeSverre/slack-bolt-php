<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

final readonly class InstallHooks
{
    private ?\Closure $success;
    private ?\Closure $failure;

    public function __construct(?callable $onSuccess = null, ?callable $onFailure = null)
    {
        $this->success = $onSuccess === null ? null : \Closure::fromCallable($onSuccess);
        $this->failure = $onFailure === null ? null : \Closure::fromCallable($onFailure);
    }

    public static function make(): self
    {
        return new self();
    }

    /** @param callable(InstallResult): void $handler */
    public function onSuccess(callable $handler): self
    {
        return clone($this, ['success' => \Closure::fromCallable($handler)]);
    }

    /** @param callable(InstallResult): void $handler */
    public function onFailure(callable $handler): self
    {
        return clone($this, ['failure' => \Closure::fromCallable($handler)]);
    }

    /** @internal Called once per callback outcome, after persistence on success. */
    public function notify(InstallResult $result): void
    {
        $handler = $result->status === InstallStatus::Installed ? $this->success : $this->failure;
        if ($handler === null) {
            return;
        }
        try {
            $handler($result);
        } catch (\Throwable $error) {
            throw new InstallHookFailed($result, $error);
        }
    }

    public function __debugInfo(): array
    {
        return ['success' => $this->success !== null, 'failure' => $this->failure !== null];
    }
}
