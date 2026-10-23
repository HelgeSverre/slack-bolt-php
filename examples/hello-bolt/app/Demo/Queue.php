<?php

declare(strict_types=1);

namespace App\Demo;

use Closure;
use DirectoryIterator;
use JsonException;
use RuntimeException;

/**
 * A small, durable local spool for the example worker.
 *
 * Claiming moves a job from pending to running before it is returned. Running
 * records are never moved back automatically after a restart: the demo prefers
 * losing an uncompleted effect over replaying an AI or terminal action.
 */
final class Queue
{
    private const int MAX_PAYLOAD_BYTES = 65_536;

    private const int MAX_FAILURE_BYTES = 1_024;

    private const int MAX_JOBS = 1_000;

    private const int COMPLETED_TTL_SECONDS = 86_400;

    private const int MAX_COMPLETED_TOMBSTONES = 4_096;

    /** @var resource|null */
    private $workerLock = null;

    /** @var array<string, true> */
    private static array $workerLocks = [];

    private readonly string $directory;

    public function __construct(string $directory)
    {
        if ($directory === '' || str_contains($directory, "\0") || is_link($directory)) {
            throw new \InvalidArgumentException('The demo queue directory must be a real, non-empty directory path.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the demo queue directory.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved) || is_link($resolved)) {
            throw new RuntimeException('Unable to resolve a safe demo queue directory.');
        }
        $this->directory = $resolved;
        $this->secureDirectory($this->directory);
        foreach (['pending', 'running', 'failed', 'completed'] as $state) {
            $this->secureDirectory($this->stateDirectory($state));
        }
    }

    public function __destruct()
    {
        $this->releaseWorker();
    }

    /**
     * @param array<string, mixed> $job
     */
    public function enqueue(array $job, string $idempotencyKey): bool
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 1_024) {
            throw new \InvalidArgumentException('The demo queue idempotency key must contain at most 1024 bytes.');
        }
        try {
            $payload = json_encode($job, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('The demo queue payload must be JSON-encodable.', previous: $exception);
        }
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new \LengthException('The demo queue payload exceeds 65536 bytes.');
        }

        $id = hash('sha256', $idempotencyKey);

