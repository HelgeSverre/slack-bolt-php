# Thunder Bolt

![Thunder Bolt — Slack apps. Modern PHP.](docs/images/readme-header.png)

[![Checks](https://github.com/HelgeSverre/slack-bolt-php/actions/workflows/checks.yml/badge.svg)](https://github.com/HelgeSverre/slack-bolt-php/actions/workflows/checks.yml)
[![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-A4AF71?style=flat-square)](https://www.php.net/releases/)
[![Packagist](https://img.shields.io/packagist/v/helgesverre/slack-bolt?include_prereleases&style=flat-square&color=A4AF71)](https://packagist.org/packages/helgesverre/slack-bolt)
[![License: MIT](https://img.shields.io/badge/license-MIT-A4AF71?style=flat-square)](LICENSE)

Build Slack apps in modern PHP: typed handlers, immutable Block Kit builders, and explicit durable jobs. Built on **PHP 8.5+** and **Tempest 3**, with inspiration from Bolt for JavaScript and Laravel's coding style.

**Experimental.** Install `helgesverre/slack-bolt` to get the latest compatible release. The PHP namespace is `Thunder\Bolt`. This is an independent framework; full Bolt JS compatibility remains a work in progress. [Parity audit](docs/research/bolt-js-parity-audit.md) · [Changes](CHANGELOG.md) · [Upgrading](UPGRADING.md).

Typed UI requests, interaction fixtures and modern rich-text references are covered by the [live acceptance record](docs/research/typed-ui-live-validation.md) and [v0.8.0 verification](docs/research/v0.8-release-verification.md).

## Explore the framework

| Build with | What it looks like |
| --- | --- |
| [Installation](#installation) | Composer package and `bolt new` project scaffolding |
| [Slack configuration](#slack-configuration) | HTTP or Socket Mode; static token or OAuth |
| [Handlers](#handlers) | Discovered attributes, typed payloads, or fluent registration |
| [Messages and views](#messages-and-views) | Immutable Block Kit, modals, and App Home builders |
| [Interactions](#interactions) | Buttons, shortcuts, select options, and form validation |
| [Web API client](#web-api-client) | Typed requests/responses, pagination, uploads, and bounded retries |
| [Middleware and errors](#middleware-and-errors) | Delivery context and an explicit failure policy |
| [OAuth installations](#oauth-installations) | State validation, installation stores, and bot/user token rotation |
| [Conversation state](#conversation-state) | Channel/thread snapshots with file, SQLite, or memory adapters |
| [Durable jobs](#durable-jobs) | Named jobs, deduplication, recovery, retries, and cancellation |
| [Assistants and streaming](#assistants-and-streaming) | Thread lifecycle, suggested prompts, sessions, and streaming replies |
| [Workflow functions](#workflow-functions) | Typed inputs and explicit complete/fail helpers |
| [Work Objects](#work-objects) | Native entity previews and application-owned comments |
| [Testing your app](#testing-your-app) | Signed fixtures, fake Slack, and reply/job assertions |
| [CLI and operations](#cli-and-operations) | Generators, diagnostics, worker status, and deployment |
| [Playground](#playground) | A shared pet, terminal, AI streaming, and an interactive tour |

## Installation

### Requirements

| Dependency | When you need it |
| --- | --- |
| PHP 8.5+ and Composer 2 | Every application |
| `pdo_sqlite` | Durable jobs or SQLite conversation storage |
| `pcntl` | Signal handling for long-running workers where supported |
| Node 22+ | Contributor tooling only; not the PHP runtime |

### Add the package

```sh
composer require helgesverre/slack-bolt
```

### Create a new application

Install the CLI, then generate an application backed by Packagist:

```sh
composer global require helgesverre/slack-bolt

bolt new my-bot --no-interaction

cd my-bot
composer install
cp .env.example .env
```

Put Composer's global vendor bin directory on `PATH` if needed: `composer global config bin-dir --absolute`.

| Scaffold option | Values | Default |
| --- | --- | --- |
| `--transport` | `http`, `socket` | `http` |
| `--auth` | `token`, `oauth` | `token` |
| `--source` | `packagist`, `path`, `vcs` | `packagist` |
| `--repository` | HTTPS/SSH URL or absolute local Git repository | Required for `vcs` |
| `--version` | Supported Composer constraint, or `dev-main` | CLI's release constraint for Packagist; `dev-main` for Git |

Generated apps use Packagist without a custom Composer repository and run independently of your CLI installation. Use `--source=path` for framework development, or `--source=vcs --repository=…` for an explicit Git source. Existing directories are never overwritten. [Full getting-started guide](docs/guides/getting-started.md).

## Slack configuration

Import the generated `slack-manifest.yaml` into a development Slack app, install it, and invite it to a test channel. The starter subscribes to `app_mention` with `app_mentions:read` and `chat:write`.

### Choose a transport

| Delivery | Application credentials | Slack setting | Run |
| --- | --- | --- | --- |
| HTTP | Bot token and signing secret | Public HTTPS request URLs ending in `/slack/events` | `php bolt serve` |
| Socket Mode | Bot token and app-level token | Enable Socket Mode; app-level scope `connections:write` | `php bolt socket:serve` |

These rows show ordinary single-workspace bot delivery. OAuth can replace the static bot token with installation authorization; its browser routes still use HTTP with either transport.

For HTTP, configure `.env`:

```dotenv
SLACK_BOT_TOKEN=xoxb-replace-me
SLACK_SIGNING_SECRET=replace-me
```

For Socket Mode, add an app-level token:

```dotenv
SLACK_APP_TOKEN=xapp-replace-me
```

### Verify and run

```sh
php bolt doctor
php bolt auth:verify
php bolt serve
# Or: php bolt socket:serve --verify-token
```

`doctor` checks local configuration; `auth:verify` explicitly contacts Slack to verify a static token. For HTTP self-message filtering without request-time lookup, copy the returned `user_id` and `bot_id` into `SLACK_BOT_USER_ID` and `SLACK_BOT_ID`.

`serve` is a development server. HTTP development needs an HTTPS tunnel; production serves `public/index.php` through your PHP host. Socket Mode needs no public event URL. Event subscriptions, interactivity, and slash commands still need their own Slack-side configuration. Follow the [Slack setup guide with screenshots](docs/guides/slack-app-setup.md).

## Handlers

Place handler classes under your application's `app/` directory. Tempest discovers attributes and injects typed payloads and delivery capabilities. Ordinary application services belong in constructors.

### Listen for an event

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

### Handle a slash command

```php
use Thunder\Bolt\Attributes\SlashCommand;
use Thunder\Bolt\Responses\CommandResponse;

final readonly class Ping
{
    #[SlashCommand('/ping')]
    public function __invoke(): CommandResponse
    {
        return CommandResponse::ephemeral('Pong.');
    }
}
```

### Choose a listener type

| Attribute | Payload | Initial response |
| --- | --- | --- |
| `#[Event('app_mention')]` | `Payloads\Events\AppMention` | None |
| `#[SlashCommand('/ping')]` | `Payloads\SlashCommand` | `CommandResponse` |
| `#[Action('approve_deployment')]` | `Payloads\Action` | `ActionResponse` |
| `#[Shortcut('open_deployment')]` | `Payloads\Shortcut` | `ShortcutResponse` |
| `#[Options('search_assignees')]` | `Payloads\Options` | `OptionsResponse` |
| `#[View('approve_deployment')]` | `Payloads\View` | `ViewResponse` |

Known events have native payload properties such as `userId` and `channelId`. App Home, channel lifecycle, and message-metadata events also have native payloads. Unknown event types use the generic event payload; `raw` preserves original Slack data. [Typed-event catalog](docs/guides/typed-events.md).

**Handlers execute inline.** Commands/interactions must return exactly one response of the correct family across matching listeners, within Slack's three-second deadline. Returning does not flush an acknowledgement early. Queue slow work explicitly.

### Register fluently

An embedded application can register handlers on an already configured `Thunder\Bolt\App`:

```php
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\CommandResponse;

$app
    ->event('app_mention', static function (AppMention $event, Reply $reply): void {
        $reply->inThread("Hello <@{$event->userId}>.");
    })
    ->command('/ping', static fn (): CommandResponse => CommandResponse::ephemeral('Pong.'));
```

Attribute and fluent registration share the same dispatcher. Choose one registration route for each handler; equivalent duplicate registrations fail at boot. [Handler and middleware guide](docs/guides/building-apps.md).

## Messages and views

Factories require essential values. Fluent methods add optional fields. Builders are immutable: retain the returned value when adding an option.

### Compose a Block Kit message

```php
use Thunder\Bolt\BlockKit\{Actions, Button, Section};
use Thunder\Bolt\Messages\Message;

$message = Message::blocks(
    Section::markdown('*Deployment ready*'),
    Actions::make(
        Button::make('Approve', 'approve_deployment')->primary(),
    ),
)->fallbackText('Deployment ready for approval.');

$reply->inChannel($message);
```

### Build a modal

```php
use Thunder\Bolt\BlockKit\{Input, PlainTextInput};
use Thunder\Bolt\Views\Modal;

$modal = Modal::make('Approve deployment',
    Input::make('Reason', PlainTextInput::make('reason')->multiline())
        ->id('reason'),
)->callbackId('approve_deployment')->submit('Approve');
```

In a shortcut handler, use its short-lived `triggerId` to open the modal and return the matching acknowledgement:

```php
use Thunder\Bolt\Responses\ShortcutResponse;
use Thunder\Bolt\WebApi\Requests\ViewRequest;

$client->views()->open(ViewRequest::open($shortcut->triggerId, $modal));

return ShortcutResponse::empty();
```

### Publish App Home

```php
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\Views\Home;
use Thunder\Bolt\WebApi\Requests\ViewRequest;

$home = Home::make(
    Section::markdown('*Workspace dashboard*'),
    Section::markdown('No pending approvals.'),
);

$client->views()->publish(ViewRequest::publish('U123', $home));
```

| Builder family | Examples |
| --- | --- |
| Layout | Sections, actions, context, dividers, containers and multi-column layouts |
| Data | Tables, interactive data tables, pie/bar/area/line charts and cards |
| Controls | Buttons, inputs, date/time pickers, selectors, workflow controls |
| Rich content | Rich text, images, files, message metadata, legacy attachments |
| Views | Modals and App Home |

The [Block Kit catalog](docs/sdk-and-block-kit.md#block-catalog) lists all 22 native block builders, element factories and composition values, with examples for layouts, data tables and charts. Raw arrays remain an escape hatch for additional Slack fields.

Tags and references compose as rich-text leaves:

```php
use Thunder\Bolt\BlockKit\{RichText, RichTextMessageMention, RichTextSection, RichTextTag, RichTextText};

$status = RichText::make(RichTextSection::make(
    RichTextTag::make('In progress')->blue(),
    RichTextText::make(' '),
    RichTextMessageMention::for('C123', '1700000000.000001'),
));
```

Work Object references, tag colors and supported styles are covered in the [rich-text guide](docs/sdk-and-block-kit.md#tags-and-rich-text-references).

## Interactions

### Respond to a button

```php
use Thunder\Bolt\Attributes\Action as OnAction;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\ActionResponse;

final readonly class ApproveDeployment
{
    #[OnAction('approve_deployment')]
    public function __invoke(Reply $reply): ActionResponse
    {
        $reply->replaceOriginal('Deployment approved.');

        return ActionResponse::empty();
    }
}
```

`replaceOriginal()` uses the incoming response URL. Some interactions, including modal actions, have no response URL; use only reply helpers supported by that delivery's context.

### Validate a modal submission

```php
use Thunder\Bolt\Attributes\View as OnView;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\ViewResponse;

final readonly class ValidateApproval
{
    #[OnView('approve_deployment')]
    public function __invoke(View $view): ViewResponse
    {
        $reason = $view->value('reason', 'reason');
        if (!is_string($reason) || trim($reason) === '') {
            return ViewResponse::errors(['reason' => 'Enter a reason.']);
        }

        return ViewResponse::empty();
    }
}
```

Errors address block IDs. Typed form readers also support `$view->form()->require('reason', 'reason')->text()`; action inputs expose selections through `$action->input()`.

### Load external select options

```php
use Thunder\Bolt\Attributes\Options as LoadOptions;
use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Responses\OptionsResponse;

final readonly class SearchAssignees
{
    #[LoadOptions('search_assignees')]
    public function __invoke(Options $request): OptionsResponse
    {
        // Use $request->value to search your application's people directory.
        return OptionsResponse::options(
            Option::text('Ada', 'ada'),
            Option::text('Linus', 'linus'),
        );
    }
}
```

| Reply capability | Purpose |
| --- | --- |
| `inChannel()` | Post to the delivery's channel |
| `inThread()` | Post under its known thread root |
| `ephemeral()` | Send a channel/user-scoped ephemeral reply |
| `respond()` | Use the delivery's response URL |
| `replaceOriginal()` / `deleteOriginal()` | Update or delete through that response URL |

Missing channel, user, thread, or response-URL context fails explicitly. Global shortcuts do not invent a channel. [Interactivity reference](docs/http-interactivity.md) covers compound selectors, regex captures, view closures, and legacy forms.

## Web API client

Inject `Thunder\Bolt\WebApi\Client` into a handler constructor. OAuth deliveries resolve an installation-scoped client; the application-wide client's credentials remain unchanged.

### Typed requests and responses

```php
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\Requests\ChatPostMessage;

$result = $client->chat()->postMessage(
    new ChatPostMessage('C123', Message::text('Hello from PHP')),
);

$messageTimestamp = $result->ts;
```

Native messages and views also work with focused UI requests:

```php
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Views\Modal;
use Thunder\Bolt\WebApi\Requests\{ChatPostEphemeral, ChatUpdate, ViewRequest};

$client->views()->open(ViewRequest::open(
    $action->triggerId,
    Modal::make('Deployment', Section::text('Ready for review.'))->close('Close'),
));
$client->chat()->update(new ChatUpdate('C123', $messageTimestamp, Message::text('Complete.')));
$client->chat()->postEphemeral(new ChatPostEphemeral('C123', 'U123', 'Saved for you.'));
```

View helpers cover open, push, update by ID or external ID, and Home publishing with optional optimistic hashes. [Typed UI request guide](docs/sdk-and-block-kit.md#typed-ui-requests).

### Arrays and Slack's method hierarchy

```php
$client->chat()->postMessage([
    'channel' => 'C123',
    'text' => 'A forward-compatible payload.',
]);

$client->admin()->conversations()->archive(['channel_id' => 'C123']);
```

### Cursor pagination

```php
foreach ($client->paginate('conversations.list', ['limit' => 100]) as $page) {
    foreach ($page->get('channels', []) as $channel) {
        // Process this page; stop iteration to avoid fetching more pages.
    }
}
```

### Upload a file

```php
use Thunder\Bolt\WebApi\Uploads\Upload;

$client->upload(
    Upload::path('report.pdf')
        ->title('Monthly report')
        ->toChannel('C123')
        ->comment('The report is ready.'),
);
```

`uploadMany()` handles batches. Upload failures expose partial progress where available; a batch is not an atomic Slack transaction.

### Opt into retries in background work

```php
use Thunder\Bolt\WebApi\RetryPolicy;

$background = $client->retrying(
    RetryPolicy::rateLimits(maxRetries: 3, maxTotalWait: 60),
);

$result = $background->conversations()->list(['limit' => 100]);
```

| Client capability | Behavior |
| --- | --- |
| Typed models | 273 request/response pairs; some nested/polymorphic values remain raw |
| Unknown fields | Available through response `data` and `get()` |
| Default retries | One send; no automatic replay |
| Rate-limit retries | Explicit bounded policy, respecting `Retry-After` |
| Transport | Replaceable PSR-18 client for timeouts and recorded tests |
| Uploads | File paths, bytes, streams, and multiple uploads |

Use waits/retries outside the acknowledgement path. Transient-error retries can repeat an already-successful write; choose them only for safe operations. OAuth exchanges, function completion, response URLs, and file-content transfers remain single-attempt. [Web API client guide](docs/web-api-client.md).

## Middleware and errors

### Attach delivery-local context

```php
use Closure;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Middleware\Middleware;

final readonly class RequestContext implements Middleware
{
    public function handle(Delivery $delivery, Closure $next): mixed
    {
        $delivery->context->set('request_id', bin2hex(random_bytes(8)));

        return $next($delivery);
    }
}

$app->use(new RequestContext());
```

Handlers can inject `Thunder\Bolt\Context` and read `get()`, `require()`, or `has()`. Transport context factories can enrich it after authorization, before middleware. [Context factory examples](docs/research/transport-context-factories.md).

### Compose message filters

```php
use Thunder\Bolt\Middleware\MessageFilter;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\Reply;

$app->message('', MessageFilter::directMention(),
    static function (Message $message, Reply $reply): void {
        $reply->inThread('You mentioned me.');
    },
);
```

`directMention()` requires a known authorized bot user ID. `MessageFilter::subtype('file_share')` selects a specific message subtype.

### Decide how failures are handled

```php
use Thunder\Bolt\Errors\{ErrorHandling, FailureContext};

$app->onError(static function (FailureContext $failure): ErrorHandling {
    error_log(json_encode($failure->summary(), JSON_THROW_ON_ERROR));

    return ErrorHandling::Handled;
});
```

The failure context includes the original error, processing stage, listener, delivery, and buffered acknowledgement state. `summary()` omits payloads and credentials. Return `Rethrow` to propagate the error instead. [Building apps](docs/guides/building-apps.md).

## OAuth installations

Use a static bot token for a single workspace, or enable OAuth for installation-specific credentials:

```dotenv
SLACK_CLIENT_ID=replace-me
SLACK_CLIENT_SECRET=replace-me
SLACK_OAUTH_REDIRECT_URI=https://your-app.example/slack/oauth_redirect
SLACK_BOT_SCOPES=app_mentions:read,chat:write,commands
```

| Route or component | Responsibility |
| --- | --- |
| `GET /slack/install` | Create state, bind the browser cookie, redirect to Slack |
| `GET /slack/oauth_redirect` | Validate/consume state, exchange code, persist installation |
| Installation store | Separate workspace/Grid and bot/user grants |
| Delivery authorizer | Select the installation before invoking handlers |
| Token rotator | Refresh expiring OAuth bot/user grants under the store lock |

Register the exact HTTPS callback in Slack. The default private file stores coordinate workers sharing that filesystem; multiple independent hosts need shared store implementations. State is one-time and server-backed. App-level `xapp` credentials are separate and are not refreshed by the OAuth token rotator.

Handlers can inspect the selected installation without capturing credentials globally:

```php
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Reply;

final readonly class InstallationGreeting
{
    #[Event('app_mention')]
    public function __invoke(Authorization $authorization, Reply $reply): void
    {
        $reply->inThread('Hello workspace '.$authorization->teamId);
    }
}
```

Per-install scopes, workspace hints, server-side metadata, installation hooks, and host-owned routes are supported. [OAuth guide](docs/oauth-and-authorization.md).

## Conversation state

### Choose the right lifetime

| Capability | Scope | Persists across deliveries? |
| --- | --- | --- |
| `Context` | Current delivery or job attempt | No |
| `ConversationContext` | Installation and channel | With a durable adapter |
| `ThreadContext` | Installation, channel, and thread root | With a durable adapter |
| Assistant focus context | Slack assistant thread | With its configured context store |
| Named job data | One durable execution | In the job store |

### Update thread state atomically

```php
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Conversation\ThreadContext;
use Thunder\Bolt\Payloads\Events\AppMention;

final readonly class CountMentions
{
    #[Event('app_mention')]
    public function __invoke(AppMention $event, ThreadContext $thread): void
    {
        $thread->update(static fn (mixed $state): array => [
            'mentions' => ($state['mentions'] ?? 0) + 1,
        ]);
    }
}
```

`value()` reads a delivery snapshot; `replace()`, `update()`, and `delete()` persist explicit changes. Use `update()` for concurrent counters. It does not deduplicate Slack retries automatically.

### Select storage

```dotenv
SLACK_CONTEXT_STORE=sqlite
SLACK_CONTEXT_STORE_PATH=/srv/my-bot/context.sqlite
```

| Driver | Suitable for |
| --- | --- |
| `file` | Default generated app; processes sharing persistent files |
| `sqlite` | Local durable state with transactional updates |
| `memory` | Tests or one long-lived application instance |
| `disabled` | Apps that do not need conversation capabilities |

Keep data outside `public/`. Memory is not shared across workers or ordinary HTTP requests. Custom adapters implement `ConversationStore`. [Conversation and thread storage](docs/conversation-and-thread-storage.md).

## Durable jobs

### Queue named data, not a live delivery

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

```sh
php bolt jobs:work
```

Dispatch commits to SQLite before returning. The worker resolves current installation credentials and fresh context for each attempt. Default duplicate keys use the Events API event ID, partitioned by installation and job class. Commands/interactions need an explicit stable `key:` when no delivery event ID exists.

### Understand execution outcomes

| Concern | Default or application responsibility |
| --- | --- |
| Attempts | One; retries must be explicitly enabled |
| Safe retries | `Jobs\RetryPolicy::idempotent(maxAttempts: 3)` promises the whole handler is repeatable |
| Abandoned unsafe attempt | Marked `uncertain` for inspection |
| Cancellation | Cooperative `JobExecution::checkpoint()` between steps |
| Lease renewal | Call `heartbeat()` during long work |
| Retention | Prune terminal payloads/history; compact deduplication receipts remain |
| Side effects | No automatic rollback or exactly-once external delivery guarantee |

The default database is `.thunder-bolt/jobs/queue.sqlite`; `SLACK_JOB_STORE_PATH` selects another private persistent path. Do not queue live clients, delivery objects, or workflow tokens. [Durable operations](docs/guides/durable-operations.md) covers retry policy, recovery, cancellation, and upgrades.

## Assistants and streaming

### Register the thread lifecycle

Return this from `config/assistant.config.php` in a generated app:

```php
use App\Listeners\WorkspaceAssistant;
use Thunder\Bolt\Assistant;

return Assistant::make()
    ->threadStarted([WorkspaceAssistant::class, 'started'])
    ->userMessage([WorkspaceAssistant::class, 'message']);
```

A thread-start handler can greet the user and offer suggested prompts:

```php
use Thunder\Bolt\Assistant\{SuggestedPrompt, Thread};
use Thunder\Bolt\Payloads\Events\AssistantThreadStarted;

final readonly class WorkspaceAssistant
{
    public function started(AssistantThreadStarted $event, Thread $thread): void
    {
        $thread->saveContext();
        $thread->say('What are we working on?');
        $thread->setSuggestedPrompts([
            new SuggestedPrompt('Explain a change', 'Explain the latest change.'),
        ]);
    }

    public function message(Thread $thread): void
    {
        $thread->say('Message received. Add your application logic here.');
    }
}
```

### Stream text into a thread

Given a thread and text fragments from your model provider, run slow model/tool work in a durable worker:

```php
use Thunder\Bolt\Assistant\AgentSessionStatus;

$stream = $thread->sayStream();
foreach ($textFragments as $fragment) {
    $stream->append($fragment);
}
$stream->finish(AgentSessionStatus::Suspended);
```

The stream needs at least one nonempty fragment before finishing. `chunks()` also accepts Slack streaming chunks for tool/task progress.

| Thread helper | Use |
| --- | --- |
| `say()` / `sayStream()` | Replies and streamed output pinned to the thread |
| `saveContext()` / `context()` | Legacy assistant focus context, separate from chat history |
| `setSuggestedPrompts()` | Starter prompts |
| `session()->processing()` / `active()` | Modern session status |
| `session()->rename()` | Modern session title |
| `setStatus()` / `setTitle()` | Retained legacy assistant-thread APIs |

Modern session APIs require the current Slack Agents capability; subscribing to legacy `assistant_thread_*` events alone does not enable them. Use `Assistant::forAgentView()` for typed current focus, Messages-tab opening and root DM routing. Enable Agent View in the target workspace first; see the [Agent View guide](docs/guides/agent-view.md). A Stop event does not cancel arbitrary PHP code: the app must cancel its jobs and check cancellation during work.

Task progress can share the text stream:

```php
use Thunder\Bolt\Assistant\Streaming\{TaskStatus, TaskUpdate};

$task = TaskUpdate::make('search', 'Read the reference');
$stream = $thread->sayStream();
$stream->chunks([$task])->append('Checking the API.');
$stream->chunks([$task->status(TaskStatus::Complete)->output('Reference found.')]);
$stream->append('Here is what it supports.')->stop();
```

Typed chunks also cover markdown, plans and Block Kit. Raw arrays remain available. [Streaming guide and limits](docs/guides/streaming-progress.md).

Generate a modern listener with `php bolt make:assistant WorkspaceAgent --agent-view`. Modern configuration uses the same application config seam:

```php
use App\Listeners\WorkspaceAgent;
use Thunder\Bolt\Assistant;

return Assistant::forAgentView()
    ->opened([WorkspaceAgent::class, 'opened'])
    ->appContextChanged([WorkspaceAgent::class, 'contextChanged'])
    ->userMessage([WorkspaceAgent::class, 'message']);
```

`AppContext` is injectable per delivery. Missing focus becomes empty context; it is never restored from an older selection. Only messages have a rooted `Thread`. Legacy `Assistant::make()` remains available.

Model execution belongs in application code. The demo uses OpenAI Responses through the thread/worker boundary; the framework does not require a model provider. [Advanced Slack features](docs/guides/advanced-slack-features.md) · [AI playground](examples/hello-bolt/README.md#native-slack-assistant).

## Workflow functions

Map function inputs to a readonly DTO and explicitly complete the execution:

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

| Capability | Behavior |
| --- | --- |
| Typed inputs | Strict mapping, defaults, nullable fields, nested DTOs, backed enums |
| `complete()` / `fail()` | One terminal response per delivery |
| Workflow credential | Delivery-scoped by default; never pooled globally |
| Slack configuration | Remote function runtime, callback definition, `function_executed`, eligible workspace |

The transport acknowledgement does not complete the function. Completion expires its workflow credential, so make it the last Slack call using that token. A failed terminal request has an uncertain outcome; do not automatically send the opposite result. [Custom-function guide](docs/guides/advanced-slack-features.md#custom-workflow-functions).

## Work Objects

Publish external domain records as Slack-native previews:

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

| Layer | Responsibility |
| --- | --- |
| `Entity` and fields | Typed preview data |
| `$client->workObjects()` | Publish details, unfurls, and comment results |
| `CommentProvider` | Application-owned persistence |
| Authorization callback | Enforce read/post/edit/delete access on every operation |

Slack feature availability, entity types, event subscriptions, and any unfurl domains/scopes must be configured separately. [Work Objects guide](docs/guides/advanced-slack-features.md#work-objects) · [Runnable comment demo](examples/hello-bolt/README.md#work-objects).

## Testing your app

Exercise real handlers without Slack credentials or network calls:

```php
use App\Listeners\GreetOnMention;
use Thunder\Bolt\Testing\BoltTest;

$bolt = BoltTest::make()->listeners(GreetOnMention::class);

$bolt->mention('hello', user: 'U123', channel: 'C123')
    ->assertAcknowledged()
    ->assertThreadReply('Hello <@U123>!')
    ->assertNoJobsQueued();
```

| Test capability | Interface |
| --- | --- |
| Commands and interactions | `command()`, `interaction()` |
| Typed interaction inputs | `InteractionFixture::action()`, `shortcut()`, `options()`, `viewSubmission()` |
| Web API responses | `$bolt->slack->respondsWith('users.info', $response)` |
| Application dependencies | `bind($fake, as: Reports::class)` |
| Real SQLite intake | `BoltTest::make(jobs: true)->jobHandlers(PublishNoteHandler::class)` |
| Assertions | Thread/channel/ephemeral replies, acknowledgements, API calls, queued jobs |
| Nested payload assertions | `assertApiCalledWith('views.open', ['view' => ['type' => 'modal']])` |

Results are scoped to one delivery. The harness uses the real HTTP receiver, memory conversation state, and optional in-memory SQLite; it does not load `.env`, boot the application kernel, or execute queued jobs. It has no PHPUnit/Pest dependency. [Public testing toolkit](docs/guides/testing-toolkit.md).

## CLI and operations

Run project commands as `php bolt …` from the generated app.

### Generate and inspect

| Command | Purpose |
| --- | --- |
| `make:listener Greeting --event=app_mention` | Typed event listener |
| `make:listener Approve --action=approve_deployment` | Button/action listener |
| `make:listener Form --view=approve_deployment` | Modal handler |
| `make:listener Format --function=format_message` | Workflow-function handler |
| `make:assistant WorkspaceAssistant` | Legacy assistant listener and configuration |
| `make:assistant WorkspaceAgent --agent-view` | Modern Agent View, typed streaming and Stop handler |
| `make:job PublishNote` | Named job and handler |
| `listener:list --format=json` | Discovered listeners |
| `completion:show --shell=zsh` | Project shell-completion script |

Generators refuse to overwrite existing files. They write local code; they do not configure or install the Slack app.

### Run and diagnose

| Command | Purpose |
| --- | --- |
| `serve` / `socket:serve` | HTTP development server or Socket Mode worker |
| `auth:verify` | Explicit live static-token identity check |
| `doctor --format=json` | Local profile, credentials/placeholders, and storage prerequisites |
| `status --format=json --require=socket,jobs` | Read worker observations and existing queue state |
| `jobs:work` | Run durable jobs in a separate process |
| `jobs:list` / `jobs:show JOB_ID` | Inspect executions and attempt history |
| `jobs:cancel JOB_ID` | Request cancellation |
| `jobs:recover` | Apply lease-recovery policy |
| `jobs:retry JOB_ID --allow-repeat` | Explicitly permit another attempt |
| `jobs:migrate` | Apply supported additive schema migrations |
| `jobs:prune --before=30d --dry-run` | Preview terminal payload/history retention |

`doctor` does not prove token validity, successful OAuth installation, or a running worker. `status` is read-only: it does not contact Slack or initialize/recover a queue. Use a process supervisor for workers and persistent private storage for state. Before upgrades, stop intake/workers and back up durable state consistently.

The [Docker Compose example](examples/deployment/README.md) demonstrates separate intake/workers, non-root execution, health checks, persistent volumes, and graceful restarts.

## Playground

The [hello-bolt application](examples/hello-bolt/README.md) demonstrates the framework end to end. Its wide Slack manifest is a development reference, not a minimal production permission baseline.

| Demo | What it exercises |
| --- | --- |
| `/kickensink` | Interactive feature tour |
| Boltling | Shared JSON-backed pet, care actions, levels, and App Home dashboard |
| Terminal | Owner-restricted terminal streamed into Slack |
| AI conversation | Responses streaming, web search, scoped tools, and progress cards |
| Agent sessions | Durable work, titles/status, and Stop-driven cancellation |
| Work items | Entity previews, JSON storage, comments, and pagination |

Run Socket Mode alongside `php bolt jobs:work` for AI/agent work and `php bolt demo:work` for the pet, terminal, and work items. The example README documents required credentials and per-demo configuration.

## Development

### PHP and documentation checks

```sh
composer install
composer test
composer test:project
composer test:vcs-project
composer analyse
composer lint
composer format:check
npm run verify:docs
```

| Check | What it establishes |
| --- | --- |
| PHPUnit | Local runtime contracts without live Slack credentials |
| Fresh-project checks | Path/VCS production installs, discovery, recorded delivery, and durable recovery |
| Mago | Formatting, lint, and static-analysis gates |
| Documentation verifier | Local links, images, PHP syntax/imports, public factories and constructors |
| Opt-in live suite | Narrow real-workspace evidence; separate from ordinary tests |

### Reference generators

```sh
npm ci
npm run test:tooling
npm run verify:generated
npm run verify:transport
npm run verify:parity
```

Regenerate pinned sources with `npm run sync:references`, then `npm run generate`. Generated output has ownership checks; edit the generators rather than generated files. Declaration-only scaffolds under `src/Scaffold/` are excluded from discovery and are not implemented runtime features.

[Development guide](docs/development.md) · [Testing, security, and troubleshooting](docs/guides/testing-security-troubleshooting.md) · [Architecture](docs/architecture.md).

## Documentation

| Start here | For |
| --- | --- |
| [Documentation index](docs/README.md) | All maintained adopter guides |
| [Public interface map](docs/reference.md) | Classes, source links and the relevant usage guides |
| [Getting started](docs/guides/getting-started.md) | Project layout, source choices, and first listener |
| [Slack setup with screenshots](docs/guides/slack-app-setup.md) | Current app-settings UI and feature prerequisites |
| [Building apps](docs/guides/building-apps.md) | Dispatch, acknowledgements, context, and errors |
| [Durable operations](docs/guides/durable-operations.md) | Recovery, cancellation, workers, and deployment |
| [Upgrading](UPGRADING.md) | Package migration and operator actions |

The documentation lives in `docs/` as Markdown, versioned with the code and readable directly on GitHub. Start at the [documentation home](docs/README.md). Dated `docs/research/` notes record decisions and evidence; they are not current setup instructions. The parity audit distinguishes implemented capabilities, PHP adaptations, useful gaps, and Node-specific exclusions.

## License

[MIT](LICENSE). Upstream references and attribution are recorded in [NOTICE](NOTICE).
