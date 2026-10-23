<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Pet\PetStore;
use PHPUnit\Framework\TestCase;

final class PetStoreTest extends TestCase
{
    private string $path;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'thunder-bolt-pet-');
        if ($temporary === false) {
            self::fail('Cannot create a temporary pet store path.');
        }
        unlink($temporary);
        $this->path = $temporary;
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '.lock'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_persists_per_workspace_state_across_store_objects(): void
    {
        $store = $this->store();
        $cared = $store->care('T-one', 'U-ada', 'feed', 'delivery-1');
        $otherTeam = $store->snapshot('T-two');
        $reloaded = $this->store()->snapshot('T-one');

        self::assertSame(12, $cared['xp']);
        self::assertSame(12, $reloaded['xp']);
        self::assertSame(1, $reloaded['contributors']['U-ada']['actions']);
        self::assertSame(0, $otherTeam['xp']);
    }

    public function test_care_decays_lazily_and_remains_recoverable_after_neglect(): void
    {
        $store = $this->store();
        $initial = $store->snapshot('T-one');
        $this->now += 900 * 50;
        $neglected = $store->snapshot('T-one');
        $recovered = $store->care('T-one', 'U-ada', 'feed', 'delivery-1');

        self::assertLessThan($initial['stats']['fullness'], $neglected['stats']['fullness']);
        self::assertSame('sleeping, but recoverable', $neglected['condition']);
        self::assertGreaterThan($neglected['stats']['fullness'], $recovered['stats']['fullness']);
        self::assertSame(12, $recovered['xp']);
    }

    public function test_delivery_ids_are_idempotent_and_cooldown_does_not_award_extra_xp(): void
    {
        $store = $this->store();
        $first = $store->care('T-one', 'U-ada', 'play', 'delivery-1');
        $again = $store->care('T-one', 'U-ada', 'play', 'delivery-1');
        $cooldown = $store->care('T-one', 'U-ada', 'clean', 'delivery-2');

        self::assertSame('idempotent', $again['outcome']);
        self::assertSame($first['xp'], $again['xp']);
        self::assertSame('cooldown', $cooldown['outcome']);
        self::assertSame($first['xp'], $cooldown['xp']);
        self::assertSame(1, $cooldown['contributors']['U-ada']['actions']);
    }

    public function test_contributors_have_isolated_counters_and_pet_levels_up(): void
    {
        $store = $this->store();
        for ($index = 0; $index < 17; $index++) {
            $store->care('T-one', ($index % 2) === 0 ? 'U-ada' : 'U-grace', 'rest', "delivery-{$index}");
            $this->now += 16;
        }
        $state = $store->snapshot('T-one');

        self::assertSame(9, $state['contributors']['U-ada']['actions']);
        self::assertSame(8, $state['contributors']['U-grace']['actions']);
        self::assertSame(3, $state['level']);
        self::assertSame('Sparkling', $state['evolution']);
        self::assertCount(2, $state['contributors']);
    }

    public function test_corrupt_json_is_never_replaced(): void
    {
        file_put_contents($this->path, '{corrupt');
        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        try {
            $store->snapshot('T-one');
        } finally {
            self::assertSame('{corrupt', file_get_contents($this->path));
        }
    }

    private function store(): PetStore
    {
        return new PetStore($this->path, fn(): int => $this->now);
    }
}
