<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

use PDO;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\OAuth\SystemClock;

/** Atomic intake, ownership and outcomes. No PHP objects or credentials are serialized. */
final readonly class SqliteJobStore
{
    private ClockInterface $clock;

    private function __construct(
        private PDO $connection,
        ?ClockInterface $clock = null,
    ) {
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('The execution store requires SQLite.');
        }
        $this->clock = $clock ?? new SystemClock();
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->exec('PRAGMA busy_timeout = 250');
        $connection->exec('PRAGMA synchronous = FULL');
        $connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS thunder_bolt_jobs (
                id TEXT PRIMARY KEY,
                tenant TEXT NOT NULL,
                type TEXT NOT NULL,
                idempotency_key TEXT NOT NULL,
                fingerprint TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                context_json TEXT NOT NULL,
                retry_json TEXT NOT NULL,
                status TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                attempt_limit INTEGER NOT NULL,
                available_at INTEGER NOT NULL,
                lease_token TEXT NULL,
                lease_expires_at INTEGER NULL,
                cancellation_requested INTEGER NOT NULL DEFAULT 0,
                failure_type TEXT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE (tenant, type, idempotency_key)
            );
            CREATE INDEX IF NOT EXISTS thunder_bolt_jobs_due ON thunder_bolt_jobs(status, available_at);
            CREATE INDEX IF NOT EXISTS thunder_bolt_jobs_leases ON thunder_bolt_jobs(status, lease_expires_at);
            SQL);
    }

    public static function open(string $path, ?ClockInterface $clock = null): self
    {
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new \InvalidArgumentException('Durable jobs require the pdo_sqlite PHP extension.');
        }
        if (
            $path === ''
            || $path === ':memory:'
            || str_starts_with($path, 'file:')
            || str_contains($path, "\0")
            || is_link($path)
        ) {
            throw new \InvalidArgumentException('Use a real SQLite execution database path.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the execution storage directory.');
        }
        if (is_link($directory)) {
            throw new \InvalidArgumentException('Execution storage must not be a symlink.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $permissions = fileperms($directory);
            if ($permissions === false || ($permissions & 0077) !== 0) {
                throw new \RuntimeException(
                    'Use a dedicated private execution directory (0700), including for SQLite sidecar files.',
                );
            }
        }
        $file = fopen($path, 'c');
        if ($file === false) {
            throw new \RuntimeException('Cannot create the execution database.');
        }
        fclose($file);
        if (!chmod($path, 0600)) {
            throw new \RuntimeException('Cannot protect the execution database.');
        }
        return new self(new PDO('sqlite:' . $path), $clock);
    }

    /** Explicit ephemeral test adapter; never use for accepting durable work. */
    public static function forTesting(?ClockInterface $clock = null): self
    {
        return new self(new PDO('sqlite::memory:'), $clock);
    }

    public function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    public function enqueue(
        string $type,
        array $payload,
        JobContext $context,
        string $key,
        RetryPolicy $retry,
        ?int $availableAt = null,
    ): DispatchResult {
        if ($key === '' || strlen($key) > 512) {
            throw new \InvalidArgumentException('A stable job key must contain 1 to 512 bytes.');
        }
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $contextJson = json_encode($context->toArray(), JSON_THROW_ON_ERROR);
        $retryJson = json_encode($retry->toArray(), JSON_THROW_ON_ERROR);
        if (strlen($payloadJson) > 65536) {
            throw new \LengthException('Job inputs exceed 65536 bytes.');
        }
        $identity = $context->toArray();
        unset($identity['delivery_id']);
        $fingerprint = hash(
            'sha256',
            $payloadJson . '|' . json_encode($identity, JSON_THROW_ON_ERROR) . '|' . $retryJson,
        );
        return $this->transaction(function () use (
            $type,
            $context,
            $key,
            $retry,
            $payloadJson,
            $contextJson,
            $retryJson,
            $fingerprint,
            $availableAt,
        ): DispatchResult {
            $id = bin2hex(random_bytes(16));
            $now = $this->now();
            $insert = $this->prepare(<<<'SQL'
                INSERT INTO thunder_bolt_jobs
                (id, tenant, type, idempotency_key, fingerprint, payload_json, context_json, retry_json, status, attempt_limit, available_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
                ON CONFLICT(tenant, type, idempotency_key) DO NOTHING
                SQL);
            $insert->execute([
                $id,
                $context->tenant(),
                $type,
                $key,
                $fingerprint,
                $payloadJson,
                $contextJson,
                $retryJson,
                $retry->maxAttempts,
                $availableAt ?? $now,
                $now,
                $now,
            ]);
            if ($insert->rowCount() === 1) {
                return new DispatchResult($id, false);
            }
            $existing = $this->prepare(
                'SELECT id, fingerprint FROM thunder_bolt_jobs WHERE tenant = ? AND type = ? AND idempotency_key = ?',
            );
            $existing->execute([$context->tenant(), $type, $key]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !hash_equals((string) $row['fingerprint'], $fingerprint)) {
                throw new \LogicException(
                    'The same job key was reused with different inputs, context or retry policy.',
                );
            }
            return new DispatchResult((string) $row['id'], true);
        });
    }

    public function find(string $id): ?JobRecord
    {
        $query = $this->prepare('SELECT * FROM thunder_bolt_jobs WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->record($row) : null;
    }

    /** @return list<JobRecord> */
    public function recent(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('List between 1 and 200 jobs.');
        }
        $query = $this->prepare('SELECT * FROM thunder_bolt_jobs ORDER BY created_at DESC, id DESC LIMIT ?');
        $query->bindValue(1, $limit, PDO::PARAM_INT);
        $query->execute();
        return array_map($this->record(...), $this->rows($query));
    }

    public function claim(int $leaseSeconds = 60): ?JobRecord
    {
        $this->validateLease($leaseSeconds);
        return $this->transaction(function () use ($leaseSeconds): ?JobRecord {
            $query = $this->prepare(
                "SELECT id FROM thunder_bolt_jobs WHERE status = 'pending' AND cancellation_requested = 0 AND available_at <= ? AND attempts < attempt_limit ORDER BY available_at, created_at, rowid LIMIT 1",
            );
            $query->execute([$this->now()]);
            $id = $query->fetchColumn();
            if (!is_string($id)) {
                return null;
            }
            $update = $this->prepare(
                "UPDATE thunder_bolt_jobs SET status = 'running', attempts = attempts + 1, lease_token = ?, lease_expires_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
            );
            $update->execute([bin2hex(random_bytes(24)), $this->now() + $leaseSeconds, $this->now(), $id]);
            return $this->find($id);
        });
    }

    public function checkpoint(string $id, #[\SensitiveParameter] string $leaseToken): JobRecord
    {
        $record = $this->find($id);
        $activeToken = $record?->leaseToken();
        if (
            $record === null
            || $record->status !== JobStatus::Running
            || $activeToken === null
            || !hash_equals($activeToken, $leaseToken)
            || ($record->leaseExpiresAt ?? 0) <= $this->now()
        ) {
            throw new LeaseLost('This job attempt no longer owns its lease.');
        }
        if ($record->cancellationRequested) {
            throw new JobCancelled('Job cancellation was requested.');
        }
        return $record;
    }

    public function heartbeat(string $id, #[\SensitiveParameter] string $leaseToken, int $leaseSeconds): void
    {
        $this->validateLease($leaseSeconds);
        $this->checkpoint($id, $leaseToken);
        $update = $this->prepare(
            "UPDATE thunder_bolt_jobs SET lease_expires_at = ?, updated_at = ? WHERE id = ? AND status = 'running' AND lease_token = ? AND lease_expires_at > ? AND cancellation_requested = 0",
        );
        $update->execute([$this->now() + $leaseSeconds, $this->now(), $id, $leaseToken, $this->now()]);
        if ($update->rowCount() !== 1) {
            $this->checkpoint($id, $leaseToken);
            throw new LeaseLost('The job heartbeat lost its lease.');
        }
    }

    public function settle(
        JobRecord $job,
        JobStatus $status,
        ?string $failureType = null,
        ?int $availableAt = null,
    ): void {
        if (!in_array(
            $status,
            [JobStatus::Pending, JobStatus::Succeeded, JobStatus::Failed, JobStatus::Cancelled, JobStatus::Uncertain],
            true,
        )) {
            throw new \InvalidArgumentException('Invalid job outcome.');
        }
        $update = $this->prepare(
            "UPDATE thunder_bolt_jobs SET status = CASE WHEN cancellation_requested = 1 AND ? <> 'uncertain' THEN 'cancelled' ELSE ? END, failure_type = ?, available_at = ?, lease_token = NULL, lease_expires_at = NULL, updated_at = ? WHERE id = ? AND status = 'running' AND lease_token = ? AND lease_expires_at > ?",
        );
        $update->execute([
            $status->value,
            $status->value,
            $failureType,
            $availableAt ?? $this->now(),
            $this->now(),
            $job->id,
            $job->leaseToken(),
            $this->now(),
        ]);
        if ($update->rowCount() !== 1) {
            throw new LeaseLost('A stale worker cannot settle this job.');
        }
    }

    public function cancel(string $id, ?string $tenant = null): bool
    {
        $update = $this->prepare(
            "UPDATE thunder_bolt_jobs SET cancellation_requested = 1, status = CASE WHEN status = 'pending' THEN 'cancelled' ELSE status END, updated_at = ? WHERE id = ? AND status IN ('pending', 'running')"
            . ($tenant === null ? '' : ' AND tenant = ?'),
        );
        $update->execute($tenant === null ? [$this->now(), $id] : [$this->now(), $id, $tenant]);
        return $update->rowCount() === 1;
    }

    public function cancelThread(JobContext $context): int
    {
        if ($context->channelId === null || $context->threadTs === null) {
            throw new \InvalidArgumentException('Thread cancellation needs both channel and thread identities.');
        }
        $update = $this->prepare(
            "UPDATE thunder_bolt_jobs SET cancellation_requested = 1, status = CASE WHEN status = 'pending' THEN 'cancelled' ELSE status END, updated_at = ? WHERE tenant = ? AND status IN ('pending', 'running') AND json_extract(context_json, '$.channel_id') = ? AND json_extract(context_json, '$.thread_ts') = ?",
        );
        $update->execute([$this->now(), $context->tenant(), $context->channelId, $context->threadTs]);
        return $update->rowCount();
    }

    /** Expired non-idempotent attempts are uncertain, never silently repeated. */
    public function recoverExpired(): int
    {
        return $this->transaction(function (): int {
            $query = $this->prepare(
                "SELECT * FROM thunder_bolt_jobs WHERE status = 'running' AND lease_expires_at <= ?",
            );
            $query->execute([$this->now()]);
            $rows = $this->rows($query);
            foreach ($rows as $row) {
                $job = $this->record($row);
                $retry =
                    $job->retry->safeToRepeat && !$job->cancellationRequested && $job->attempts < $job->attemptLimit;
                $status = $retry ? JobStatus::Pending : JobStatus::Uncertain;
                $update = $this->prepare(
                    'UPDATE thunder_bolt_jobs SET status = ?, available_at = ?, lease_token = NULL, lease_expires_at = NULL, failure_type = ?, updated_at = ? WHERE id = ?',
                );
                $update->execute([
                    $status->value,
                    $this->now() + ($retry ? $job->retry->delay($job->attempts) : 0),
                    'lease_expired',
                    $this->now(),
                    $job->id,
                ]);
            }
            return count($rows);
        });
    }

    public function retry(string $id, bool $allowRepeat = false): void
    {
        $this->transaction(function () use ($id, $allowRepeat): void {
            $job = $this->find($id) ?? throw new \OutOfBoundsException('Job not found.');
            if (!in_array($job->status, [JobStatus::Failed, JobStatus::Uncertain, JobStatus::Cancelled], true)) {
                throw new \LogicException('Only failed, uncertain or cancelled jobs can be retried.');
            }
            if (!$job->retry->safeToRepeat && !$allowRepeat) {
                throw new \LogicException(
                    'This job may already have caused effects. Explicitly allow repeating it after inspection.',
                );
            }
            $update = $this->prepare(
                "UPDATE thunder_bolt_jobs SET status = 'pending', cancellation_requested = 0, failure_type = NULL, attempt_limit = ?, available_at = ?, updated_at = ? WHERE id = ?",
            );
            $update->execute([$job->attempts + $job->retry->maxAttempts, $this->now(), $this->now(), $id]);
        });
    }

    private function validateLease(int $seconds): void
    {
        if ($seconds < 1 || $seconds > 86400) {
            throw new \InvalidArgumentException('A job lease must last between one second and one day.');
        }
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function transaction(\Closure $operation): mixed
    {
        if ($this->connection->inTransaction()) {
            throw new \LogicException('Execution storage needs a dedicated SQLite connection.');
        }
        $this->connection->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation();
            $this->connection->exec('COMMIT');
            return $result;
        } catch (\Throwable $exception) {
            $this->connection->exec('ROLLBACK');
            throw $exception;
        }
    }

    private function record(array $row): JobRecord
    {
        return new JobRecord(
            (string) $row['id'],
            (string) $row['type'],
            $this->json((string) $row['payload_json']),
            JobContext::fromArray($this->json((string) $row['context_json'])),
            RetryPolicy::fromArray($this->json((string) $row['retry_json'])),
            JobStatus::from((string) $row['status']),
            (int) $row['attempts'],
            (int) $row['attempt_limit'],
            (int) $row['available_at'],
            $row['lease_expires_at'] === null ? null : (int) $row['lease_expires_at'],
            $row['lease_token'] === null ? null : (string) $row['lease_token'],
            (int) $row['cancellation_requested'] === 1,
            $row['failure_type'] === null ? null : (string) $row['failure_type'],
        );
    }

    private function prepare(string $sql): \PDOStatement
    {
        $query = $this->connection->prepare($sql);
        return $query instanceof \PDOStatement
            ? $query
            : throw new \RuntimeException('Cannot prepare execution storage query.');
    }

    /** @return list<array<array-key, mixed>> */
    private function rows(\PDOStatement $query): array
    {
        $rows = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException('Invalid execution storage row.');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function json(string $json): array
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : throw new \UnexpectedValueException('Invalid execution storage JSON.');
    }
}
