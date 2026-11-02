# Thunder Bolt

Build Slack apps in modern PHP: typed handlers, immutable Block Kit builders, and a Slack client that feels at home in your editor.

Thunder Bolt is built on **PHP 8.5+** and **Tempest 3**, with inspiration from Bolt for JavaScript and Laravel's coding style. The `Thunder\Bolt` namespace and package name are provisional.

**Experimental v0.2.0 preview.** This README follows `main`; the v0.2.0 tag captures the reviewed compatibility milestone. HTTP and Socket Mode runtimes work, but this is still an active port. It is not an official Slack SDK or a complete replacement for Bolt JS yet. [Changes](CHANGELOG.md) · [Upgrading](UPGRADING.md). The package is not yet registered on Packagist.

The [Bolt JS parity audit](docs/research/bolt-js-parity-audit.md) distinguishes implemented capabilities, PHP adaptations, useful gaps and Node-specific exclusions. Endpoint/model counts do not establish full framework parity.

## A Slack app, one handler at a time

```php
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Reply;

final readonly class GreetMention
{
    #[Event('app_mention')]
    public function __invoke(AppMention $event, Reply $reply): void
    {
        $reply->inThread("Hello <@{$event->userId}>!");
    }
}
```

Put the class in your application's `app/` directory. Tempest discovers the handler and injects its dependencies. Payloads expose native properties such as `userId` and `channelId`, while `raw` preserves Slack's original data.

Views and actions share typed form readers: `$view->form()->require('title', 'input')->text()` and `$action->input()->selectedValue()`. Original payloads and existing value/state readers stay available.

Message middleware composes through `MessageFilter::subtype('file_share')` and `MessageFilter::directMention()` from `Thunder\Bolt\Middleware`. For example, `$app->message('', MessageFilter::directMention(), $handler)` selects messages beginning with a mention of the authorized bot. It preserves the message text and requires a known bot user ID.

## Get started

You need PHP 8.5+ and Composer 2. Node is only required when maintaining the reference generators.

```sh
git clone --branch main https://github.com/HelgeSverre/slack-bolt-php.git
cd slack-bolt-php
composer install

php bin/bolt new work/my-bot --no-interaction
cd work/my-bot
composer install
cp .env.example .env
php bolt doctor
php bolt serve
```

Set `SLACK_BOT_TOKEN` and `SLACK_SIGNING_SECRET` in `.env`. Configure your Slack app's Events API and interactivity request URL as a publicly reachable HTTPS `/slack/events` endpoint, install the app with the scopes in the generated manifest, and invite it to a channel. Use an HTTPS tunnel for local HTTP development.

Run `php bolt auth:verify` to check a static token and print its identity. For HTTP apps, put the returned `user_id` and `bot_id` in `SLACK_BOT_USER_ID` and `SLACK_BOT_ID` so self-message filtering needs no request-time lookup. For Socket Mode, `php bolt socket:serve --verify-token` verifies and caches identity in the worker. Verification is explicit; help and discovery stay network-free.

Choose the transport and installation style when scaffolding:

```sh
php bin/bolt new work/socket-app --transport=socket --auth=token --no-interaction
php bin/bolt new work/oauth-app --transport=http --auth=oauth --no-interaction
```

HTTP/token remains the default. Socket Mode needs an app-level token with `connections:write`; OAuth needs client credentials and a registered HTTPS callback. OAuth still uses HTTP install/callback routes when events arrive through Socket Mode. Generated manifests, environment examples and start commands follow the selected profile. `config/setup.config.php` records onboarding defaults; runtime settings remain in `BoltConfig`.

Until the package is published, generated apps use a local Composer path repository pointing at this checkout. Keep the checkout available. `serve` starts a development server; production serves `public/index.php` through your PHP host.

The [hello-bolt example](examples/hello-bolt/) connects mentions, buttons, shortcuts, external options, modal validation, and stored conversation state.

It also includes a [playground](examples/hello-bolt/README.md): a shared JSON-backed pet, an owner-restricted terminal, and an OpenAI Responses agent with web search and scoped pet-care tools. Run `php bolt jobs:work` for AI and agent sessions, `php bolt demo:work` for pet, terminal and work items, alongside the Socket Mode worker. `/kickensink` opens an interactive tour.

### Run with Socket Mode

Enable Socket Mode in your Slack app settings and create an app-level token with the `connections:write` scope. Set these values in your application's `.env`:

