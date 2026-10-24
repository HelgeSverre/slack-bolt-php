# Laravel AI interop research

Checked 24 October 2026 against Laravel AI `1.x`, its current [official documentation](https://laravel.com/framework/docs/master/ai-sdk), [package manifest](https://github.com/laravel/ai/blob/1.x/composer.json), and source contracts. This is input to Thunder Bolt's Assistant runtime; it is not an implementation or an endorsement of adding Laravel to the core package.

## What Laravel AI provides

### Agent contract — 🟢

`Laravel\Ai\Contracts\Agent` has `prompt()`, `stream()`, `queue()`, `broadcast()` and `broadcastNow()`. Agents expose instructions; conversational agents expose a replayable iterable of typed messages; tool-capable agents expose typed tools. The `Promptable` trait creates a run invocation ID, resolves tools/providers, supports model failover, and accepts a string, message, or tool-approval decision. Source: [Agent.php](https://github.com/laravel/ai/blob/1.x/src/Contracts/Agent.php), [Conversational.php](https://github.com/laravel/ai/blob/1.x/src/Contracts/Conversational.php), [Promptable.php](https://github.com/laravel/ai/blob/1.x/src/Promptable.php).

### Streaming — 🟢

`stream()` returns an iterable `StreamableAgentResponse`. It yields typed stream events such as text deltas, tool calls/results, reasoning and an end event; after iteration it aggregates text, citations and usage. It supports success/failure callbacks and can expose SSE, Vercel's data protocol, or an agent-interaction protocol. Source: [streaming docs](https://laravel.com/framework/docs/master/ai-sdk#streaming), [StreamableAgentResponse.php](https://github.com/laravel/ai/blob/1.x/src/Responses/StreamableAgentResponse.php).

### Conversations — 🟢

Laravel AI can associate a conversation UUID and a participant object with a response. Its built-in persistence relationship is an Eloquent morph-many relation, so it belongs to an installed Laravel application and database, not a portable process-local primitive. Source: [HasConversations.php](https://github.com/laravel/ai/blob/1.x/src/Concerns/HasConversations.php), [conversation context docs](https://laravel.com/framework/docs/master/ai-sdk#conversation-context).

### Dependencies — 🟢

The current package requires Laravel Illuminate Console, Container, Contracts, Database, Filesystem, Json Schema and Support `^12|^13`, alongside Laravel Prompts and related packages. It auto-registers an `AiServiceProvider`. It is therefore a real Laravel integration, not a lightweight framework-neutral PSR package. Source: [composer.json](https://github.com/laravel/ai/blob/1.x/composer.json).

## Recommended Thunder Bolt integration

Keep `thunder/bolt` framework-neutral. Its Assistant module should own Slack routing, authorization, lifecycle metadata, status/title/prompt calls, and assistant-thread context. It should not depend on `laravel/ai` or assume Eloquent.

Offer an optional package later, e.g. `thunder/bolt-laravel-ai`, guarded by `class_exists(Laravel\Ai\Contracts\Agent::class)`. Its small adapter can accept an `Agent`, call `stream($input)`, translate only useful stream events into Slack message streaming/status updates, and persist Laravel's conversation UUID in the Assistant thread context. Application code remains responsible for mapping Slack users to Laravel conversation participants and for selecting model/provider/tool policy.

Suggested seam:

```php
interface AssistantRun
{
    /** @return iterable<AssistantStreamEvent> */
    public function stream(AssistantInput $input): iterable;
}
```

The core runtime depends on this neutral seam only. A Laravel adapter maps `TextDelta`, tool lifecycle and end/error events to its own small values. It should not expose Laravel's HTTP response protocols: Slack has its own streaming APIs, and putting Vercel/SSE wire formats into the Assistant handler would leak an unrelated transport concern.

## Useful ideas to adopt

- Typed stream events and terminal aggregation make tool progress and message streaming testable.
- A stable invocation/run ID lets logs, status, cancellation, and provider work correlate without becoming the storage key.
- Carrying an opaque provider conversation ID in durable thread context permits continuation without making it the identity of the Slack thread.
- Provider/model/tool selection stays application policy. It is not Assistant runtime configuration.

## Avoid

- Do not add Laravel AI as a direct dependency or persist Slack state through Eloquent by default.
- Do not promise source-compatible `Agent` injection: Laravel's interface requires a Laravel container, configuration, providers, queues and database assumptions.
- Do not adopt Vercel's data-stream protocol for Slack. It is useful only inside the optional adapter's event translation, if at all.

## Open questions

- Laravel's current conversation API is documented around Eloquent participants; the optional adapter needs an example application and integration test before claiming durable Laravel conversation continuation.
- Tool approval and queued/broadcast behavior are provider/application workflows. They should remain outside the first Assistant runtime milestone.
