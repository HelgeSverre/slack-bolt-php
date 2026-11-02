<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime\Operations;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobStatus;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Jobs\SqliteJobStore;
use Thunder\Bolt\Runtime\QueueSnapshot;
use Thunder\Bolt\Runtime\RuntimeMonitor;
use Thunder\Bolt\Runtime\RuntimeStatus;

final class RuntimeStatusTest extends TestCase
{
    private string $directory;
    private OperationsClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-operations-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->clock = new OperationsClock();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path)
            unlink($path);
        rmdir($this->directory);
    }

    public function test_status_requires_a_confirmed_socket_and_marks_old_observations_stale(): void
    {
        $monitor = new RuntimeMonitor($this->directory, $this->clock);
        $status = new RuntimeStatus($monitor, $this->directory . '/absent.sqlite', $this->clock);
        self::assertFalse($status->report(['socket'])['healthy']);
        $socket = $monitor->start('socket');
        self::assertFalse($status->report(['socket'])['healthy']);
        $socket->update('connected');
        self::assertTrue($status->report(['socket'])['healthy']);
        $this->clock->time += 30;
        self::assertFalse($status->report(['socket'])['healthy']);
        self::assertTrue($status->report(['socket'])['processes'][0]['stale']);
        $socket->pulse();
        self::assertTrue($status->report(['socket'])['healthy']);
        $socket->update('backoff');
        self::assertFalse($status->report(['socket'])['healthy']);
        $socket->stop();
        self::assertSame([], $monitor->snapshot());
        self::assertFileDoesNotExist($this->directory . '/absent.sqlite');
    }

    public function test_a_new_ready_worker_can_replace_a_stale_process_without_hiding_it(): void
    {
        $monitor = new RuntimeMonitor($this->directory, $this->clock);
        $monitor->start('socket')->update('connected');
        $this->clock->time += 31;
        $fresh = $monitor->start('socket');
        $fresh->update('connected');
        $report = new RuntimeStatus($monitor, $this->directory . '/missing', $this->clock)->report(['socket']);
        self::assertTrue($report['healthy']);
        self::assertSame(1, $report['active']['socket']);
        self::assertSame(1, $report['attention']['stale_processes']);
    }

    public function test_queue_observation_counts_due_scheduled_and_expired_without_recovering_them(): void
    {
        $path = $this->directory . '/queue.sqlite';
        $store = SqliteJobStore::open($path, $this->clock);
        $context = JobContext::workspace('T1', 'C1');
        $first = $store->enqueue('test', ['secret' => 'canary-private-payload'], $context, 'one', RetryPolicy::once());
        $running = $store->claim(10);
        self::assertNotNull($running);
        $store->enqueue('test', [], $context, 'two', RetryPolicy::once());
        $store->enqueue('test', [], $context, 'three', RetryPolicy::once(), $this->clock->time + 100);
        $this->clock->time += 11;
        $before = file_get_contents($path);
        $snapshot = QueueSnapshot::read($path, $this->clock->time);
        self::assertSame(2, $snapshot['counts']['pending']);
        self::assertSame(1, $snapshot['counts']['running']);
        self::assertSame(1, $snapshot['due']);
        self::assertSame(1, $snapshot['scheduled']);
        self::assertSame(11, $snapshot['oldest_due_seconds']);
        self::assertSame(1, $snapshot['expired_leases']);
        self::assertSame(JobStatus::Running, $store->find($first->id)->status);
        self::assertSame($before, file_get_contents($path));
        self::assertStringNotContainsString('canary-private-payload', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_jobs_health_requires_an_existing_queue_and_a_fresh_worker(): void
    {
        $monitor = new RuntimeMonitor($this->directory, $this->clock);
        $worker = $monitor->start('jobs');
        $worker->update('idle');
        $status = new RuntimeStatus($monitor, $this->directory . '/queue.sqlite', $this->clock);
        self::assertFalse($status->report(['jobs'])['healthy']);
        SqliteJobStore::open($this->directory . '/queue.sqlite', $this->clock);
        self::assertTrue($status->report(['jobs'])['healthy']);
        $worker->update('running', str_repeat('a', 32));
        $this->clock->time += 31;
        self::assertFalse($status->report(['jobs'])['healthy']);
        $worker->pulse();
        self::assertTrue($status->report(['jobs'])['healthy']);
    }

    public function test_corrupt_records_fail_closed_without_returning_arbitrary_fields(): void
    {
        file_put_contents($this->directory . '/' . str_repeat('a', 32) . '.json', '{"secret":"never-print-me"}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid runtime observation.');
        new RuntimeMonitor($this->directory, $this->clock)->snapshot();
    }

    public function test_snapshot_waits_for_a_short_heartbeat_write_and_reads_a_coherent_record(): void
    {
        $monitor = new RuntimeMonitor($this->directory);
        $process = $monitor->start('socket');
        $process->update('connected');
        $record = glob($this->directory . '/*.json')[0];
        $ready = $this->directory . '/lock-ready';
        $child = proc_open(
            [
                PHP_BINARY,
                '-r',
                '$h=fopen($argv[1], "rb"); flock($h, LOCK_EX); file_put_contents($argv[2], "ready"); usleep(100000); fclose($h);',
                $record,
                $ready,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        fclose($pipes[0]);
        try {
            $deadline = hrtime(true) + 2_000_000_000;
            while (!is_file($ready) && hrtime(true) < $deadline)
                usleep(1000);
            self::assertFileExists($ready);
            self::assertSame('connected', $monitor->snapshot()[0]['state']);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($child));
        }
    }

    public function test_a_failed_diagnostic_sink_does_not_escape_the_heartbeat(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('private diagnostic failure');
            }
        };
        file_put_contents($this->directory . '/blocked', 'file');
        $process = new RuntimeMonitor($this->directory . '/blocked/child', logger: $logger)->start('jobs');
        $process->update('idle');
        $process->stop();
        self::assertSame('file', file_get_contents($this->directory . '/blocked'));
    }

    public function test_heartbeat_write_failure_does_not_interrupt_work(): void
    {
        $monitor = new RuntimeMonitor($this->directory . '/missing/child');
        file_put_contents($this->directory . '/missing', 'not a directory');
        $process = $monitor->start('jobs');
        $process->update('running', str_repeat('a', 32));
        $process->pulse();
        $process->stop();
        self::assertSame('not a directory', file_get_contents($this->directory . '/missing'));
    }
}

final class OperationsClock implements ClockInterface
{
    public int $time = 1000;

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->time);
    }
}
