<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use PHPUnit\Framework\TestCase;
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
