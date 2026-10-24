# Assistant runtime decisions

Checked 24 October 2026. Reports: [Bolt contract](assistant-bolt-contract.md), [Laravel AI](assistant-laravel-ai.md), [Vercel Eve](assistant-eve.md). These reports distinguish sourced behavior from proposals.

| Decision | Slack/Bolt JS | Laravel AI | Eve | Thunder Bolt |
| --- | --- | --- | --- | --- |
| Lifecycle ownership | Assistant middleware consumes lifecycle events and IM thread replies | Agent owns a model invocation | Channel adapts a session | Assistant configuration and injected Thread |
| Durable context | Initial bot message metadata; custom store seam | Provider conversation UUID and Laravel persistence | Stable tenant/session identity | Installation/DM/root keyed store over existing durable adapters |
| Progress | Status, titles, suggested prompts and native streaming | Typed model stream events | Coalesced channel progress | Thread helpers and lazy Slack stream; model execution stays outside core |
| Dependencies | Slack client | Illuminate container and services | Eve runtime and optional Connect | Existing Tempest and PSR transport; no model SDK dependency |

Confidence: high for the cited contracts and source; the Thunder Bolt column records implementation decisions.

1. Keep the Assistant interface small: lifecycle/message handlers and a Thread capability. It hides routing, authorized destination, context storage and Slack helper arguments.
2. Reuse tenant-aware conversation adapters with an `assistant:` storage namespace. Focus context never shares the generic history value. Provide an explicit Slack metadata adapter without Bolt's unkeyed process cache.
3. Default context-changed behavior saves new context. Applications choose what to do at thread start and when to invoke a model. Handler middleware, authorization and error reporting use the existing App pipeline.
4. Preserve unrelated outbound metadata and pin reply/stream destinations. A focus channel is a hint, not a permission grant or reply destination.
5. Adopt Laravel's class-based authoring and iterable-stream ergonomics, plus Eve's explicit channel/session separation. Do not import either full agent runtime into a Slack transport framework.
6. Defer a dedicated Laravel AI package until it can be tested in an actual Laravel application with participant mapping, conversation continuation and tool policy. Plain handler/worker interoperability is available now, but is not a source-compatible bridge.

The current Slack docs recommend agent-session methods in place of assistant-thread helpers. This milestone deliberately targets the pinned Bolt JS assistant contract, preserving that distinction rather than claiming full agent-session support. See [status method guidance](https://docs.slack.dev/reference/methods/assistant.threads.setStatus/) and [assistant lifecycle event](https://docs.slack.dev/reference/events/assistant_thread_started/).

## Validation

Local fixtures cover lifecycle dispatch, scoped authorization, IM/subtype filtering, middleware/error propagation, store isolation and restart persistence, metadata compatibility, empty context, helper payloads and stream terminal states. The current Slack Agent experience was tested live in the development workspace: a root DM created a named thread, status and prompt calls succeeded, the answer streamed, and a follow-up after restarting both workers invoked the pet-status tool in the same thread. The legacy lifecycle events and focus-channel changes were verified with fixtures; opening the current Agent DM did not emit a legacy start event in this check. The playground therefore provides an explicit root-DM adapter while the core retains Bolt JS filtering.
