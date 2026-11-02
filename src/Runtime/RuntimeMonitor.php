<?php

declare(strict_types=1);

namespace Thunder\Bolt\Runtime;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Thunder\Bolt\OAuth\SystemClock;

/** Host-local process observations. No job inputs, credentials or connection URLs. */
final readonly class RuntimeMonitor
{
    public function __construct(
        public string $directory,
        private ClockInterface $clock = new SystemClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function start(string $role): ProcessHeartbeat
    {
        if (!in_array($role, ['socket', 'jobs'], true))
            throw new \InvalidArgumentException('Monitor socket or jobs processes.');
        return new ProcessHeartbeat($this->directory, $role, $this->clock, $this->logger);
    }

    /** @return list<array{id: string, role: string, pid: int, state: string, started_at: int, seen_at: int, job_id: ?string, stale: bool}> */
    public function snapshot(int $staleAfter = 30): array
    {
        if ($staleAfter < 5 || $staleAfter > 86400)
            throw new \InvalidArgumentException('Use a heartbeat age between 5 and 86400 seconds.');
        set_error_handler(static function (): never {
            throw new \RuntimeException('Runtime observations could not be read.');
        });
        try {
            $processes = [];
            foreach (glob($this->directory . '/*.json') ?: [] as $path) {
                if (count($processes) >= 10000 || is_link($path))
                    throw new \RuntimeException('Runtime observations cannot be read safely.');
                try {
                    $handle = fopen($path, 'rb');
                } catch (\RuntimeException $exception) {
                    if (!file_exists($path))
                        continue;
                    throw $exception;
                }
                if ($handle === false)
                    continue; // A gracefully stopped process may remove its record during a snapshot.
                try {
                    $deadline = hrtime(true) + 250_000_000;
                    while (!flock($handle, LOCK_SH | LOCK_NB)) {
                        if (hrtime(true) >= $deadline)
                            throw new \RuntimeException('Runtime observation remained locked.');
                        usleep(1000);
                    }
                    $json = stream_get_contents($handle, 8193);
                    if ($json === false || strlen($json) > 8192)
                        throw new \RuntimeException('Cannot read runtime observation.');
                    $record = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                } finally {
                    fclose($handle);
                }
                if (
                    !is_array($record)
                    || count($record) !== 7
                    || !is_string($record['id'] ?? null)
                    || preg_match('/\A[a-f0-9]{32}\z/', $record['id']) !== 1
                    || basename($path) !== $record['id'] . '.json'
                    || !in_array($record['role'] ?? null, ['socket', 'jobs'], true)
                    || !is_int($record['pid'] ?? null)
                    || !in_array($record['state'] ?? null, ProcessHeartbeat::STATES, true)
                    || !is_int($record['started_at'] ?? null)
                    || !is_int($record['seen_at'] ?? null)
                    || !array_key_exists('job_id', $record)
                    || $record['job_id'] !== null
                    && (!is_string($record['job_id']) || preg_match('/\A[a-f0-9]{32}\z/', $record['job_id']) !== 1)
                )
                    throw new \RuntimeException('Invalid runtime observation.');
                // Copy only known fields even if a file has been edited externally.
                $processes[] = [
                    'id' => $record['id'],
                    'role' => $record['role'],
                    'pid' => $record['pid'],
                    'state' => $record['state'],
                    'started_at' => $record['started_at'],
                    'seen_at' => $record['seen_at'],
                    'job_id' => $record['job_id'],
                    'stale' =>
                        $record['seen_at'] > $this->clock->now()->getTimestamp()
                            || ($this->clock->now()->getTimestamp() - $record['seen_at']) >= $staleAfter,
                ];
            }
            return $processes;
        } finally {
            restore_error_handler();
        }
    }
}
