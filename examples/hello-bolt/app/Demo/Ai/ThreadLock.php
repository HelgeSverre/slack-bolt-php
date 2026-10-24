<?php

declare(strict_types=1);

namespace App\Demo\Ai;

use Thunder\Bolt\Jobs\JobExecution;

/** Serializes one conversation without holding an unbounded database transaction. */
final class ThreadLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly string $directory,
        private readonly string $key,
    ) {}

    public function acquire(JobExecution $execution): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create AI conversation locks.');
        }
        $path = $this->directory . '/' . hash('sha256', $this->key) . '.lock';
        if (is_link($path)) {
            throw new \RuntimeException('AI conversation lock path is unsafe.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false || !chmod($path, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Cannot open AI conversation lock.');
        }
        try {
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                $execution->checkpoint();
                $execution->heartbeat();
                usleep(100_000);
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            throw $exception;
        }
        $this->handle = $handle;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
