<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Jobs;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobRecord;
use Thunder\Bolt\Jobs\JobStatus;
use Thunder\Bolt\Jobs\LeaseLost;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Jobs\SqliteJobStore;

final class JobOperationsTest extends TestCase
{
    private string $directory;
    private OperationsClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-job-operations-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->clock = new OperationsClock();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_existing_unversioned_jobs_upgrade_without_changing_identity_inputs_or_live_leases(): void
    {
        $db = $this->legacy();
        foreach (JobStatus::cases() as $status) {
            $this->seed($db, $status);
        }
        $original = $db->query(
            'SELECT id, fingerprint, payload_json, context_json, retry_json, lease_token FROM thunder_bolt_jobs ORDER BY id',
        )->fetchAll(PDO::FETCH_ASSOC);
        $store = $this->open();
        self::assertSame(2, $store->schemaVersion());
        self::assertSame(
            $original,
            $db->query(
                'SELECT id, fingerprint, payload_json, context_json, retry_json, lease_token FROM thunder_bolt_jobs ORDER BY id',
            )->fetchAll(PDO::FETCH_ASSOC),
        );
        foreach (JobStatus::cases() as $status) {
            $job = $store->find($status->value);
            self::assertNotNull($job);
            self::assertSame($status, $job->status);
            $history = $store->inspect($status->value)['history'];
            self::assertCount(1, $history);
            self::assertSame('legacy_imported', $history[0]['event']);
            self::assertSame('earlier_history_unavailable', $history[0]['reason']);
            self::assertSame($job->attempts, $history[0]['attempt']);
        }
        $duplicate = $store->enqueue(
            'ExampleJob',
            ['text' => 'private input'],
            $this->context(),
            'succeeded',
            RetryPolicy::once(),
        );
        self::assertTrue($duplicate->duplicate);
        self::assertSame('succeeded', $duplicate->id);
        self::assertCount(1, $store->inspect('succeeded')['history']);
        $running = $store->find('running');
        self::assertNotNull($running);
        $store->checkpoint($running->id, 'original-lease');
        $store->settle($running, JobStatus::Succeeded);
        self::assertSame(JobStatus::Succeeded, $store->find('running')?->status);
        self::assertCount(2, $this->open()->inspect('running')['history']);
        self::assertCount(6, $this->open()->recent());
    }

    public function test_failed_migration_rolls_back_ddl_version_and_data_together(): void
    {
        $db = $this->legacy();
        $this->seed($db, JobStatus::Pending);
        // Simulate a conflicting object encountered after ALTER TABLE has begun.
        $db->exec('CREATE VIEW thunder_bolt_job_history AS SELECT id FROM thunder_bolt_jobs');
        try {
            $this->open();
            self::fail('Expected migration conflict.');
        } catch (\PDOException) {
            self::assertNotContains('finished_at', $db->query('PRAGMA table_info(thunder_bolt_jobs)')->fetchAll(
                PDO::FETCH_COLUMN,
                1,
            ));
            self::assertSame(
                0,
                (int) $db->query(
                    "SELECT COUNT(*) FROM sqlite_master WHERE name IN ('thunder_bolt_job_schema', 'thunder_bolt_job_receipts')",
                )->fetchColumn(),
            );
            self::assertSame('pending', $db->query('SELECT status FROM thunder_bolt_jobs')->fetchColumn());
        }
        $db->exec('DROP VIEW thunder_bolt_job_history');
        self::assertSame(2, $this->open()->schemaVersion());
    }

    public static function invalidVersions(): iterable
    {
        yield 'future release' => [999];
        yield 'negative' => [-1];
        yield 'invalid version zero' => [0];
        yield 'fractional' => [2.5];
        yield 'non-numeric' => ['future'];
    }

