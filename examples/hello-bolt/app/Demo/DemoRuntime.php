<?php

declare(strict_types=1);

namespace App\Demo;

use Amp\DeferredCancellation;
use App\Demo\Ai\ResponsesAgent;
use App\Demo\Pet\PetStore;
use App\Demo\Pet\PetView;
use Tempest\Console\Console;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\Client;

use function Amp\async;
use function Amp\delay;

/** A deliberately local showcase worker, not the framework's future job system. */
final class DemoRuntime
{
    private readonly Queue $queue;
    private readonly ThreadState $threads;
    private readonly PetStore $pet;
    private readonly TerminalDemo $terminal;
    private readonly ResponsesAgent $agent;
    private readonly DeferredCancellation $cancellation;
    /** @var array<string, AiTask> */
    private array $tasks = [];

    public function __construct(
        DemoConfig $config,
        private readonly Client $client,
        private readonly Console $console,
    ) {
        $this->queue = $config->queue();
        $this->threads = new ThreadState($config->directory);
        $this->pet = new PetStore($config->directory . '/pet.json');
        $this->terminal = new TerminalDemo($config, $this->client, $this->threads, $this->console);
        $this->cancellation = new DeferredCancellation();
        $this->agent = new ResponsesAgent(
            new OpenAiHttp($this->cancellation->getCancellation()),
            $config->openAiKey,
            $config->openAiModel,
        );
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
        $this->console->info('Demo worker ready: pet, terminal, and AI. Press Ctrl+C to stop.');
        try {
            while (!$stop && ($shouldStop === null || !$shouldStop())) {
                foreach ($this->tasks() as $key => $task) {
                    if ($task->future->isComplete()) {
                        $task->future->await();
                        $this->removeTask($key);
                    }
                }
                $this->terminal->poll();
                $item = $this->queue->claim();
                if ($item !== null) {
                    $job = DemoJob::fromArray($item['job']);
                    $id = $item['id'];
                    if (($job->mode ?? '') === 'ai') {
                        $key = ThreadState::id($job->team, $job->channel, $job->thread ?? $id);
                        if (
                            array_any($this->tasks(), static fn(AiTask $task): bool => $task->conversationKey === $key)
                            || count($this->tasks()) >= 2
                        ) {
                            $this->queue->fail($id, 'AI conversation is busy.');
                            try {
                                $this->reply(
                                    $job,
                                    'Beep boop — still thinking. Please try again after the current answer.',
                                );
                            } catch (\Throwable) {
                                $this->console->info('Could not deliver the optional AI busy notice.');
                            }
                        } else {
                            $this->putTask($id, new AiTask(async(fn() => $this->execute($job, $id)), $key));
                        }
                    } else {
                        $this->execute($job, $id);
                    }
                }
                delay(0.1);
            }
        } finally {
            $this->cancellation->cancel();
            foreach ($this->tasks() as $task) {
                try {
                    $task->future->await();
                } catch (\Throwable) {
                    $this->console->error('An AI job stopped during worker shutdown.');
                }
            }
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
                'terminal' => $this->terminal->handle($job),
                'ai' => $this->ai($job, $id),
                default => throw new \InvalidArgumentException('Unknown demo job.'),
            };
            $this->queue->complete($id);
        } catch (\Throwable $exception) {
            $this->queue->fail($id, $exception::class);
            $this->console->error('Demo job failed: ' . $exception::class);
            try {
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

    private function ai(DemoJob $job, string $id): void
    {
        $text = trim($job->text);
        if ($text === '') {
            $text = 'Say hello, introduce yourself as an AI, and invite people to ask anything or help care for the shared pet.';
        }
        $result = $this->reply($job, '🤖 Beep boop — thinking. I can search the web and help care for our pet.');
        $ts = $result->get('ts');
        if (!is_string($ts) || $ts === '') {
            throw new \RuntimeException('Slack did not return the AI message timestamp.');
        }
        $thread = $job->thread ?? $ts;
        $state = $this->threads->get($job->team, $job->channel, $thread) ?? [];
        $conversationKey = ThreadState::id($job->team, $job->channel, $thread);
        $task = $this->task($id);
        if ($task instanceof AiTask) {
            $task->conversationKey = $conversationKey;
        }
        $this->threads->put($job->team, $job->channel, $thread, ['mode' => 'ai', 'history' => $state['history'] ?? []]);
        $history = $state['history'] ?? [];
        $answer = $this->agent->ask(
            $text,
            is_array($history) && array_is_list($history) ? $history : [],
            function (string $name, array $arguments) use ($job, $id): array {
                return match ($name) {
                    'pet_status' => $this->pet->snapshot($job->team),
                    'care_for_pet' => $this->careTool($job, $id, $arguments),
                    default => throw new \InvalidArgumentException('Unknown AI tool.'),
                };
            },
            function (string $progress) use ($job, $ts): void {
                try {
                    $this->client
                        ->chat()
                        ->update([
                            'channel' => $job->channel,
                            'ts' => $ts,
                            'text' => '🤖 Beep boop — searched the web. Putting the answer together …',
                        ]);
                } catch (\Throwable) {
                    $this->console->info('Skipped an optional AI progress update.');
                }
            },
        );
        $this->client
            ->chat()
            ->update([
                'channel' => $job->channel,
                'ts' => $ts,
                'text' => $answer->text,
                'unfurl_links' => false,
                'unfurl_media' => false,
            ]);
        if ($answer->succeeded()) {
            $this->threads->put($job->team, $job->channel, $thread, [
                'mode' => 'ai',
                'history' => $answer->history,
            ]);
        }
    }

    private function careTool(DemoJob $job, string $id, array $arguments): array
    {
        $action = $arguments['action'] ?? null;
        if (!is_string($action)) {
            throw new \InvalidArgumentException('A pet care tool requires an action.');
        }
        return $this->pet->care($job->team, $job->user, $action, $id . ':pet:' . $action);
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

    /** @return array<string, AiTask> */
    private function tasks(): array
    {
        $tasks = $this->tasks;

        return $tasks;
    }

    private function task(string $id): ?AiTask
    {
        return $this->tasks()[$id] ?? null;
    }

    private function putTask(string $id, AiTask $task): void
    {
        $tasks = $this->tasks();
        $tasks[$id] = $task;
        $this->tasks = $tasks;
    }

    private function removeTask(string $id): void
    {
        $tasks = $this->tasks();
        unset($tasks[$id]);
        $this->tasks = $tasks;
    }
}
