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

The client preserves Slack's method hierarchy and returns endpoint-specific readonly responses. It includes 271 request/response model pairs and shared nested models derived from pinned upstream sources.

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

## Included today

- Signed HTTP Events API, URL verification, typed event/message handlers, and middleware.
- Socket Mode for events, commands, and interactions, with a CLI worker, heartbeat, reconnects, and envelope acknowledgements.
- Commands, actions, shortcuts, options, view submissions/closures, and retained legacy interactions.
- OAuth v2 installation, one-time state validation, installation stores, scoped authorization, and token rotation.
- Conversation/thread state with memory, file, and SQLite adapters and atomic updates.
- Typed Web API models, message/view builders, uploads, pagination, and opt-in retries.
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
