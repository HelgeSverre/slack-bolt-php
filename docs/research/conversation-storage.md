# Conversation and thread storage

**Scope:** a source map and recommended PHP boundary for Bolt JS 5.1.0 conversation
state. It is separate from OAuth installation records and from Assistant thread context.

**Pinned source:** [Bolt JS 5.1.0 `conversation-store.ts`](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/conversation-store.ts),
[event helpers](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/helpers.ts#L39-L109), and
[App setup](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L441-L451).

## Bolt JS contract

`ConversationStore<State>` has `set(conversationId, value, expiresAt?)` and
`get(conversationId)`. `expiresAt` is an **absolute Unix timestamp in milliseconds**,
not a duration. The in-memory store deletes an expired entry and rejects only when
`Date.now() > expiresAt`; equality is still valid. A missing entry rejects with
`Conversation not found`; an expired entry rejects with `Conversation expired`.

`convoStore` defaults to `MemoryStore`; `convoStore: false` omits the entire global
middleware. Its memory is process-local and therefore unsuitable for more than one
application process. This default and the opt-out are covered by the upstream
[App tests](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/test/unit/App/basic.spec.ts#L221-L254).

The middleware first determines a bare `conversationId`. When one exists, it installs
`context.updateConversation(value, expiresAt)` before attempting `store.get()`. A
successful read becomes `context.conversation`; `updateConversation()` directly calls
`set()` and does **not** replace that already-loaded context snapshot. It then calls
`next()` in every case. Bolt catches every read error, suppresses it, and only logs
errors whose message differs from `Conversation not found`; it never catches a write
error. See [the middleware](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/conversation-store.ts#L60-L82)
and [its unit tests](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/test/unit/conversation-store.spec.ts#L48-L185).

The default global order is `ignoreSelf`, then conversation context. Thus a self event
does not load state. Listener middleware follows global middleware. The HTTP receiver's
`processBeforeResponse` setting changes acknowledgement timing, not this order or the
store contract. [Constructor order](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L441-L455)
and [ack behavior](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPResponseAck.ts#L96-L116)
make that distinction explicit.

**Confidence: 🟢** — direct pinned source and tests.

## Conversation and thread discovery

Bolt's `getTypeAndConversation()` is intentionally channel-oriented. It does not derive
a thread ID. For Events API payloads it takes `event.channel` (string or object ID), then
overwrites it with `event.channel_id`, then `event.item.channel`; the last defined value
wins. This covers reaction/star item channels as well as ordinary events. It uses
`channel_id` for slash commands, `channel.id` for actions/options/message shortcuts, and
no conversation for global shortcuts or views. Actions include `dialog_submission`; options
include `name` or `block_suggestion`. These exact branches are in
[helpers.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/helpers.ts#L39-L109).

Bolt does have a separate event-only thread helper. Its precedence is direct
`event.thread_ts`, `event.assistant_thread.thread_ts`, `event.message.thread_ts`, then
`event.previous_message.thread_ts`. The latter two are required for `message_changed` and
`message_deleted`. It intentionally does not turn a top-level event `ts` into a thread ID.
The unit cases cover app mentions, message subtypes, `link_shared`, assistant events, changed
and deleted messages [here](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/test/unit/helpers.spec.ts#L283-L334).

The PHP extractor should retain that parity table and add narrowly-evidenced interaction
sources instead of guessing:

| Delivery family | Channel candidate | Thread candidate | Rule |
| --- | --- | --- | --- |
| Event | Bolt's `channel`, `channel_id`, `item.channel` precedence | direct, assistant thread, changed-message, deleted-message precedence above | A plain message `ts` remains a channel-level delivery. |
| Slash command | `channel_id` | none | Direct-message commands still have a channel ID. |
| Block action / legacy interactive message | `channel.id`, then `container.channel_id` when `channel` is absent | `container.thread_ts`, then `message.thread_ts` if present | Slack documents a required `container` and optional `channel`/`message`; use the container as a fallback, not a replacement. |
| Options / block suggestion | `channel.id`, then `container.channel_id` when supplied | `container.thread_ts` when supplied | A modal suggestion can have neither. |
| Message shortcut | `channel.id` | `message.thread_ts` when present | A message timestamp alone must not be promoted to a thread root. |
| Global shortcut | none | none | Do not invent a conversation. |
| View submission / close / modal action | none by default | none by default | `view.id`, `root_view_id`, `private_metadata`, and `response_urls[*].channel_id` are not the source conversation. |

Slack's payload reference confirms that block actions carry a required `container` and optional
`channel`, `message`, and `view`, while view submissions may carry output `response_urls` with
their own destination channel ([block action reference](https://docs.slack.dev/reference/interaction-payloads/block_actions-payload/),
[view interaction reference](https://docs.slack.dev/reference/interaction-payloads/view-interactions-payload/)).
`private_metadata` is application-defined opaque data: never parse it automatically into a
storage key. A caller that deliberately encodes an address may pass it through an explicit,
application-owned resolver.

Message metadata may carry application state but is likewise not a conversation identifier.
Treat it as payload data; do not silently join state by metadata event type or payload. This
prevents schema collisions between independent features.

**Confidence: 🟢** for Bolt event/channel and event-thread behavior; **🟡** for the interaction
fallbacks, which are a deliberate PHP completion of payload fields Bolt currently ignores.

## Recommended PHP boundary

Do not expose a bare channel string to a shared store. Slack channel IDs can recur across
installation scope and a Slack Connect channel may be delivered through different workspace or
Grid authorizations. Resolve authorization first, then derive a tenant-safe immutable address:

```php
final readonly class ConversationAddress
{
    public function __construct(
        public InstallationScope $tenant,
        public string $channelId,
        public ?string $threadTs = null,
    ) {}
}

interface ConversationStore
{
    /** Returns null for an absent or expired value. */
    public function get(ConversationAddress $address): ?ConversationSnapshot;

    /** $expiresAt is absolute UTC time; equal-to-now remains valid. */
    public function put(ConversationAddress $address, mixed $value, ?DateTimeImmutable $expiresAt = null): ConversationSnapshot;

    public function forget(ConversationAddress $address): void;
}
```

`InstallationScope` must use the same canonical key as authorization: workspace team ID for a
workspace installation, enterprise ID without a contextual team ID for an organization
installation. A deployment-wide namespace (normally app ID) belongs outside that key when one
database serves multiple apps. This prevents both tenant leakage and accidental merging of a
workspace installation with a Grid install that sees the same Slack Connect channel.

Use channel-only addresses for parity. Let callers explicitly opt into
`threadTs` partitioning when they need state per root thread; never silently make all message
timestamps new thread keys. `ConversationSnapshot` should be immutable and include version and
expiry. `put()` returns the replacement snapshot so a handler can deliberately use fresh state;
the loaded delivery snapshot stays stable. This avoids Bolt's mutable/reference and stale-context
surprise while preserving an obvious `updateConversation()` convenience wrapper.

The global middleware should run after authorization and self filtering, load once, then inject a
read-only snapshot plus an explicit replace/update closure into the delivery. Missing and expired
records are normal absence. A malformed record, lock failure, or unavailable durable store is an
operational failure and must reach the normal error path; do not copy Bolt's catch-all cache-miss
behavior. Provide `conversationStore: false` to disable it. For Bolt compatibility, an omitted
store may select an in-process `MemoryConversationStore`, but production configuration should
require an explicit durable adapter and surface that choice in diagnostics.

## Implemented PHP slice

The current runtime implements this boundary as `ConversationKey`, `ConversationStore`,
`ConversationContext`, and `ThreadContext` in `Thunder\\Bolt\\Conversation`. A key contains a
conversation ID, its canonical tenant scope, and optionally a thread timestamp; `key()` is an
opaque SHA-256 value, so raw Slack IDs never become file names. A workspace installation is keyed
by team ID. An enterprise installation is keyed by enterprise ID with no contextual team ID, which
keeps Grid state shared across the contextual workspaces selected for the same installation.

```php
$app->event('app_mention', function (ConversationContext $conversation): void {
    $state = $conversation->value(['step' => 'start']);

    $conversation->update(
        fn (mixed $current): array => [...(is_array($current) ? $current : []), 'step' => 'waiting'],
        expiresAt: new DateTimeImmutable('+15 minutes'),
    );
});
```

`ConversationStore` exposes `get()`, `set()`, `delete()`, and an atomic `update()` callback.
The included memory, file, and SQLite stores accept portable JSON only. File updates hold a
per-key advisory lock; SQLite uses `BEGIN IMMEDIATE`. All use an absolute integer millisecond
expiry and retain state at the exact boundary. The durable adapters also reject corrupt records
rather than silently decoding them.

`ConversationContext` and `ThreadContext` are delivery-scoped facades. They expose
`isAvailable()`, `exists()`, `value()`, `replace()`, `update()`, and `delete()`. A global shortcut
or modal has unavailable contexts and cannot write an invented key. `replace()` and `update()`
refresh the PHP delivery snapshot after a successful write; this intentionally improves on Bolt
JS's stale `context.conversation` after `updateConversation()`.

There are two documented deviations to keep visible in compatibility work:

- `StoredContext` currently suppresses every load failure after debug logging, matching Bolt's
  continuation behavior. Writes still propagate. This is a deliberate parity choice, despite the
  stronger operational-error recommendation above.
- `DeliveryContext` treats the timestamp of a plain `message` or `app_mention` as its root thread
  key. Bolt's event helper only uses explicit `thread_ts` (or its nested lifecycle equivalents).
  This PHP completion gives every message delivery a stable thread context, but it is broader than
  Bolt's helper and must remain tested as a deviation.

Direct `App` construction defaults to `MemoryConversationStore`, as Bolt JS does. The Tempest
application initializer chooses the configured durable file, SQLite, memory, or disabled adapter;
production projects should choose a durable adapter explicitly.

## Assistant thread context is separate

`AssistantThreadContextStore` is not a general conversation store. Its `get(args)` and
`save(args)` receive full Assistant middleware arguments and return/save a small Assistant context
(`channel_id`, `team_id`, `enterprise_id`). The default implementation reads the first few thread
messages through `conversations.replies`, then writes context into the assistant bot's initial
message metadata with `chat.update` ([source](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/AssistantThreadContextStore.ts#L5-L89)).

Do not couple that API to this milestone. It belongs with complete Assistant lifecycle and
metadata behavior. The common `ConversationAddress` extractor may later be reused, but Assistant
storage must retain its distinct `get/save` contract and Slack message-metadata semantics.

## Compatibility tests

1. Memory expiration at `now < expiresAt`, `now === expiresAt`, and `now > expiresAt`; the last
   deletes the entry. Verify the public adapter's expiry precision against Bolt's milliseconds.
2. Default memory enablement, explicit custom store, and `false` disabling all reads/writes.
3. Self event is ignored before lookup; global state is loaded before listener middleware;
   acknowledgement mode does not change either fact.
4. Absent, expired, corrupt, and unavailable records proceed without loaded state after a debug
   log; writes and update failures reach the configured error path and are never swallowed.
5. Event channel precedence and all four thread locations, including `message_changed` and
   `message_deleted`; action/options container fallbacks; no fabricated modal/global-shortcut key.
6. The same channel ID under two workspace tenants, an enterprise installation, and a Slack
   Connect modal context cannot read one another's state. Thread and channel-only addresses do
   not collide.
