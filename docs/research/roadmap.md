# Implementation plan

Current ordering refreshed 28 October 2026; earlier milestone sections below retain their historical scope. The [Bolt JS parity audit](bolt-js-parity-audit.md) is the current compatibility assessment. The target is useful capability and protocol coverage of pinned Bolt JS 5.1.0 through idiomatic PHP, with explicit host adaptations and Node-specific exclusions. The v0.1.0 preview is tagged; further demos and package publishing should not displace the meaningful gaps found in that audit.

## Next compatibility slices

1. Completed 28 October: delivery-local Context, structured failure context/policy and explicit static-token identity verification/hydration. [Implementation and evidence](delivery-context-identity-errors.md). Help and discovery stay network-free.
2. Completed 28 October: workflow-token opt-out, composable subtype/direct-mention filters and sanitized SSL probes. [Implementation and evidence](message-filters-function-policy-ssl.md). Transport context factories were completed in the v0.2 work below.
3. Completed in v0.2 preview preparation: [per-install OAuth options, atomic request/state and hooks](oauth-install-requests.md), [typed transport context factories](transport-context-factories.md), [shared typed interaction readers](typed-interaction-values.md) and [fresh-project OAuth-to-job recovery coverage](v0.2-validation.md).
4. Completed: independent review, refreshed parity evidence, full Linux/macOS CI and the [v0.2.0 GitHub prerelease](https://github.com/HelgeSverre/slack-bolt-php/releases/tag/v0.2.0). [Completion evidence](v0.2-release-verification.md). Deeper event DTOs, additional adapters and a documentation site remain demand-led follow-ups.

Production operation (2 November): runtime observations, read-only status, supervised deployment and real process failure coverage are implemented; the two-hour local reliability run and all five CI jobs passed. See [the operations plan](production-operations-plan.md) for current evidence.

Keep existing `App::dispatch`, capture/retry metadata and function terminal guards. Do not add Node start/stop/Express/AWS interfaces for naming parity. Preserve typed returned acknowledgements, explicit named jobs, tenant/thread scoping, PSR logging and PSR-18 transport. The audit matrix records constraints; inventory counts alone are not implementation progress.

## Agreed foundation

- PHP 8.5+, Tempest 3, and the provisional `Thunder\Bolt` namespace. Composer locks the tested dependency set, including Tempest 3.20.0.
- Readonly payloads and message values, named factories, immutable builders, method attributes, and chainable listener registration. Application dependencies use constructor injection; delivery data is passed to handler methods.
- A package-owned Slack Web API client and Block Kit implementation. Generated names preserve Slack's method hierarchy; endpoint models and specialized builders follow the pinned source schemas.
- HTTP and Socket Mode listeners execute inline by default. Commands and interactions return one response value from their own family; reply helpers make outbound Web API or response-URL calls. Named jobs provide explicit durable background execution.
- A project-local CLI for application and listener scaffolding, discovery, diagnostics, development serving, and completions.

## Initial milestone: implemented and locally verified

| Requirement | Current implementation | Evidence |
| --- | --- | --- |
| Gather versioned references | Pinned Bolt, Web API, OAuth, Socket Mode, and Types sources; Slack documentation index; source hashes and licenses | [Source manifest](upstream-manifest.json), [research brief](research-brief.md) |
| Map the Bolt JS contract | Compiler-derived declarations, public `App` members, options, and export dispositions | [API surface](bolt-js-api-surface.md), [parity inventory](api-parity-inventory.json), [scaffold dispositions](scaffold-disposition.json) |
| Produce a first transpilation/scaffold | 126 source-linked declarations and 359 export mappings; unsupported constructs remain explicit | `scripts/extract-typescript.mjs`, `scripts/generate-scaffold.mjs`, `src/Scaffold/` |
| Start the real protocol implementation | Signed raw-body verification, URL challenge, Events API, message and slash-command routing, retry metadata, explicit unsupported-payload responses | `src/Http/`, `tests/Http/`, `tests/Integration/HttpControllerTest.php` |
| Implement the PHP handler interface | Typed payloads, method attributes, fluent registration, duplicate detection, middleware sequencing, independent listener failures, and unknown-event preservation | `src/App.php`, `src/Invocation/`, `tests/Runtime/` |
| Provide reply and message composition | Channel/thread/ephemeral destinations; incoming thread-root selection; immutable text/block messages and initial command responses | `src/Reply.php`, `src/Messages/`, `src/BlockKit/`, `tests/Messages/`, `tests/Runtime/AppTest.php` |
| Start the package-owned SDK | 259 direct method bindings across 34 root groups and 94 typed resources; JSON/OAuth-form transport, errors, token overrides, and lazy cursor pagination | `src/WebApi/`, `src/Generated/WebApi/`, `tests/WebApi/ClientTest.php` |
| Make projects easy to start | `new`, `make:listener`, `listener:list`, `doctor`, `serve`, discovery commands, and project-local completions | `src/Console/`, `stubs/`, `tests/Console/`, `examples/hello-bolt/` |
| Explain the PHP deviations with examples | Small typed handler methods, explicit reply destinations, returned command responses, immutable builders, constructor injection, and generators | [Architecture](../architecture.md), [design decisions](php-design-decisions.md), [README](../../README.md) |
| Establish local checks | PHPUnit, PHPStan level 6, Pint, strict Composer validation, generator consistency and ownership checks | `composer.json`, `tests/`, `test/tooling/` |

The generated declarations are migration scaffolds, excluded from Tempest discovery. At this initial milestone, generated SDK methods accepted arrays or `RequestPayload`; endpoint-specific models were added in the later SDK milestone. Local fixture tests do not prove full upstream conformance or a successful real-workspace installation.

The [initial implementation audit](implementation-audit.md) records the requirement checks, local validation results, fresh-project smoke test, and remaining limits.

## HTTP interactivity milestone: implemented and locally verified

| Requirement | Current implementation | Evidence |
| --- | --- | --- |
| Actions, shortcuts, options, and views | Readonly payloads, attributes, fluent registration, literal/regex/compound selectors, first-action matching, and explicit view closure handling | `src/Payloads/`, `src/Attributes/`, `src/Routing/`, `tests/Http/InteractivityTest.php` |
| Initial response parity | Family-specific returned responses; modal errors/update/push/clear; grouped/empty options; exactly one initial response | `src/Responses/`, `src/Invocation/AcknowledgementValidator.php`, `tests/Responses/` |
| Retained legacy forms | Interactive-message actions/suggestions and dialog submissions/suggestions; appropriate legacy message/options/error bodies | [Protocol reference](http-interactivity.md), signed fixture tests |
| Response-URL delivery | Respond, replace, and delete helpers; view response URL selection; opaque URL transport without a bot bearer token | `src/Reply.php`, `tests/WebApi/ResponseUrlTest.php` |
| CLI and cached discovery | Four new listener templates, type filters, cacheable regex/compound descriptors, and project-local completions | `tests/Console/`, `tests/Integration/BoltDiscoveryTest.php`, [CLI validation](interactivity-cli-validation.md) |
| Connected example | Mention button/update plus global shortcut, modal opening, external choices, and submitted input validation | `examples/hello-bolt/`, `tests/Examples/HelloBoltInteractivityTest.php` |
| Working HTTP integration | A fresh installed project handles ten signed cases over local HTTP, including retained legacy families and a cached regex attribute | [Milestone audit](http-interactivity-audit.md) |

The [HTTP interactivity guide](../http-interactivity.md) documents the current interface. Execution remains inline; handlers must meet Slack's three-second deadline. Real-workspace installation and remote CI remain release checks.

## Installation and authorization milestone: implemented and locally verified

The **installation and authorization milestone** implements OAuth v2 install/callback routes, browser-bound one-time state, file/memory installation stores, workspace/Grid and per-user selection, isolated delivery clients, atomic bot/user refresh, and explicit long-lived credential migration. Lifecycle events bypass credential lookup for cleanup. See the [API guide](../oauth-and-authorization.md), [source reference](oauth-authorization.md), and [validation audit](oauth-authorization-audit.md). Classic OAuth v1, state-free admin installation flows, and OpenID Connect remain outside this modern interface.

## Conversation and thread storage milestone: implemented and locally verified

`ConversationContext` and `ThreadContext` are loaded after authorization/self filtering and before global middleware, with method injection and delivery access. Store adapters implement portable JSON state, exact millisecond expiry, deletion, and atomic updates. Memory supports embedded applications; file storage is the Tempest default and SQLite is an optional durable adapter. Canonical installation/channel/root keys prevent tenant and thread collisions.

Local checks cover adapter contracts, root/nested-message derivation, unavailable contexts, restart persistence, parallel PHP workers for both durable adapters, and SQLite expiry-cleanup races. A fresh installed HTTP application exercised all four configured modes with cached discovery. See the [guide](../conversation-and-thread-storage.md), [source parity reference](conversation-storage.md), and [milestone audit](conversation-storage-audit.md).

Assistant-specific metadata context remains with the Assistant runtime milestone. Shared database adapters for deployments on independent hosts remain an extension of the implemented store contract.

## SDK and Block Kit milestone

The SDK now adds native endpoint request/response classes, nested response models with raw-field preservation, immutable message/attachment/view builders, pinned Block Kit families, streamed multipart fields, modern single/multiple external uploads, and bounded retry policies. The source audit also restored the 12 camel-case-root `slackLists` methods omitted by the initial extractor. Mago 1.50.0 replaces Pint/PHPStan for formatting, linting, and source analysis; no analyzer baseline hides existing errors.

See the [client guide](../web-api-client.md), [builder guide](../sdk-and-block-kit.md), [type coverage inventory](web-api-model-coverage.json), and [validation audit](sdk-block-kit-audit.md). Generated models do not imply exhaustive validation of every Slack cross-field constraint. Polymorphic and extensible raw values remain explicit; live Slack conformance and remaining SDK refinements stay release gates.

## Socket Mode milestone

`socket:serve` receives events, commands, and interactions through the same App dispatch pipeline. The runner handles Slack envelopes, hello/disconnect frames, bounded reconnect backoff, retry metadata, and shutdown. Amp provides TLS verification, WebSocket framing, message limits, and ping/pong health. Local tests cover lifecycle failures and installation/context isolation; live Slack conformance remains a release gate. See the [README](../../README.md#slack-configuration) for setup; deeper documentation is deferred to the separate docs site.

## Remaining implementation order

Durable named jobs are implemented: SQLite intake/deduplication, atomic claims/leases, bounded opt-in retries, uncertain recovery, cooperative cancellation, current authorization/state scopes and worker/operations scaffolds. See the [implementation audit](durable-execution-audit.md).

Modern agent sessions are implemented with typed lifecycle, session titles, stream completion states and stop handling. The AI demo now runs named durable jobs; persisted cancellation reaches model I/O and tool checkpoints. `/kickensink` provides an interactive tour. Pet, terminal and work-item spool entries remain application-local. See the [session integration notes](agent-sessions.md).

The first opt-in live transport slice is complete: five conversation read methods use documented GET/query encoding, pagination preserves filters and rejects cycles, and the Slack-metadata context adapter has a live persistence round trip. See the [transport audit](web-api-transport-conformance.md). This closes the specific `conversations.replies` gap found during the session demo.

1. **Compatibility and release validation.** Maintain a behavior matrix, targeted live-workspace checks and package installation/release verification. Linux/macOS CI already passes; paid-plan custom-function validation still needs an eligible workspace. Triage Mago advisories explicitly. Fresh named-job project installation is verified, but publishing/release automation and CLI startup optimization remain open.
2. **Demand-led adapters and operational growth.** Add deployment/shared-store adapters when needed. SQLite retention, schema migrations and operator history are implemented in the [operations milestone](job-operations.md). Deduplication receipts deliberately remain indefinite; any future key-expiry policy needs an explicit replay horizon.

Each milestone updates the [parity map](api-parity.md) with implemented behavior and its evidence. Full parity requires behavior coverage for every retained feature family; generated symbol counts alone cannot close a milestone. Remote Linux/macOS CI is verified in the current inventory. v0.1.0 is already published as a GitHub prerelease; v0.2.0 passed the explicit release gate above. Packagist publication remains separate.

The [application and async investigation](durable-execution-explained.md) led to the user's explicit-jobs/SQLite choice and the current implementation. Execution storage remains separate from conversation storage, and Tempest owns composition. A broad application superclass is not implemented. Earlier per-system interface sketches remain research; the README documents shipped methods.