```dotenv
SLACK_APP_TOKEN=xapp-your-app-token
SLACK_BOT_TOKEN=xoxb-your-bot-token
```

```sh
php bolt socket:serve
```

The same discovered handlers, middleware, installation authorization, and conversation stores work over Socket Mode. This runner needs no public request URL or signing secret. OAuth applications still need their HTTP installation and callback routes.

The worker acknowledges Slack envelopes, reconnects with bounded backoff, monitors connection health, and stops cleanly on SIGINT/SIGTERM when PHP's `pcntl` extension is available. Handlers still execute inline and must finish within Slack's three-second acknowledgement window. Run one worker per process under your process supervisor.

## Compose messages and views

Factories expose required values; fluent methods add optional behavior. Builders are immutable, and PHP's `#[NoDiscard]` helps catch ignored updates.

```php
use Thunder\Bolt\BlockKit\{Actions, Button, Input, PlainTextInput, Section};
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Views\Modal;

$message = Message::blocks(
    Section::markdown('*Deployment ready*'),
    Actions::make(Button::make('Approve', 'approve_deployment')->primary()),
)->fallbackText('Deployment ready for approval.');

$modal = Modal::make('Approve deployment',
    Input::make('Reason', PlainTextInput::make('reason')->multiline())->id('reason'),
)->callbackId('approve_deployment')->submit('Approve');
```

Dedicated builders cover the pinned Block Kit families, including selectors, rich text, tables, cards, and workflow controls. Raw extension points support newer Slack payloads. Message builders also support metadata, legacy attachments, unfurls, and threading.

## Delivery context and errors

Middleware can attach ephemeral values through `Delivery::context`; handlers inject `Thunder\Bolt\Context` and read them with `get()` or `require()`. Context is shared within one delivery and isolated from subsequent deliveries and job attempts. Use conversation/thread stores for persistent state and named job DTOs for durable work.

Configure the `httpContextFactory` option on `BoltConfig` or `contextFactory` on Socket Mode `Config` to enrich that Context from typed transport input after authorization, before middleware. [Factory examples](docs/research/transport-context-factories.md).

OAuth supports per-install scopes, workspace hints and server-side metadata through immutable `InstallRequest` values. Configure `OAuthConfig` with installation hooks for post-save onboarding and failure handling; reuse `OAuthHttpHandler` when your app owns the installation routes. [Installation interface](docs/research/oauth-install-requests.md).

For structured errors, register `$app->onError(function (FailureContext $failure): ErrorHandling { ... })` using types from `Thunder\Bolt\Errors`. The context exposes the original error, listener, processing stage, available delivery and buffered response state. Return `ErrorHandling::Handled` or `ErrorHandling::Rethrow`; log `$failure->summary()` for metadata without payloads or credentials. The existing `error()` callback remains supported. [Interface details and examples](docs/research/delivery-context-identity-errors.md).

## Return acknowledgements explicitly

Commands and interactions return typed response values. For example:

```php
use Thunder\Bolt\Attributes\SlashCommand;
use Thunder\Bolt\Responses\CommandResponse;

final readonly class Ping
{
    #[SlashCommand('/ping')]
    public function __invoke(): CommandResponse
    {
        return CommandResponse::ephemeral('Pong!');
    }
}
```

Register `/ping` in Slack with the same request URL. Actions, shortcuts, external options, and views follow the same pattern with their own attributes and response classes.

**Listeners execute inline.** Slack must receive the acknowledgement within three seconds. Dispatch a named job for slow work; `jobs:work` executes it after durable acceptance.

## Use the Slack client

The client preserves Slack's method hierarchy and returns endpoint-specific readonly responses. It includes 273 request/response model pairs: 271 from the pinned SDK and two Work Object comment methods from versioned Slack documentation inputs.

```php
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\Requests\ChatPostMessage;
use Thunder\Bolt\WebApi\Uploads\Upload;

$result = $client->chat()->postMessage(
    new ChatPostMessage('C123', Message::text('Hello from PHP')),
);

$messageTimestamp = $result->ts;

$client->upload(
    Upload::path('report.pdf')->title('Monthly report')->toChannel('C123'),
);
```

The injected client also supports arrays, cursor pagination, streamed multipart fields, and single/multiple external uploads. Unknown response fields remain in `data` and `get()`. Some polymorphic and deeply nested SDK fields deliberately retain raw representations.

