# Bolt JS assistant contract

Checked 24 October 2026 against pinned Bolt JS `ffbe0b6…`, not a current Slack certification.

Slack's current documentation steers new agent experiences toward `agents.sessions.*`. This milestone intentionally preserves Bolt JS assistant-thread parity; it should remain a thin Slack runtime, not an AI provider abstraction, so a future session transport can reuse the same thread and context values.

## Contract observed

`App.assistant(new Assistant(config))` installs one global middleware. It consumes, rather than forwards, the following matching deliveries:

| Incoming delivery | Bolt JS handler | Required shape |
| --- | --- | --- |
| `assistant_thread_started` | `threadStarted` | `assistant_thread.channel_id`, `assistant_thread.thread_ts`, optional `assistant_thread.context` |
| `assistant_thread_context_changed` | configured `threadContextChanged`, otherwise store `save` | same assistant-thread fields |
| `message` | `userMessage` | a thread reply in an IM, with no subtype or `file_share` |

`threadStarted` and `userMessage` are required. Each may be one callback or an ordered middleware list. The context-changed handler is optional; its default is to persist the event's context. Malformed assistant configuration fails before registration. Missing a channel or root timestamp at execution time throws a dedicated error.

Sources: pinned [`Assistant.ts`](../../work/reference-sources/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/Assistant.ts), [`App.ts`](../../work/reference-sources/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), and unit tests. Confidence: high.

## Utility behavior

Every handler receives helpers scoped to the resolved `(channel_id, thread_ts)`:

- `say()` posts into the assistant thread. It merges the incoming `assistant_thread.context`, or store context when absent, into message metadata under `assistant_thread_context`.
- `sayStream()` streams within the same thread.
- `setStatus(string|options)` supplies the channel/thread automatically; options include Slack's icons and loading messages.
- `setTitle(string)` does the same for `assistant.threads.setTitle`.
- `setSuggestedPrompts({ prompts, title? })` supplies the channel/thread for `assistant.threads.setSuggestedPrompts`; each prompt is `{title, message}`.
- `getThreadContext()` and `saveThreadContext()` delegate to the configured store.

Sources: [`Assistant.ts` utilities](../../work/reference-sources/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/Assistant.ts), [`create-set-status.ts`](../../work/reference-sources/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-set-status.ts), and generated PHP request models. Confidence: high.

## Context-store parity and limits

Bolt's `AssistantThreadContextStore` has only `get(args)` and `save(args)`. Its default implementation asks `conversations.replies` for thread messages with metadata, finds the first bot-authored message, and writes `assistant_thread_context` metadata back with `chat.update`. It also caches the most recently saved context **on the store instance**, which is not tenant or thread keyed. That cache is convenient in a single worker but unsafe as a model for concurrent or long-lived multi-thread PHP workers.

Source: [`AssistantThreadContextStore.ts`](../../work/reference-sources/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/bolt-js-ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/AssistantThreadContextStore.ts) and its unit tests. Confidence: high.

## Recommended Thunder Bolt seam

Use a deep, delivery-scoped `AssistantThread` value rather than copying Bolt's middleware bag. It should take a trusted delivery and expose:

```php
interface AssistantThread
{
    public function context(): array;
    public function saveContext(array $context): void;
    public function reply(string|Message $message): Response;
    public function status(string|AssistantStatus $status): Response;
    public function title(string $title): Response;
    public function prompts(AssistantPrompt ...$prompts): Response;
}
```

`AssistantThread` resolves and validates the assistant root once, hides Web API field names, and requires a real channel/root timestamp. `AssistantContextStore` should use a small `load(AssistantThreadKey): array` / `save(AssistantThreadKey, array): void` interface. Adapt the existing `ConversationStore` internally with the existing tenant-aware `ConversationKey`, rather than reproducing Slack metadata as the primary store. A metadata adapter remains useful for strict Bolt compatibility and cross-process recovery, but must key every cache entry by tenant + channel + root timestamp.

This has better locality than injecting six helper closures into every handler, and it fits Thunder Bolt's existing typed delivery injection: extend `Delivery`, let `HandlerInvoker` recognize `AssistantThread`, and create it after trusted authorization in `App::dispatch()`. Keep generic `#[Event('assistant_thread_started')]` and `#[Event('assistant_thread_context_changed')]` listeners available. Add an opt-in `App::assistant(AssistantConfig)` only if it performs the message filtering and default context saving; do not make it an AI provider abstraction.

## Correctness tests to carry into the implementation

1. Only IM thread user messages route to the assistant handler; bot/system messages, channel messages, root messages and unrelated events do not.
2. Both lifecycle events accept channel/root fields from `assistant_thread`; generic `Event` remains usable for forwards compatibility.
3. Every helper sends the correct root `thread_ts`, including a message where `ts` differs from root time.
4. Context partitions by team/enterprise + channel + root timestamp, survives a fresh store/worker, and never leaks to another thread.
5. Context-change persistence is atomic and validates serializable values; status/title/prompts reject blank or oversized values before transport.
6. Metadata compatibility reads and updates only the initial eligible bot-authored thread message, and does not overwrite unrelated metadata fields.
7. Handler and lifecycle failures follow the existing App error/acknowledgement behavior.

## Existing Thunder Bolt fit

The existing `DeliveryContext` already calculates tenant-aware conversation and thread identities, and `ThreadContext` already supplies durable atomic replacement/update over memory, file and SQLite adapters. `HandlerInvoker` has one central typed-injection map, while `App::dispatch()` is the trusted point where authorization, `Client`, `Reply`, and contexts become per-delivery values. These are the intended seams; an assistant runtime should reuse them instead of maintaining a competing context store.

Sources: [`DeliveryContext`](../../src/Conversation/DeliveryContext.php), [`ConversationKey`](../../src/Conversation/ConversationKey.php), [`StoredContext`](../../src/Conversation/StoredContext.php), [`Delivery`](../../src/Invocation/Delivery.php), [`HandlerInvoker`](../../src/Invocation/HandlerInvoker.php), [`App`](../../src/App.php). Confidence: high.
