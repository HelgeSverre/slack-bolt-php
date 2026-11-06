# Runtime storage and browser routes

Available since Thunder Bolt 0.9. Upgrading the global CLI does not upgrade an existing app's Composer dependency.

## One bootstrap for HTTP and commands

New applications use `Thunder\Bolt\Bootstrap` for HTTP; the project CLI uses the same bootstrap. In an existing application's `public/index.php`, after upgrading its dependency:

```php
use Thunder\Bolt\Bootstrap;
use Tempest\Router\HttpApplication;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::boot(dirname(__DIR__))->get(HttpApplication::class)->run();
```

`Bootstrap` loads the project's `.env`, discovers `config/`, and boots Tempest with a common runtime directory. It adds no container, router or application lifecycle alongside Tempest. Embedded applications constructing `App` directly can keep their own host and configuration.

The generated HTTP entry point also retains a legacy Tempest fallback for explicitly pinned older packages that do not provide `Bootstrap`. That fallback does not add runtime relocation or session-key commands to an old library; upgrade the app dependency to use these additions.

## Relocate runtime state

```dotenv
BOLT_RUNTIME_PATH=/var/lib/my-slack-app
```

The default is `<project>/.thunder-bolt`. Relative values resolve from the project root, including when PHP's HTTP working directory is `public/`. Process environment overrides `.env`; an explicit PHP `runtimePath` argument overrides the environment:

```php
$container = Bootstrap::boot(
    root: '/srv/my-slack-app/current',
    runtimePath: '/var/lib/my-slack-app',
);
```

Use the environment setting when HTTP and CLI must share a deployment configuration. The explicit argument is useful for an embedded host; configure its workers consistently too.

| State | Default below the runtime directory | Optional override |
| --- | --- | --- |
| OAuth installations and state | `oauth/` | `SLACK_INSTALLATION_STORE_PATH` |
| File conversation context | `conversations/` | `SLACK_CONTEXT_STORE_PATH` |
| SQLite conversation context | `conversations.sqlite` | `SLACK_CONTEXT_STORE_PATH` |
| Durable jobs | `jobs/queue.sqlite` | `SLACK_JOB_STORE_PATH` |
| Worker observations | `runtime/` | Bind your own `RuntimeMonitor` |
| Tempest discovery, sessions and other internal state | Managed by Tempest | Shared runtime setting |

Store overrides also resolve relative to the project root. A custom adapter owns its own storage choices. Keep any custom in-checkout state out of Git; the generated ignore file only knows the default `.thunder-bolt/` location.

Bootstrap refuses a runtime inside `public/`, including disguised parent segments and symlinks, and requires a writable directory or parent. Newly created runtime directories use private permissions. Operators own permissions on existing directories. `doctor` inspects runtime and individual store locations; `--workers` also requires job prerequisites. An existing HTTP entry point that calls Tempest directly must be updated to participate in the shared setting.

A read-only production release can now use a separate writable state directory without a release-local symlink. Supply the same environment to PHP-FPM and supervised workers. Use a production HTTP host with document root `public/`; `bolt serve` remains a development server. Separate code caches by release, or clear/rebuild discovery caches with old workers stopped when reusing a persistent runtime. Back up durable state consistently, keep encryption keys separately, and verify recovery before deployment.

For public browser routes, set `BASE_URI` to the actual HTTPS origin as well as `ENVIRONMENT=production`. The generated local `BASE_URI` is a development default, not a production URL; it must match the host's URL generation and cookie security settings.

## Preserve intentional HTTP errors

Custom routes keep their returned status, body and headers, just like Slack receiver routes:

```php
use Tempest\Http\GenericResponse;
use Tempest\Http\Status;
use Tempest\Router\Get;

final readonly class ConnectionPage
{
    #[Get('/connect/expired')]
    public function expired(): GenericResponse
    {
        return new GenericResponse(
            Status::GONE,
            '<h1>This connection link has expired.</h1>',
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }
}
```

A deliberately returned 4xx/5xx no longer becomes Tempest's generic error page. Thrown exceptions still go through Tempest's exception handlers and renderers. Route-binding failures remain HTTP 404 responses. If an application relied on the old conversion, throw the appropriate exception when it wants exception rendering. Set `ENVIRONMENT=production` wherever requests may contain credentials; local diagnostic pages are for trusted development.

## Optional encrypted browser sessions

Slack request verification uses `SLACK_SIGNING_SECRET`. Tempest's encrypted cookies and sessions use a separate `SIGNING_KEY`. Slack OAuth's built-in state/cookie flow is stateless and does not require the latter. Custom account-connection pages using Tempest sessions do.

For a local `.env`:

```sh
cp .env.example .env
php bolt key:generate
php bolt doctor --sessions
```

The command generates a key matching Tempest's configured encryption algorithm, saves it atomically with private file permissions, and never prints it. Existing nonempty keys stay unchanged. It refuses a missing or symlinked `.env`; manage symlinked/external secrets through their provider. Rotation also affects any other data encrypted with this key; it does not rotate Slack credentials.

```sh
# Intentional rotation only: existing encrypted cookies/sessions become unreadable.
php bolt key:generate --force
```

`doctor --sessions` validates the configured key without displaying it. Its JSON report adds `sessionsReady`; this is `null` when not requested. A session-key failure does not falsely mark Slack's HTTP configuration unconfigured. Plain `doctor` continues to work for Slack apps without browser sessions.

For production, generate and inject the key through the operator's secret-management process, keep it stable across workers/releases, and use HTTPS with appropriate cookie settings. Session storage does not automatically authorize a Slack user or add CSRF protection to a custom form: bind a short-lived grant to the intended principal, validate CSRF, and avoid exposing credentials or bearer links in logs and URLs.

The [Tempest cookie documentation](https://tempestphp.com/3.x/essentials/routing#cookie-management) describes the underlying encrypted-cookie behavior. The disposable `composer test:onboarding` check exercises a real browser-session cookie round-trip, custom HTTP 410, production exception rendering and shared HTTP/CLI storage without contacting Slack.
