# Codebase inventory and next milestones

Audited 24 October 2026 against `9a0d548`, the current handwritten source, tests, generated inventories, and official Slack references. This is a capability inventory, not an exhaustive correctness or security review. No live Slack writes were performed.

## Current baseline

- PHP 8.5.12: **462 tests, 3,170 assertions**, passing.
- Mago format check passes. Lint has **24 warnings and 39 help findings**; analysis has **150 warnings and 45 help findings**, with zero errors. Exit success does not mean zero findings. These categories can overlap and should not be added into a unique-defect count.
- All **7 generator tests** pass. Verification confirms **273 endpoint request/response pairs**, **35 root groups**, **98 resource classes**, **126 Bolt declarations**, and **359 export mappings**.
- [Latest checked CI](https://github.com/HelgeSverre/slack-bolt-php/actions/runs/40090286250) passed on Linux/macOS and for generated outputs at `9a0d548`.
- Pinned Bolt reference: **5.1.0**, `ffbe0b6…`; the official [release listing](https://github.com/slackapi/bolt-js/releases) also identifies 5.1.0 as latest at audit time. SDK declarations and documentation supplements have their own provenance in `upstream-manifest.json` and `web-api-model-coverage.json`.

## What is implemented

| Module | Working interface and behavior | Important limit |
| --- | --- | --- |
| Registration and invocation | Attributes and fluent event/message/command/action/shortcut/options/view/function registration; matching, middleware, dependency injection, scoped authorization and error aggregation | `App` owns much of the orchestration; no blanket claim of upstream behavioral conformance |
| HTTP and Socket Mode | Signed HTTP deliveries and challenges; interaction acknowledgements; Socket Mode envelope responses, connection health, reconnect and shutdown | Both dispatch inline before acknowledging |
| Interactivity | Buttons/actions, global/message shortcuts, external options, modal submissions/closures, typed initial responses, response URLs, retained legacy forms | Context-sensitive acknowledgements must remain synchronous; unknown families are explicit failures |
| OAuth and authorization | OAuth v2, cookie-bound one-use state, file/memory installation stores, bot/user refresh and migration, delivery-scoped clients | Built-in stores are local-process/host choices; uninstall/revocation cleanup is deliberately application policy |
| Conversation and thread state | Memory/file/SQLite adapters, atomic updates, expiry, installation/channel/root context | No built-in Redis/Postgres adapter or distributed execution coordinator |
| Web API | 273 callable modeled endpoints, cursor pagination, multipart, upload orchestration, bounded opt-in retries, unknown-field preservation | Not exhaustive schema validation; 29 request and 6,504 response field occurrences remain raw for documented reasons |
| Block Kit and views | Dedicated builders across pinned block/control/composition/rich-text families; message, attachment, modal and Home composition | Remaining cross-field/surface constraints are enforced by Slack; raw escape hatches are not typed completeness |
| Work Objects | Entity metadata/unfurls/details, comments, mutation provider interface, submission handling and example persistence | Provider authorization and external source-of-truth storage belong to application code; availability depends on Slack app/workspace setup |
| Assistant threads | Lifecycle listeners, thread context adapters, status/title/prompts, native message streaming and task chunks | Implements the pinned assistant-thread contract, not a full modern agent-session runtime |
| Custom functions | `function_executed`, typed inputs and DTO mapping, scoped execution/JIT token, complete/fail and local terminal-state guard | Local guard is per delivery; protected async handoff exists in the example, not as a core execution adapter; live Workflow Builder creation was previously blocked by the development plan |
| CLI | Project/listener scaffolds, listing, diagnostics, serving, Socket Mode and shell completions | Event generation still rejects every `--event` except `app_mention`; assistant setup/templates are absent; generated projects depend on this checkout |
| Demonstration | JSON-backed Boltling and Home dashboard, terminal, streamed AI/tool progress, Work Objects, assistant and async custom-function examples | Demonstration business logic should stay application code; queue/recovery primitives are candidates for extraction |

## Prioritized gaps

### 1. Durable delivery and worker execution — recommended next milestone

Evidence: `src/Http/Receiver.php` calls `App::dispatch()` before constructing its response. `src/SocketMode/Runner.php::dispatchEnvelope()` also dispatches before sending the envelope acknowledgement. Timing warnings observe lateness; they do not prevent it. `examples/hello-bolt/app/Demo/Queue.php` persists and deduplicates jobs, but deliberately never replays claimed work after restart. The custom-function example separately protects execution credentials and terminal outcomes.

Build one reusable execution module, initially with SQLite storage and a Tempest worker adapter, that hides persistence, claims and delivery bookkeeping behind a small interface. Define these policies before implementation:

- Persist before acknowledging eligible deliveries; deduplicate using stable, tenant-scoped delivery identities.
- Separate synchronous acknowledgement work from explicitly deferred business work. Modal validation, options, response bodies and expiring interaction triggers cannot simply be queued wholesale.
- Atomic claims/leases, bounded attempts/backoff, failed-job inspection and explicit recovery for uncertain outcomes.
- Reauthorize normal jobs when executing. Keep short-lived workflow credentials protected with explicit expiry rather than serializing a live client or entire invocation container.
- Distinguish retrying delivery processing from repeating Slack mutations. Do not promise exactly-once external side effects.

Acceptance: crash before/after persistence and acknowledgement; duplicate delivery; competing workers; worker death/lease expiry; tenant isolation; expired workflow credentials; uncertain outbound writes; and end-to-end acknowledgement timing for both transports. Include usable worker/status/recovery commands and one converted demo.

### 2. Modern agent sessions and cancellation

The SDK **already exposes** `agents()->sessions()->setStatus()` and `rename()`, and `ChatStopStreamRequest` has `sessionStatus`. The missing work is the higher-level runtime: typed `agent_session_stopped` / `agent_session_title_changed` payloads, session lifecycle helpers, cooperative worker cancellation, and user-renamed title handling. `Assistant\Thread` still uses `assistant.threads.*`; `MessageStream::stop()` does not expose `session_status`.

Slack documents `processing`, `active`, `suspended` and `closed`, recommends migration from `assistant.threads.*`, and requires applications to stop their own work when the stop event arrives. Existing compatibility behavior is not a reason to remove the older interface abruptly. [Official agent-session contract](https://docs.slack.dev/ai/agent-sessions/).

Acceptance: cancellation reaches running work, streams close consistently, statuses leave `processing`, title-change events preserve user intent, and tenant/thread isolation remains intact. This follows the worker milestone naturally.

### 3. CLI parity with the runtime — small independent milestone

`src/Console/BoltCommands.php::makeListener()` explicitly rejects non-`app_mention` events with a first-milestone message; `ProjectScaffold::listener()` always chooses `AppMention` for event templates. The runtime supports generic event listeners and several richer payload classes already.

Add event-to-payload mappings plus a generic fallback; assistant configuration/handler scaffolds; and opt-in manifest snippets for selected capabilities. Keep manifests minimal by default. Verify generated code by discovering and dispatching fixtures in a fresh generated project. Address package-install scaffolding once a published version exists; the current path repository is documented and intentional.

### 4. Compatibility and release readiness

- A maintained behavior matrix must separate implemented, deliberately different, unsupported/retired and still-unverified contracts.
- Expand signed HTTP/Socket Mode conformance fixtures and targeted live smoke coverage, particularly multi-workspace OAuth/rotation, uploads and paid-plan custom functions. Existing example demonstrations are evidence, not full conformance.
- Triage Mago warnings by correctness impact. Mixed dynamic input and stylistic help are not interchangeable; fix important findings and document deliberate dynamic seams rather than adding blanket suppressions or PHPDoc everywhere.
- Add release/package installation verification and release automation before publishing. The project remains experimental and unpublished.

### 5. Deployment adapters and shared stores — demand-led

Receiver lifecycle/error customization, incoming request metadata extraction, and a Lambda/serverless adapter remain opportunities. A literal Express clone has little value in PHP; implement equivalent behavior at PHP-native seams. Distributed installation/state/conversation adapters should follow an actual multi-host deployment requirement; the existing interfaces already provide extension points.

### 6. Typed payload breadth and deeper DTO fidelity — incremental

Generic listeners already make other event families usable, so missing dedicated event classes are an ergonomics gap, not absent delivery support. Add common families such as reactions, membership, file/link and lifecycle events as concrete examples need them. Target raw SDK fields by usage and representability instead of treating every unresolved TypeScript union as a reason for a new PHP abstraction.

## What not to count as missing implementation

`src/Scaffold/Bolt` is explicitly a migration reference, excluded from discovery. **28 scaffold classes contain 66 `NotPortedYet` calls**, but several have real PHP equivalents elsewhere (`App`, assistants, custom functions, receivers and stores). Error-class name parity is also not necessarily missing capability. Preserve generator ownership; map semantic equivalents rather than implementing all scaffolds literally.

The legacy workflow-step view builder is outbound serialization for a retained SDK type. It does not justify implementing Slack's retired Steps from Apps runtime. Laravel AI integration is currently an application-level seam; a Laravel/Eloquent bridge is optional integration work, not a prerequisite for core Bolt parity.

## Documentation corrections

`api-parity.md` and the remaining-order section of `roadmap.md` previously lagged assistants/functions and remote CI. Their summaries are refreshed alongside this audit. Older milestone audits remain historical evidence and should retain their dates, counts and validation limits.

## Suggested sequence

Durable execution → modern agent sessions/cancellation → behavior/release conformance. The CLI improvements are a compact independent task. No further logo work or comprehensive documentation site is needed to unblock these milestones.