Calls send once by default. Background or CLI code can opt into bounded retries with `$client->retrying()`. OAuth exchanges, function completion, response URLs, and file-content transfers remain single-attempt. Supply a PSR-18 transport to configure timeouts or test outgoing requests.

## Durable named jobs

Keep listener responses fast and explicitly queue slow business work. Named jobs are readonly constructor data; Tempest discovers their ordinary `#[CommandHandler]` methods.

```php
use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\{Job, JobExecution};
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Reply;

final readonly class PublishNote implements Job
{
    public function __construct(public string $text) {}
}

final readonly class QueueNote
{
    #[Event('app_mention')]
    public function __invoke(AppMention $event, Jobs $jobs): void
    {
        $jobs->dispatch(new PublishNote($event->text));
    }
}

final readonly class PublishNoteHandler
{
    public function __construct(private Reply $reply, private JobExecution $execution) {}

    #[CommandHandler]
    public function handle(PublishNote $job): void
    {
        $this->execution->checkpoint();
        $this->reply->inThread($job->text);
    }
}
```

Dispatch commits to SQLite before returning. Default duplicate keys use the Events API event ID, partitioned by installation and job class; pass `key:` for commands/interactions or an explicit business identity. Reusing a key with different inputs or destinations fails. The worker resolves current credentials and conversation state in a fresh scope; it does not serialize a live client or delivery.

```sh
php bolt make:job PublishNote
php bolt jobs:work
php bolt jobs:work --once
php bolt jobs:list
php bolt jobs:show JOB_ID
php bolt jobs:cancel JOB_ID
php bolt jobs:recover
php bolt jobs:retry JOB_ID --allow-repeat
php bolt jobs:migrate
php bolt jobs:prune --before=30d --dry-run
php bolt jobs:prune --before=30d --limit=1000
```

Requires `pdo_sqlite` when used. The default file is `.thunder-bolt/jobs/queue.sqlite`; `SLACK_JOB_STORE_PATH` or a typed `Jobs\JobConfig` selects another private local path. On POSIX, an existing custom parent directory must be private (`0700`); the factory will not change shared-directory permissions. Help/discovery do not open the database. Run the worker under your process supervisor; `--max-jobs=100` bounds a worker's lifetime and `--lease=120` selects its claim duration.

The default is **one attempt**. Opt into bounded retries and expired-lease recovery with `Jobs\RetryPolicy::idempotent(maxAttempts: 3)` only when repeating the **whole handler** is safe. Posting a new Slack message is not inherently idempotent. Unsafe abandoned attempts become `uncertain`; inspect them before explicitly allowing a repeat.

`jobs:show` includes timestamps, attempt history and recovery decisions; `--history-limit=100` bounds the newest entries. History is committed with each state change and contains no job inputs or lease tokens. Heartbeats do not create history entries. Older databases receive one explicitly incomplete legacy snapshot, preserving their existing jobs, leases and keys.

`jobs:prune` deletes inputs and history for succeeded, failed and cancelled jobs completed strictly before the retention cutoff. Pending, running and uncertain jobs are protected. Ages use days or hours (`30d`, `12h`); each call handles at most 1,000 jobs and reports `more_available`. Preview with `--dry-run`, then repeat batches as needed. Compact deduplication receipts remain indefinitely: an identical delivery still returns the original ID, different inputs still conflict, and `jobs:show` identifies a pruned job. Pruned inputs cannot be recovered or retried. Receipts still grow; pruning frees SQLite pages for reuse rather than shrinking the file automatically.

Stores apply supported additive schema migrations when opened; `jobs:migrate` opens the store explicitly and reports its version. Before upgrading, stop older workers and make a consistent private SQLite backup. Migrations are transactional and reject newer schemas rather than attempting a downgrade. A prune preview never deletes jobs, but opening an older store can still migrate its schema. [Operations and upgrade details](docs/research/job-operations.md).

Cancellation is cooperative: call `checkpoint()` between steps and `heartbeat()` before the lease expires. Pending jobs are cancelled immediately; running jobs stop when they check. A delivery-bound `$jobs->cancelThread()` targets the current installation/channel/root, useful for `agent_session_stopped`; application listeners still enforce business permissions and session UI/status changes. Blocking I/O needs bounded timeouts. Cancellation never rolls back a completed external action.

Modal validation, option choices and expiring triggers remain synchronous. Temporary workflow credentials require an explicit protected execution reference, as in the custom-function example; normal job dispatch rejects ambient function context. The queue reuses Tempest discovery and DI, with its own SQLite lifecycle rather than Tempest's experimental `#[Async]` queue. [Execution details and validation](docs/research/durable-execution-audit.md).

