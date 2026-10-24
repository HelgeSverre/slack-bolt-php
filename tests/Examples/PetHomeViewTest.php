<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\DemoJob;
use App\Demo\Pet\PetHomeView;
use PHPUnit\Framework\TestCase;

final class PetHomeViewTest extends TestCase
{
    public function test_dashboard_renders_stats_level_progress_activity_and_caretakers(): void
    {
        $view = PetHomeView::make($this->state(), 'feed')->toArray();
        self::assertSame('home', $view['type']);
        self::assertSame('boltling_home', $view['callback_id']);
        self::assertSame('Boltling', $view['blocks'][0]['text']['text']);
        self::assertCount(4, $view['blocks'][2]['fields']);
        self::assertStringContainsString('96/100', $view['blocks'][2]['fields'][0]['text']);
        self::assertStringContainsString('88 XP to level 3', $view['blocks'][3]['elements'][0]['text']);
        self::assertSame(
            [
                'demo_pet_home_feed',
                'demo_pet_home_play',
                'demo_pet_home_clean',
                'demo_pet_home_rest',
                'demo_pet_home_refresh',
            ],
            array_column($view['blocks'][4]['elements'], 'action_id'),
        );
        self::assertSame('Fed Boltling. +12 XP.', $view['blocks'][5]['elements'][0]['text']);
        self::assertStringContainsString('<@UADA> · 2 actions · 24 XP', $view['blocks'][11]['text']['text']);
        self::assertStringContainsString('<!date^1700000000^', $view['blocks'][11]['text']['text']);
    }

    public function test_activity_is_newest_first_and_bounded_to_eight_entries(): void
    {
        $state = $this->state();
        $state['audit'] = array_map(
            static fn(int $number): array => [
                'actor' => 'U' . $number,
                'action' => 'feed',
                'at' => 1_700_000_000 + $number,
                'outcome' => 'cared',
            ],
            range(1, 12),
        );
        $view = PetHomeView::make($state)->toArray();
        $activity = $view['blocks'][8]['text']['text'];
        self::assertStringStartsWith('<@U12> fed Boltling', $activity);
        self::assertStringContainsString('<@U5>', $activity);
        self::assertStringNotContainsString('<@U4>', $activity);
        self::assertCount(8, explode("\n", $activity));
    }

    public function test_empty_pet_and_cooldown_have_clear_feedback(): void
    {
        $state = $this->state();
        $state['audit'] = [];
        $state['contributors'] = [];
        $state['outcome'] = 'cooldown';
        $view = PetHomeView::make($state, 'play')->toArray();
        self::assertStringContainsString('cooldown', $view['blocks'][5]['elements'][0]['text']);
        self::assertStringNotContainsString('+12 XP', $view['blocks'][5]['elements'][0]['text']);
        self::assertStringContainsString('No care yet', $view['blocks'][8]['text']['text']);
        self::assertStringContainsString('Nobody yet', $view['blocks'][11]['text']['text']);
    }

    public function test_only_home_jobs_accept_missing_channels(): void
    {
        self::assertSame(
            '',
            DemoJob::fromArray(new DemoJob('pet_home', 'refresh', 'T1', 'U1', '')->toArray())->channel,
        );
        $this->expectException(\InvalidArgumentException::class);
        new DemoJob('pet', 'feed', 'T1', 'U1', '');
    }

    private function state(): array
    {
        return [
            'name' => 'Boltling',
            'evolution' => 'Hatchling',
            'level' => 2,
            'condition' => 'thriving',
            'xp' => 112,
            'stats' => ['fullness' => 96, 'joy' => 68, 'cleanliness' => 70, 'energy' => 74],
            'contributors' => ['UADA' => ['actions' => 2, 'xp' => 24, 'last_action_at' => 1_700_000_000]],
            'audit' => [],
            'outcome' => 'cared',
            'cooldown_seconds' => 15,
        ];
    }
}
