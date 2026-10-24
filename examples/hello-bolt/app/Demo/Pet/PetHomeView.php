<?php

declare(strict_types=1);

namespace App\Demo\Pet;

use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Context;
use Thunder\Bolt\BlockKit\Divider;
use Thunder\Bolt\BlockKit\Header;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\BlockKit\Text;
use Thunder\Bolt\Views\Home;

/** @phpstan-import-type PublicState from PetStore */
final class PetHomeView
{
    /** @param PublicState $state */
    public static function make(array $state, string $action = 'refresh'): Home
    {
        $stats = $state['stats'];
        $remaining = 100 - ($state['xp'] % 100);
        return Home::make(
            Header::make($state['name']),
            Section::markdown(
                "Our shared workspace pet. Everyone can help.\n*{$state['evolution']} · Level {$state['level']}* · {$state['condition']}",
            ),
            Section::fields(
                Text::markdown(self::stat('Fullness', $stats['fullness'])),
                Text::markdown(self::stat('Joy', $stats['joy'])),
                Text::markdown(self::stat('Cleanliness', $stats['cleanliness'])),
                Text::markdown(self::stat('Energy', $stats['energy'])),
            ),
            Context::make(Text::markdown(
                "{$state['xp']} XP total · {$remaining} XP to level " . ($state['level'] + 1),
            )),
            Actions::make(
                Button::make('Feed', 'demo_pet_home_feed')->primary(),
                Button::make('Play', 'demo_pet_home_play'),
                Button::make('Clean', 'demo_pet_home_clean'),
                Button::make('Rest', 'demo_pet_home_rest'),
                Button::make('Refresh', 'demo_pet_home_refresh'),
            ),
            Context::make(Text::plain(self::feedback($state, $action))),
            Divider::make(),
            Section::markdown('*Recent activity*'),
            Section::markdown(self::activity($state)),
            Divider::make(),
            Section::markdown('*Top caretakers*'),
            Section::markdown(self::caretakers($state)),
            Context::make(Text::plain('Changes made elsewhere appear when you reopen Home or press Refresh.')),
        )->callbackId('boltling_home');
    }

    public static function unavailable(): Home
    {
        return Home::make(
            Header::make('Boltling'),
            Section::text('The pet dashboard could not load. Your saved state has been kept. Try Refresh shortly.'),
            Actions::make(Button::make('Refresh', 'demo_pet_home_refresh')),
        )->callbackId('boltling_home');
    }

    private static function stat(string $label, int $value): string
    {
        $filled = max(0, min(10, intdiv($value, 10)));
        return "*{$label}*\n`" . str_repeat('▰', $filled) . str_repeat('▱', max(0, 10 - $filled)) . "` {$value}/100";
    }

    /** @param PublicState $state */
    private static function feedback(array $state, string $action): string
    {
        return match ($state['outcome']) {
            'cared' => self::verb($action) . ' Boltling. +12 XP.',
            'cooldown' => "Care is on cooldown. Wait up to {$state['cooldown_seconds']} seconds before another action.",
            'idempotent' => 'That action was already handled. No extra care or XP was added.',
            default => 'Feed raises fullness; play raises joy; clean raises cleanliness; rest restores energy.',
        };
    }

    /** @param PublicState $state */
    private static function activity(array $state): string
    {
        $lines = [];
        foreach (array_reverse(array_slice($state['audit'], -8)) as $event) {
            $lines[] =
                '<@'
                . $event['actor']
                . '> '
                . lcfirst(self::verb($event['action']))
                . ' Boltling · '
                . self::date($event['at']);
        }
        return $lines === [] ? 'No care yet. Give Boltling its first meal or some company.' : implode("\n", $lines);
    }

    /** @param PublicState $state */
    private static function caretakers(array $state): string
    {
        $lines = [];
        foreach ($state['contributors'] as $user => $contribution) {
            $count = $contribution['actions'];
            $unit = $count === 1 ? 'action' : 'actions';
            $lines[] =
                "<@{$user}> · {$count} {$unit} · {$contribution['xp']} XP · "
                . self::date($contribution['last_action_at']);
        }
        return $lines === [] ? 'Nobody yet. Care for Boltling to appear here.' : implode("\n", $lines);
    }

    private static function date(int $timestamp): string
    {
        return '<!date^' . $timestamp . '^{date_short_pretty} at {time}|' . gmdate('Y-m-d H:i', $timestamp) . ' UTC>';
    }

    private static function verb(string $action): string
    {
        return match ($action) {
            'feed' => 'Fed',
            'play' => 'Played with',
            'clean' => 'Cleaned',
            'rest' => 'Rested with',
            default => 'Cared for',
        };
    }
}
