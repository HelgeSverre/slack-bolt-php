# Getting started

Thunder Bolt runs on PHP 8.5+ and Tempest 3. The public Composer package is `helgesverre/slack-bolt`; its PHP namespace is `Thunder\Bolt`. Install it in an existing project with `composer require helgesverre/slack-bolt`, or create a new application below.

[Documentation home](../README.md) · [Public interface map](../reference.md)

## Create a project

Install the CLI, then create an application from Packagist:

```sh
composer global require helgesverre/slack-bolt
bolt new my-bot --no-interaction
cd my-bot
composer install
cp .env.example .env
```

If `bolt` is not on your shell's `PATH`, find Composer's global bin directory with `composer global config bin-dir --absolute` and add it to `PATH`.

The generated app has a PSR-4 `App\` namespace, an `app/Listeners` directory, `config/` files discovered by Tempest, `public/index.php`, a matching `slack-manifest.yaml`, and a project-local `bolt` launcher. The default `mentions` starter uses an `app_mention` listener, `app_mentions:read`, and `chat:write`.

The CLI supports `--profile`, independently of transport and authorization:

| Profile | Generated experience | Bot scopes |
| --- | --- | --- |
| `mentions` (default) | Mention the app and get a reply | `app_mentions:read`, `chat:write` |
| `interactive` | App Home button and `/bolt` → modal → validated private confirmation | `commands`, `chat:write` |
| `agent` | Agent View messages, typed streaming and native Stop handler | `assistant:write`, `chat:write`, `im:history` |

```sh
bolt new office-app --profile=interactive --transport=socket --no-interaction
bolt new workspace-agent --profile=agent --auth=oauth --no-interaction
```

Each profile generates its own listener/configuration, scopes, events and feature settings. Agent View requires workspace support; this profile targets a new app and does not migrate an existing Slack app. The example reply requires no AI account. Replace or extend the generated PHP classes as the application grows.

Interactive and agent starters require dependency constraints that exclude versions before 0.8.0; older pins are rejected before creating a directory. The mention starter remains available for older supported releases. Runtime relocation and session-key commands require the newer shared bootstrap described below.

`new` creates a new directory and refuses to overwrite an existing one. Choose delivery and authorization while scaffolding when the defaults are not right:

```sh
bolt new socket-app --transport=socket --auth=token --no-interaction
bolt new oauth-app --transport=http --auth=oauth --no-interaction
```

The generated `config/setup.config.php` records those onboarding defaults. Runtime configuration stays in `.env` and `BoltConfig`; it is not inferred from a command that happened to run first.

Generated applications use the CLI's current release constraint and run independently of the CLI installation. Framework contributors can select [a checkout or Git source](#framework-checkouts-and-git-sources) explicitly.

## Configure the local app

For a single-workspace HTTP app, set:

```dotenv
SLACK_BOT_TOKEN=xoxb-replace-me
SLACK_SIGNING_SECRET=replace-me
SLACK_BOT_USER_ID=
SLACK_BOT_ID=
```

Run `php bolt auth:verify` to verify a static token and obtain the bot and user IDs. Copy those IDs into the optional environment fields when self-message filtering needs them without a request-time lookup.

For Socket Mode, add an app-level token as well:

```dotenv
SLACK_APP_TOKEN=xapp-replace-me
```

For OAuth, set `SLACK_CLIENT_ID`, `SLACK_CLIENT_SECRET`, and an exact HTTPS `SLACK_OAUTH_REDIRECT_URI`; then follow [OAuth and authorization](../oauth-and-authorization.md). OAuth authorization and a static bot token are alternatives for ordinary deliveries. Socket Mode can use either authorization choice, but OAuth installation and callback routes still use HTTP.

The generated `.gitignore` excludes `.env` and `.thunder-bolt/`. Keep credentials and state outside `public/`, never commit them, and do not paste them into issue reports or logs.

The shared bootstrap supports `BOLT_RUNTIME_PATH` for state outside a read-only release. Custom browser forms can use `php bolt key:generate` and `php bolt doctor --sessions`. These additions, available since 0.9, are covered in [runtime storage and browser routes](runtime-and-browser-routes.md), including how to upgrade an existing HTTP entry point.

## Configure Slack

Import the generated `slack-manifest.yaml` into a development Slack app, then use the [Slack app setup guide](slack-app-setup.md) to complete the transport-specific UI steps. It explains where Slack currently exposes the signing secret, app-level token, Event Subscriptions, Interactivity, commands, OAuth redirect URLs, and feature-specific settings.

For HTTP development, expose `php bolt serve` through a trusted HTTPS tunnel and configure `/slack/events` at every relevant Slack request-URL setting. For Socket Mode, enable Socket Mode and create an app-level token with `connections:write`; a public event request URL is not needed for events or interactive payloads. Slash commands still need the configuration Slack requires for each command.

Install or reinstall the app after changing scopes, event subscriptions, shortcuts, commands, or the manifest. Invite the installed bot to the channel before expecting channel events.

## Start and check it

```sh
# HTTP development
php bolt serve

# or Socket Mode
php bolt socket:serve

# Inspect local configuration only; no Slack request is made.
php bolt doctor --format=json
```

`doctor` exits nonzero when the selected profile is incomplete. It checks placeholders and local paths, not token validity, a completed OAuth installation, or a live worker. `status` is different: it reads local worker observations and existing queue state.

```sh
php bolt status --format=json --require=socket
```

Use `--require=jobs` after introducing durable jobs, or `--require=socket,jobs` when both workers run. The command neither contacts Slack nor starts, migrates, recovers, or mutates a queue.

## Add the first listener

Put discovered classes under `app/`. Tempest supplies dependencies by type:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Reply;

final readonly class GreetMention
{
    #[Event('app_mention')]
    public function __invoke(AppMention $event, Reply $reply): void
    {
        $reply->inThread("Hello <@{$event->userId}>.");
    }
}
```

Use `php bolt make:listener Greeting --event=app_mention` to produce the same starting shape. The [build handlers guide](building-apps.md) explains acknowledgement rules, interactions, context, middleware, errors, Block Kit, and the Web API client.

## Framework checkouts and Git sources

To develop against this framework checkout, create a path-backed application from its root:

```sh
composer install
php bin/bolt new work/my-bot --source=path --no-interaction
cd work/my-bot
composer install
cp .env.example .env
```

The path source selects the checkout's development alias and links the application to that directory. Keep the checkout available while using the app. For an explicit Git source:

```sh
bolt new team-bot \
  --source=vcs \
  --repository=https://github.com/HelgeSverre/slack-bolt-php.git \
  --version=dev-main \
  --no-interaction
```

`--repository` accepts an HTTPS URL, a `git@…` URL, or an absolute local Git repository. It is required for `vcs` and rejected for `packagist` and `path`. A path source also rejects `--version`. Packagist uses the CLI's release constraint; Git defaults to `dev-main`. Explicit version constraints must require a scaffolding-compatible release, starting at 0.3.0, or select `dev-main`. Version validation is local; Composer resolves the selected constraint during installation.

## Next choices

- Need more than one workspace or rotating OAuth credentials? Read [OAuth and authorization](../oauth-and-authorization.md).
- Need state that survives requests? Choose a conversation store in [durable operations](durable-operations.md).
- Need slow work, retries, or Stop support? Use named jobs; do not make a Slack delivery wait.
- Need a complete runnable reference? Start from [`examples/hello-bolt`](../../examples/hello-bolt/README.md), but treat its wide manifest and local demo services as examples, not baseline production configuration.
