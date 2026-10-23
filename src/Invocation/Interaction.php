<?php

declare(strict_types=1);

namespace Thunder\Bolt\Invocation;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Routing\ListenerKind;

/** @internal Shared classification for HTTP and programmatic dispatch. */
final class Interaction
{
    public static function kind(array $body): ?ListenerKind
    {
        return match ($body['type'] ?? null) {
            'block_suggestion', 'dialog_suggestion' => ListenerKind::Options,
            // Bolt classifies legacy suggestion requests before message actions.
            'interactive_message' => array_key_exists('name', $body) ? ListenerKind::Options : ListenerKind::Action,
            'block_actions', 'dialog_submission' => ListenerKind::Action,
            'shortcut', 'message_action' => ListenerKind::Shortcut,
            'view_submission', 'view_closed' => ListenerKind::View,
            default => null,
        };
    }

    public static function fromArray(array $body): Action|Shortcut|Options|View
    {
        $kind = self::kind($body);
        if ($kind === ListenerKind::Action && in_array($body['type'], ['block_actions', 'interactive_message'], true)) {
            if (
                !is_array($body['actions'] ?? null)
                || !array_is_list($body['actions'])
                || !is_array($body['actions'][0] ?? null)
            ) {
                throw new InvalidPayload('An action delivery needs a non-empty actions list.');
            }
            if ($body['type'] === 'block_actions' && !is_string($body['actions'][0]['action_id'] ?? null)) {
                throw new InvalidPayload('A block action needs an action_id.');
            }
        }
        if ($kind === ListenerKind::Options && !is_string($body['value'] ?? null)) {
            throw new InvalidPayload('An options request needs a string value.');
        }
        if (
            $kind === ListenerKind::Options
            && $body['type'] === 'block_suggestion'
            && (!is_string($body['action_id'] ?? null) || !is_string($body['block_id'] ?? null))
        ) {
            throw new InvalidPayload('A block suggestion needs action_id and block_id.');
        }
        if ($kind === ListenerKind::View && (!is_array($body['view'] ?? null) || array_is_list($body['view']))) {
            throw new InvalidPayload('A view delivery needs a view object.');
        }
        if ($kind === ListenerKind::Shortcut && !is_string($body['callback_id'] ?? null)) {
            throw new InvalidPayload('A shortcut needs a callback_id.');
        }

        return match ($kind) {
            ListenerKind::Action => Action::fromArray($body),
            ListenerKind::Shortcut => Shortcut::fromArray($body),
            ListenerKind::Options => Options::fromArray($body),
            ListenerKind::View => View::fromArray($body),
            default => throw new InvalidPayload('Unsupported Slack interaction type.'),
        };
    }
}
