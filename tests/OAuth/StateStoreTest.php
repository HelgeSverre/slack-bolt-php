<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\OAuth\InstallRequest;
use Thunder\Bolt\OAuth\State\FileStateStore;
use Thunder\Bolt\OAuth\State\InMemoryStateStore;
use Thunder\Bolt\OAuth\State\State;
use Thunder\Bolt\OAuth\State\StateStore;

final class StateStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/thunder-bolt-oauth-state-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function test_in_memory_state_is_bound_to_its_cookie_and_consumed_after_a_mismatch(): void
    {
        $store = new InMemoryStateStore();
        $store->put(new State('state', 'cookie', 200));

        self::assertFalse($store->consume('state', 'wrong-cookie', 100));
        self::assertFalse($store->consume('state', 'cookie', 100));
    }

    public function test_expired_state_is_not_accepted(): void
    {
        $store = new InMemoryStateStore();
        $store->put(new State('state', 'cookie', 100));

        self::assertFalse($store->consume('state', 'cookie', 100));
    }

    public function test_file_store_consumes_a_state_only_once_across_store_instances(): void
    {
        $firstWorker = new FileStateStore($this->directory);
        $secondWorker = new FileStateStore($this->directory);
        $firstWorker->put(new State('state', 'cookie', 200));

        self::assertTrue($secondWorker->consume('state', 'cookie', 100));
        self::assertFalse($firstWorker->consume('state', 'cookie', 100));
    }

    public function test_all_state_stores_share_the_same_consumption_contract(): void
    {
        foreach ([new InMemoryStateStore(), new FileStateStore($this->directory)] as $store) {
            self::assertInstanceOf(StateStore::class, $store);
            $store->put(new State('state-' . $store::class, 'cookie', 200));
            self::assertTrue($store->consume('state-' . $store::class, 'cookie', 100));
        }
    }

    public function test_request_metadata_is_returned_only_once_after_cookie_and_expiry_validation(): void
    {
        foreach ([new InMemoryStateStore(), new FileStateStore($this->directory)] as $store) {
            $options = InstallRequest::make()->metadata(['account' => 42]);
            $store->put(new State('valid', 'cookie', 200, $options));
            self::assertSame($options->toArray(), $store->consumeRequest('valid', 'cookie', 100)?->toArray());
            self::assertNull($store->consumeRequest('valid', 'cookie', 100));
            $store->put(new State('expired', 'cookie', 100, $options));
            self::assertNull($store->consumeRequest('expired', 'cookie', 100));
            $store->put(new State('wrong', 'cookie', 200, $options));
            self::assertNull($store->consumeRequest('wrong', 'other', 100));
            self::assertNull($store->consumeRequest('wrong', 'cookie', 100));
        }
    }

    public function test_legacy_records_are_read_and_corrupt_request_records_are_consumed(): void
    {
        $store = new FileStateStore($this->directory);
        $store->put(new State('legacy', 'cookie', 200));
        $path = $this->directory . '/' . hash('sha256', 'legacy') . '.json';
        file_put_contents($path, json_encode([
            'cookie_hash' => hash('sha256', 'cookie'),
            'expires_at' => 200,
        ], JSON_THROW_ON_ERROR));
        self::assertSame([], $store->consumeRequest('legacy', 'cookie', 100)?->metadata);
        foreach (['broken', ['scopes' => 'commands'], ['metadata' => false]] as $index => $record) {
            $value = 'corrupt-' . $index;
            $store->put(new State($value, 'cookie', 200));
            $path = $this->directory . '/' . hash('sha256', $value) . '.json';
            file_put_contents($path, json_encode([
                'cookie_hash' => hash('sha256', 'cookie'),
                'expires_at' => 200,
                'request' => $record,
            ], JSON_THROW_ON_ERROR));
            self::assertNull($store->consumeRequest($value, 'cookie', 100));
            self::assertFileDoesNotExist($path);
        }
    }

    public function test_two_php_processes_cannot_both_consume_the_same_request(): void
    {
        $store = new FileStateStore($this->directory);
        $store->put(new State('race', 'cookie', 200, InstallRequest::make()->metadata(['account' => 42])));
        $script =
            'require $argv[1]; $store = new \\Thunder\\Bolt\\OAuth\\State\\FileStateStore($argv[2]); '
            . 'echo $store->consumeRequest("race", "cookie", 100) === null ? "lost" : "won";';
        $workers = [];
        try {
            foreach ([1, 2] as $_) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $this->directory],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $outcomes = [];
            foreach ($workers as [$process, $pipes]) {
                $outcomes[] = stream_get_contents($pipes[1]);
                self::assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
            }
            $workers = [];
            sort($outcomes);
            self::assertSame(['lost', 'won'], $outcomes);
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe))
                        fclose($pipe);
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->remove($path . '/' . $item);
                }
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }
}
