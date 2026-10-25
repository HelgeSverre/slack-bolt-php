<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobStatus;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Jobs\SqliteJobStore;

final class JobOperationsCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('This CLI installation fixture uses Unix dependency symlinks.');
        }
        $this->directory = sys_get_temp_dir() . '/bolt-job-cli-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/composer.json', json_encode([
            'name' => 'test/job-operations',
            'type' => 'project',
            'autoload' => ['psr-4' => ['Thunder\\Bolt\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        symlink(dirname(__DIR__, 2) . '/vendor', $this->directory . '/vendor');
        symlink(dirname(__DIR__, 2) . '/src', $this->directory . '/src');
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isLink() || !$file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_help_and_invalid_flags_do_not_initialize_storage(): void
    {
        [$status, $output] = $this->command(['jobs:prune', '--help']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('before=30d', $output);
        self::assertStringContainsString('--dry-run', $output);
        self::assertStringContainsString('limit=1000', $output);
        self::assertFileDoesNotExist($this->path());
        foreach ([
            ['jobs:prune', '--before=-30d'],
            ['jobs:prune', '--before=0d'],
            ['jobs:prune', '--limit=0'],
            ['jobs:show', 'unknown', '--history-limit=0'],
        ] as $arguments) {
            [$status, $output, $error] = $this->command($arguments);
            self::assertNotSame(0, $status);
            self::assertSame('', $output);
            self::assertNotSame('', $error);
            self::assertFileDoesNotExist($this->path());
        }
    }

    public function test_migrate_show_preview_prune_and_pruned_inspection_work_through_the_real_cli(): void
    {
        [$status, $output, $error] = $this->command(['jobs:migrate']);
        self::assertSame(0, $status, $error);
        self::assertSame(2, json_decode($output, true, flags: JSON_THROW_ON_ERROR)['schema_version']);
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@' . (time() - (60 * 86400)));
            }
        };
        $store = SqliteJobStore::open($this->path(), $clock);
        $context = JobContext::workspace('T-fixture');
        $accepted = $store->enqueue(
            'FixtureJob',
            ['private' => 'never expose'],
            $context,
            'fixture-key',
            RetryPolicy::once(),
        );
        $claim = $store->claim();
        self::assertNotNull($claim);
        $store->settle($claim, JobStatus::Succeeded);

        [$status, $output, $error] = $this->command(['jobs:show', $accepted->id, '--history-limit=2']);
        self::assertSame(0, $status, $error);
        $detail = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('succeeded', $detail['status']);
        self::assertCount(2, $detail['history']);
        self::assertTrue($detail['history_truncated']);
        self::assertStringNotContainsString('never expose', $output);
        self::assertStringNotContainsString($claim->leaseToken() ?? 'never-match', $output);
        $bytes = file_get_contents($this->path());
        [$status, $output, $error] = $this->command(['jobs:prune', '--before=30d', '--dry-run', '--limit=1']);
        self::assertSame(0, $status, $error);
        $preview = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $preview['eligible']);
        self::assertSame(0, $preview['pruned']);
        self::assertTrue($preview['dry_run']);
        self::assertSame($bytes, file_get_contents($this->path()));
        [$status, $output, $error] = $this->command(['jobs:prune', '--before=30d']);
        self::assertSame(0, $status, $error);
        self::assertSame(1, json_decode($output, true, flags: JSON_THROW_ON_ERROR)['pruned']);
        [$status, $output, $error] = $this->command(['jobs:show', $accepted->id]);
        self::assertSame(0, $status, $error);
        $pruned = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('pruned', $pruned['status']);
        self::assertSame('retained', $pruned['deduplication']);
        self::assertTrue($pruned['history_pruned']);
        [$status] = $this->command(['jobs:retry', $accepted->id, '--allow-repeat']);
        self::assertNotSame(0, $status);
        self::assertTrue($store->enqueue(
            'FixtureJob',
            ['private' => 'never expose'],
            $context,
            'fixture-key',
            RetryPolicy::once(),
        )->duplicate);
    }

    public function test_completion_metadata_includes_operator_commands_and_flags(): void
    {
        $path = $this->directory . '/completion.json';
        [$status, $output, $error] = $this->command(['completion:generate', '--path=' . $path]);
        self::assertSame(0, $status, $output . $error);
        $metadata = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('jobs:prune', $metadata['commands']);
        self::assertArrayHasKey('jobs:migrate', $metadata['commands']);
        $flags = json_encode($metadata['commands']['jobs:prune']['flags'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('dry-run', $flags);
        self::assertStringContainsString('before', $flags);
        self::assertStringContainsString('limit', $flags);
        self::assertFileDoesNotExist($this->path());
    }

    private function path(): string
    {
        return $this->directory . '/jobs/queue.sqlite';
    }

    /** @return array{int, string, string} */
    private function command(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/bolt', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
            [...getenv(), 'SLACK_JOB_STORE_PATH' => $this->path()],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output === false ? '' : $output, $error === false ? '' : $error];
    }
}
