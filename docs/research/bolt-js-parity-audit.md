# Bolt JS parity audit

**Current assessment — 3 November 2026:** refreshed for v0.5.0 and the Agent View starter/durable demo. The pinned Bolt JS baseline remains 5.1.0; this is not a new audit of moving upstream HEAD. Practical legacy contracts remain covered or explicitly adapted, broad inbound native typing remains partial, and Node-specific exclusions remain intentional. Modern Agent View is recorded as a PHP extension without extra baseline parity credit. See the [current matrix](bolt-js-parity-matrix.md) and [Agent View acceptance record](agent-view-starter-validation.md).

The material below is the historical 26 October baseline and its follow-ups. Its P1/P2 recommendations have been implemented; they are not the current backlog.

## Historical audit — 26 October 2026

**28 October follow-up:** the first three gaps below now have PHP implementations: explicit static-token identity verification/hydration, delivery-local Context and structured failure context/policy. See the [implementation and evidence](delivery-context-identity-errors.md) and refreshed [matrix](bolt-js-parity-matrix.md). The dated findings and counts below describe the v0.1.0 baseline; the current matrix records the changes. Identity and receiver policies deliberately retain explicit PHP execution boundaries.

**Second 28 October follow-up:** composable message filters, configurable workflow-token attachment and sanitized SSL probes are also implemented. [Behavior and evidence](message-filters-function-policy-ssl.md). Those were the next priorities at that checkpoint. The v0.2 follow-up below records their implementation.

**Historical v0.2 verdict:** the planned practical compatibility slices are implemented with behavioral evidence. Complete native typing of the Slack event universe remains narrower, and deliberate PHP adaptations/exclusions remain. Literal API parity is neither achieved nor the right PHP target. No percentage is claimed. The dated baseline findings below are preserved for traceability; see the v0.2 follow-up and current matrix for their disposition.