    #[DataProvider('invalidVersions')]
    public function test_unknown_schema_versions_are_rejected_without_altering_the_database(int|float|string $version): void
    {
        $db = $this->legacy();
        $this->seed($db, JobStatus::Pending);
        $db->exec('CREATE TABLE thunder_bolt_job_schema (id INTEGER PRIMARY KEY, version INTEGER NOT NULL)');
        $insert = $db->prepare('INSERT INTO thunder_bolt_job_schema VALUES (1, ?)');
        $insert->execute([$version]);
        $before = file_get_contents($this->path());
        try {
            $this->open();
            self::fail('Expected a schema compatibility error.');
        } catch (\RuntimeException) {
            self::assertSame($before, file_get_contents($this->path()));
            self::assertNotContains('finished_at', $db->query('PRAGMA table_info(thunder_bolt_jobs)')->fetchAll(
                PDO::FETCH_COLUMN,
                1,
            ));
        }
    }

    public function test_history_tracks_attempts_cancellation_manual_retry_and_recovery_without_lease_secrets(): void
    {
        $store = $this->open();
        $id = $this->enqueue($store, 'history', RetryPolicy::idempotent());
        $first = $store->claim(10);
        self::assertNotNull($first);
        $this->clock->time++;
        $store->heartbeat($id, $first->leaseToken() ?? '', 10);
        self::assertCount(2, $store->inspect($id)['history'], 'Heartbeat polling must not inflate history.');
        $store->settle($first, JobStatus::Pending, \RuntimeException::class, $this->clock->time + 5);
        self::assertNull($store->find($id)?->finishedAt);
        $this->clock->time += 5;
        $second = $store->claim(10);
        self::assertNotNull($second);
        self::assertTrue($store->cancel($id));
        self::assertTrue($store->cancel($id));
        $store->settle($second, JobStatus::Succeeded);
        $detail = $store->inspect($id);
        self::assertSame('cancelled', $detail['status']);
        self::assertSame(
            ['accepted', 'claimed', 'attempt_finished', 'claimed', 'cancellation_requested', 'attempt_finished'],
            array_column($detail['history'], 'event'),
        );
        self::assertSame([0, 1, 1, 2, 2, 2], array_column($detail['history'], 'attempt'));
        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private input', $json);
        self::assertStringNotContainsString($first->leaseToken() ?? 'never-match', $json);
        self::assertStringNotContainsString($second->leaseToken() ?? 'never-match', $json);
        $store->retry($id);
        self::assertNull($store->find($id)?->finishedAt);
        $third = $store->claim(1);
        self::assertNotNull($third);
        $this->clock->time++;
        self::assertSame(1, $store->recoverExpired());
        $limited = $store->inspect($id, 2);
        self::assertTrue($limited['history_truncated']);
        self::assertSame(['claimed', 'recovered'], array_column($limited['history'], 'event'));
        self::assertSame('lease_expired_retry', $limited['history'][1]['reason']);
        self::assertSame('pending', $limited['status']);
        $history = $store->inspect($id)['history'];
        self::assertSame('operator_retry', $history[6]['reason']);
    }

    public function test_uncertain_recovery_history_explains_each_decision_and_explicit_repeat(): void
    {
        $store = $this->open();
        foreach ([
            ['unsafe',    RetryPolicy::once(),        false, 'lease_expired_non_idempotent'],
            ['exhausted', RetryPolicy::idempotent(1), false, 'lease_expired_attempts_exhausted'],
            ['cancelled', RetryPolicy::idempotent(2), true,  'lease_expired_cancellation_uncertain'],
        ] as [$key, $policy, $cancel, $reason]) {
            $id = $this->enqueue($store, $key, $policy);
            self::assertNotNull($store->claim(1));
            if ($cancel) {
                $store->cancel($id);
            }
            $this->clock->time++;
            $store->recoverExpired();
            $detail = $store->inspect($id);
            self::assertSame('uncertain', $detail['status']);
            self::assertSame($reason, $detail['history'][array_key_last($detail['history'])]['reason']);
            if ($key === 'unsafe') {
                $store->retry($id, allowRepeat: true);
                $history = $store->inspect($id)['history'];
                self::assertSame('operator_allow_repeat', $history[array_key_last($history)]['reason']);
                // Keep this accepted manual retry out of the next iteration's claim.
                $store->cancel($id);
            }
        }
    }

