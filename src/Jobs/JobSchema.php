<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

use PDO;

/** @internal Additive migrations run atomically on the store's dedicated connection. */
final class JobSchema
{
    public const int VERSION = 2;

    public static function migrate(PDO $connection): void
    {
        $version = self::version($connection);
        if ($version === self::VERSION) {
            return;
        }
        $connection->exec('BEGIN IMMEDIATE');
        try {
            // Another opener may have finished migrating while this connection waited.
            $version = self::version($connection);
            if ($version === 0) {
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
                $expected = [
                    'id',
                    'tenant',
                    'type',
                    'idempotency_key',
                    'fingerprint',
                    'payload_json',
                    'context_json',
                    'retry_json',
                    'status',
                    'attempts',
                    'attempt_limit',
                    'available_at',
                    'lease_token',
                    'lease_expires_at',
                    'cancellation_requested',
                    'failure_type',
                    'created_at',
                    'updated_at',
                ];
                $columns = self::query($connection, 'PRAGMA table_info(thunder_bolt_jobs)')
                    ->fetchAll(PDO::FETCH_COLUMN, 1);
                if ($columns !== $expected) {
                    throw new \RuntimeException('Unrecognized legacy execution schema; no migration was applied.');
                }
                $connection->exec(<<<'SQL'
                    CREATE TABLE thunder_bolt_job_schema (id INTEGER PRIMARY KEY CHECK (id = 1), version INTEGER NOT NULL);
                    INSERT INTO thunder_bolt_job_schema (id, version) VALUES (1, 1);
                    SQL);
                $version = 1;
            }
            if ($version === 1) {
                $connection->exec(<<<'SQL'
                    ALTER TABLE thunder_bolt_jobs ADD COLUMN finished_at INTEGER NULL;
                    UPDATE thunder_bolt_jobs SET finished_at = updated_at WHERE status IN ('succeeded', 'failed', 'cancelled', 'uncertain');
                    CREATE INDEX thunder_bolt_jobs_retention ON thunder_bolt_jobs(finished_at, id) WHERE status IN ('succeeded', 'failed', 'cancelled');
                    CREATE TABLE thunder_bolt_job_history (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        job_id TEXT NOT NULL REFERENCES thunder_bolt_jobs(id) ON DELETE CASCADE,
                        attempt INTEGER NOT NULL,
                        event TEXT NOT NULL,
                        status TEXT NOT NULL,
                        occurred_at INTEGER NOT NULL,
                        reason TEXT NULL,
                        available_at INTEGER NULL,
                        lease_expires_at INTEGER NULL
                    );
                    CREATE INDEX thunder_bolt_job_history_job ON thunder_bolt_job_history(job_id, id);
                    INSERT INTO thunder_bolt_job_history (job_id, attempt, event, status, occurred_at, reason, available_at, lease_expires_at)
                    SELECT id, attempts, 'legacy_imported', status, updated_at, 'earlier_history_unavailable', available_at, lease_expires_at FROM thunder_bolt_jobs;
                    CREATE TABLE thunder_bolt_job_receipts (
                        tenant TEXT NOT NULL,
                        type TEXT NOT NULL,
                        key_digest TEXT NOT NULL,
                        fingerprint TEXT NOT NULL,
                        job_id TEXT NOT NULL UNIQUE,
                        last_status TEXT NOT NULL,
                        pruned_at INTEGER NOT NULL,
                        PRIMARY KEY (tenant, type, key_digest)
                    );
                    UPDATE thunder_bolt_job_schema SET version = 2 WHERE id = 1;
                    SQL);
            }
            $connection->exec('COMMIT');
        } catch (\Throwable $exception) {
            $connection->exec('ROLLBACK');
            throw $exception;
        }
    }

    public static function version(PDO $connection): int
    {
        $exists = self::query(
            $connection,
            "SELECT 1 FROM sqlite_master WHERE name = 'thunder_bolt_job_schema' AND type = 'table'",
        )
            ->fetchColumn();
        if ($exists === false) {
            return 0;
        }
        $rows = self::query($connection, 'SELECT id, version FROM thunder_bolt_job_schema')->fetchAll(PDO::FETCH_ASSOC);
        $row = $rows[0] ?? null;
        if (count($rows) !== 1 || !is_array($row) || (int) ($row['id'] ?? 0) !== 1) {
            throw new \RuntimeException('Invalid execution schema version record.');
        }
        $version = filter_var($row['version'] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version < 1 || $version > self::VERSION) {
            throw new \RuntimeException('Unsupported execution schema version; use a compatible Thunder Bolt release.');
        }
        return $version;
    }

    private static function query(PDO $connection, string $sql): \PDOStatement
    {
        $query = $connection->query($sql);
        return $query instanceof \PDOStatement ? $query : throw new \RuntimeException('Cannot read execution schema.');
    }
}
