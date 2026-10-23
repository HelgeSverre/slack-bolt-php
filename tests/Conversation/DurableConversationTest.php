<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Conversation;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\Exceptions\ConversationExpired;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;
use Thunder\Bolt\Conversation\Stores\SqliteConversationStore;

final class DurableConversationTest extends TestCase
{
    #[DataProvider('drivers')]
    public function test_independent_workers_update_without_losing_changes_and_a_new_adapter_reads_the_result(string $driver): void
    {
        if ($driver === 'sqlite' && !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not installed.');
        }
        $directory = sys_get_temp_dir() . '/bolt-context-workers-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $key = new ConversationKey('C1', 'T1')->thread('100.001');
        $this->store($driver, $directory)->set($key, 0);
        $runner = $directory . '/worker.php';
        file_put_contents($runner, <<<'PHP'
            <?php
            require $argv[1];
            $directory = $argv[2];
            $store = $argv[3] === 'file'
                ? new Thunder\Bolt\Conversation\Stores\FileConversationStore($directory.'/records')
                : new Thunder\Bolt\Conversation\Stores\SqliteConversationStore(new PDO('sqlite:'.$directory.'/contexts.sqlite', options: [PDO::ATTR_TIMEOUT => 2]));
            $key = (new Thunder\Bolt\Conversation\ConversationKey('C1', 'T1'))->thread('100.001');
            touch($directory.'/ready-'.$argv[4]);
            $deadline = microtime(true) + 3;
            while (count(glob($directory.'/ready-*')) !== 2) {
                if (microtime(true) > $deadline) throw new RuntimeException('Worker barrier timed out.');
                usleep(1_000);
            }
            for ($index = 0; $index < 10; $index++) {
                $store->update($key, static function ($count) { usleep(5_000); return $count + 1; });
            }
            PHP);
        $processes = [];
        try {
            foreach ([1, 2] as $worker) {
                $process = proc_open(
                    [
                        PHP_BINARY,
                        $runner,
                        dirname(__DIR__, 2) . '/vendor/autoload.php',
                        $directory,
                        $driver,
                        (string) $worker,
                    ],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $output);
            }
            self::assertSame(20, $this->store($driver, $directory)->get($key)->value);
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                }
            }
            $this->removeDirectory($directory);
        }
    }

    public static function drivers(): iterable
    {
        yield 'file' => ['file'];
        yield 'sqlite' => ['sqlite'];
    }

    public function test_sqlite_expiry_cleanup_keeps_a_fresh_record_written_after_the_expired_read(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not installed.');
        }
        $connection = new class('sqlite::memory:') extends PDO {
            public ?\Closure $beforeDelete = null;

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (str_starts_with(ltrim($query), 'DELETE') && $this->beforeDelete !== null) {
                    $callback = $this->beforeDelete;
                    $this->beforeDelete = null;
                    $callback();
                }

                return parent::prepare($query, $options);
            }
        };
        $store = new SqliteConversationStore($connection);
        $key = new ConversationKey('C1', 'T1');
        $store->set($key, 'expired', 1);
        $connection->beforeDelete = static fn() => $store->set($key, 'fresh');

        try {
            $store->get($key);
            self::fail('The original snapshot was expired.');
        } catch (ConversationExpired) {
            self::assertSame('fresh', $store->get($key)->value);
        }
    }

    private function store(string $driver, string $directory): ConversationStore
    {
        return $driver === 'file'
            ? new FileConversationStore($directory . '/records')
            : new SqliteConversationStore(new PDO('sqlite:' . $directory . '/contexts.sqlite'));
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
