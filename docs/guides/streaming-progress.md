# Streaming text and tool progress

`Thread::sayStream()` creates one destination-bound stream. It starts on the first append, retains Slack's returned timestamp and closes explicitly. Typed chunks live under `Thunder\Bolt\Assistant\Streaming`; raw arrays remain supported.

```php
use Thunder\Bolt\Assistant\Streaming\{MarkdownText, PlanUpdate, TaskStatus, TaskUpdate};
use Thunder\Bolt\Assistant\Thread;
use Thunder\Bolt\BlockKit\URLSourceElement;

function answer(Thread $thread): void
{
    $stream = $thread->sayStream(['task_display_mode' => 'plan']);
    $task = TaskUpdate::make('reference', 'Read Slack reference');

    $stream->chunks([
        PlanUpdate::make('Check the API'),
        $task->details('Reading the streaming method.'),
        MarkdownText::make("Checking the reference.\n\n"),
    ]);

    // Run application work here; keep slow work in a durable job.
    $stream->chunks([
        $task->status(TaskStatus::Complete)
            ->output('Found the supported chunk types.')
            ->sources(URLSourceElement::make('https://docs.slack.dev/reference/methods/chat.startStream/', 'Slack reference')),
    ]);
    $stream->append('Text, tasks, plans and blocks can share one stream.')->stop();
}
```

Builders are immutable. Keep the returned value when changing status/details/output/sources. Reuse a task ID to update its card; the stream does not impose an application task-state machine. `TaskUpdate::make()` defaults to `TaskStatus::InProgress` and also accepts a validated wire-status string for adapting provider events.

| Value | Purpose |
| --- | --- |
| `MarkdownText::make($text)` | A text fragment; `append($text)` is shorthand |
| `TaskUpdate::make($id, $title, $status)` | Task identity and progress, with fluent details/output/sources |
| `TaskStatus` | `Pending`, `InProgress`, `Complete`, `Error` |
| `PlanUpdate::make($title)` | Rename the plan |
| `Blocks::make(...$blocks)` | Stream existing Block Kit builders |

```php
use Thunder\Bolt\Assistant\Streaming\Blocks;
use Thunder\Bolt\BlockKit\Section;

$stream->chunks([
    Blocks::make(Section::text('Reference checked')),
    ['type' => 'task_update', 'id' => 'render', 'title' => 'Render answer', 'status' => 'complete', 'hide_title' => true],
]);
```

Arrays are the escape hatch for new fields and chunk types. Each typed value also exposes `toArray()` and JSON serialization for lower-level `Client::chat()` calls. A streaming task's details/output are strings; a Block Kit `TaskCard` uses rich-text values. They are different wire formats.

## Limits and failures

`MessageStream` checks 12,000 accumulated markdown characters and 50 distinct task IDs across typed chunks, recognizable raw chunks and `append()` calls. Updates to an existing task do not consume another slot. Text counts characters, not UTF-8 bytes. A rejected batch makes no API call and does not advance counters; the stream remains usable. Direct low-level API calls bypass this accounting.

Typed task details/output support at most 12,000 characters each, and sources at most 50. `Blocks::make()` accepts 1–50 blocks; completion accepts at most 50 final blocks. Slack also applies a whole-stream block limit with replacement semantics; the helper leaves that accounting and API warnings to Slack.

Slack offers `timeline` and `plan` display modes. Pending tasks are hidden in timeline mode. Streams expire ten minutes after creation; appends do not extend this window. Choose ordinary messages for longer-running work. See [Slack's streaming method and limits](https://docs.slack.dev/reference/methods/chat.startStream/).

Call `stop()` to finalize and leave the session active. Use `finish(AgentSessionStatus::Suspended)` to request input instead. Mark unfinished tasks `Error` explicitly when work fails or is cancelled; closing a stream does not execute tools, cancel jobs or repair task statuses. See [durable operations](durable-operations.md) for cancellation and recovery.

After a failed Slack request or successful completion, a stream instance cannot be reused. Streaming writes may have reached Slack even when their response is lost; replaying model tools or appends can duplicate effects. Application-level fallback and cancellation stay explicit. The AI demo retains its batching and final-message fallback while using typed task values.

The [4 November modern-block acceptance](../research/modern-blocks-live-validation.md) found an unresolved `internal_error` when streaming a `Layout` containing a `Container`, including final blocks passed to `chat.stopStream`. The same composition rendered in ordinary messages and Home. Simple streamed layouts and the reverse composition (container → layout → section) succeeded. Do not assume serialization or ordinary-message acceptance establishes streaming compatibility, and do not replay a failed streaming write automatically.

## Testing

The [public testing toolkit](testing-toolkit.md) supplies minimal successful responses for `chat.startStream`, `chat.appendStream` and `chat.stopStream`; start returns a synthetic timestamp. It captures wire arguments without simulating Slack's full streaming protocol. Assert the recorded calls with `assertApiCalled()` and a `SlackCall` predicate, or stub failures with `respondsWith()`.

`assertNoMessagesSent()` includes streaming writes. `assertMessageSent()` and `assertThreadReply()` still target ordinary `chat.postMessage`; use API-call assertions for streamed text and task state.