        return $this->withLock(function () use ($id, $job): bool {
            $this->pruneCompletedTombstones();
            if ($this->recordExists($id)) {
                return false;
            }
            if ($this->recordCount() >= self::MAX_JOBS) {
                throw new \OverflowException('The demo queue has reached its capacity.');
            }
            $this->writeRecord('pending', $id, [
                'version' => 1,
                'id' => $id,
                'state' => 'pending',
                'createdAt' => time(),
                'sequence' => $this->nextSequence(),
                'job' => $job,
            ]);

            return true;
        });
    }

    /** @return array{id: string, job: array<array-key, mixed>}|null */
    public function claim(): ?array
    {
        return $this->withLock(function (): ?array {
            /** @var list<array{id: string, sequence: int, record: array<array-key, mixed>}> $records */
            $records = [];
            foreach ($this->recordIds('pending') as $id) {
                $record = $this->readRecord('pending', $id);
                if (
                    ($record['state'] ?? null) !== 'pending'
                    || !is_int($record['sequence'] ?? null)
                    || $record['sequence'] < 1
                ) {
                    throw new RuntimeException('The demo queue contains an invalid pending record.');
                }
                $records[] = ['id' => $id, 'sequence' => $record['sequence'], 'record' => $record];
            }
            if ($records === []) {
                return null;
            }
            usort($records, static fn(array $left, array $right): int => $left['sequence'] <=> $right['sequence']);
            $selected = $records[0];
            $id = $selected['id'];
            $record = $selected['record'];
            $source = $this->recordPath('pending', $id);
            $target = $this->recordPath('running', $id);
            if (!rename($source, $target)) {
                throw new RuntimeException('Unable to claim the demo queue job.');
            }
            $record['state'] = 'running';
            $record['claimedAt'] = time();
            $this->writeRecord('running', $id, $record);

            /** @var array<array-key, mixed> $job */
            $job = $record['job'];

            return ['id' => $id, 'job' => $job];
        });
    }

    public function complete(string $id): void
    {
        $this->assertId($id);
        $this->withLock(function () use ($id): void {
            $path = $this->recordPath('running', $id);
            if (is_link($path)) {
                throw new RuntimeException('The demo queue contains an unsafe record path.');
            }
            if (!is_file($path)) {
                return;
            }
            $this->pruneCompletedTombstones(self::MAX_COMPLETED_TOMBSTONES - 1);
            $this->writeCompletedTombstone($id);
            if (!unlink($path)) {
                throw new RuntimeException('Unable to complete the demo queue job.');
            }
        });
    }

    public function fail(string $id, string $reason): void
    {
        $this->assertId($id);
        $this->withLock(function () use ($id, $reason): void {
            $record = $this->readRecord('running', $id);
            $record['state'] = 'failed';
            $record['failedAt'] = time();
            $record['failure'] = $this->failureReason($reason);
            $this->writeRecord('failed', $id, $record);
            if (!unlink($this->recordPath('running', $id))) {
                throw new RuntimeException('Unable to finalize the failed demo queue job.');
            }
        });
    }

    public function acquireWorker(): bool
    {
        if ($this->workerLock !== null || isset(self::$workerLocks[$this->directory])) {
            return false;
        }
        $path = $this->directory . '/worker.lock';
        if (is_link($path)) {
            throw new RuntimeException('The demo queue worker lock path is unsafe.');
        }
        $lock = fopen($path, 'c+');
        if ($lock === false || !chmod($path, 0600)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to create the demo queue worker lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return false;
        }
        $this->workerLock = $lock;
        self::$workerLocks[$this->directory] = true;

        return true;
    }

    public function releaseWorker(): void
    {
        if (!is_resource($this->workerLock)) {
            return;
        }
        flock($this->workerLock, LOCK_UN);
        fclose($this->workerLock);
        $this->workerLock = null;
        unset(self::$workerLocks[$this->directory]);
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function withLock(Closure $operation): mixed
    {
        $path = $this->directory . '/queue.lock';
        if (is_link($path)) {
            throw new RuntimeException('The demo queue lock path is unsafe.');
        }
        $lock = fopen($path, 'c+');
        if ($lock === false || !chmod($path, 0600)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to create the demo queue lock.');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Unable to lock the demo queue.');
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recordExists(string $id): bool
    {
        foreach (['pending', 'running', 'failed', 'completed'] as $state) {
            $path = $this->recordPath($state, $id);
            if (is_link($path)) {
                throw new RuntimeException('The demo queue contains an unsafe record path.');
            }
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    private function recordCount(): int
    {
        return (
            count($this->recordIds('pending')) + count($this->recordIds('running')) + count($this->recordIds('failed'))
        );
    }

    private function nextSequence(): int
    {
        $path = $this->directory . '/sequence.json';
        if (is_link($path)) {
            throw new RuntimeException('The demo queue sequence path is unsafe.');
        }
        $lastSequence = 0;
        if (is_file($path)) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Unable to read the demo queue sequence.');
            }
            try {
                // @mago-expect analysis:mixed-assignment JSON input is validated below before use.
                $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The demo queue sequence is malformed.', previous: $exception);
            }
            if (
                !is_array($metadata)
                || ($metadata['version'] ?? null) !== 1
                || !is_int($metadata['lastSequence'] ?? null)
                || $metadata['lastSequence'] < 0
            ) {
                throw new RuntimeException('The demo queue sequence is invalid.');
            }
            $lastSequence = $metadata['lastSequence'];
        }
        if ($lastSequence >= PHP_INT_MAX) {
            throw new RuntimeException('The demo queue sequence is exhausted.');
        }
        $next = $lastSequence + 1;
        $this->writeJson($this->directory, 'sequence.json', [
            'version' => 1,
            'lastSequence' => $next,
        ]);

        return $next;
    }

    /** @return list<string> */
    private function recordIds(string $state): array
    {
        $ids = [];
        foreach (new DirectoryIterator($this->stateDirectory($state)) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if ($file->isFile() && preg_match('/\A\.[a-f0-9]{32}\.tmp\z/', $file->getFilename()) === 1) {
                continue;
            }
            if (!$file->isFile()) {
                throw new RuntimeException('The demo queue contains an unsafe record path.');
            }
            if ($file->isLink()) {
                throw new RuntimeException('The demo queue contains an unsafe record path.');
            }
            $matches = [];
            if (preg_match('/\A([a-f0-9]{64})\.json\z/', $file->getFilename(), $matches) !== 1) {
                throw new RuntimeException('The demo queue contains an unsafe record path.');
            }
            $ids[] = $matches[1];
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @return array<array-key, mixed> */
    private function readRecord(string $state, string $id): array
    {
        $path = $this->recordPath($state, $id);
        if (is_link($path)) {
            throw new RuntimeException('The demo queue contains an unsafe record path.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the demo queue job.');
        }
        try {
            // @mago-expect analysis:mixed-assignment JSON input is validated below before use.
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The demo queue contains malformed JSON.', previous: $exception);
        }
        if (
            !is_array($decoded)
            || ($decoded['version'] ?? null) !== 1
            || ($decoded['id'] ?? null) !== $id
            || !is_array($decoded['job'] ?? null)
        ) {
            throw new RuntimeException('The demo queue contains an invalid job record.');
        }

        return $decoded;
    }

    private function writeCompletedTombstone(string $id): void
    {
        $this->writeJson($this->stateDirectory('completed'), $id . '.json', [
            'version' => 1,
            'id' => $id,
            'state' => 'completed',
            'completedAt' => time(),
        ]);
    }

    private function pruneCompletedTombstones(?int $maximum = null): void
    {
        $oldestFirst = [];
        $expiration = time() - self::COMPLETED_TTL_SECONDS;
        foreach ($this->recordIds('completed') as $id) {
            $tombstone = $this->readCompletedTombstone($id);
            if ($tombstone['completedAt'] < $expiration) {
                if (!unlink($this->recordPath('completed', $id))) {
                    throw new RuntimeException('Unable to remove an expired demo queue tombstone.');
                }

                continue;
            }
            $oldestFirst[] = $tombstone;
        }
        usort(
            $oldestFirst,
            static fn(array $left, array $right): int => (
                $left['completedAt'] <=> $right['completedAt'] ?: $left['id'] <=> $right['id']
            ),
        );
        $limit = $maximum ?? self::MAX_COMPLETED_TOMBSTONES;
        while (count($oldestFirst) > $limit) {
            $tombstone = array_shift($oldestFirst);
            if ($tombstone === null || !unlink($this->recordPath('completed', $tombstone['id']))) {
                throw new RuntimeException('Unable to trim demo queue tombstones.');
            }
        }
    }

    /** @return array{id: string, completedAt: int} */
    private function readCompletedTombstone(string $id): array
    {
        $path = $this->recordPath('completed', $id);
        if (is_link($path)) {
            throw new RuntimeException('The demo queue contains an unsafe record path.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the demo queue tombstone.');
        }
        try {
            // @mago-expect analysis:mixed-assignment JSON input is validated below before use.
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The demo queue contains malformed JSON.', previous: $exception);
        }
        if (
            !is_array($decoded)
            || ($decoded['version'] ?? null) !== 1
            || ($decoded['id'] ?? null) !== $id
            || ($decoded['state'] ?? null) !== 'completed'
            || !is_int($decoded['completedAt'] ?? null)
        ) {
            throw new RuntimeException('The demo queue contains an invalid completion tombstone.');
        }

        return ['id' => $id, 'completedAt' => $decoded['completedAt']];
    }

    /** @param array<array-key, mixed> $record */
    private function writeRecord(string $state, string $id, array $record): void
    {
        $this->writeJson($this->stateDirectory($state), $id . '.json', $record);
    }

    /** @param array<array-key, mixed> $payload */
    private function writeJson(string $directory, string $filename, array $payload): void
    {
        $target = $directory . '/' . $filename;
        if (is_link($target)) {
            throw new RuntimeException('The demo queue contains an unsafe record path.');
        }
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the demo queue job.', previous: $exception);
        }
        $temporary = $directory . '/.' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = fopen($temporary, 'x');
        if ($handle === false || !chmod($temporary, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Unable to create the demo queue job file.');
        }
        try {
            if (
                fwrite($handle, $json) !== strlen($json)
                || !fflush($handle)
                || function_exists('fsync') && !fsync($handle)
            ) {
                throw new RuntimeException('Unable to persist the demo queue job.');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $target)) {
            unlink($temporary);
            throw new RuntimeException('Unable to publish the demo queue job.');
        }
    }

    private function failureReason(string $reason): string
    {
        if ($reason === '' || preg_match('//u', $reason) !== 1) {
            return 'The worker reported an invalid failure diagnostic.';
        }

        return mb_strcut($reason, 0, self::MAX_FAILURE_BYTES, 'UTF-8');
    }

    private function stateDirectory(string $state): string
    {
        return $this->directory . '/' . $state;
    }

    private function recordPath(string $state, string $id): string
    {
        $this->assertId($id);

        return $this->stateDirectory($state) . '/' . $id . '.json';
    }

    private function assertId(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('The demo queue job identifier is invalid.');
        }
    }

    private function secureDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('The demo queue spool directory must not be a symbolic link.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create the demo queue spool directory.');
        }
        if (!chmod($path, 0700)) {
            throw new RuntimeException('Unable to secure the demo queue spool directory.');
        }
    }
}
