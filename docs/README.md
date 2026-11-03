# Thunder Bolt documentation

The documentation lives here as ordinary Markdown, versioned with the framework. Start with the guides, use the reference map to find a public interface, and open the examples when you want a runnable application.

Thunder Bolt requires PHP 8.5+ and Tempest 3. Install the public Composer package, [`helgesverre/slack-bolt`](https://packagist.org/packages/helgesverre/slack-bolt), without a version constraint for the latest compatible release. Application imports use `Thunder\Bolt`.

## Build your first app

1. [Getting started](guides/getting-started.md) — install, generate a project and run your first listener.
2. [Slack app setup](guides/slack-app-setup.md) — configure the Slack UI, tokens, scopes and delivery URLs.
3. [Building apps](guides/building-apps.md) — handlers, acknowledgements, replies, middleware and errors.
4. [Testing your app](guides/testing-toolkit.md) — signed fixtures, fake Slack and durable-job intake assertions.

## Guides by topic

| Topic | Guide |
| --- | --- |
| Buttons, shortcuts, external selects and modals | [HTTP interactivity](http-interactivity.md) |
| Message, attachment, view and Block Kit builders | [SDK and Block Kit](sdk-and-block-kit.md) |
| Typed requests/responses, pagination, uploads and retries | [Web API client](web-api-client.md) |
| Workspace installation, authorization and token rotation | [OAuth and authorization](oauth-and-authorization.md) |
| Channel and thread state, storage adapters and expiry | [Conversation and thread storage](conversation-and-thread-storage.md) |
| Named jobs, retries, recovery, cancellation and operations | [Durable operations](guides/durable-operations.md) |
| Agent View setup, root DMs and current focus | [Agent View](guides/agent-view.md) |
| Streaming text, tool progress, plans and blocks | [Streaming progress](guides/streaming-progress.md) |
| Legacy assistants, session controls, custom functions and Work Objects | [Advanced Slack features](guides/advanced-slack-features.md) |
| App Home, channel lifecycle and message metadata | [Typed events](guides/typed-events.md) |
| Credentials, diagnostics and common failures | [Security and troubleshooting](guides/testing-security-troubleshooting.md) |

## Reference

- [Public interface map](reference.md) — find the relevant class, source and usage guide.
- [CLI command overview](../README.md#cli-and-operations) — generators, diagnostics and workers; use `php bolt <command> --help` for the installed version's arguments.
- [Upgrading](../UPGRADING.md) — changes that need an application or operator action.
- [Changelog](../CHANGELOG.md) — release history.

## Runnable examples

| Example | What it demonstrates |
| --- | --- |
| [Playground](../examples/hello-bolt/README.md) | Agent View, durable AI, typed progress, native Stop, a shared pet, App Home and Work Objects |
| [Deployment](../examples/deployment/README.md) | Socket Mode and durable workers in supervised containers with persistent state |

## Framework development

[Development](development.md) covers Mago, PHPUnit, generators and documentation checks. [Architecture](architecture.md) explains the framework's responsibilities and PHP interface decisions.

The [parity audit](research/bolt-js-parity-audit.md) and [roadmap](research/roadmap.md) record compatibility and project direction. Other files under `research/` preserve dated decisions, experiments and upstream references; follow maintained guides for current setup instructions.

When reading on GitHub, select a release tag to see the documentation shipped with that release. Changes to guides and implementation travel in the same commits and pull requests.
