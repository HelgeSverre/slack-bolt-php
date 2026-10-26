# API parity map

The current source-backed assessment is the [26 October 2026 Bolt JS parity audit](bolt-js-parity-audit.md), comparing the v0.1.0 preview with **Bolt JS 5.1.0** at `ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d`. Use its [detailed matrix](bolt-js-parity-matrix.md) and [reviewed JSON manifest](bolt-js-parity.json) for individual capabilities, source/test evidence, all AppOptions, public App member names, built-ins and module coverage.

**Broad capability coverage is implemented; full Bolt JS parity has not been established.** PHP-native replacements and deliberate exclusions are assessed separately from gaps. No overall parity percentage is claimed.

| Area | Assessment |
| --- | --- |
| Listener families, interactivity and matching | Implemented with behavioral evidence, including regex captures and legacy interaction classification. Reusable subtype/direct-mention filters are missing. |
| Middleware and delivery context | Ordered pipelines work; custom request-local enrichment and stock-adapter context factories are gaps. Persistent conversation state is a different concern. |
| Authorization and OAuth | Scoped clients, basic OAuth v2, one-time state, stores and rotation work. Static-token identity hydration and advanced installer controls remain narrower than Bolt. |
| HTTP and Socket Mode | Verified ingress, acknowledgements and Socket Mode lifecycle work. Inline returned responses plus named jobs intentionally replace early-ack continuation. Receiver/error hooks and SSL probes are not fully equivalent. |
| Error handling | Aggregate failures, parsed payload and metadata are available; structured listener/delivery/stage context and configurable receiver policy are incomplete. |
| Assistant and custom functions | Lifecycle/context/streaming and typed function inputs/complete/fail are implemented. Workflow-token opt-out is missing; paid-plan live validation remains limited. |
| Web API and Block Kit | 273 typed request/response pairs, generated resources, uploads, pagination/retries and reviewed transport policy exist. This is separate from inbound Bolt parity. Some nested/polymorphic model fields remain raw. |
| Conversation/thread state | Implemented through memory/file/SQLite adapters with stronger tenant/thread scoping than the baseline. |
| Durable jobs, modern agent sessions and Work Objects | Implemented PHP product capabilities; they do not count toward unrelated upstream gaps. |
| Express/AWS/Node interfaces and TypeScript-only exports | Recommended exclusions or PHP host adaptations, not missing runtime capabilities. |

The [upstream contract research](bolt-js-parity-upstream.md) explains what should be preserved semantically. In particular, `App::dispatch`, delivery regex captures, retry metadata and per-delivery function terminal guards already exist. Do not reopen them as missing features just because their PHP names differ.

## Reference declarations are not runtime coverage

The [compiler-derived surface](bolt-js-api-surface.md), [catalog](typescript-api-catalog.json), [inventory index](api-parity-inventory.json) and [scaffold dispositions](scaffold-disposition.json) account for 359 extracted exports across 46 upstream files. The 126 declarations under `src/Scaffold/Bolt` are excluded from Tempest discovery and retain throwing/declaration-only bodies. Their presence does not establish behavioral parity.

The [Web API transport audit](web-api-transport-conformance.md) records contracts for the 273 catalogued endpoints. [Model coverage](web-api-model-coverage.json) and [Block Kit coverage](block-kit-coverage.md) describe typed/building scope. Neither constitutes an exhaustive framework conformance suite.

Run `npm run verify:parity` to check audit inventory consistency and evidence paths. Runtime/transport tests remain separate; updating an assessment requires source and behavioral evidence, not just a green inventory check.