This audit compares PHP revision [`21192af`](https://github.com/HelgeSverre/slack-bolt-php/tree/21192afee5efbbd35f90bbcf3e6b07858f5227b2) (the v0.1.0 preview) with `@slack/bolt` **5.1.0**, pinned at [`ffbe0b6…`](https://github.com/slackapi/bolt-js/tree/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d). It does not claim parity with a moving upstream release or every Slack API. The [upstream research](bolt-js-parity-upstream.md) cites the primary source contract; the [detailed matrix](bolt-js-parity-matrix.md) links PHP implementation and behavioral tests. The [machine-readable manifest](bolt-js-parity.json) is the reviewed assessment.

## Scope and method

- Accounted for all **30 AppOptions**, **19 distinct public App member names** (overloads grouped), **15 built-in middleware exports**, and **46 extracted source modules**.
- Reviewed **33 capability areas**: 11 covered, 8 adapted to PHP, 6 partial, 3 missing, 3 recommended exclusions and 2 PHP extensions. Extensions earn no baseline parity credit. These are audit groupings of unequal size and importance, not a completeness score.
- Used handwritten runtime source and behavioral tests as evidence. The 359-export inventory and 126 scaffold declarations are navigation aids, not runtime implementations. Types/re-exports are grouped by behavior rather than counted as missing features.
- Ran offline probes for static-token identity, custom middleware context and error-handler arguments. Existing tests establish captures, retry metadata, per-installation clients, function terminal guards and the supported response families.
- This is a static/source-and-fixture audit with narrow probes, not a differential run of every upstream test. Enterprise and paid-plan fixtures do not become live verification by being listed. The 273-method Web API audit is separate from inbound Bolt behavior.

## Historical v0.1.0 gaps (subsequently addressed by the follow-ups)

| Priority | Gap | Baseline limitation | PHP acceptance criterion |
| --- | --- | --- | --- |
| P1 | Static-token identity and verification | `ignoreSelf` needs known bot identity. Token-only configuration neither verifies the token nor hydrates `botUserId`/`botId`; static `BoltConfig` has no `botId`. | An explicit startup/health-check identity resolver, cached per configured credential, supplies both bot identifiers and reports invalid credentials. No network work during help/discovery. Cover bot messages lacking a `user` field as well as normal self messages. |
| P1 | Delivery-local middleware context | `Delivery` is readonly and `next()` cannot forward a replacement. There is no request-local place for middleware to attach fetched records, tracing or claims. | A typed context capability shared by one delivery's middleware/handlers and isolated from subsequent deliveries/tenants. Do not use persistent conversation state to carry live services. |
| P1 | Structured error context and policy | `App.error` receives aggregate failures, parsed payload and metadata; selected listener, full delivery identity and acknowledgement state are absent. Receivers use fixed response/error behavior. | A redacted failure context with listener/delivery/stage information and explicit handling policy, preserving original failures and durable-intake failures. Do not expose Node request/response callbacks. |
| P2 | OAuth installation controls | The basic v2 flow is implemented, but `Installer.begin()` has no per-install metadata, team hint or scoped request options, and built-in success/failure hooks/pages/paths are fixed. | Typed installation-request options with browser-bound metadata round trip and success/failure hooks. Keep custom route ownership in the host. Treat classic OAuth v1 and cosmetic install pages as demand-led rather than prerequisites. |
| P2 | Function token-attachment policy | Workflow tokens always take precedence. Bolt allows `attachFunctionToken: false` to retain the normal installation token. | Explicit configuration and tests for default workflow-token use and opting out, including the no-installation-token case. Authorization policy must remain enforced. |
| P2 | `subtype` and `directMention` conveniences | No packaged reusable filters; user middleware can inspect raw messages. `app_mention` event routing is not a substitute for a direct-mention filter on message events. | Small composable PHP filters, tested against upstream message-subtype and mention parsing behavior. No need for separate copies of every `only*`/matcher export. |
| P2 | SSL-check probe | `ssl_check=1` has no receiver branch. Bolt's HTTP receiver sanitizes it and responds without dispatching the app. | Recognize only the narrow probe shape, return empty 200, and prove extra fields cannot reach listeners or bypass normal signature verification. |
| P2 | Host-supplied context factory | `dispatch(body, metadata)` accepts caller metadata, but stock receivers expose no custom-property extractor. | Optional HTTP/Socket Mode context factory feeding the delivery-local context above; avoid exposing raw server globals or serializing live request objects into jobs. |
| P3 | Broader inbound DTO ergonomics | Many action variants, view-state values and event types remain raw/generic. Their routing exists. | Add typed accessors/DTOs for demonstrated application needs, preserving unknown fields. Do not replicate every TypeScript union just for class counts. |

P1 is the next compatibility slice. P2 is useful behavior or ergonomics to schedule after the shared seams. P3 is incremental typing/coverage work.

## Capabilities already present

Event/message/command routing, interaction constraints and response families, middleware ordering, regex captures, retry metadata, trusted ingress, installation-scoped authorization, OAuth v2/state/stores/rotation, conversation state, HTTP verification and Socket Mode delivery have real implementations and tests. Assistant lifecycle/context/status/title/prompts/streaming and custom function inputs/completion are implemented too. See the matrix for each claim's limits and evidence.

In particular, these are **not missing**:

- `App::dispatch(array $body, array $metadata = [])` already replaces `processEvent` for a trusted parsed delivery.
- `Delivery::matches` and `constraintMatches` already preserve regex captures. HTTP and Socket Mode already provide retry metadata.
- `Execution` already guards duplicate and uncertain `complete`/`fail` calls within a delivery. Cross-process terminal fencing would be a stronger durability feature, not something Bolt JS itself guarantees.
- Function callback IDs are string-only in the pinned Bolt version; PHP's string-only registration matches it.
- Arbitrary Slack events can use the generic event payload and `raw`, even without a dedicated DTO.

## PHP adaptations to keep

| Upstream interface/behavior | PHP choice | Assessment |
| --- | --- | --- |
| `App.start/stop`, HTTP server options, custom routes | PHP host/Tempest owns HTTP; runners own CLI worker lifecycle. `Receiver.handle` and `App.dispatch` support custom hosts. | Keep. Add concrete host adapters only when needed. |
| `ack()` and early-response continuation | One typed returned acknowledgement after inline processing; slow work is explicit named-job intake. | Keep, with a documented timing difference. This does not enforce the three-second deadline for arbitrary listeners or provide Bolt's early-ack mode. |
| Concurrent Promise listener execution | Sequential PHP listener pipelines, aggregated independent failures. | Keep as the synchronous model. Concurrent execution would need a separate semantics/design decision. |
| Generic `context` plus `say`/`respond` functions | Typed payload, `Delivery`, `Reply`, authorization/state/helper injection. | Keep; fill the custom request-local context gap without replacing typed capabilities. |
| Signed state-secret serialization | Opaque one-time state plus browser binding and durable state stores. | Keep. Matching the JS state format is unnecessary. |
| Channel-only conversation store | Installation/conversation/thread identity and memory/file/SQLite adapters. | Keep stronger scoping. |
| `@slack/logger` and `clientOptions` | PSR-3 logging, PSR-18 transport, explicit retry policy. | Keep. Public property names and Node options need no replicas. |

Do not add Express middleware, Node server/buffer types or AWS Lambda JavaScript event/callback classes. Do not port TypeScript utility types, Promise helpers, every error class name or SDK/logger re-exports. Do not add a signature-verification off switch merely because Bolt exposes one. A deployment that authenticates with mutual TLS would need an explicit verified host adapter, not silently unsigned application requests. Developer-mode presets are optional convenience; full-payload debug logging is not a parity target.

Legacy dialog/action/options forms are already supported where they fit the classifier. The outbound legacy `WorkflowStep` view builder is not a workflow-step execution runtime; it must not be presented as one. New custom functions are the supported workflow execution path.

## Reproduced behavior

These probes used only a recording HTTP transport and literal fixture credentials; no Slack API or private token file was involved:

| Probe | Observed result | Interpretation |
| --- | --- | --- |
| Fixed token, default `ignoreSelf`, no bot identity; deliver a message from that bot | Listener ran once; zero HTTP requests | No automatic identity lookup/filtering from the token alone. |
| Same fixture with `botUserId` configured | Listener did not run; zero HTTP requests | The self-filter exists; the gap is obtaining/providing dependable identity. |
| Global middleware writes `Delivery.metadata['custom']` | PHP `Error`: cannot indirectly modify readonly `Delivery::$metadata` | Metadata is not a mutable custom context seam. |
| Throwing listener handled through `App.error` | Three arguments: `HandlerFailures`, parsed `Message`, metadata containing `eventId` | Base error handling exists; full delivery/listener context is absent. |

Existing tests separately prove bot-id self-filtering through an authorizer, regex capture isolation, retry propagation, tenant-scoped clients and function completion guards. The probe results therefore do not erase those implemented capabilities.

## Historical recommended slice and exit criteria

Implement **delivery context and failure context**, plus **explicit static-token identity resolution**. These share invocation/authorization seams and help real applications more than new demos. Then add the small function-policy/filter/SSL compatibility pieces, followed by typed OAuth install options. Keep the current PHP host/acknowledgement model unless a concrete async host requires a different one.

Re-audit the manifest when each gap closes. A row moves to covered only with source plus behavioral evidence; an adaptation needs an explicit replacement and documented constraint. Use `npm run verify:parity` to detect missing AppOptions/member/built-in/module mappings and stale evidence paths. The command checks bookkeeping and generated-document consistency, not semantic correctness.

Audit validation passed **840 tests / 5,254 assertions**, seven reference-tooling tests, generated/transport verification and the new parity inventory check. These remain useful validation, not a certificate of complete parity. No runtime fixes or new Slack messages were part of this audit.

## v0.2 preview follow-up — 28 October 2026

The next practical slices are implemented: [typed installation requests, atomic state, hooks and reusable host routes](oauth-install-requests.md), [HTTP/Socket context factories](transport-context-factories.md), and [typed action/form value readers](typed-interaction-values.md). The [release validation record](v0.2-validation.md) traces them through a freshly generated application to durable recovery in a new worker process. Current status is in the generated matrix; the numbered findings below preserve the historical audit baseline.

This closes the planned practical slices without claiming complete native typing of every Slack event or every nested object. The payload typing row remains partial for that breadth. Rendered install pages, state-free direct installation, classic OAuth v1 and Node host adapters remain deliberate omissions/adaptations, not release prerequisites. The updated matrix records source and behavioral evidence rather than counting reference declarations as runtime coverage.
