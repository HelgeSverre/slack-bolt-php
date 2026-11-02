# Durable work and operations

There are three different kinds of state in a Thunder Bolt application. Keeping them separate avoids subtle reliability and security mistakes.

| Need | Use | Lifetime |
| --- | --- | --- |
| Values shared while one delivery is dispatched | `Context` | One delivery |
| A channel or thread's application state | Conversation/thread store | Durable |
| Slow, recoverable business work | Named SQLite job | Durable |

OAuth installations are a fourth, separate store. They hold credentials and must not be used as application state.

## Store conversation and thread state

Generated Tempest apps default to a file store under `.thunder-bolt/conversations/`; SQLite, memory, and disabled modes are also available.

```dotenv
SLACK_CONTEXT_STORE=file
SLACK_CONTEXT_STORE_PATH=
```

Use `file` or `sqlite` for data that must survive a PHP request or process restart. `memory` is useful in a test or a deliberately long-lived process, but a normal HTTP deployment creates a fresh application for each request. Custom paths must be private, persistent, and outside `public/`.

```php
use DateTimeImmutable;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\ThreadContext;

final readonly class CountMentions
{
    public function __invoke(ConversationContext $conversation, ThreadContext $thread): void
    {
        $conversation->update(static function (mixed $state): array {
            $state = is_array($state) ? $state : [];

            return ['mentions' => ($state['mentions'] ?? 0) + 1];
        });

        if ($thread->isAvailable()) {
            $thread->replace(['last_seen' => new DateTimeImmutable()->format(DATE_ATOM)]);
        }
    }
}
```

`update()` is an atomic read/modify/write operation. `replace()` replaces the complete value; it does not merge maps. Both update the snapshot shared by later matching listeners in this delivery. State in a different delivery is loaded fresh. Conversation context requires a trusted installation and channel; thread context additionally requires a known thread root. Do not invent either from modal metadata or a response URL.

Values must be portable JSON values, not serialized objects. See [conversation and thread storage](../conversation-and-thread-storage.md) for keys, expiry, explicit store access, and adapter requirements.

## Dispatch slow work as a named job

Events and interaction acknowledgements must complete promptly. A named job commits its small constructor payload to SQLite before the handler returns; a separate process resolves a fresh application scope and current authorization when it runs.

```php
use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\{Job, JobExecution};

final readonly class PublishDigest implements Job
{
    public function __construct(public string $channelId, public string $text) {}
}

final readonly class QueueDigest
{
    public function __invoke(Jobs $jobs): void
    {
        $jobs->dispatch(new PublishDigest('C123', 'The digest is ready.'));
    }
}

final readonly class PublishDigestHandler
{
    public function __construct(private JobExecution $execution) {}

    #[CommandHandler]
    public function handle(PublishDigest $job): void
    {
        $this->execution->checkpoint();
        // Do bounded I/O, then checkpoint again before another effect.
    }
}
```

Create the pair with `php bolt make:job PublishDigest`. The worker needs `pdo_sqlite` and is a distinct process:

```sh
php bolt jobs:migrate
php bolt jobs:work
```

The default database is `.thunder-bolt/jobs/queue.sqlite`; set `SLACK_JOB_STORE_PATH` to a private persistent SQLite path. A successful dispatch is not a guarantee that an external effect runs exactly once. The default is one attempt because an interrupted HTTP call may have succeeded remotely.

## Retries, recovery, and cancellation

Only opt into retries when repeating the *whole handler* is safe. A new chat message is normally not idempotent by itself. Ambiguous abandoned attempts become `uncertain` unless their policy explicitly permits recovery.

```sh
php bolt jobs:list
php bolt jobs:show JOB_ID
php bolt jobs:cancel JOB_ID
php bolt jobs:recover
php bolt jobs:retry JOB_ID --allow-repeat
```

Cancellation is cooperative. Pending jobs cancel immediately; running work stops only when it reaches `JobExecution::checkpoint()`. Long work must also call `heartbeat()` before its lease expires. Use bounded network timeouts: PHP cannot interrupt arbitrary synchronous code safely, and cancellation cannot undo a Slack request or another completed external side effect.

`jobs:retry --allow-repeat` is deliberately explicit for potentially repeated work. Inspect the job history first. `jobs:prune --before=30d --dry-run` previews a bounded deletion of terminal inputs/history; it never removes pending, running, or uncertain jobs. The durable job contract and migration behavior are described in [UPGRADING](../../UPGRADING.md).

## Operate workers

Run intake and durable workers under a real supervisor. Socket Mode and `jobs:work` write local heartbeat observations. Check their shared local state directory with:

```sh
php bolt status --format=json --require=socket,jobs
```

The status command is read-only. It does not ping Slack, inspect an HTTP server, start missing processes, or recover jobs. It answers only for processes sharing its configured local state directory. A stale heartbeat is evidence to investigate, not proof that an external side effect failed.

For deployment, stop intake first, then workers; back up private state only after writers stop; run `jobs:migrate` before starting newer workers. Do not mix old and migrated workers against the same SQLite file. A live SQLite file needs a consistent SQLite backup, not a blind copy of one database file. The [deployment example](../../examples/deployment/README.md) shows the exact Compose sequence, health-check limits, and graceful-stop behavior.

## Pick a store for the topology

File stores coordinate processes on the same filesystem. SQLite coordinates processes sharing the same database file. Neither turns separate disks into a distributed datastore. For multiple independent hosts, provide a durable shared implementation of the relevant store contract and preserve its atomic-update/claim semantics.

Keep all state paths private, back them up deliberately, and test restore/recovery before relying on them. See [testing, security, and troubleshooting](testing-security-troubleshooting.md) for local diagnostics and failure checks.
