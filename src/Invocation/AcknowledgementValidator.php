<?php

declare(strict_types=1);

namespace Thunder\Bolt\Invocation;

use Thunder\Bolt\Exceptions\InvalidAcknowledgement;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Payloads\SlashCommand;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\Acknowledgement;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\CommandResponse;
use Thunder\Bolt\Responses\OptionsResponse;
use Thunder\Bolt\Responses\ShortcutResponse;
use Thunder\Bolt\Responses\ViewResponse;

/** @internal Keeps response-family and wire-format decisions in one place. */
final class AcknowledgementValidator
{
    /** @return class-string<Acknowledgement>|null */
    public static function expected(object $payload): ?string
    {
        return match (true) {
            $payload instanceof SlashCommand => CommandResponse::class,
            $payload instanceof Action => ActionResponse::class,
            $payload instanceof Shortcut => ShortcutResponse::class,
            $payload instanceof Options => OptionsResponse::class,
            $payload instanceof View => ViewResponse::class,
            default => null,
        };
    }

    public static function forPayload(object $payload, Acknowledgement $response): Acknowledgement
    {
        $expected = self::expected($payload);
        if ($expected === null || !$response instanceof $expected) {
            throw new InvalidAcknowledgement(
                'This delivery cannot return '
                . $response::class
                . '. Expected '
                . ($expected ?? 'no initial response')
                . '.',
            );
        }
        $body = $response->toArray();
        if ($payload instanceof Action && $body !== []) {
            $allowed = match ($payload->type) {
                'interactive_message' => !isset($body['errors']),
                'dialog_submission' => array_keys($body) === ['errors'],
                default => false,
            };
            if (!$allowed) {
                throw new InvalidAcknowledgement("{$payload->type} does not accept this acknowledgement body.");
            }
        }
        if ($payload instanceof View && $payload->type === 'view_closed' && $body !== []) {
            throw new InvalidAcknowledgement('A view_closed delivery accepts only ViewResponse::empty().');
        }
        if ($payload instanceof Options && $response instanceof OptionsResponse) {
            return self::options($payload, $body);
        }

        return $response;
    }

    /** @param array<string,mixed> $body */
    private static function options(Options $payload, array $body): OptionsResponse
    {
        $convert = static function (array $option) use ($payload): array {
            $text = $option['text'] ?? $option['label'] ?? null;
            if ($payload->type === 'block_suggestion') {
                if (!is_array($text) || !is_string($text['text'] ?? null)) {
                    throw new InvalidAcknowledgement('Block suggestions need option text objects.');
                }

                return $option;
            }
            $label = is_array($text) ? $text['text'] ?? null : $text;
            if (!is_string($label)) {
                throw new InvalidAcknowledgement('Legacy options need string labels.');
            }

            return [$payload->type === 'dialog_suggestion' ? 'label' : 'text' => $label, 'value' => $option['value']];
        };
        if (isset($body['options'])) {
            $options = $body['options'];
            if (
                !is_array($options)
                || !array_is_list($options)
                || !array_all($options, static fn(mixed $value): bool => is_array($value))
            ) {
                throw new InvalidAcknowledgement('Options must be a list of option objects.');
            }
            /** @var list<array<string, mixed>> $options */
            $body['options'] = array_map($convert, $options);
        } else {
            $groups = $body['option_groups'] ?? null;
            if (
                !is_array($groups)
                || !array_is_list($groups)
                || !array_all($groups, static fn(mixed $value): bool => is_array($value))
            ) {
                throw new InvalidAcknowledgement('Option groups must be a list of group objects.');
            }
            /** @var list<array<string, mixed>> $groups */
            $body['option_groups'] = array_map(
                static function (array $group) use ($payload, $convert): array {
                    $label = $group['label'];
                    if ($payload->type !== 'block_suggestion' && is_array($label)) {
                        $label = $label['text'];
                    }
                    if ($payload->type === 'block_suggestion' && !is_array($label)) {
                        throw new InvalidAcknowledgement('Block option groups need label text objects.');
                    }
                    $options = $group['options'] ?? null;
                    if (
                        !is_array($options)
                        || !array_is_list($options)
                        || !array_all($options, static fn(mixed $value): bool => is_array($value))
                    ) {
                        throw new InvalidAcknowledgement('Option groups need a list of option objects.');
                    }
                    /** @var list<array<string, mixed>> $options */

                    return ['label' => $label, 'options' => array_map($convert, $options)];
                },
                $groups,
            );
        }

        return OptionsResponse::fromArray($body);
    }
}
