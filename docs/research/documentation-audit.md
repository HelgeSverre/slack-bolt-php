# Documentation and onboarding audit — 2 November 2026

This audit checks the repository's current `main` documentation against handwritten runtime code, CLI behavior, and current Slack app settings. It does not turn historical research notes into current operating instructions or claim full Bolt JS parity.

## Maintained coverage

| Adopter need | Current entry point |
| --- | --- |
| Requirements, install, local/VCS scaffolding, first listener | [Getting started](../guides/getting-started.md) |
| Manifest import, tokens, HTTP/Socket settings, OAuth, feature prerequisites | [Slack app setup](../guides/slack-app-setup.md) |
| Handlers, acknowledgements, context, middleware, errors, messages and client calls | [Building apps](../guides/building-apps.md) |
| Durable state, named jobs, cancellation, recovery and operations | [Durable operations](../guides/durable-operations.md) |
| Installation stores, state validation and bot/user token rotation | [OAuth](../oauth-and-authorization.md) |
| Actions, shortcuts, external options and modal validation | [HTTP interactivity](../http-interactivity.md) |
| Typed SDK, Block Kit, uploads, pagination and retries | [Web API client](../web-api-client.md), [SDK and Block Kit](../sdk-and-block-kit.md) |
| Assistant threads, modern sessions, workflow functions, Work Objects and metadata | [Advanced features](../guides/advanced-slack-features.md) |
| Tests, secrets, diagnostics and common failures | [Testing and troubleshooting](../guides/testing-security-troubleshooting.md) |
| Production process layout and health checks | [Deployment example](../../examples/deployment/README.md) |
| Contributor checks and generation policy | [Development](../development.md) |

The [documentation index](../README.md) separates these guides from dated research and captured third-party reference material. The root README remains a compact overview, with links into the maintained guides.

## Slack settings verification

The [verification notes](slack-app-setup-verification.md) record official Slack sources and the configuration-to-runtime matrix. Eleven screenshots capture the existing development app's current UI, including manifest creation, Socket Mode, a blank app-level token form, events, interactivity, slash-command creation, OAuth redirects, App Home, Agents, Work Object previews and workflow prerequisites. No settings were saved, credentials generated, or installation permissions changed. Saved screenshots exclude secrets.

The guide uses current sidebar labels and warns that Slack mixes old and new settings URLs. Broad demo permissions are separated from the minimal generated manifest. Bot/user OAuth refresh is distinguished from app-level token rotation, which this framework does not manage.

## Corrected drift and remaining boundaries

- Conversation storage and playground descriptions now acknowledge the implemented assistant runtime and durable agent jobs.
- Current Agents context/Agent View configuration is explicitly distinguished from the retained `assistant_thread_*` lifecycle. The legacy sample uses the legacy status API; modern sessions need their own prerequisites.
- Custom functions need remote-runtime configuration and `function_executed`, plus workspace eligibility. The broad demo manifest alone does not complete that setup.
- Function-only Socket Mode can use delivery-scoped workflow tokens; ordinary bot traffic still needs installation authorization.
- Sending message metadata does not configure inbound metadata subscriptions. Work Object unfurl domains and authorization remain application configuration.
- At the initial audit snapshot the repository was private and unpublished. The subsequent public package is `helgesverre/slack-bolt`, retaining `Thunder\Bolt` imports; use the root README for installation. Path scaffolding uses the checkout; VCS scaffolding requires an explicit repository and currently `dev-main`. Old tags lack the generated application's setup API.

## Ongoing verification

`npm run verify:docs` checks local links/images/anchors across owned Markdown, plus maintained PHP imports, snippet syntax and public factory/constructor references. Historical research receives link checks only. Third-party archives, external URL availability, instance-method chains, argument semantics and runtime behavior require separate review. The tooling has regression tests and runs in Linux/macOS CI.

`composer test:project` verifies a fresh production-dependency path installation. `composer test:vcs-project` snapshots current package inputs into a disposable Git repository, installs through Composer's VCS resolver, and detaches the seed repository before testing discovery and offline diagnostics. Both use synthetic credentials and do not contact Slack or publish a package. Focused CLI integration tests cover source validation and option parsing; the full suite, Mago and generated-reference checks remain independent gates.

Local validation for this slice: 1,037 PHPUnit tests / 6,232 assertions; 12 Node tooling tests; 75 owned Markdown files with 674 local links, 114 PHP imports, 43 parsed PHP snippets and 63 factory/constructor references. Fresh path and VCS installations, generated/transport/parity inventories, and the disposable Docker deployment passed. Mago formatting, analysis and lint gates passed with their existing nonfatal warnings. These local results do not establish live Slack behavior or a remote CI result.

Clean-checkout CI exposed historical citations into ignored local `work/` and installed `vendor/` trees. They now use pinned upstream source permalinks; the checker rejects links to local generated/private state even when those targets happen to exist. Windows Git fixture cleanup explicitly makes disposable object files writable before deleting them.
