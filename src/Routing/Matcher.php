<?php

declare(strict_types=1);

namespace Thunder\Bolt\Routing;

use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Payloads\Events\FunctionExecuted;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Payloads\SlashCommand;
use Thunder\Bolt\Payloads\View;

final class Matcher
{
    public static function matches(ListenerDescriptor $descriptor, object $payload): bool
    {
        return self::captures($descriptor, $payload) !== null;
    }

    /** @return array<int|string, string>|null */
    public static function captures(ListenerDescriptor $descriptor, object $payload): ?array
    {
        return self::match($descriptor, $payload)['matches'] ?? null;
    }

    /** @return array{matches: array<int|string,string>, constraintMatches: array<string,array<int|string,string>>}|null */
    public static function match(ListenerDescriptor $descriptor, object $payload): ?array
    {
        if ($descriptor->selector instanceof InteractionConstraints) {
            return self::interaction($descriptor->kind, $descriptor->selector, $payload);
        }
        $matches = match ($descriptor->kind) {
            ListenerKind::Event => self::event($descriptor->selector, $payload),
            ListenerKind::Function => $payload instanceof FunctionExecuted
                && $descriptor->selector === $payload->callbackId
                    ? []
                    : null,
            ListenerKind::Message => self::message($descriptor->selector, $payload),
            ListenerKind::SlashCommand => self::command($descriptor->selector, $payload),
            default => null,
        };

        return $matches === null ? null : ['matches' => $matches, 'constraintMatches' => []];
    }

    /** @return array<int|string, string>|null */
    private static function event(string|Regex $selector, object $payload): ?array
    {
        $type = match (true) {
            $payload instanceof AppMention => 'app_mention',
            $payload instanceof Message => 'message',
            $payload instanceof Event => $payload->type,
            default => null,
        };

        return $type === null ? null : self::selectorCaptures($selector, $type, $selector === $type);
    }

    /** @return array<int|string, string>|null */
    private static function message(string|Regex $selector, object $payload): ?array
    {
        if (!$payload instanceof Message) {
            return null;
        }

        return self::selectorCaptures(
            $selector,
            $payload->text,
            $selector instanceof Regex || str_contains($payload->text, $selector),
        );
    }

    /** @return array<int|string, string>|null */
    private static function command(string|Regex $selector, object $payload): ?array
    {
        if (!$payload instanceof SlashCommand) {
            return null;
        }

        return self::selectorCaptures($selector, $payload->command, $selector === $payload->command);
    }

    /** @return array{matches: array<int|string,string>, constraintMatches: array<string,array<int|string,string>>}|null */
    private static function interaction(
        ListenerKind $kind,
        InteractionConstraints $constraints,
        object $payload,
    ): ?array {
        $correctFamily = match ($kind) {
            ListenerKind::Action => $payload instanceof Action,
            ListenerKind::Shortcut => $payload instanceof Shortcut,
            ListenerKind::Options => $payload instanceof Options,
            ListenerKind::View => $payload instanceof View,
            default => false,
        };
        if (
            !$correctFamily
            || !(
                $payload instanceof Action
                || $payload instanceof Shortcut
                || $payload instanceof Options
                || $payload instanceof View
            )
        ) {
            return null;
        }
        if ($constraints->type !== null && $constraints->type !== $payload->type) {
            return null;
        }
        $actionId = $payload instanceof Action || $payload instanceof Options ? $payload->actionId : null;
        $blockId = $payload instanceof Action || $payload instanceof Options ? $payload->blockId : null;
        // Bolt's block/action constraints apply only to payloads with an action_id.
        if (($constraints->actionId !== null || $constraints->blockId !== null) && $actionId === null) {
            return null;
        }
        $captures = [];
        foreach ([
            'actionId' => $actionId,
            'blockId' => $blockId,
            'callbackId' => $payload->callbackId,
        ] as $field => $subject) {
            $selector = $constraints->{$field};
            if ($selector === null) {
                continue;
            }
            if ($subject === null) {
                return null;
            }
            $matches = self::selectorCaptures($selector, $subject, $selector === $subject);
            if ($matches === null) {
                return null;
            }
            if ($selector instanceof Regex) {
                $captures[$field] = $matches;
            }
        }

        return [
            'matches' => $captures['actionId'] ?? $captures['callbackId'] ?? $captures['blockId'] ?? [],
            'constraintMatches' => $captures,
        ];
    }

    /** @return array<int|string, string>|null */
    private static function selectorCaptures(string|Regex $selector, string $subject, bool $literalMatch): ?array
    {
        if ($selector instanceof Regex) {
            return $selector->captures($subject);
        }

        return $literalMatch ? [] : null;
    }
}
