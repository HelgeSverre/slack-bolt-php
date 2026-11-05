<?php

declare(strict_types=1);

namespace App\Demo;

use App\Demo\Terminal\TerminalHub;
use Tempest\Console\Console;
use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\Client;

final class TerminalDemo
{
    private readonly TerminalHub $terminal;
    /** @var array<string, TerminalScreen> */
    private array $screens = [];
    /** @var array<string, \App\Demo\Terminal\TerminalSnapshot> */
    private array $screenUpdates = [];

    /** @param null|\Closure(DemoJob): Client $clientForJob */
    public function __construct(
        private readonly DemoConfig $config,
        private readonly Client $client,
        private readonly ThreadState $threads,
        private readonly Console $console,
        private readonly ?\Closure $clientForJob = null,
    ) {
        $workspace = $config->directory . '/terminal-workspace';
        if (!is_dir($workspace) && !mkdir($workspace, 0700, true) && !is_dir($workspace)) {
            throw new \RuntimeException('Unable to create the demo terminal directory.');
        }
        $this->terminal = new TerminalHub($workspace, $config->terminalUsers);
    }

    public function shutdown(): void
    {
        $this->terminal->shutdown();
        $this->poll();
    }

    public function handle(DemoJob $job): void
    {
        $thread = $job->thread;
        $sid = is_string($thread) ? ThreadState::id($job->team, $job->channel, $thread) : null;
        $operation = $job->operation;
        if ($operation === 'open' && ($sid === null || !isset($this->screens[$sid]))) {
            if (!$this->config->allowsTerminal($job->user)) {
                throw new \RuntimeException('Terminal access is not configured for this user.');
            }
            // The first message becomes the thread root for slash-command launches.
            $result = $this->reply($job, 'Opening a local shell for <@' . $job->user . '> …');
            $screenTs = $result->get('ts');
            if (!is_string($screenTs) || $screenTs === '') {
                throw new \RuntimeException('Slack did not return the terminal screen timestamp.');
            }
            $thread ??= $screenTs;
            $sid = ThreadState::id($job->team, $job->channel, $thread);
            $this->terminal->open($sid, $job->user);
            $this->screens[$sid] = new TerminalScreen($job->inThread($thread), $screenTs);
            $this->threads->put($job->team, $job->channel, $thread, [
                'mode' => 'terminal',
                'owner' => $job->user,
            ]);
            if ($job->text !== '') {
                $this->terminal->input($sid, $job->user, $job->text);
            }
            return;
        }
        if ($sid === null || !isset($this->screens[$sid])) {
            throw new \RuntimeException('This terminal session is no longer running.');
        }
        match ($operation) {
            'interrupt' => $this->terminal->interrupt($sid, $job->user),
            'close' => $this->terminal->close($sid, $job->user),
            default => $this->terminal->input($sid, $job->user, $job->text),
        };
    }

    public function poll(): void
    {
        foreach ($this->terminal->poll() as $id => $snapshot) {
            $this->screenUpdates[$id] = $snapshot;
        }
        foreach ($this->screenUpdates as $id => $snapshot) {
            $target = $this->screens[$id] ?? null;
            if ($target === null || $snapshot->status === 'running' && (microtime(true) - $target->updated) < 1.5) {
                continue;
            }
            $screen = mb_strcut($snapshot->output, -2400, null, 'UTF-8');
            $screen = mb_substr(str_replace(['&', '<', '>', '```'], ['&amp;', '&lt;', '&gt;', 'ˋˋˋ'], $screen), -2400);
            $message = Message::blocks(
                Section::markdown(
                    '*Slack terminal* · <@'
                    . $target->job->user
                    . '> · '
                    . $snapshot->status
                    . "\n```\n"
                    . ($screen === '' ? '(waiting for shell output)' : $screen)
                    . "\n```",
                ),
                Section::markdown(
                    'Runs a shell on the demo host. Reply in this thread to type. '
                    . 'Only the session owner can type. Updates are coalesced.',
                ),
                Actions::make(
                    Button::make('Ctrl+C', 'demo_terminal_interrupt'),
                    Button::make('Close', 'demo_terminal_close')->danger(),
                ),
            )->fallbackText('Slack terminal · ' . $snapshot->status);
            try {
                $this
                    ->clientFor($target->job)
                    ->chat()
                    ->update(['channel' => $target->job->channel, 'ts' => $target->message, ...$message->toArray()]);
                $this->screens[$id]->updated = microtime(true);
                unset($this->screenUpdates[$id]);
                if ($snapshot->status !== 'running') {
                    $this->threads->put(
                        $target->job->team,
                        $target->job->channel,
                        $target->job->thread ?? $target->message,
                        [
                            'mode' => 'closed',
                        ],
                    );
                    unset($this->screens[$id]);
                }
            } catch (\Throwable) {
                $this->screens[$id]->updated = microtime(true) + 5;
                $this->console->error('Terminal screen update failed; will retry the latest screen.');
            }
        }
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
        return $this->clientFor($job)->call('chat.postMessage', $body);
    }

    private function clientFor(DemoJob $job): Client
    {
        return $this->clientForJob === null ? $this->client : ($this->clientForJob)($job);
    }
}
