# Implementation plan

Updated 23 October 2026. The target remains capability and protocol parity with the pinned Bolt JS framework, expressed through idiomatic PHP. HTTP events, interactivity, installation, authorization, and conversation/thread storage now run alongside a broad source scaffold; this does not establish full framework parity.

## Agreed foundation

- PHP 8.5+, Tempest 3, and the provisional `Thunder\Bolt` namespace. Composer locks the tested dependency set, including Tempest 3.20.0.
- Readonly payloads and message values, named factories, immutable builders, method attributes, and chainable listener registration. Application dependencies use constructor injection; delivery data is passed to handler methods.
- A package-owned Slack Web API client and Block Kit implementation. Generated names preserve Slack's method hierarchy; endpoint models and specialized builders follow the pinned source schemas.
- HTTP handlers execute inline by default. Commands and interactions return one response value from their own family; reply helpers make outbound Web API or response-URL calls. Optional workers and Socket Mode are later adapters.
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

## Remaining implementation order

1. **Runtime adapters.** Add Socket Mode lifecycle/reconnect/acknowledgements, optional durable workers, serverless receiver equivalents, and configurable receiver lifecycle/error handling. Gate on adapter integration tests, including timeout, retry, reconnect, and shutdown paths.
2. **Assistants and custom functions.** Add assistant listener families, metadata-backed thread context, streaming, and custom-function completion/failure. Gate on callback, authorization, acknowledgement, and streaming fixtures.
3. **Compatibility and release validation.** Expand the upstream conformance matrix, finish migration documentation and CLI templates for new families, run Linux/macOS CI and controlled Slack-workspace smoke tests, and address measured CLI startup performance. Cached help currently measures about 0.36 seconds on Herd; the 50 ms target is still open.

Each milestone updates the [parity map](api-parity.md) with implemented behavior and its evidence. Full parity requires behavior coverage for every retained feature family; generated symbol counts alone cannot close a milestone. The initial implementation has Linux/macOS CI configuration, but no remote CI run or release has been performed from this checkout.
