<?php

declare(strict_types=1);

namespace App\Demo\Ai;

use Thunder\Bolt\WebApi\Client;

/** One Slack stream per answer; a failed delivery never replays model tools. */
final class SlackAnswerStream
{
    private ?string $timestamp = null;
    private bool $failed = false;
    private string $pendingText = '';
    private int $textBytes = 0;
    private float $lastFlush = 0;
    /** @var array<string, array> */
    private array $tasks = [];
    /** @var list<array> */
    private array $pendingTasks = [];

    public function __construct(
        private readonly Client $client,
        private readonly string $channel,
        private readonly string $thread,
        private readonly string $user,
        private readonly string $team,
    ) {}

    public function accept(AgentEvent $event): void
    {
        if ($this->failed) {
            return;
        }
        if ($event->type === 'text') {
            $text = mb_strcut($event->text, 0, max(0, 12_000 - $this->textBytes));
            $this->textBytes += strlen($text);
            // Escape each fragment, including partial Slack mention syntax.
            $this->pendingText .= str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        } elseif ($event->type === 'task') {
            $task = ['type' => 'task_update', 'id' => $event->id, 'title' => $event->title, 'status' => $event->status];
            $this->tasks[$event->id] = $task;
            $this->pendingTasks[] = $task;
        }
        if (
            $event->type === 'task'
            || (microtime(true) - $this->lastFlush) >= 1.5
            || strlen($this->pendingText) >= 2_000
        ) {
            $this->flush();
        }
    }

    public function finish(AgentResult $answer): void
    {
        $this->pendingTasks = [...$this->pendingTasks, ...$this->unfinishedTasks()];
        if (!$answer->succeeded() || $this->textBytes === 0) {
            $this->pendingText .=
                ($this->textBytes > 0 ? "\n\n" : '')
                . str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $answer->text);
        }
        if (!$this->failed) {
            try {
                if ($this->timestamp === null) {
                    $this->flush();
                }
                if (!$this->failed && $this->timestamp !== null) {
                    $this->client
                        ->chat()
                        ->stopStream([
                            'channel' => $this->channel,
                            'ts' => $this->timestamp,
                            ...(($chunks = $this->chunks()) !== [] ? ['chunks' => $chunks] : []),
                            ...($this->textBytes > 0 ? $this->sources($answer) : []),
                        ]);
                    return;
                }
            } catch (\Throwable) {
                $this->failed = true;
            }
        }
        if ($this->timestamp !== null) {
            try {
                $this->client
                    ->chat()
                    ->stopStream([
                        'channel' => $this->channel,
                        'ts' => $this->timestamp,
                        'chunks' => [
                            ...$this->unfinishedTasks(),
                            [
                                'type' => 'markdown_text',
                                'text' => "\n\nLive delivery interrupted. The complete response follows.",
                            ],
                        ],
                    ]);
            } catch (\Throwable) {
                $this->failed = true;
            }
        }
        $this->client
            ->chat()
            ->postMessage([
                'channel' => $this->channel,
                'thread_ts' => $this->thread,
                'text' => $answer->text,
                'unfurl_links' => false,
                'unfurl_media' => false,
            ]);
    }

    private function flush(): void
    {
        $chunks = $this->chunks();
        if ($chunks === []) {
            return;
        }
        try {
            if ($this->timestamp === null) {
                $response = $this->client
                    ->chat()
                    ->startStream([
                        'channel' => $this->channel,
                        'thread_ts' => $this->thread,
                        'recipient_user_id' => $this->user,
                        'recipient_team_id' => $this->team,
                        'task_display_mode' => 'timeline',
                        'chunks' => $chunks,
                    ]);
                $timestamp = $response->get('ts');
                if (!is_string($timestamp) || $timestamp === '') {
                    throw new \RuntimeException('Slack did not return a stream timestamp.');
                }
                $this->timestamp = $timestamp;
            } else {
                $this->client
                    ->chat()
                    ->appendStream(['channel' => $this->channel, 'ts' => $this->timestamp, 'chunks' => $chunks]);
            }
            $this->lastFlush = microtime(true);
        } catch (\Throwable) {
            $this->failed = true;
        }
    }

    /** @return list<array> */
    private function chunks(): array
    {
        $chunks = [];
        if ($this->pendingText !== '') {
            $chunks[] = ['type' => 'markdown_text', 'text' => $this->pendingText];
        }
        $chunks = [...$chunks, ...$this->pendingTasks];
        $this->pendingTasks = [];
        $this->pendingText = '';
        return $chunks;
    }

    private function sources(AgentResult $answer): array
    {
        $links = [];
        foreach ($answer->citations as $citation) {
            $url = $citation['url'] ?? null;
            if (!is_string($url) || !preg_match('~^https?://[^\s<>|]+$~i', $url)) {
                continue;
            }
            $title = is_string($citation['title'] ?? null) ? $citation['title'] : $url;
            $title = str_replace(['&', '<', '>', '|'], ['&amp;', '(', ')', '/'], $title);
            $link = '<' . $url . '|' . $title . '>';
            if (strlen('Sources: ' . implode(' · ', [...$links, $link])) > 2_900) {
                continue;
            }
            $links[$url] = $link;
        }
        if ($links === []) {
            return [];
        }
        $text = 'Sources: ' . implode(' · ', $links);
        return ['blocks' => [['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $text]]]]];
    }

    /** @return list<array> */
    private function unfinishedTasks(): array
    {
        $tasks = [];
        foreach ($this->tasks as $task) {
            if ($task['status'] !== 'in_progress') {
                continue;
            }
            $tasks[] = [...$task, 'status' => 'error'];
        }
        return $tasks;
    }
}
