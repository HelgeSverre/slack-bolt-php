<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Demo\DemoConfig;
use App\Demo\DemoJob;
use App\Demo\Jobs\Answer;
use App\Demo\Jobs\ShowKitchenSink;
use App\Demo\Jobs\StreamKitchenSink;
use App\Demo\KitchenSinkView;
use Thunder\Bolt\Attributes\Action as ActionHandler;
use Thunder\Bolt\Attributes\SlashCommand as CommandHandler;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\SlashCommand;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\CommandResponse;
use Thunder\Bolt\Routing\Regex;

final readonly class KitchenSink
{
    public function __construct(
        private DemoConfig $config,
    ) {}

    #[CommandHandler('/kickensink')]
    public function command(SlashCommand $command, Jobs $jobs): CommandResponse
    {
        $key = $command->raw['trigger_id'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new \InvalidArgumentException('The showcase requires a Slack command delivery ID.');
        }
        $jobs->dispatch(new ShowKitchenSink(), key: 'kitchen-sink:' . $key);
        return CommandResponse::ephemeral('Queued the tour. It will appear here shortly.');
    }

    #[CommandHandler('/kitchensink')]
    public function correctlySpelledCommand(SlashCommand $command, Jobs $jobs): CommandResponse
    {
        return $this->command($command, $jobs);
    }

    #[ActionHandler(new Regex('/^kitchen_sink_(ai|pet|work|details|stream|stop)$/'))]
    public function action(Action $action, Delivery $delivery, Jobs $jobs): ActionResponse
    {
        $client = $delivery->client ?? throw new \LogicException('The showcase needs a Slack client.');
        $operation = substr($action->actionId ?? '', strlen('kitchen_sink_'));
        if ($operation === 'details') {
            $client
                ->views()
                ->open(['trigger_id' => $action->triggerId, 'view' => KitchenSinkView::details()->toArray()]);
            return ActionResponse::empty();
        }
        if ($operation === 'stop') {
            $jobs->cancelThread();
            // Also settle sessions whose work had already completed or never streamed.
            $client
                ->agents()
                ->sessions()
                ->setStatus([
                    'channel_id' => $action->channelId,
                    'thread_ts' => $action->threadTs ?? $action->messageTs,
                    'status' => 'active',
                ]);
            return ActionResponse::empty();
        }
        $key = $action->action['action_ts'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new \InvalidArgumentException('A showcase action requires its Slack timestamp.');
        }
        if ($operation === 'stream') {
            $jobs->dispatch(new StreamKitchenSink(), key: 'kitchen-sink:stream:' . $key);
        } elseif ($operation === 'ai') {
            $jobs->dispatch(
                new Answer(
                    text: 'Search the web: how big is the Sun? Explain briefly and cite your source.',
                    thread: $action->threadTs ?? $action->messageTs,
                ),
                key: 'kitchen-sink:ai:' . $key,
            );
        } else {
            $team = $delivery->authorization?->teamId ?? $delivery->authorizationSource?->teamId ?? '';
            if ($team === '' || $action->userId === null || $action->channelId === null) {
                throw new \InvalidArgumentException('This demo requires a workspace, channel and user.');
            }
            $job = new DemoJob(
                $operation === 'pet' ? 'pet' : 'work',
                $operation === 'pet' ? 'status' : 'Ship modern Slack agent sessions',
                $team,
                $action->userId,
                $action->channelId,
                $action->threadTs ?? $action->messageTs,
                'open',
            );
            $this->config->queue()->enqueue($job->toArray(), 'kitchen-sink:' . $operation . ':' . $key);
        }
        return ActionResponse::empty();
    }
}
