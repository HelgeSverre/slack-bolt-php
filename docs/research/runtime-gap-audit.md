# Runtime gap audit

Verified 24 October 2026 against the checked-in PHP source and the pinned Bolt JS 5.1.0 archive (`ffbe0b6…`). This is a runtime/parity audit, not a review of Slack Work Objects.

## What already runs

The handwritten [`App`](../../src/App.php) has real global/listener middleware, authorization, delivery-scoped conversation state, typed handler invocation, and acknowledgement validation. It supports event, message, command, action, shortcut, options, and view registrations ([`App.php:105`](../../src/App.php#L105), [`App.php:321`](../../src/App.php#L321)). [`Http\Receiver`](../../src/Http/Receiver.php) verifies signed payloads, URL challenges, commands, events, and recognized interactions; [`SocketMode\Runner`](../../src/SocketMode/Runner.php) dispatches events, interactions, and slash commands through that same application pipeline. These must not be confused with the similarly named generated scaffolds.

## Assistant milestone completed

Assistant lifecycle routing, durable focus context, status, title, prompts and native streaming helpers now live in the handwritten [Assistant](../../src/Assistant.php) module. Both lifecycle events have typed payloads. The default store uses installation/DM/root-scoped conversation adapters; an explicit Slack metadata adapter offers Bolt-style compatibility without an unkeyed cache. [Assistant tests](../../tests/Assistant/) cover routing, middleware, authorization, context isolation and helper behavior. Modern agent-session lifecycle, title and stream completion helpers are implemented alongside the legacy methods. The AI demo connects Stop to durable cancellation and model I/O; [integration notes](agent-sessions.md). Laravel AI compatibility remains application-level; there is no full Laravel package.

## Custom functions milestone completed

The handwritten custom-function runtime now supports callback routing, typed inputs/DTO mapping, execution context and complete/fail helpers for events and function-scoped interactions. Workflow tokens are scoped to the authorized delivery client. The playground demonstrates an explicit Tempest async handoff with encrypted credentials, tenant/execution deduplication and reference-only commands. Core receivers still acknowledge after inline dispatch returns; slow work needs that explicit handoff. Live custom-function creation is gated by the development workspace's free Slack plan.

## Durable execution milestone completed

| Surface | Current state | Evidence |
| --- | --- | --- |
| Durable named jobs | Explicit named jobs commit before dispatch returns; independent workers use atomic claims, bounded opt-in retries, uncertainty-aware recovery and cooperative cancellation. Listeners still return synchronous responses. | [Implementation audit](durable-execution-audit.md), `src/Jobs/`, `tests/Jobs/`. |

## Receiver and lifecycle parity

The older [`Demo\Queue`](../../examples/hello-bolt/app/Demo/Queue.php) remains an application-local spool for pet, terminal and work-item demos. AI turns, assistant setup and the interactive session tour now use the framework-owned SQLite queue. Old queued AI work is not silently replayed. Serverless receivers and exhaustive release conformance remain later work.

Thunder has a deliberately PHP-native HTTP entry point and Socket Mode worker, but not Bolt JS receiver-class parity:

- There is no public `App::init()`, `start()`, `stop()`, or `processEvent()` counterpart. Receiver lifecycle ownership is separate from `App` registration, middleware, and trusted-array `dispatch()` ([`App.php`](../../src/App.php#L105)).
- No Express-compatible adapter or AWS Lambda handler exists. Both receiver classes are explicit throwing scaffolds: [`ExpressReceiver.php`](../../src/Scaffold/Bolt/Receivers/ExpressReceiver.php#L17) and [`AwsLambdaReceiver.php`](../../src/Scaffold/Bolt/Receivers/AwsLambdaReceiver.php#L17).
- The real Socket Mode runner accepts only `events_api`, `interactive`, and `slash_commands` envelopes; other envelope families are logged and ignored ([`SocketMode/Runner.php`](../../src/SocketMode/Runner.php#L204)).
- Bolt’s configurable custom routes, receiver lifecycle/error callbacks, `processBeforeResponse`, and deferred acknowledgement model have no matching handwritten abstraction. The existing receiver returns one inline HTTP response ([`Http/Receiver.php`](../../src/Http/Receiver.php#L31)).

## Narrow or lightly covered runtime areas

- Dedicated event payloads cover mentions/messages, Work Objects, assistant threads, custom functions, reactions, channel membership, shared links/files, uninstall/revocation and agent-session stop/title changes. Other events remain usable through [`Payloads\Event`](../../src/Payloads/Event.php). Typed agent-session events do not themselves implement a job-cancellation runtime.
- The [`WorkflowStep`](../../src/Views/WorkflowStep.php) builder serializes the legacy `workflow_step` view. It is outbound representation only, with no workflow-step listener runtime; the builder coverage note says this directly ([`block-kit-coverage.md`](block-kit-coverage.md#L34)).
- The present suite has strong groups for HTTP, runtime dispatch, Socket Mode, OAuth, storage, views, Block Kit, and Web API, and Assistant fixtures. Custom-function fixtures now cover routing, authorization, typed inputs, interactions, terminal outcomes and a real Tempest command-bus handoff. See the current test grouping in [`tests/`](../../tests/).

## How to read the scaffold count

`src/Scaffold/` is a migration inventory, excluded from Tempest discovery; it is not executable framework coverage ([`README.md`](../../README.md#L146)). The generated inventory contains **28 scaffold classes, 85 interfaces, 13 options/config DTOs, 147 declaration-only exports, and 86 unresolved re-exports** ([`api-parity.md`](api-parity.md#L34)). Of the 28 scaffold classes, **28 files contain 66 explicit `NotPortedYet` method/constructor throws** in this checkout.

That count is useful as a map of remaining design work, but it must not be read as “28 broken runtime classes”: the real `App`, HTTP receiver, conversation stores, and Socket Mode runner have intentionally different PHP implementations.

## Documentation debt

The [roadmap](roadmap.md), [parity map](api-parity.md) and [architecture overview](../architecture.md) now reflect assistant/custom-function completion and verified remote CI. Earlier dated milestone audits remain historical snapshots. The package is still experimental and unpublished. The [application/async explainer](durable-execution-explained.md) distinguishes shipped execution behavior from proposed durability and composition changes.

## Sources and caveat

The checked-in reference archive is Bolt JS 5.1.0 at `ffbe0b6…`; extraction metadata records the exact source locations in [`scaffold-disposition.json`](scaffold-disposition.json). Generated SDK declarations demonstrate that an endpoint is callable; they do **not** demonstrate corresponding inbound routing, handler context, lifecycle behavior, or tested protocol parity.
