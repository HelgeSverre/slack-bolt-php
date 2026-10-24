# Thunder Bolt

Build Slack apps in modern PHP: typed handlers, immutable Block Kit builders, and a Slack client that feels at home in your editor.

Thunder Bolt is built on **PHP 8.5+** and **Tempest 3**, with inspiration from Bolt for JavaScript and Laravel's coding style. The `Thunder\Bolt` namespace and package name are provisional.

**Experimental and unpublished.** HTTP and Socket Mode runtimes work, but this is still an active port. It is not an official Slack SDK or a complete replacement for Bolt JS yet.

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

## Get started

You need PHP 8.5+ and Composer 2. Node is only required when maintaining the reference generators.

```sh
git clone https://github.com/HelgeSverre/slack-bolt-php.git
cd slack-bolt-php
composer install

php bin/bolt new work/my-bot --no-interaction
cd work/my-bot
composer install
cp .env.example .env
php bolt serve
```

Set `SLACK_BOT_TOKEN` and `SLACK_SIGNING_SECRET` in `.env`. Configure your Slack app's Events API and interactivity request URL as a publicly reachable HTTPS `/slack/events` endpoint, install the app with the scopes in the generated manifest, and invite it to a channel. Use an HTTPS tunnel for local HTTP development.

Until the package is published, generated apps use a local Composer path repository pointing at this checkout. Keep the checkout available. `serve` starts a development server; production serves `public/index.php` through your PHP host.

The [hello-bolt example](examples/hello-bolt/) connects mentions, buttons, shortcuts, external options, modal validation, and stored conversation state.

It also includes a [playground](examples/hello-bolt/README.md): a shared JSON-backed pet, an owner-restricted terminal, and an OpenAI Responses assistant with web search and scoped pet-care tools. Run `php bolt demo:work` alongside the Socket Mode worker to try `/bolt pet`, `/bolt terminal`, and `/bolt ai how big is the Sun?`.

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

**Handlers execute inline.** Slack must receive the acknowledgement within three seconds. Keep slow work outside the request; durable background workers are planned.

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

Calls send once by default. Background or CLI code can opt into bounded retries with `$client->retrying()`. OAuth exchanges, response URLs, and file-content transfers remain single-attempt. Supply a PSR-18 transport to configure timeouts or test outgoing requests.

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
        $thread->setTitle(mb_substr($message->text, 0, 80));
        $thread->setStatus('is working');
        try {
            $thread->say('Message received. Add your application logic here.');
        } finally {
            $thread->clearStatus();
        }
    }
}
```

Assistant handlers consume IM thread replies with no subtype or `file_share`, matching Bolt JS. Other events continue to ordinary listeners. `threadContextChanged(...)` is optional: its default saves the new focus context. Focus context is separate from conversation history and is scoped by installation, DM and root timestamp; file and SQLite stores survive restarts. `contextStore(...)` accepts another adapter, including `SlackMetadataThreadContextStore` for Bolt-style message metadata storage. Without an explicit adapter, assistants use the application's conversation backend; disabling that backend selects Slack metadata storage, which requires a bot user ID and thread-history access.

`Thread::sayStream()` returns a lazy stream with `append()`, `chunks()` and `stop()`. Text and task chunks use Slack's native streaming protocol. `Status::make('is working')->loadingMessages(...)` supplies optional status customization. All helpers pin the destination to the current assistant thread.

Enable your Slack app's **Agent experience**, install with `assistant:write`, `chat:write` and `im:history`, and subscribe to `assistant_thread_started`, `assistant_thread_context_changed` and `message.im`. Slack currently recommends newer agent-session methods; this module implements the assistant-thread contract used by the pinned Bolt JS reference. It does not yet implement the full agent-session protocol.

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
- Project/listener scaffolding, discovery, diagnostics, and shell completions.

Capability parity is the goal; PHP handlers use native types, dependency injection, and explicit response values. Generated declaration scaffolds under `src/Scaffold/` are planning artifacts, excluded from discovery, and do not represent implemented runtime features.

## CLI

Run commands from a generated application:

```sh
php bolt make:listener Greeting --event=app_mention
php bolt make:listener Approve --action=approve_deployment
php bolt make:listener Submission --view=approve_deployment
php bolt listener:list
php bolt doctor
php bolt socket:serve
php bolt completion:show --shell=zsh
```

The CLI scaffolds local code; Slack app configuration and installation still happen through Slack. `doctor` checks local requirements and configuration without contacting Slack or printing credentials.

## Development

```sh
composer test
composer analyse
composer lint
composer format:check
```

Mago 1.50.0 provides formatting, linting, and static analysis. Native types carry ordinary signatures; PHPDoc adds useful collection types, generics, and explanations. Local fixtures and recorded transports test behavior without sending Slack messages.

Reference tooling requires Node 22+:

```sh
npm ci
npm run test:tooling
npm run verify:generated
```

To regenerate from the pinned upstream archives, run `npm run sync:references`, then `npm run generate`. Ownership checks protect generated files from accidental overwrites. JavaScript tooling is not a runtime dependency.

## What's next

The next milestone is **durable background execution**: acknowledge promptly, persist jobs, and run slow handlers through workers with retries and delivery deduplication. Assistant and custom-function support follow.

Extended guides and API documentation will live on a separate docs site. Existing design notes and coverage inventories remain under `docs/` for maintainers. Controlled live-workspace tests and Linux/macOS CI are release gates; generated coverage alone does not establish full Bolt JS conformance.

## License

[MIT](LICENSE). Upstream references and attribution are recorded in [NOTICE](NOTICE).