## Custom workflow functions

Match a Workflow Builder function by its manifest callback ID, then explicitly complete or fail the execution:

```php
use Thunder\Bolt\Attributes\{FromInputs, SlackFunction};
use Thunder\Bolt\Functions\Execution;

final readonly class FormatInputs
{
    public function __construct(public string $message, public bool $uppercase = false) {}
}

final readonly class FormatMessage
{
    #[SlackFunction('format_message')]
    public function __invoke(#[FromInputs] FormatInputs $inputs, Execution $execution): void
    {
        $text = $inputs->uppercase ? mb_strtoupper($inputs->message) : $inputs->message;
        $execution->complete(['message' => $text]);
    }
}
```

`$app->function('format_message', FormatMessage::class)` provides fluent registration. Inputs may also be injected as `Functions\Inputs` for strict accessors such as `string()` and `integer()`, or mapped explicitly with `as(MyInputs::class)`. DTO mapping supports constructor defaults, nullable fields, nested DTOs, backed enums and `#[InputName('wire_name')]` aliases. It does not coerce strings into numbers or booleans. Missing and invalid inputs raise `InvalidInputs`; application error handling remains explicit.

`Execution` supplies `complete()`, `fail()`, `inputs()`, `client()` and a typed execution context. Function-scoped actions and views receive the same capability alongside their normal acknowledgement responses. By default, the workflow token overrides the delivery client after installation authorization; it never enters the global client pool. Set `BoltConfig::attachFunctionToken` or `SLACK_ATTACH_FUNCTION_TOKEN` to `false` to use authorized installation credentials instead. Client injection, replies and completion helpers follow the same selection; opting out requires an installation credential. When using a workflow token, make completion your last Slack call: that credential expires when the function ends. A duplicate terminal call is rejected locally, and a failed completion request leaves the outcome `Uncertain` rather than automatically attempting the opposite result. This guard is per delivery, not distributed idempotency.

