<?php

declare(strict_types=1);

namespace Thunder\Bolt\Runtime;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** @internal One process owns one random record. Monitoring must never decide job outcomes. */
final class ProcessHeartbeat
{
    public const array STATES = ['starting', 'connecting', 'connected', 'backoff', 'idle', 'running'];

    private readonly string $id;
    private readonly int $startedAt;
    private int $lastWrite = 0;
    private string $state = 'starting';
    private ?string $jobId = null;
    private bool $failed = false;

    public function __construct(
        private readonly string $directory,
        private readonly string $role,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->startedAt = $clock->now()->getTimestamp();
        $this->update('starting');
    }

    public function update(string $state, ?string $jobId = null): void
    {
        if (
            !in_array($state, self::STATES, true)
            || $jobId !== null && preg_match('/\\A[a-f0-9]{32}\\z/', $jobId) !== 1
        )
            throw new \InvalidArgumentException(
                'Use a known process state and a job identifier, without execution data.',
            );
        $this->state = $state;
        $this->jobId = $jobId;
        $this->write();
    }

    public function pulse(): void
    {
        if (($this->clock->now()->getTimestamp() - $this->lastWrite) >= 5)
            $this->write();
    }

    public function stop(): void
    {
        set_error_handler(static function (): never {
            throw new \RuntimeException('Runtime observation removal failed.');
        });
        try {
            if (is_file($this->path()) && !unlink($this->path()))
                $this->failure();
        } catch (\Throwable) {
            $this->failure();
        } finally {
            restore_error_handler();
        }
    }

    private function write(): void
    {
        $handle = null;
        set_error_handler(static function (): never {
            throw new \RuntimeException('Runtime observation filesystem failed.');
        });
        try {
            if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory))
                throw new \RuntimeException('Cannot create runtime observation directory.');
            if (is_link($this->directory) || is_link($this->path()))
                throw new \RuntimeException('Runtime observations must not be symlinks.');
            if (PHP_OS_FAMILY !== 'Windows' && ((fileperms($this->directory) ?: 0) & 0077) !== 0)
                throw new \RuntimeException('Runtime observations require a private directory.');
            $handle = fopen($this->path(), 'c+b');
            if ($handle === false || !chmod($this->path(), 0600) || !flock($handle, LOCK_EX | LOCK_NB))
                throw new \RuntimeException('Cannot protect runtime observation.');
            $now = $this->clock->now()->getTimestamp();
            $json = json_encode([
                'id' => $this->id,
                'role' => $this->role,
                'pid' => getmypid(),
                'state' => $this->state,
                'started_at' => $this->startedAt,
                'seen_at' => $now,
                'job_id' => $this->jobId,
            ], JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle))
                throw new \RuntimeException('Cannot write runtime observation.');
            $this->lastWrite = $now;
            $this->failed = false;
        } catch (\Throwable) {
            $this->failure();
        } finally {
            restore_error_handler();
            if (is_resource($handle))
                fclose($handle);
        }
    }

    private function failure(): void
    {
        if ($this->failed)
            return;
        $this->failed = true;
        try {
            $this->logger->warning('Runtime heartbeat could not be recorded. Check private storage permissions.');
        } catch (\Throwable) {
            // A failing diagnostic sink must not fail an otherwise valid job.
            return;
        }
    }

    private function path(): string
    {
        return $this->directory . '/' . $this->id . '.json';
    }
}
