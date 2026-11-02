# Conversation and thread storage

Conversation state belongs to a Slack channel within an installation. Thread state belongs to one root message in that channel. Both are available to handlers and middleware after authorization and self-message filtering.

```php
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\ThreadContext;
use Thunder\Bolt\Payloads\Events\AppMention;

final readonly class RememberMention
{
    #[Event('app_mention')]
    public function __invoke(
        AppMention $event,
        ConversationContext $conversation,
        ThreadContext $thread,
    ): void {
        $conversation->update(static fn (mixed $state): array => [
            'mentions' => ($state['mentions'] ?? 0) + 1,
        ]);

        if ($thread->isAvailable()) {
            $thread->replace(
                ['last_user_id' => $event->userId],
                expiresAt: new DateTimeImmutable('+1 day'),
            );
        }
    }
}
```

Request delivery context as method parameters. Application services and the `ConversationStore` itself can use constructor injection. Middleware reads the same capabilities through `$delivery->conversation` and `$delivery->thread`.

## Read, replace, update, delete

| Method | Behavior |
| --- | --- |
| `isAvailable()` | Storage is enabled and the delivery identifies this channel/thread |
| `exists()` | The delivery snapshot loaded or explicitly stored a value |
| `value($default = null)` | Read the snapshot, using the default only when no stored value exists |
| `replace($value, $expiresAt = null)` | Persist a complete replacement and update this delivery's snapshot |
| `update($callback, $expiresAt = null)` | Atomically transform the latest stored value and update the snapshot |
| `delete()` | Delete this record and clear the snapshot |

`null` is a valid stored value: `exists()` is true and `value('fallback')` returns null. Missing or expired state starts without a value. An unavailable context returns its read default; attempting a write throws `MissingConversationContext`.

`replace()` and `update()` return the context for chaining and persist immediately. They replace the entire value rather than merging arrays. Keep update callbacks short and pure: return the new value without calling other store/context operations from inside the callback. File updates hold a lock; SQLite updates hold a write transaction. A callback failure preserves the prior live value.

One snapshot is loaded before global middleware. Matching listeners share that delivery's context, so an explicit write is visible to later listeners. A different delivery receives a fresh snapshot. Use `update()` for counters or other transformations that must see concurrent changes; reading `value()` and then calling `replace()` is not an atomic read/modify/write operation.

Channel and thread writes are independent operations. A later handler error does not roll back earlier successful writes, and Slack retries do not deduplicate application updates automatically. Use event IDs/idempotency handling where your workflow needs it.

## Choose an adapter

Generated Tempest applications default to durable files outside `public/`:

```dotenv
SLACK_CONTEXT_STORE=file
SLACK_CONTEXT_STORE_PATH=
```

| Driver | Default location | Lifetime |
| --- | --- | --- |
| `file` | `.thunder-bolt/conversations/` | Survives PHP requests and process restarts |
| `sqlite` | `.thunder-bolt/conversations.sqlite` | Survives requests and restarts; requires `pdo_sqlite` |
| `memory` | In the current store object | Useful for tests or a single long-lived application instance |
| `disabled` | None | Both context capabilities are unavailable |

`SLACK_CONTEXT_STORE_PATH` selects a file-store directory or SQLite database filename. The default file records and SQLite bootstrap use private file permissions. File records use hashed keys and atomic replacement. Keep custom locations outside the public document root and on persistent storage.

Memory is not shared between workers. In a normal PHP HTTP deployment, a fresh application is built per request, so memory state does not survive those requests. File/SQLite adapters coordinate processes sharing their storage. Multiple application hosts with independent disks need a shared adapter implementing the store contract.

`php bolt doctor --format=json` reports `contextStore`; selecting SQLite without its extension is a local requirements failure. It does not contact Slack or prove that a configured backend is reachable.

PHP configuration can return a `BoltConfig` from `config/bolt.config.php`:

```php
return new BoltConfig(
    token: $token,
    signingSecret: $signingSecret,
    conversationStoreDriver: 'sqlite',
    conversationStorePath: '/srv/my-bot/context.sqlite',
);
```