The transport acknowledgement confirms delivery; it does not complete the workflow. Inline event handlers need no response value, and the receiver acknowledges after they return. For slow work, the [Tempest async example](examples/hello-bolt/README.md#custom-functions-and-tempest-async) persists an encrypted, short-lived execution credential and queues only an opaque reference. It deduplicates executions before business work. Tempest's async bus is experimental; this example adds explicit claim/outcome handling without presenting it as a framework-wide queue.

## Slack assistants

Register one assistant with typed lifecycle and message handlers. In a Tempest app, return this value from `config/assistant.config.php`; otherwise pass it to `$app->assistant(...)`.

```php
use App\Listeners\WorkspaceAssistant;
use Thunder\Bolt\Assistant;

return Assistant::make()
    ->threadStarted([WorkspaceAssistant::class, 'started'])
    ->userMessage([WorkspaceAssistant::class, 'message']);
```

The injected thread object supplies the destination and the authorized client:

```php
use Thunder\Bolt\Assistant\{SuggestedPrompt, Thread};
use Thunder\Bolt\Payloads\Events\{AssistantThreadStarted, Message};

final readonly class WorkspaceAssistant
{
    public function started(AssistantThreadStarted $event, Thread $thread): void
    {
        $thread->saveContext();
        $thread->say('Ready. What are we working on?');
        $thread->setSuggestedPrompts([
            new SuggestedPrompt('Explain something', 'Explain how this works.'),
        ]);
    }

    public function message(Message $message, Thread $thread): void
    {
        $thread->session()->processing();
        try {
            $thread->say('Message received. Add your application logic here.');
        } finally {
            $thread->session()->active();
        }
    }
}
```

Assistant handlers consume IM thread replies with no subtype or `file_share`, matching Bolt JS. Other events continue to ordinary listeners. `threadContextChanged(...)` is optional: its default saves the new focus context. Focus context is separate from conversation history and is scoped by installation, DM and root timestamp; file and SQLite stores survive restarts. `contextStore(...)` accepts another adapter, including `SlackMetadataThreadContextStore` for Bolt-style message metadata storage. Without an explicit adapter, assistants use the application's conversation backend; disabling that backend selects Slack metadata storage, which requires a bot user ID and thread-history access.

`Thread::session()` controls the modern session lifecycle: `processing(?string $title)`, `active()`, `suspended()`, `closed()` and `rename(string $title)`. Set a title when creating a session; respect subsequent `agent_session_title_changed` user edits. `Thread::sayStream()` returns a lazy stream with `append()`, `chunks()` and `stop()`. Use `finish(AgentSessionStatus::Suspended)` when waiting for input, or `Closed` for completed work. `stop()` finishes as active. All helpers pin the destination to the current thread. Existing `setStatus()`, `clearStatus()` and `setTitle()` retain their older `assistant.threads.*` semantics for compatibility.

Enable your Slack app's **Agent experience**, install with `assistant:write`, `chat:write` and `im:history`, and subscribe to `assistant_thread_started`, `assistant_thread_context_changed`, `message.im`, `agent_session_stopped` and `agent_session_title_changed`. The stop subscription enables Slack's native Stop control while processing. A stop event does not itself cancel your work or clear status: the application must cancel its jobs and transition the session. The playground demonstrates both, including interrupting a pending Responses HTTP read. [Slack's session contract](https://docs.slack.dev/ai/agent-sessions/) describes the lifecycle.

Slow model calls belong in a worker: the [native assistant playground](examples/hello-bolt/README.md#native-slack-assistant) saves context and queues work before acknowledging. Core handlers execute inline.

### Using Laravel AI

Model execution remains application code. In a configured Laravel application, a worker can call `$agent->prompt($text)->text` and pass the result to `$thread->say(...)`, or translate its text deltas into `sayStream()->append(...)`. Keep Laravel's conversation UUID in your application conversation state and map Slack users to Laravel participants explicitly. This milestone provides the Slack integration seam and a [source-backed compatibility assessment](docs/research/assistant-laravel-ai.md); it does not include a full Laravel AI package or Eloquent integration. Laravel dependencies are not required by Thunder Bolt.

## Work Objects

Publish external work as native Slack previews, open details, and synchronize comments with your application.

```php
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WorkObjects\{Entity, EntityField, ExternalReference};

$task = Entity::task(
    new ExternalReference('TASK-42', 'task'),
    'https://example.com/tasks/42',
    'Ship the PHP integration',
)->field('status', EntityField::status('In progress', 'blue'))->comments(0);

$client->chat()->postMessage([
    'channel' => 'C123',
    ...Message::text('Ready for review')->entities($task)->toArray(),
]);
```

`$client->workObjects()` publishes details, unfurls, comment pages and mutation results. Typed `EntityDetailsRequested` and `EntityCommentsRequested` payloads work with ordinary event handlers. `Comments` connects a `CommentProvider` to an explicit application authorization policy; it checks permissions on every operation and derives Slack's edit/delete/reply flags from that policy. Providers own durable storage and must enforce permissions during writes. Event metadata and entity metadata have separate builders.

Slack app configuration and feature availability are required. The [work-item demo](examples/hello-bolt/README.md#work-objects) includes JSON storage, threaded comments, pagination and delivery deduplication.

## Included today

- Signed HTTP Events API, URL verification, typed event/message handlers, and middleware.
- Socket Mode for events, commands, and interactions, with a CLI worker, heartbeat, reconnects, and envelope acknowledgements.
- Commands, actions, shortcuts, options, view submissions/closures, and retained legacy interactions.
- OAuth v2 installation, one-time state validation, installation stores, scoped authorization, and token rotation.
- Conversation/thread state with memory, file, and SQLite adapters and atomic updates.
- Typed Web API models, message/view builders, uploads, pagination, and opt-in retries.
- Work Object entities, details, unfurls and bidirectional comments with application-owned storage and authorization.
- Slack assistant lifecycle handlers, isolated durable focus context, status, titles, suggested prompts and streaming helpers.
- Custom functions with typed inputs, execution context, workflow-token scoping and explicit complete/fail helpers.
- Project/listener scaffolding, discovery, diagnostics, and shell completions.

Capability parity is the goal; PHP handlers use native types, dependency injection, and explicit response values. Generated declaration scaffolds under `src/Scaffold/` are planning artifacts, excluded from discovery, and do not represent implemented runtime features.

## CLI

Run commands from a generated application:

```sh
php bolt make:listener Greeting --event=app_mention
php bolt make:listener Reaction --event=reaction_added
php bolt make:listener Approve --action=approve_deployment
php bolt make:listener Submission --view=approve_deployment
php bolt make:listener FormatMessage --function=format_message
php bolt make:assistant WorkspaceAssistant
php bolt make:job PublishNote
php bolt jobs:work
php bolt listener:list
php bolt doctor
php bolt status --format=json --require=socket,jobs
php bolt socket:serve
php bolt completion:show --shell=zsh
```

Known event selectors receive native payload types; other events receive the generic `Payloads\Event`. Non-chat event templates make no automatic Slack calls. `make:assistant` writes a listener and `config/assistant.config.php`, refuses existing files, and prints the Slack capabilities to enable without changing your manifest.

The CLI scaffolds local code; Slack app configuration and installation still happen through Slack. `doctor` checks local requirements, placeholders and storage paths without contacting Slack or printing credentials. It exits nonzero when the selected profile is not ready. Use `php bolt doctor --format=json` for scripts, `--workers` to require durable-job prerequisites, or `--transport=socket --auth=oauth` to override setup defaults. Local readiness does not verify a stored OAuth installation, a token, or a running worker.

`php bolt status --format=json --require=socket,jobs` checks fresh process observations and reads existing queue counts, due work, expired leases and uncertain outcomes. It does not initialize or recover a queue, contact Slack, or probe your HTTP server. Exit 1 means required processes/storage were not observed healthy; failed/uncertain jobs are reported separately for inspection. Use `--require=jobs` for a worker deployment or `--require=none` for inspection only. Heartbeats older than 30 seconds are stale; long jobs should checkpoint and explicitly renew their leases through `JobExecution::heartbeat()`.

The [deployment example](examples/deployment/README.md) runs intake and workers separately with persistent local storage, health checks and graceful shutdown. SIGTERM lets the current job finish before the next claim, including `jobs:work --once`. A forced kill follows the existing lease-recovery rules. `composer test:soak -- --duration=7200` runs the opt-in local reliability harness; it uses native WebSockets and isolated process tests, with recorded OAuth/Slack responses.

## Development

```sh
composer test
composer test:project
composer analyse
composer lint
composer format:check
```

Mago 1.50.0 provides formatting, linting, and static analysis. Native types carry ordinary signatures; PHPDoc adds useful collection types, generics, and explanations. Local fixtures and recorded transports test behavior without sending Slack messages.

`composer test:project` creates a disposable app, installs production dependencies through Composer, exercises discovered mention/button handlers with signed fixtures, and recovers an abandoned SQLite job in a separate worker process. It needs network access to package repositories, uses a fixture Slack transport, and removes its temporary app afterward. No Slack credentials are required.

`composer test` excludes live tests. For an explicit workspace smoke run, configure a private token file, a conversation and an existing thread:

```sh
SLACK_LIVE_TESTS=1 SLACK_LIVE_ENV_FILE=/absolute/path/to/.env \
SLACK_LIVE_CHANNEL=D123 SLACK_LIVE_THREAD_TS=1700000000.000001 \
composer test:live
```

The file supplies `SLACK_BOT_TOKEN` (or use `SLACK_LIVE_TOKEN`). Reads cover conversation info, history, list, members and replies with bounded pagination. Set `SLACK_LIVE_MEMBERS_CHANNEL` if members should use another conversation. Add `SLACK_LIVE_WRITES=1` to verify Slack-metadata context storage using a temporary thread; the test deletes only its own fixture messages. Use a dedicated development conversation. Scopes, conversation access and Slack rate limits apply. [Audited contracts and validation](docs/research/web-api-transport-conformance.md).

Reference tooling requires Node 22+:

```sh
npm ci
npm run test:tooling
npm run verify:generated
npm run verify:transport
npm run verify:parity
```

To regenerate from the pinned upstream archives, run `npm run sync:references`, then `npm run generate`. Ownership checks protect generated files from accidental overwrites. JavaScript tooling is not a runtime dependency.

## What's next

The preview includes a transport contract inventory for all 273 catalogued endpoints, a fresh-project integration check, and upgrade guidance. Next comes adopter feedback, broader opt-in live coverage and demand-led native typing of additional Slack events. Larger deployments may need additional durable-store adapters and an explicit deduplication-key expiry policy.

Extended guides and API documentation will live on a separate docs site. Existing design notes and coverage inventories remain under `docs/` for maintainers. Recorded integration checks and Linux/macOS CI are release gates. Opt-in live checks provide narrower real-workspace evidence; generated coverage alone does not establish full Bolt JS conformance.

## License

[MIT](LICENSE). Upstream references and attribution are recorded in [NOTICE](NOTICE).
