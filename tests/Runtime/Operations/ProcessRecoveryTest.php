<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime\Operations;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Jobs\JobStatus;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Runtime\QueueSnapshot;
use Thunder\Bolt\Runtime\RuntimeMonitor;
use ThunderBoltReliability\ProbeJob;

require_once dirname(__DIR__, 2) . '/Fixtures/runtime/process.php.stub';

final class ProcessRecoveryTest extends TestCase
{
    private string $directory;
    private array $children = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-process-recovery-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                foreach ($pipes as $pipe)
                    if (is_resource($pipe))
                        fclose($pipe);
                proc_close($process);
            }
        }
        $this->remove($this->directory);
    }

    public function test_two_workers_claim_each_job_once_and_duplicate_deliveries_share_one_record(): void
    {
        [$store, $jobs] = \ThunderBoltReliability\setup($this->directory);
        for ($index = 0; $index < 24; $index++) {
            $job = new ProbeJob($this->directory, 'normal', $index);
            $first = $jobs->dispatch($job, key: 'delivery-' . $index);
            self::assertSame($first->id, $jobs->dispatch($job, key: 'delivery-' . $index)->id);
        }
        $one = $this->start(12);
        $two = $this->start(12);
        self::assertSame(0, $this->finish($one));
        self::assertSame(0, $this->finish($two));
        self::assertCount(24, glob($this->directory . '/effect-*'));
        self::assertCount(24, file($this->directory . '/attempts'));
        self::assertSame(24, QueueSnapshot::read($this->directory . '/queue.sqlite', time())['counts']['succeeded']);
        self::assertSame([], new RuntimeMonitor($this->directory . '/runtime')->snapshot());
    }

    public function test_a_killed_safe_attempt_is_recovered_by_a_new_process(): void
    {
        [$store, $jobs] = \ThunderBoltReliability\setup($this->directory);
        $job = $jobs->dispatch(
            new ProbeJob($this->directory, 'crash-before', 1),
            key: 'safe',
            retry: RetryPolicy::idempotent(backoffSeconds: 0),
        );
        $child = $this->start();
        $this->awaitReady($job->id);
        self::assertSame(JobStatus::Running, $store->find($job->id)->status);
        self::assertSame('running', new RuntimeMonitor($this->directory . '/runtime')->snapshot()[0]['state']);
        proc_terminate($child[0], 9);
        self::assertNotSame(0, $this->finish($child));
        usleep(2_100_000);
        self::assertSame(1, QueueSnapshot::read($this->directory . '/queue.sqlite', time())['expired_leases']);
        self::assertSame(0, $this->finish($this->start()));
        self::assertSame(JobStatus::Succeeded, $store->find($job->id)->status);
        self::assertSame(2, $store->find($job->id)->attempts);
        self::assertSame('effect', file_get_contents($this->directory . '/effect-1'));
    }

    public function test_a_kill_after_an_unsafe_effect_is_uncertain_and_not_replayed(): void
    {
        [$store, $jobs] = \ThunderBoltReliability\setup($this->directory);
        $job = $jobs->dispatch(new ProbeJob($this->directory, 'crash-after', 2), key: 'unsafe');
        $child = $this->start();
        $this->awaitReady($job->id);
        proc_terminate($child[0], 9);
        self::assertNotSame(0, $this->finish($child));
        usleep(2_100_000);
        self::assertSame(1, $store->recoverExpired());
        self::assertSame(JobStatus::Uncertain, $store->find($job->id)->status);
        self::assertNull($store->claim());
        self::assertCount(1, file($this->directory . '/attempts'));
        self::assertSame('effect', file_get_contents($this->directory . '/effect-2'));
    }

    public function test_running_cancellation_stops_before_the_effect_in_another_process(): void
    {
        [$store, $jobs] = \ThunderBoltReliability\setup($this->directory);
        $job = $jobs->dispatch(new ProbeJob($this->directory, 'cancel', 3), key: 'cancel');
        $child = $this->start();
        $this->awaitReady($job->id);
        self::assertTrue($store->cancel($job->id));
        self::assertSame(0, $this->finish($child));
        self::assertSame(JobStatus::Cancelled, $store->find($job->id)->status);
        self::assertFileDoesNotExist($this->directory . '/effect-3');
    }

    public function test_sigterm_finishes_the_current_job_and_leaves_the_next_pending(): void
    {
        if (!function_exists('pcntl_signal'))
            self::markTestSkipped('Graceful POSIX signals require PCNTL.');
        [$store, $jobs] = \ThunderBoltReliability\setup($this->directory);
        $first = $jobs->dispatch(new ProbeJob($this->directory, 'drain', 4), key: 'drain');
        $child = $this->start(0);
        $this->awaitReady($first->id);
        $next = $jobs->dispatch(new ProbeJob($this->directory, 'normal', 5), key: 'next');
        proc_terminate($child[0], SIGTERM);
        self::assertSame(0, $this->finish($child));
        self::assertSame(JobStatus::Succeeded, $store->find($first->id)->status);
        self::assertSame(JobStatus::Pending, $store->find($next->id)->status);
        self::assertFileDoesNotExist($this->directory . '/effect-5');
        self::assertSame([], new RuntimeMonitor($this->directory . '/runtime')->snapshot());
    }

    public function test_cli_once_also_drains_its_current_job_on_sigterm(): void
    {
        if (!function_exists('pcntl_signal'))
            self::markTestSkipped('Graceful POSIX signals require PCNTL.');
        [$store, $jobs] = \ThunderBoltReliability\setup($this->directory);
        $job = $jobs->dispatch(new ProbeJob($this->directory, 'drain', 6), key: 'once-drain');
        $child = $this->start(1, 'console-once');
        $this->awaitReady($job->id);
        proc_terminate($child[0], SIGTERM);
        self::assertSame(0, $this->finish($child, 'succeeded'));
        self::assertSame(JobStatus::Succeeded, $store->find($job->id)->status);
        self::assertSame([], new RuntimeMonitor($this->directory . '/runtime')->snapshot());
    }

    public function test_socket_cli_sigterm_finishes_the_current_delivery_and_closes_its_connection(): void
    {
        if (!function_exists('pcntl_signal'))
            self::markTestSkipped('Graceful POSIX signals require PCNTL.');
        $child = $this->start(1, 'socket');
        $this->awaitReady('socket');
        self::assertSame('connected', new RuntimeMonitor($this->directory . '/runtime')->snapshot()[0]['state']);
        proc_terminate($child[0], SIGTERM);
        self::assertSame(0, $this->finish($child));
        self::assertFileExists($this->directory . '/socket-effect');
        self::assertFileExists($this->directory . '/socket-closed');
        self::assertCount(2, file($this->directory . '/socket-receives'));
        self::assertSame(
            'E-probe',
            json_decode(
                file_get_contents($this->directory . '/socket-ack'),
                true,
                flags: JSON_THROW_ON_ERROR,
            )['envelope_id'],
        );
        self::assertSame([], new RuntimeMonitor($this->directory . '/runtime')->snapshot());
    }

    private function start(int $maxJobs = 1, string $mode = ''): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/Fixtures/runtime/process.php.stub',
                $this->directory,
                (string) $maxJobs,
                $mode,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        return $this->children[] = [$process, $pipes];
    }

    private function awaitReady(string $id): void
    {
        $deadline = hrtime(true) + 3_000_000_000;
        while (!is_file($this->directory . '/ready-' . $id) && hrtime(true) < $deadline)
            usleep(10_000);
        self::assertFileExists($this->directory . '/ready-' . $id);
    }

    private function finish(array $child, ?string $expectedStatus = null): int
    {
        [$process, $pipes] = $child;
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame('', $error);
        if ($expectedStatus === null)
            self::assertSame('', $output);
        else
            self::assertSame($expectedStatus, json_decode($output, true, flags: JSON_THROW_ON_ERROR)['status']);
        return proc_close($process);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $file)
                $this->remove($file->getPathname());
            rmdir($path);
        } else
            unlink($path);
    }
}
