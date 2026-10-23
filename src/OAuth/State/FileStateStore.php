<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth\State;

use RuntimeException;

/**
 * A file-backed state store for ordinary PHP worker deployments. Every
 * consumption holds one directory lock while it reads and deletes the record,
 * making successful state use one-time across workers sharing this directory.
 */
final readonly class FileStateStore implements StateStore
{
    public function __construct(
        private string $directory,
    ) {
        if ($directory === '') {
            throw new \InvalidArgumentException('An OAuth state directory is required.');
        }
    }

    public function put(State $state): void
    {
        $this->ensureDirectory();
        $lock = $this->lock();

        try {
            $path = $this->path($state->value);
            if (file_exists($path)) {
                throw new RuntimeException('An OAuth state collision occurred.');
            }

            $record = json_encode([
                'cookie_hash' => hash('sha256', $state->cookieBinding),
                'expires_at' => $state->expiresAt,
            ], JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $record, LOCK_EX) === false) {
                throw new RuntimeException('Unable to persist OAuth state.');
            }
            chmod($path, 0600);
        } finally {
            $this->unlock($lock);
        }
    }

    public function consume(string $value, string $cookieBinding, int $now): bool
    {
        $this->ensureDirectory();
        $lock = $this->lock();

        try {
            $path = $this->path($value);
            if (!is_file($path)) {
                return false;
            }

            $encoded = file_get_contents($path);
            if ($encoded === false || !unlink($path)) {
                throw new RuntimeException('Unable to consume OAuth state.');
            }

            try {
                $record = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return false;
            }

            if (
                !is_array($record)
                || !is_string($record['cookie_hash'] ?? null)
                || !is_int($record['expires_at'] ?? null)
            ) {
                return false;
            }

            return $record['expires_at'] > $now && hash_equals($record['cookie_hash'], hash('sha256', $cookieBinding));
        } finally {
            $this->unlock($lock);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create OAuth state directory.');
        }
    }

    /** @return resource */
    private function lock()
    {
        $lock = fopen($this->directory . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Unable to lock OAuth state storage.');
        }
        chmod($this->directory . '/.lock', 0600);

        return $lock;
    }

    /** @param resource $lock */
    private function unlock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function path(string $value): string
    {
        return $this->directory . '/' . hash('sha256', $value) . '.json';
    }
}
