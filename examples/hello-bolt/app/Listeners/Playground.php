<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Demo\DemoConfig;
use App\Demo\DemoInput;
use App\Demo\Jobs\Answer;
use App\Demo\ThreadState;
use Thunder\Bolt\Attributes\Action as ActionHandler;
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Attributes\Shortcut as ShortcutHandler;
use Thunder\Bolt\Attributes\SlashCommand as CommandHandler;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Payloads\SlashCommand;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\CommandResponse;
use Thunder\Bolt\Responses\ShortcutResponse;
use Thunder\Bolt\Routing\Regex;

final readonly class Playground
{
    public function __construct(
        private DemoConfig $config,
    ) {}

    #[CommandHandler('/bolt')]
    public function command(SlashCommand $command, Delivery $delivery, Jobs $jobs): CommandResponse
    {
        $input = DemoInput::parse($command->text);
        if ($input === null || $input['mode'] === 'help') {
            return CommandResponse::ephemeral(DemoInput::help());
        }
        if ($input['mode'] === 'terminal' && !$this->config->allowsTerminal($command->userId)) {
            return CommandResponse::ephemeral('Terminal access is limited to SLACK_TERMINAL_ALLOWED_USERS.');
        }
        $this->enqueue(
            $input['mode'],
            $input['text'],
            $command->userId,
            $command->channelId,
            null,
            $delivery,
            (string) ($command->raw['trigger_id'] ?? ''),
            $jobs,
        );
        return CommandResponse::ephemeral('Queued! The demo worker will post here in a moment.');
    }

    #[ShortcutHandler('test_message', type: 'message_action')]
    public function messageShortcut(Shortcut $shortcut, Delivery $delivery, Jobs $jobs): ShortcutResponse
    {
        $text = $shortcut->raw['message']['text'] ?? '';
        $this->enqueue(
            'ai',
            'Please explain or answer this Slack message: ' . (is_string($text) ? mb_substr($text, 0, 4000) : ''),
            $shortcut->userId ?? '',
            $shortcut->channelId ?? '',
            $shortcut->threadTs ?? $shortcut->messageTs,
            $delivery,
            $shortcut->triggerId ?? '',
            $jobs,
        );
        return ShortcutResponse::empty();
    }

    #[Event('app_mention')]
    public function mention(AppMention $event, Delivery $delivery, Reply $reply, Jobs $jobs): void
    {
        $input = DemoInput::parse($event->text);
        if ($input === null) {
            return;
        }
        if ($input['mode'] === 'help') {
            $reply->inThread(DemoInput::help());
            return;
        }
        if ($input['mode'] === 'terminal' && !$this->config->allowsTerminal($event->userId)) {
            $reply->ephemeral('Terminal access is limited to SLACK_TERMINAL_ALLOWED_USERS.');
            return;
        }
        $this->enqueue(
            $input['mode'],
            $input['text'],
            $event->userId,
            $event->channelId,
            $event->threadTs ?? $event->ts,
            $delivery,
            $event->ts,
            $jobs,
        );
    }

    #[Event('message')]
    public function thread(Message $event, Delivery $delivery, Jobs $jobs): void
    {
        if (
            $event->threadTs === null
            || isset($event->raw['subtype'])
            || isset($event->raw['bot_id'])
            || str_contains($event->text, '<@')
        ) {
            return;
        }
        $team = $delivery->authorizationSource?->teamId ?? '';
        $state = new ThreadState($this->config->directory)->get($team, $event->channelId, $event->threadTs);
        $mode = $state['mode'] ?? null;
        if ($mode !== 'ai' && $mode !== 'terminal') {
            return;
        }
        if (
            $mode === 'terminal'
            && (!$this->config->allowsTerminal($event->userId) || ($state['owner'] ?? '') !== $event->userId)
        ) {
            return;
        }
        $this->enqueue(
            $mode,
            html_entity_decode($event->text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $event->userId,
            $event->channelId,
            $event->threadTs,
            $delivery,
            $event->ts,
            $jobs,
            'input',
        );
    }

    #[ActionHandler(new Regex('/^demo_pet_(feed|play|clean|rest|refresh)$/'))]
    public function pet(Action $action, Delivery $delivery, Jobs $jobs): ActionResponse
    {
        $this->enqueue(
            'pet',
            substr($action->actionId ?? '', strlen('demo_pet_')),
            $action->userId ?? '',
            $action->channelId ?? '',
            $action->threadTs,
            $delivery,
            (string) ($action->action['action_ts'] ?? ''),
            $jobs,
            'care',
            $action->messageTs,
        );
        return ActionResponse::empty();
    }

    #[ActionHandler(new Regex('/^demo_terminal_(interrupt|close)$/'))]
    public function terminalControl(Action $action, Delivery $delivery, Jobs $jobs): ActionResponse
    {
        $team = $delivery->authorizationSource?->teamId ?? '';
        $thread = $action->threadTs ?? $action->messageTs ?? '';
        $state = new ThreadState($this->config->directory)->get($team, $action->channelId ?? '', $thread);
        if (!$this->config->allowsTerminal($action->userId ?? '') || ($state['owner'] ?? '') !== $action->userId) {
            return ActionResponse::empty();
        }
        $this->enqueue(
            'terminal',
            '',
            $action->userId ?? '',
            $action->channelId ?? '',
            $action->threadTs ?? $action->messageTs,
            $delivery,
            (string) ($action->action['action_ts'] ?? ''),
            $jobs,
            substr($action->actionId ?? '', strlen('demo_terminal_')),
        );
        return ActionResponse::empty();
    }

    private function enqueue(
        string $mode,
        string $text,
        string $user,
        string $channel,
        ?string $thread,
        Delivery $delivery,
        string $identity,
        Jobs $jobs,
        string $operation = 'open',
        ?string $message = null,
    ): void {
        $team = $delivery->authorizationSource?->teamId ?? '';
        if ($team === '' || $channel === '' || $user === '' || strlen($text) > 8000) {
            throw new \InvalidArgumentException('The demo requires a workspace, channel, user, and bounded input.');
        }
        $source = $delivery->authorizationSource?->raw ?? [];
        $eventId = $source['event_id'] ?? $identity;
        if ($mode === 'ai') {
            $jobs->dispatch(
                new Answer($text, $thread),
                hash('sha256', json_encode([$team, $mode, $eventId, $text, $thread], JSON_THROW_ON_ERROR)),
            );
            return;
        }
        $job = [
            'mode' => $mode,
            'text' => $text,
            'team' => $team,
            'user' => $user,
            'channel' => $channel,
            'thread' => $thread,
            'operation' => $operation,
            'message' => $message,
        ];
        $this->config->queue()->enqueue($job, hash('sha256', json_encode([
            $team,
            $mode,
            $eventId,
            $job,
        ], JSON_THROW_ON_ERROR)));
    }
}
