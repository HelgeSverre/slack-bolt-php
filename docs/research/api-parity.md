# API parity map

Current inventory: [24 October 2026 codebase audit](codebase-inventory-2026-10-24.md), followed by the [CLI/payload gap closure](gap-closure-and-async-audit.md). This summary distinguishes handwritten runtime behavior from generated migration declarations.

## Bolt application surface — 🟢 inventory, 🟡 implementation status

The generated [Bolt surface](bolt-js-api-surface.md) lists every extracted export grouped by source module, and every public `App` member and `AppOptions` property with its source type. The source-of-truth machine catalog is [typescript-api-catalog.json](typescript-api-catalog.json); [api-parity-inventory.json](api-parity-inventory.json) is a small index for tools and reviews.

| Bolt JS family | PHP target | Implementation status |
| --- | --- | --- |
| `App` construction and event/message/command listeners | `Thunder\Bolt\App`, typed payloads, attributes and fluent registration | 🟡 working first slice; full parity pending |
| middleware / listener matching / context | invocation pipeline and delivery-scoped reply helper | 🟡 working first slice; full parity pending |
| HTTP receiver / signing / URL verification | `Http\Receiver` with inline buffered acknowledgement | 🟡 working first slice; other receiver protocols pending |
| `client` Web API calls | `WebApi\Client` plus generated resource groups | 🟢 typed models, named resources, multipart, uploads, opt-in retries; [scope](sdk-block-kit-audit.md) |
| shortcuts, actions, options, views | readonly payloads, attributes/fluent selectors, typed acknowledgements, response-URL replies | 🟢 implemented for HTTP; [scope and evidence](http-interactivity-audit.md) |
| OAuth installation / token rotation | OAuth v2 installer, one-time state, file/memory stores, bot/user grant rotation and scoped authorizers | 🟢 HTTP milestone implemented; [scope](../oauth-and-authorization.md) and [source mapping](oauth-authorization.md) |
| Socket Mode | `SocketMode\Runner`, Amp transport, `socket:serve` | 🟡 implemented with explicit durable job intake; full protocol conformance remains open |
| assistants and agent sessions | assistant lifecycle listeners, scoped context stores, typed session status/title, stop events and native streaming | 🟢 pinned assistant-thread compatibility retained; modern lifecycle and durable AI cancellation implemented; [session notes](agent-sessions.md) |
| custom functions | callback listeners, typed inputs/DTOs, execution context and complete/fail | 🟢 runtime and protected example handoff implemented; temporary credentials still require an explicit protected reference for named jobs; live paid-plan validation remains open |
| Durable execution | Named readonly jobs, Tempest handlers, SQLite store, worker/operations CLI | 🟢 implemented with atomic claims, bounded opt-in retries, uncertainty-aware recovery and cooperative cancellation; [evidence](durable-execution-audit.md) |
| Work Objects | entity builders, details/unfurls, comments and mutation-provider interface | 🟢 implemented with fixtures and a playground; provider authorization and external storage remain application responsibilities |
| Typed event ergonomics and scaffolding | Native reaction/membership/link/file/lifecycle/agent-session payloads; generic fallback; assistant scaffold | 🟢 added beyond the original payload slice; stop/title-change routing is not a complete cancellation runtime |
| conversation store / middleware | delivery-scoped conversation state plus thread extension; memory, file, SQLite adapters | 🟢 implemented; [guide](../conversation-and-thread-storage.md), [source mapping](conversation-storage.md), [audit](conversation-storage-audit.md) |

The HTTP interaction matrix covers `block_actions`, `shortcut`, `message_action`, `block_suggestion`, `view_submission`, `view_closed`, and retained legacy `interactive_message`, `dialog_submission`, and `dialog_suggestion` forms. Selector normalization and classification follow pinned Bolt JS 5.1.0, including the first action, legacy suggestion precedence, and submission-only view shorthand. See the [source mapping](http-interactivity.md) and [PHP API guide](../http-interactivity.md). `dialog_cancellation` is unsupported by that upstream classifier and receives an explicit `501`.

PHP listeners return response values and perform explicit durable intake inline; named job handlers run in independent workers. Socket Mode reuses App dispatch and adds envelope acknowledgements, reconnects, connection health, and shutdown handling. Durable intake is verified before acknowledgement on both transports; this does not guarantee arbitrary listener code or disk contention fits Slack's deadline. Local fixtures, transport tests and selected demonstrations do not establish exhaustive live-workspace conformance.

## Generated Web API contract — 🟢

`Client` uses `Generated\WebApi\HasResources`. Its 35 pure root accessors return fresh readonly resource groups; dotted paths become nested resource calls. The generator creates 98 resources covering 273 endpoints: 271 direct pinned endpoints plus two official-documentation Work Objects supplements. This includes the 12 `slackLists` endpoints missed by the initial camel-case-root extraction. Named methods retain `array|RequestPayload` compatibility and return endpoint-specific native response models through `Client::callTyped()`.

The generated request classes cover each endpoint's flattened arguments. Responses expose native properties and recursively hydrated shared object models where the source can be represented soundly. Polymorphic, indexed, and unresolved TypeScript constructs remain raw; all future response fields remain in `data`. The [model coverage inventory](web-api-model-coverage.json) records resolved models and individual fallback paths. This is distinct from exhaustive Slack cross-field validation.

`files()->uploadV2(Upload ...$uploads)` delegates to the PHP upload helper; it never posts to a fabricated `files.uploadV2` endpoint. Uploads reserve, transfer, and complete with partial-failure progress and caller-owned-stream protection. JSON, OAuth forms, and multipart fields share the PSR-18 transport. Retries are bounded and opt-in; OAuth, response URLs, and content transfers remain single-attempt. See the [client guide](../web-api-client.md).

Message, attachment, modal/home, rich-text, composition, and specialist Block Kit builders are mapped in the [builder coverage inventory](block-kit-coverage.md). Raw extension points remain explicit rather than being counted as dedicated builders.

## Quick-transpilation declarations — 🟢 generated, 🔴 non-runtime

`src/Scaffold/Bolt` contains 126 source-linked PHP declarations for every extracted Bolt class and interface, including App, receivers, error families, stores, assistants, custom functions, and structural `*Options`/`*Config` DTOs. Every scaffold declaration and the export map carry `#[Tempest\Discovery\SkipDiscovery]`, so these planning artifacts cannot become live Tempest registrations. Class method bodies call `NotPortedYet::forSymbol()` and use `mixed ...$arguments` where translating TypeScript safely would require design work. The generated [scaffold disposition](scaffold-disposition.json) accounts for all 359 extracted exports: 28 class skeletons, 85 interface skeletons, 13 structural options/config DTOs, 147 declaration-only functions/types/enums, and 86 unresolved re-exports. Each map entry links its exact upstream source and, where applicable, its scaffold path.

## Required compatibility tests — 🟡

1. Use Bolt fixture envelopes to prove selector matching, global/listener middleware order, duplicate `next()` rejection, and independent listener failures.
2. Verify valid and invalid v0 signatures, five-minute timestamp boundaries, URL verification, retry metadata, and the HTTP acknowledgement deadline.
3. Exercise slash command, interactive, options, view, shortcut, event, assistant, and custom-function response forms.
4. Record Web API request method/path/body and compare direct generated calls with Slack's documented names; test 429 conversion and paginator termination without real Slack traffic.
5. Run protocol tests against OAuth install/callback/state and Socket Mode acknowledgement/reconnect once adapters exist.
