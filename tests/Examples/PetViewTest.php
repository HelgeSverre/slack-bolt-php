<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Pet\PetView;
use PHPUnit\Framework\TestCase;

final class PetViewTest extends TestCase
{
    public function test_renders_a_complete_interactive_pet_message(): void
    {
        $message = PetView::message([
            'name' => 'Boltling',
            'evolution' => 'Hatchling',
            'level' => 1,
            'condition' => 'thriving',
            'xp' => 12,
            'stats' => ['fullness' => 96, 'joy' => 68, 'cleanliness' => 70, 'energy' => 74],
            'contributors' => ['U-ada' => ['actions' => 1, 'xp' => 12]],
            'latest_action' => ['actor' => 'U-ada', 'action' => 'feed', 'at' => 1_700_000_000],
        ])->toArray();

        self::assertStringContainsString('Boltling', $message['text']);
        self::assertSame('section', $message['blocks'][0]['type']);
        self::assertSame(
            ['demo_pet_feed', 'demo_pet_play', 'demo_pet_clean', 'demo_pet_rest', 'demo_pet_refresh'],
            array_column($message['blocks'][2]['elements'], 'action_id'),
        );
        self::assertStringContainsString('<@U-ada>', $message['blocks'][3]['elements'][0]['text']);
    }
}
