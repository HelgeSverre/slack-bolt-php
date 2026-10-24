<?php

declare(strict_types=1);

namespace App\Demo;

use App\Demo\Pet\PetHomeView;
use App\Demo\Pet\PetStore;
use App\Demo\Pet\PetView;
use App\Demo\Work\WorkObjectsDemo;
use Tempest\Console\Console;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\Client;

use function Amp\delay;

/** Local worker for pet, terminal, and work-item showcases. */
final class DemoRuntime
{
    private readonly Queue $queue;
    private readonly ThreadState $threads;
    private readonly PetStore $pet;
    private readonly TerminalDemo $terminal;

    public function __construct(
        private readonly DemoConfig $config,
        private readonly Client $client,
        private readonly Console $console,
    ) {
        $this->queue = $config->queue();
        $this->threads = new ThreadState($config->directory);
        $this->pet = new PetStore($config->directory . '/pet.json');
        $this->terminal = new TerminalDemo($config, $this->client, $this->threads, $this->console);
    }

    /** @param null|callable(): bool $shouldStop */
    public function run(?callable $shouldStop = null): void
    {
        if (!$this->queue->acquireWorker()) {
            throw new \RuntimeException('Another demo worker is already running for this application.');
        }
        $stop = false;
        /** @var array<int, callable|int> $handlers */
        $handlers = [];
        $asyncSignals = null;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
            $asyncSignals = pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                /** @var callable|int $handler PCNTL's stub is broader than its actual handler return. */
                $handler = pcntl_signal_get_handler($signal);
                $handlers[$signal] = $handler;
                pcntl_signal($signal, static function () use (&$stop): void {
                    $stop = true;
                });
            }
        }
        $this->console->info('Demo worker ready: pet, terminal, and work items. Run `bolt jobs:work` for AI.');
        try {
            while (!$stop && ($shouldStop === null || !$shouldStop())) {
                $this->terminal->poll();
                $item = $this->queue->claim();
                if ($item !== null) {
                    $job = DemoJob::fromArray($item['job']);
                    $id = $item['id'];
                    $this->execute($job, $id);
                }
                delay(0.1);
            }
        } finally {
            $this->terminal->shutdown();
            $this->queue->releaseWorker();
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($asyncSignals !== null) {
                pcntl_async_signals($asyncSignals);
            }
        }
    }

    private function execute(DemoJob $job, string $id): void
    {
        try {
            match ($job->mode) {
                'pet' => $this->pet($job, $id),
                'pet_home' => $this->petHome($job, $id),
                'work' => new WorkObjectsDemo($this->config, $this->client)->handle($job, $id),
                'assistant', 'ai' => throw new \LogicException(
                    'AI jobs moved to the durable named-job worker. Run `bolt jobs:work` for new requests.',
                ),
                'terminal' => $this->terminal->handle($job),
                default => throw new \InvalidArgumentException('Unknown demo job.'),
            };
            $this->queue->complete($id);
        } catch (\Throwable $exception) {
            $this->queue->fail($id, $exception::class);
            $this->console->error('Demo job failed: ' . $exception::class);
            if ($exception instanceof \Thunder\Bolt\WebApi\SlackApiException) {
                $code = preg_replace('/[^a-zA-Z0-9_]/', '', $exception->error);
                $this->console->error('Slack API error: ' . substr($code ?? '', 0, 100));
            }
            try {
                if ($job->mode === 'work' && $job->channel === '') {
                    return;
                }
                if ($job->mode === 'pet_home') {
                    $this->client
                        ->views()
                        ->publish(['user_id' => $job->user, 'view' => PetHomeView::unavailable()->toArray()]);
                    return;
                }
                $this->reply(
                    $job,
                    'The demo could not finish that action. Check the local worker. '
                    . 'For an expired terminal, open a fresh one with `/bolt terminal`.',
                );
            } catch (\Throwable) {
                $this->console->error('The demo failure could not be delivered to Slack.');
            }
        }
    }

    private function pet(DemoJob $job, string $id): void
    {
        $action = strtolower($job->text);
        $state = in_array($action, ['feed', 'play', 'clean', 'rest'], true)
            ? $this->pet->care($job->team, $job->user, $action, $id)
            : $this->pet->snapshot($job->team);
        $message = PetView::message($state);
        if (is_string($job->message ?? null)) {
            $this->client->chat()->update(['channel' => $job->channel, 'ts' => $job->message, ...$message->toArray()]);
        } else {
            $this->reply($job, $message);
        }
    }

    private function petHome(DemoJob $job, string $id): void
    {
        $state = in_array($job->text, ['feed', 'play', 'clean', 'rest'], true)
            ? $this->pet->care($job->team, $job->user, $job->text, $id)
            : $this->pet->snapshot($job->team);
        $this->client
            ->views()
            ->publish([
                'user_id' => $job->user,
                'view' => PetHomeView::make($state, $job->text)->toArray(),
            ]);
    }

    private function reply(DemoJob $job, string|Message $message): \Thunder\Bolt\WebApi\Response
    {
        $body = [
            'channel' => $job->channel,
            ...($message instanceof Message ? $message->toArray() : ['text' => $message]),
        ];
        if (is_string($job->thread ?? null) && $job->thread !== '') {
            $body['thread_ts'] = $job->thread;
        }
        return $this->client->call('chat.postMessage', $body);
    }
}
