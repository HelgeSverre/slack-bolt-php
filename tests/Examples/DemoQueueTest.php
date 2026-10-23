<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Queue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/examples/hello-bolt/app/Demo/Queue.php';

final class DemoQueueTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/thunder-bolt-demo-queue-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function test_a_claim_survives_restart_without_being_replayed_then_can_be_completed(): void
    {
        $queue = new Queue($this->directory);
        self::assertTrue($queue->enqueue(['kind' => 'summarize', 'text' => 'Review this request'], 'event:1'));

        $record = new Queue($this->directory)->claim();

        self::assertIsArray($record);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $record['id']);
        self::assertSame(['kind' => 'summarize', 'text' => 'Review this request'], $record['job']);
        self::assertNull(new Queue($this->directory)->claim());

        new Queue($this->directory)->complete($record['id']);
        self::assertNull(new Queue($this->directory)->claim());

        $pending = glob($this->directory . '/pending/*.json');
        self::assertIsArray($pending);
        self::assertCount(0, $pending);
    }

    public function test_enqueue_deduplicates_across_pending_running_and_failed_records(): void
    {
        $queue = new Queue($this->directory);
        self::assertTrue($queue->enqueue(['kind' => 'effect'], 'event:dedupe'));
        self::assertFalse($queue->enqueue(['kind' => 'effect'], 'event:dedupe'));

        $record = $queue->claim();
        self::assertIsArray($record);
        $queue->fail($record['id'], str_repeat('x', 2_000));

        self::assertFalse(new Queue($this->directory)->enqueue(['kind' => 'effect'], 'event:dedupe'));
        $failed = glob($this->directory . '/failed/*.json');
        self::assertIsArray($failed);
        $contents = (string) file_get_contents($failed[0]);
        $stored = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('failed', $stored['state']);
        self::assertLessThanOrEqual(1_024, strlen($stored['failure']));
    }

    public function test_claims_jobs_in_enqueue_order_even_when_identifiers_sort_differently(): void
    {
        $queue = new Queue($this->directory);
        // The SHA-256 identifiers sort input, open, close. A hash-based claim
        // order would therefore process the second input before the open.
        self::assertTrue($queue->enqueue(['name' => 'open'], 'fifo:86'));
        self::assertTrue(new Queue($this->directory)->enqueue(['name' => 'input'], 'fifo:27'));
        self::assertTrue($queue->enqueue(['name' => 'close'], 'fifo:52'));

        self::assertSame('open', $queue->claim()['job']['name']);
        self::assertSame('input', new Queue($this->directory)->claim()['job']['name']);
        self::assertSame('close', $queue->claim()['job']['name']);
    }

    public function test_completion_writes_a_payload_free_tombstone_that_deduplicates_redelivery(): void
    {
        $queue = new Queue($this->directory);
        self::assertTrue($queue->enqueue(['kind' => 'terminal', 'command' => 'ls'], 'event:completed'));
        $record = $queue->claim();
        self::assertIsArray($record);
        $queue->complete($record['id']);

        self::assertFalse(new Queue($this->directory)->enqueue([
            'kind' => 'terminal',
            'command' => 'ls',
        ], 'event:completed'));
        $completed = glob($this->directory . '/completed/*.json');
        self::assertIsArray($completed);
        self::assertCount(1, $completed);
        $tombstone = json_decode((string) file_get_contents($completed[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('completed', $tombstone['state']);
        self::assertArrayNotHasKey('job', $tombstone);
    }

    public function test_malformed_record_fails_closed_and_job_files_are_private(): void
    {
        $queue = new Queue($this->directory);
        $queue->enqueue(['kind' => 'effect'], 'event:malformed');
        $pending = glob($this->directory . '/pending/*.json');
        self::assertIsArray($pending);
        self::assertCount(1, $pending);
        self::assertSame(0600, fileperms($pending[0]) & 0777);
        self::assertSame(0700, fileperms($this->directory . '/pending') & 0777);
        file_put_contents($pending[0], '{not json');

        $this->expectException(RuntimeException::class);
        new Queue($this->directory)->claim();
    }

    public function test_rejects_unsafe_identifiers_and_allows_only_one_worker(): void
    {
        $first = new Queue($this->directory);
        $second = new Queue($this->directory);
        self::assertTrue($first->acquireWorker());
        self::assertFalse($second->acquireWorker());
        $first->releaseWorker();
        self::assertTrue($second->acquireWorker());
        $second->releaseWorker();

        $this->expectException(\InvalidArgumentException::class);
        $first->complete('../outside');
    }

    public function test_rejects_a_queue_directory_containing_a_null_byte(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Queue($this->directory . "\0unsafe");
    }

    public function test_capacity_is_bounded(): void
    {
        $queue = new Queue($this->directory);
        for ($index = 0; $index < 1_000; $index++) {
            self::assertTrue($queue->enqueue(['kind' => 'effect'], "event:{$index}"));
        }

        $this->expectException(\OverflowException::class);
        $queue->enqueue(['kind' => 'effect'], 'event:over-capacity');
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->remove($path . '/' . $item);
            }
        }
        rmdir($path);
    }
}