    public function test_pruning_is_bounded_preserves_protected_states_and_keeps_cross_connection_deduplication(): void
    {
        $store = $this->open();
        $terminal = [];
        foreach ([JobStatus::Succeeded, JobStatus::Failed, JobStatus::Cancelled] as $status) {
            $id = $this->enqueue($store, $status->value);
            $claim = $store->claim();
            self::assertNotNull($claim);
            $store->settle($claim, $status);
            $terminal[$status->value] = $id;
        }
        $uncertain = $this->enqueue($store, 'uncertain');
        self::assertNotNull($store->claim(1));
        $this->clock->time++;
        $store->recoverExpired();
        $running = $this->enqueue($store, 'running');
        self::assertNotNull($store->claim(1));
        $pending = $this->enqueue($store, 'pending');
        $this->clock->time += 40 * 86400;
        $cutoff = new DateTimeImmutable('@' . ($this->clock->time - (30 * 86400)));
        $db = $this->database();
        $bytes = file_get_contents($this->path());
        $preview = $store->prune($cutoff, dryRun: true, limit: 2);
        self::assertSame(2, $preview->eligible);
        self::assertTrue($preview->moreAvailable);
        self::assertSame(0, $preview->jsonSerialize()['pruned']);
        self::assertSame($bytes, file_get_contents($this->path()), 'A preview must not modify database bytes.');
        $result = $store->prune($cutoff, limit: 2);
        self::assertSame(2, $result->eligible);
        self::assertSame(6, $result->historyEntries);
        self::assertSame(1, $store->prune($cutoff)->eligible);
        self::assertSame(0, $store->prune($cutoff)->eligible);
        foreach ([$uncertain, $running, $pending] as $id) {
            self::assertNotNull($store->find($id));
        }
        foreach ($terminal as $key => $id) {
            self::assertNull($store->find($id));
            $detail = $store->inspect($id);
            self::assertSame('pruned', $detail['status']);
            self::assertSame($key, $detail['last_status']);
            self::assertSame('retained', $detail['deduplication']);
            self::assertTrue($detail['history_pruned']);
            $duplicate = $this->open()->enqueue(
                'ExampleJob',
                ['text' => 'private input'],
                $this->context(),
                $key,
                RetryPolicy::once(),
            );
            self::assertTrue($duplicate->duplicate);
            self::assertSame($id, $duplicate->id);
            self::assertFalse($store->cancel($id));
            self::assertSame(
                0,
                (int) $db->query("SELECT COUNT(*) FROM thunder_bolt_job_history WHERE job_id = '{$id}'")->fetchColumn(),
            );
        }
        self::assertSame(3, (int) $db->query('SELECT COUNT(*) FROM thunder_bolt_job_receipts')->fetchColumn());
        $receipts = json_encode(
            $db->query('SELECT * FROM thunder_bolt_job_receipts')->fetchAll(PDO::FETCH_ASSOC),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString('private input', $receipts);
        self::assertStringNotContainsString('idempotency_key', $receipts);
        $otherTenant = $store->enqueue(
            'ExampleJob',
            ['text' => 'private input'],
            JobContext::workspace('T2'),
            'succeeded',
            RetryPolicy::once(),
        );
        self::assertFalse($otherTenant->duplicate);
        $this->expectException(\LogicException::class);
        $store->enqueue('ExampleJob', ['text' => 'changed'], $this->context(), 'succeeded', RetryPolicy::once());
    }

    public function test_retention_uses_completion_time_and_excludes_the_exact_cutoff(): void
    {
        $store = $this->open();
        $id = $this->enqueue($store, 'long-lived');
        $this->clock->time += 40 * 86400;
        $job = $store->claim();
        self::assertNotNull($job);
        $store->settle($job, JobStatus::Succeeded);
        self::assertSame(0, $store->prune(new DateTimeImmutable('@' . $this->clock->time))->eligible);
        self::assertSame($this->clock->time, $store->find($id)?->finishedAt);
        $this->clock->time++;
        self::assertSame(1, $store->prune(new DateTimeImmutable('@' . $this->clock->time))->eligible);
        $this->expectException(\OutOfBoundsException::class);
        $store->retry($id, allowRepeat: true);
    }

    public function test_job_mutations_and_history_roll_back_together_and_pruning_is_atomic(): void
    {
        $store = $this->open();
        $id = $this->enqueue($store, 'atomic');
        $db = $this->database();
        $db->exec(
            "CREATE TRIGGER deny_claim_history BEFORE INSERT ON thunder_bolt_job_history WHEN NEW.event = 'claimed' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END",
        );
        try {
            $store->claim();
            self::fail('Expected atomic claim failure.');
        } catch (\PDOException) {
            self::assertSame(JobStatus::Pending, $store->find($id)?->status);
            self::assertSame(0, $store->find($id)?->attempts);
            self::assertCount(1, $store->inspect($id)['history']);
        }
        $db->exec('DROP TRIGGER deny_claim_history');
        $job = $store->claim();
        self::assertNotNull($job);
        $db->exec(
            "CREATE TRIGGER deny_finish_history BEFORE INSERT ON thunder_bolt_job_history WHEN NEW.event = 'attempt_finished' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END",
        );
        try {
            $store->settle($job, JobStatus::Succeeded);
            self::fail('Expected atomic outcome failure.');
        } catch (\PDOException) {
            self::assertSame(JobStatus::Running, $store->find($id)?->status);
            self::assertCount(2, $store->inspect($id)['history']);
        }
        $db->exec('DROP TRIGGER deny_finish_history');
        $store->settle($job, JobStatus::Succeeded);
        $this->clock->time++;
        $db->exec(
            "CREATE TRIGGER deny_prune BEFORE DELETE ON thunder_bolt_jobs BEGIN SELECT RAISE(ABORT, 'fixture failure'); END",
        );
        try {
            $store->prune($this->clock->now());
            self::fail('Expected atomic pruning failure.');
        } catch (\PDOException) {
            self::assertNotNull($store->find($id));
            self::assertCount(3, $store->inspect($id)['history']);
            self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM thunder_bolt_job_receipts')->fetchColumn());
        }
        $db->exec('DROP TRIGGER deny_prune');
        $store->prune($this->clock->now());
        $this->expectException(LeaseLost::class);
        $store->settle($job, JobStatus::Succeeded);
    }

    private function path(): string
    {
        return $this->directory . '/queue.sqlite';
    }

    private function open(): SqliteJobStore
    {
        return SqliteJobStore::open($this->path(), $this->clock);
    }

    private function database(): PDO
    {
        return new PDO('sqlite:' . $this->path(), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function legacy(): PDO
    {
        $db = $this->database();
        $db->exec((string) file_get_contents(__DIR__ . '/../Fixtures/jobs-v0.sql'));
        return $db;
    }

    private function context(): JobContext
    {
        return JobContext::workspace('T1', 'C1', '1.0', 'U1');
    }

    private function enqueue(SqliteJobStore $store, string $key, ?RetryPolicy $retry = null): string
    {
        return $store->enqueue(
            'ExampleJob',
            ['text' => 'private input'],
            $this->context(),
            $key,
            $retry ?? RetryPolicy::once(),
        )->id;
    }

    private function seed(PDO $db, JobStatus $status): void
    {
        $payload = json_encode(['text' => 'private input'], JSON_THROW_ON_ERROR);
        $context = json_encode($this->context()->toArray(), JSON_THROW_ON_ERROR);
        $identity = $this->context()->toArray();
        unset($identity['delivery_id']);
        $retry = json_encode(RetryPolicy::once()->toArray(), JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $payload . '|' . json_encode($identity, JSON_THROW_ON_ERROR) . '|' . $retry);
        $insert = $db->prepare(
            'INSERT INTO thunder_bolt_jobs VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $insert->execute([
            $status->value,
            'team:T1',
            'ExampleJob',
            $status->value,
            $fingerprint,
            $payload,
            $context,
            $retry,
            $status->value,
            $status === JobStatus::Pending ? 0 : 1,
            1,
            $this->clock->time - (40 * 86400),
            $status === JobStatus::Running ? 'original-lease' : null,
            $status === JobStatus::Running ? $this->clock->time + 60 : null,
            $status === JobStatus::Cancelled ? 1 : 0,
            null,
            $this->clock->time - (90 * 86400),
            $this->clock->time - (40 * 86400),
        ]);
    }
}

final class OperationsClock implements ClockInterface
{
    public int $time = 1800000000;

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->time);
    }
}
