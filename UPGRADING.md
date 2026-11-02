# Upgrading

## Public package name

The Composer package is now `helgesverre/slack-bolt`; the PHP namespace remains `Thunder\Bolt`. Existing applications using `thunder/bolt` must update their Composer requirement and any path-repository `options.versions` key, then run `composer update helgesverre/slack-bolt thunder/bolt`. Update a hand-written launcher from `vendor/thunder/bolt/bin/bolt` to `vendor/helgesverre/slack-bolt/bin/bolt`. Newly generated apps already use the new path. Do not change application imports.

Use `^0.3` for the first release under the public package name. Tags before v0.3.0 predate the rename and current scaffolding API; `dev-main` remains available explicitly.

## v0.3.0

`bolt new` and `ProjectScaffold::create()` now default to Packagist and `^0.3`, without a custom repository or dependency on the CLI checkout. Contributor scripts that must exercise the current working tree should pass `--source=path` or provide `ProjectSource::local()` explicitly. Its checkout alias is now `0.3.x-dev`; update existing path-repository version overrides and requirements together. Git sources remain available with `--source=vcs --repository=…`. No network request is made until the separate Composer installation step.

Additional known events now decode into subclasses of `Payloads\Event`. Generic listeners and `raw` access remain compatible. Malformed required fields on these known events now fail validation before dispatch; update incomplete synthetic fixtures accordingly.

Restart workers/intake after updating to enable runtime observations. `status` cannot verify processes started with an older version, and is scoped to processes sharing its local state directory. New heartbeat files live under `.thunder-bolt/runtime`; they are separate from job ownership and do not require a queue schema migration. Custom library hosts opt in by passing a `RuntimeMonitor` to `Worker` or `SocketMode\Runner`.

`doctor` now exits 1 when local prerequisites for the selected transport/authorization profile are incomplete. Scripts that previously relied on exit 0 for an unconfigured app should inspect `--format=json` and its `ready` field. Legacy projects infer Socket Mode from an app token and OAuth from runtime configuration; use explicit flags if inference does not match your intent. New projects store these defaults in `config/setup.config.php`.

Restart long-running Socket Mode workers after updating to receive the idle-memory fix. No store migrations are introduced.

## v0.2.0 preview

PHP 8.5+ and Tempest 3 remain the baseline. For local path installations, stop intake/workers, preserve private configuration and durable stores, check out `v0.2.0`, and run `composer install` in the framework checkout. Change existing app constraints and path-repository version overrides from `0.1.x-dev` to `0.2.x-dev`, then run `composer update thunder/bolt --with-dependencies` in each app. Newly scaffolded apps already use the new development alias. Restart all processes on the same version. This release adds no job database schema migration beyond v0.1.0.

- Existing `Installer::begin()` calls keep their configured defaults. Per-install options require the new `RequestStateStore` extension; old custom `StateStore` adapters remain valid for default flows. Built-in stores support it automatically. Persist `State::$request` and return it atomically with state consumption.
- Custom install routes must set the cookie named by `InstallRedirect::$cookieName`. The built-in/reusable handler handles per-flow cookies and legacy fixed-name pending flows. Old file records remain readable, but only a newly started flow can carry new account metadata. Do not mix old and new HTTP workers during an install flow.
- `Installer::complete()` now returns `InstallStatus::Failed` with an error for storage/exchange failures. Update exhaustive status matches and code that previously relied on these errors throwing. Hook exceptions still throw `InstallHookFailed`, retaining the determined result and original exception. Success hooks run after storage; callback replay cannot rerun exchange or onboarding.
- Failure-stage matches must account for `FailureStage::Context`. HTTP and Socket Mode factories enrich fresh Context after authorization and before middleware; factory failure cannot become a successful acknowledgement.
- Existing action/view raw properties and `View::value()`/`state()` are unchanged. New `input()`/`form()` readers are additive; rich-text trees and unfamiliar controls retain raw access.
- `MessageFilter::directMention()` needs a known bot user ID. Static-token apps can verify explicitly with `auth:verify` or `socket:serve --verify-token`. `SLACK_ATTACH_FUNCTION_TOKEN=false` selects installation authorization and requires a configured bot/user grant, including for function-only Socket Mode apps.

See [installation requests and host routes](docs/research/oauth-install-requests.md), [transport factories](docs/research/transport-context-factories.md), [typed values](docs/research/typed-interaction-values.md), and [release validation scope](docs/research/v0.2-validation.md).

## v0.1.0 foundation

Requires PHP 8.5+ and Composer 2. SQLite jobs and SQLite context storage require `pdo_sqlite`. Gzipped enterprise analytics additionally requires `zlib` and is limited to 32 MiB of decompressed data per response.

For an existing checkout and its generated apps:

1. Stop HTTP intake and workers before switching code. Back up the job database consistently while workers are stopped, and preserve private `.env`, OAuth installation and conversation stores. Store backups outside the public web directory.
2. Check out `v0.1.0` and run `composer install` in the framework checkout. Generated apps still use a local path repository: keep that checkout in place and run `composer update thunder/bolt --with-dependencies` inside each app. Commit the application's resulting lock file.
3. From each app, run `php bolt jobs:migrate`, then `php bolt doctor` and inspect recent jobs with `php bolt jobs:list`. Opening a job store also upgrades its schema automatically; the explicit command makes that step visible before workers restart.
4. Restart intake and workers on the same version. Do not mix older workers with the migrated store. There is no down migration; rollback requires restoring the consistent database backup together with the previous code.

Read-only methods now follow the reviewed GET contracts; custom transports must accept query arguments and empty bodies. Two form-only endpoints (`auth.teams.list` and `admin.apps.activities.list`) now receive URL-encoded POST bodies. Token overrides remain in the Authorization header. External upload alternate text now uses Slack's `alt_txt`; fluent `altText()` calls are unchanged.

Pruning is optional. Preview a bounded batch with `php bolt jobs:prune --before=30d --dry-run`. Pending, running and uncertain jobs are protected. Terminal inputs and history are removed by a real prune, while compact deduplication receipts remain indefinitely. Pruning does not shrink the database file or erase backups.

For a new app, follow the README. `composer test:project` validates a disposable installation without Slack credentials; `composer test:live` separately opts into a real development workspace. Neither command should be run against a production database.
