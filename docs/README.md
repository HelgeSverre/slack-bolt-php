# Thunder Bolt documentation

Thunder Bolt is an experimental PHP 8.5+ Slack framework built on Tempest 3. The public Composer package is [`helgesverre/slack-bolt`](https://packagist.org/packages/helgesverre/slack-bolt), with the PHP namespace `Thunder\Bolt`. Use `^0.3` for the current experimental release, or `dev-main` explicitly for development. Start with the guides below; the older research material is deliberately kept separate so an adopter does not need to treat a dated audit as current operating guidance.

## Start here

1. [Getting started](guides/getting-started.md) — create a project, select HTTP or Socket Mode, configure local state, and run the first checks.
2. [Slack app setup](guides/slack-app-setup.md) — the current Slack UI steps, manifest import, tokens, request URLs, and feature-specific capabilities.
3. [Build handlers](guides/building-apps.md) — events, commands, interactions, acknowledgements, context, middleware, errors, Block Kit, and the Web API client.
4. [Run durable work](guides/durable-operations.md) — conversation state, named SQLite jobs, cancellation, deployment, and operational checks.

## Choose a feature

| Need | Read |
| --- | --- |
| OAuth for multiple workspaces, installation storage, token refresh | [OAuth and authorization](oauth-and-authorization.md) |
| Buttons, shortcuts, external selects, and modals | [HTTP interactivity](http-interactivity.md) |
| Durable channel and thread state | [Conversation and thread storage](conversation-and-thread-storage.md) |
| Typed methods, builders, uploads, pagination, and retries | [Web API client](web-api-client.md) and [Block Kit](sdk-and-block-kit.md) |
| Assistants, agent sessions, custom functions, or Work Objects | [Advanced Slack features](guides/advanced-slack-features.md) |
| Testing application handlers with fake Slack and durable-intake assertions | [Public testing toolkit](guides/testing-toolkit.md) |
| App Home, channel lifecycle, and message-metadata payloads | [Typed events](guides/typed-events.md) |
| Tests, secrets, local diagnostics, or common setup failures | [Testing, security, and troubleshooting](guides/testing-security-troubleshooting.md) |
| Deploying Socket Mode and durable workers with Compose | [Deployment example](../examples/deployment/README.md) |

## Maintainer material

[Development](development.md) covers Mago, PHPUnit, and the reference generators. [Architecture](architecture.md) explains the framework seams and PHP-specific choices. `docs/research/` contains dated source audits, parity inventories, and captured upstream material. It is useful when maintaining the port, but it is not the canonical setup guide and may describe a historical milestone.

The root [README](../README.md) is the compact capability overview. [UPGRADING](../UPGRADING.md) is the migration record for changes that need an operator action.