For embedded dispatchers, pass `conversationStore: $store` to `App`. A directly constructed `App` defaults to memory, matching Bolt JS; `conversationStore: false` disables it. The Tempest initializer selects the configured durable adapter and honors an explicitly bound `ConversationStore` when enabled.

## Keys, tenancy, and thread roots

Storage keys follow the authorized installation: workspace installs use the team ID; organization installs use the enterprise ID. Contextual workspace IDs do not split an organization installation's state. Channel and root timestamp then distinguish records. Two installations never share state merely because an incoming channel ID matches. A static-token delivery without a team ID uses the store's single-application fallback scope.

`$conversation->key` and `$thread->key` expose the immutable `ConversationKey`. Slack timestamps remain strings, preserving their precision.

| Delivery | Conversation | Thread |
| --- | --- | --- |
| Message or mention | Channel | `thread_ts`, otherwise the message's `ts` |
| Changed/replied message | Channel | Nested message's root timestamp |
| Deleted message | Channel | Root from `previous_message`, when supplied |
| Message action/shortcut | Source channel | Message root, falling back to its known message/container timestamp |
| Reaction | Item channel | Only an explicit `thread_ts`; the reacted message might itself be a reply |
| Slash command/options | Source channel, when supplied | Unavailable without a known root |
| Global shortcut or modal callback | Unavailable | Unavailable |

The framework does not infer channel identity from modal `private_metadata`, response URLs, or an incidental container. Global shortcut/view callbacks remain unavailable even if stray top-level channel fields appear. If your modal workflow deliberately carries validated channel/thread information, access the store explicitly using that application's known identity.

## Expiration and portable values

Context methods accept native `DateTimeInterface` expiration values. The store interface names its lower-level integer argument `expiresAtMilliseconds`: an absolute Unix timestamp in milliseconds, matching Bolt JS. At the exact expiry millisecond the value remains valid; it expires only after that instant. Expired records are removed when encountered. A replacement/update without an expiry clears the previous deadline.

Values support null, booleans, integers, finite floats, UTF-8 strings, and PHP array lists/maps. Objects, resources, non-finite numbers, cyclic arrays, and excessive nesting are rejected. All adapters use portable JSON rather than PHP serialization, detach snapshots, and preserve `1.0` as a float. Convert application value objects to arrays before storing them.

Like Bolt's conversation middleware, a load failure is debug-logged and the handler continues with an empty snapshot. Persistence failures propagate; a failed write cannot silently produce a successful interaction acknowledgement. Corrupt records are storage errors, distinct from ordinary missing/expired state.

## Explicit store access and custom adapters

```php
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\Stores\SqliteConversationStore;

$store = new SqliteConversationStore(new PDO('sqlite:/srv/my-bot/context.sqlite'));
$channel = new ConversationKey('C123', teamId: 'T123');
$thread = $channel->thread('1700000000.000001');

$store->set($thread, ['step' => 'awaiting_email']);
$record = $store->get($thread);
```

`ConversationStore` provides `get()`, `set()`, `delete()`, and atomic `update()`. `get()` returns `ConversationRecord` or throws `ConversationNotFound`/`ConversationExpired`. Update callbacks receive the latest decoded value, or null for a missing/expired record. Deletes affect the selected record; deleting channel state leaves independent thread records intact.

Bind a custom adapter in Tempest before resolving `App`. It must preserve canonical keys, portable JSON values, exact expiry semantics, and atomic updates across its deployment. When using SQLite, provide a dedicated connection; update transactions cannot run inside an application-owned transaction.

The checked-in `RememberConversation` example counts channel/thread mentions. The [source parity reference](research/conversation-storage.md) records the retained Bolt behavior and PHP extensions. Assistant focus context uses its own scoped adapter (`StoredThreadContextStore` or Slack metadata) so it remains distinct from generic conversation state; see [advanced Slack features](guides/advanced-slack-features.md).
