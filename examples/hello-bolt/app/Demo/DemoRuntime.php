<?php

declare(strict_types=1);

namespace App\Demo;

use Amp\DeferredCancellation;
use App\Demo\Ai\ResponsesAgent;
use App\Demo\Ai\SlackAnswerStream;
use App\Demo\Pet\PetHomeView;
use App\Demo\Pet\PetStore;
use App\Demo\Pet\PetView;
use App\Demo\Work\WorkObjectsDemo;
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
        private readonly DemoConfig $config,
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
        $this->console->info('Demo worker ready: pet, terminal, AI, work items, and assistants. Press Ctrl+C to stop.');
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
                    if ($job->mode === 'ai' || $job->mode === 'assistant' && $job->operation === 'message') {
                        $key = ThreadState::id($job->team, $job->channel, $job->thread ?? $id);
                        if (
                            array_any($this->tasks(), static fn(AiTask $task): bool => $task->conversationKey === $key)
                            || count($this->tasks()) >= 2
                        ) {
                            $this->queue->fail($id, 'AI conversation is busy.');
                            try {
                                $this->reply($job, 'A response is still in progress. Try again when it finishes.');
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
                'pet_home' => $this->petHome($job, $id),
                'work' => new WorkObjectsDemo($this->config, $this->client)->handle($job, $id),
                'assistant' => $this->assistant($job, $id),
                'terminal' => $this->terminal->handle($job),
                'ai' => $this->ai($job, $id),
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
                if ($job->mode === 'assistant') {
                    try {
                        new AssistantDemo($this->config)
                            ->threadFor($job, $this->client)
                            ->clearStatus();
                    } catch (\Throwable) {
                        $this->console->info('Could not clear the optional assistant status.');
                    }
                    return;
                }
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

    private function ai(DemoJob $job, string $id): void
    {
        $text = trim($job->text);
        if ($text === '') {
            $text = 'Say hello briefly and invite questions or help caring for the shared pet.';
        }
        $thread = $job->thread;
        if ($thread === null || $thread === '') {
            $result = $this->reply($job, 'Ask here. I can search the web and care for our shared pet.');
            $timestamp = $result->get('ts');
            if (!is_string($timestamp) || $timestamp === '') {
                throw new \RuntimeException('Slack did not return the AI thread timestamp.');
            }
            $thread = $timestamp;
        }
        $state = $this->threads->get($job->team, $job->channel, $thread) ?? [];
        $conversationKey = ThreadState::id($job->team, $job->channel, $thread);
        $task = $this->task($id);
        if ($task instanceof AiTask) {
            $task->conversationKey = $conversationKey;
        }
        $this->threads->put($job->team, $job->channel, $thread, ['mode' => 'ai', 'history' => $state['history'] ?? []]);
        $history = $state['history'] ?? [];
        $stream = new SlackAnswerStream($this->client, $job->channel, $thread, $job->user, $job->team);
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
            onEvent: $stream->accept(...),
        );
        $stream->finish($answer);
        if ($answer->succeeded()) {
            $this->threads->put($job->team, $job->channel, $thread, [
                'mode' => 'ai',
                'history' => $answer->history,
            ]);
        }
    }

    private function assistant(DemoJob $job, string $id): void
    {
        $assistant = new AssistantDemo($this->config);
        if ($job->operation === 'started') {
            $assistant->start($job, $this->client);

            return;
        }
        if ($job->operation !== 'message') {
            throw new \InvalidArgumentException('Unknown assistant demo operation.');
        }
        $thread = $assistant->beginAnswer($job, $this->client);
        try {
            $this->ai($job, $id);
        } finally {
            $thread->clearStatus();
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
