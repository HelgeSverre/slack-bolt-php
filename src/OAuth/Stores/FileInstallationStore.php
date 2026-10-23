<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth\Stores;

use Closure;
use Thunder\Bolt\OAuth\Installation;
use Thunder\Bolt\OAuth\InstallationQuery;
use Thunder\Bolt\OAuth\InstallationStore;

final readonly class FileInstallationStore implements InstallationStore
{
    public function __construct(
        private string $directory,
    ) {
        if ($directory === '') {
            throw new \InvalidArgumentException('An installation store needs a directory.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the installation store directory.');
        }
        if (!is_readable($directory) || !is_writable($directory)) {
            throw new \RuntimeException('The installation store directory must be readable and writable.');
        }
    }

    public function save(Installation $installation): void
    {
        if ($installation->botToken !== null) {
            $query = $installation->query()->forBot();
            $this->locked($query, function () use ($query, $installation): void {
                $this->write($query, $installation);
            });
        }
        if ($installation->userId !== null && $installation->userToken !== null) {
            $query = $installation->query()->forUser($installation->userId);
            $this->locked($query, function () use ($query, $installation): void {
                $this->write($query, $installation);
            });
        }
    }

    public function fetch(InstallationQuery $query): ?Installation
    {
        return $this->locked($query, fn(): ?Installation => $this->read($query), LOCK_SH);
    }

    public function delete(InstallationQuery $query): void
    {
        $this->locked($query, function () use ($query): void {
            $paths = $query->userId === null
                ? (glob($this->directory . DIRECTORY_SEPARATOR . $this->scopePrefix($query) . '-*.json') ?: [])
                : [$this->recordPath($query)];
            foreach ($paths as $path) {
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException('Unable to delete the installation record.');
                }
            }
        });
    }

    public function update(InstallationQuery $query, Closure $updater): ?Installation
    {
        return $this->locked($query, function () use ($query, $updater): ?Installation {
            $updated = $updater($this->read($query));
            if ($updated === null) {
                $path = $this->recordPath($query);
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException('Unable to delete the installation record.');
                }

                return null;
            }

            $this->write($query, $updated);

            return $updated;
        });
    }

    private function read(InstallationQuery $query): ?Installation
    {
        $path = $this->recordPath($query);
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Unable to read the installation record.');
        }
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The installation record is invalid JSON.', previous: $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('The installation record must be an object.');
        }

        return Installation::fromStoredArray($data);
    }

    private function write(InstallationQuery $query, Installation $installation): void
    {
        $json = json_encode($installation->toArray(), JSON_THROW_ON_ERROR);
        $temporary = tempnam($this->directory, '.installation-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create a temporary installation record.');
        }
        try {
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $json, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write the installation record.');
            }
            if (!rename($temporary, $this->recordPath($query))) {
                throw new \RuntimeException('Unable to persist the installation record.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function recordPath(InstallationQuery $query): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->recordName($query) . '.json';
    }

    private function lockPath(InstallationQuery $query): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->scopePrefix($query) . '.lock';
    }

    private function recordName(InstallationQuery $query): string
    {
        $kind = $query->userId === null ? 'bot' : 'user:' . $query->userId;

        return $this->scopePrefix($query) . '-' . hash('sha256', $kind);
    }

    private function scopePrefix(InstallationQuery $query): string
    {
        return hash('sha256', $query->scopeKey());
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function locked(InstallationQuery $query, Closure $operation, int $lock = LOCK_EX): mixed
    {
        $handle = fopen($this->lockPath($query), 'c');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the installation lock.');
        }
        try {
            if (!chmod($this->lockPath($query), 0600)) {
                throw new \RuntimeException('Unable to secure the installation lock.');
            }
            if (!flock($handle, $lock)) {
                throw new \RuntimeException('Unable to lock the installation record.');
            }
            try {
                return $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
