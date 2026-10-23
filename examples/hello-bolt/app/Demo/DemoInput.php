<?php

declare(strict_types=1);

namespace App\Demo;

final class DemoInput
{
    /** @return null|array{mode: string, text: string} */
    public static function parse(string $text): ?array
    {
        $text = trim(preg_replace('/^\s*<@[A-Z0-9]+>\s*/', '', $text) ?? $text);
        if (!preg_match('/^(pet|terminal|ai|help)(?:\s+(.*))?$/is', $text, $matches)) {
            return null;
        }
        return ['mode' => strtolower($matches[1]), 'text' => trim($matches[2] ?? '')];
    }

    public static function help(): string
    {
        return (
            "*Thunder Bolt playground*\n"
            . "• `/bolt pet` — meet our shared pet. Feed, play, clean, and help it level up.\n"
            . "• `/bolt terminal` — open a persistent shell (configured users only). Reply in its thread to type.\n"
            . "• `/bolt ai your question` — ask the AI; continue chatting in its thread.\n"
            . 'You can also mention me with `pet`, `terminal`, or `ai …`.'
        );
    }
}
