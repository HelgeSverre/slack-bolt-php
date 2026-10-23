# Framework, runtime, and CLI research

## Tempest — 🟢 source/version, 🟡 adoption details

The project uses [Tempest 3.20.0](https://github.com/tempestphp/tempest-framework/releases/tag/v3.20.0), released on 22 October 2026. It replaces the earlier 3.19.2 plan because that exact release could not resolve its required `nette/php-generator` 4.2.1; 3.20.0 resolves the corrected dependency. Tempest's container/discovery style is useful for attribute registration, but Bolt's protocol pipeline remains framework-owned and testable without a web server.

## Laravel — 🟡 inspiration only

[Laravel framework source](https://github.com/laravel/framework) and [documentation](https://laravel.com/docs) inform fluent factories, configuration defaults, exceptions, and command ergonomics. Application dependencies are constructor-injected. Slack payloads and reply capabilities are delivery-scoped and passed into handler methods.

## Runtime — 🟡 first slice

The inline receiver verifies Slack before decoding, then dispatches a trusted envelope and returns a buffered HTTP response. Generated Web API groups are stateless facades over the root `Client`. Initial rate-limit and pagination contracts stay explicit: `RateLimited(retryAfter)` exposes the decision and `paginate()` is a generator. Automatic retry is deliberately absent from this slice.

## CLI — 🟢 first-slice implementation

The package provides `bin/bolt`; generated projects expose `php bolt` and `php vendor/bin/bolt`. Commands create applications and explicit event/message/slash-command listeners, list discovery results, check local configuration, start a development server, manage discovery caches, and emit project-local shell completions. Scaffolding leaves the generated SDK tree untouched. Attribute discovery and fluent registrations share duplicate-registration diagnostics. The CLI does not replace Slack's platform CLI or install applications into a workspace.
