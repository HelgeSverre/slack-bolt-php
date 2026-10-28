# API parity map

The source-backed [26 October 2026 Bolt JS parity audit](bolt-js-parity-audit.md) compares v0.1.0 with **Bolt JS 5.1.0** at `ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d`. Its first three gaps were addressed on 28 October: [identity, request context and failures](delivery-context-identity-errors.md). The v0.2 preview adds [installation requests/hooks](oauth-install-requests.md), [transport factories](transport-context-factories.md), [typed form readers](typed-interaction-values.md) and a [fresh-project integration fixture](v0.2-validation.md). Use the refreshed [matrix](bolt-js-parity-matrix.md) and [JSON manifest](bolt-js-parity.json) for the current capability assessment.

**Broad capability coverage is implemented; full Bolt JS parity has not been established.** PHP-native replacements and deliberate exclusions are assessed separately from gaps. No overall parity percentage is claimed.

| Area | Assessment |
| --- | --- |
| Listener families, interactivity and matching | Implemented with behavioral evidence, including regex captures, legacy interaction classification and composable subtype/direct-mention filters. |
| Middleware and delivery context | Ordered pipelines and shared request-local Context work. Both stock transports now offer typed, authorized context factories. Persistent conversation state is a different concern. |
| Authorization and OAuth | Scoped clients, modern OAuth v2, atomic browser-bound request/state, per-install options, hooks, host routes, stores and rotation work. Static identity verification is explicit and cached per App. Classic v1 and state-free flows remain deliberate exclusions. |
| HTTP and Socket Mode | Verified ingress, acknowledgements, sanitized SSL probes and Socket Mode lifecycle work. Inline returned responses plus named jobs intentionally replace early-ack continuation. Host routing and asynchronous timeout behavior remain PHP adaptations. |
| Error handling | Structured listener/delivery/stage/acknowledgement context, explicit handling policy and safe HTTP processing-error responses are implemented. Host route policy and verification rejection statuses remain separate; no asynchronous unhandled timer exists. |
| Assistant and custom functions | Lifecycle/context/streaming, typed function inputs/complete/fail and configurable workflow-token attachment are implemented; paid-plan live validation remains limited. |
| Web API and Block Kit | 273 typed request/response pairs, generated resources, uploads, pagination/retries and reviewed transport policy exist. This is separate from inbound Bolt parity. Some nested/polymorphic model fields remain raw. |
| Conversation/thread state | Implemented through memory/file/SQLite adapters with stronger tenant/thread scoping than the baseline. |
| Durable jobs, modern agent sessions and Work Objects | Implemented PHP product capabilities; they do not count toward unrelated upstream gaps. |
| Express/AWS/Node interfaces and TypeScript-only exports | Recommended exclusions or PHP host adaptations, not missing runtime capabilities. |

The [upstream contract research](bolt-js-parity-upstream.md) explains what should be preserved semantically. In particular, `App::dispatch`, delivery regex captures, retry metadata and per-delivery function terminal guards already exist. Do not reopen them as missing features just because their PHP names differ.

## Reference declarations are not runtime coverage

The [compiler-derived surface](bolt-js-api-surface.md), [catalog](typescript-api-catalog.json), [inventory index](api-parity-inventory.json) and [scaffold dispositions](scaffold-disposition.json) account for 359 extracted exports across 46 upstream files. The 126 declarations under `src/Scaffold/Bolt` are excluded from Tempest discovery and retain throwing/declaration-only bodies. Their presence does not establish behavioral parity.

The [Web API transport audit](web-api-transport-conformance.md) records contracts for the 273 catalogued endpoints. [Model coverage](web-api-model-coverage.json) and [Block Kit coverage](block-kit-coverage.md) describe typed/building scope. Neither constitutes an exhaustive framework conformance suite.

Run `npm run verify:parity` to check audit inventory consistency and evidence paths. Runtime/transport tests remain separate; updating an assessment requires source and behavioral evidence, not just a green inventory check.
