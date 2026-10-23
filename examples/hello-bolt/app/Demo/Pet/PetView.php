<?php

declare(strict_types=1);

namespace App\Demo\Pet;

use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Context;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\BlockKit\Text;
use Thunder\Bolt\Messages\Message;

/**
 * @phpstan-import-type PublicState from PetStore
 * @phpstan-type PetState PublicState
 */
final class PetView
{
    /** @param PetState $state */
    public static function message(array $state): Message
    {
        $stats = self::stats($state);
        $latest = self::latest($state);
        $leaders = self::leaders($state);

        return Message::blocks(
            Section::markdown(
                "*⚡ {$state['name']}* · {$state['evolution']} · level {$state['level']}\n{$state['condition']}",
            ),
            Section::fields(
                Text::markdown("*Fullness*\n{$stats['fullness']}/100"),
                Text::markdown("*Joy*\n{$stats['joy']}/100"),
                Text::markdown("*Cleanliness*\n{$stats['cleanliness']}/100"),
                Text::markdown("*Energy*\n{$stats['energy']}/100"),
                Text::markdown("*Experience*\n{$state['xp']} XP"),
            ),
            Actions::make(
                Button::make('Feed', 'demo_pet_feed')->primary(),
                Button::make('Play', 'demo_pet_play'),
                Button::make('Clean', 'demo_pet_clean'),
                Button::make('Rest', 'demo_pet_rest'),
                Button::make('Refresh', 'demo_pet_refresh'),
            ),
            Context::make(Text::markdown("*Latest:* {$latest}\n*Caregivers:* {$leaders}")),
        )->fallbackText(self::summary($state));
    }

    /** @param PetState $state */
    public static function summary(array $state): string
    {
        $stats = self::stats($state);

        return sprintf(
            '%s is a %s at level %d: fullness %d, joy %d, cleanliness %d, energy %d. Latest: %s.',
            $state['name'],
            $state['evolution'],
            $state['level'],
            (int) $stats['fullness'],
            (int) $stats['joy'],
            (int) $stats['cleanliness'],
            (int) $stats['energy'],
            self::latest($state),
        );
    }

    /** @param PetState $state @return array{fullness: int, joy: int, cleanliness: int, energy: int} */
    private static function stats(array $state): array
    {
        return $state['stats'];
    }

    /** @param PetState $state */
    private static function latest(array $state): string
    {
        if ($state['latest_action'] === null) {
            return 'No care yet';
        }

        return "<@{$state['latest_action']['actor']}> chose {$state['latest_action']['action']}";
    }

    /** @param PetState $state */
    private static function leaders(array $state): string
    {
        if ($state['contributors'] === []) {
            return 'No caregivers yet';
        }
        $leaders = [];
        foreach (array_slice($state['contributors'], 0, 3, true) as $userId => $contribution) {
            $leaders[] = "<@{$userId}> ({$contribution['actions']})";
        }

        return implode(', ', $leaders);
    }
}
