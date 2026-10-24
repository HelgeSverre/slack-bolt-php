# Runtime gap audit

Verified 24 October 2026 against the checked-in PHP source and the pinned Bolt JS 5.1.0 archive (`ffbe0b6…`). This is a runtime/parity audit, not a review of Slack Work Objects.

## What already runs

The handwritten [`App`](../../src/App.php) has real global/listener middleware, authorization, delivery-scoped conversation state, typed handler invocation, and acknowledgement validation. It supports event, message, command, action, shortcut, options, and view registrations ([`App.php:105`](../../src/App.php#L105), [`App.php:321`](../../src/App.php#L321)). [`Http\Receiver`](../../src/Http/Receiver.php) verifies signed payloads, URL challenges, commands, events, and recognized interactions; [`SocketMode\Runner`](../../src/SocketMode/Runner.php) dispatches events, interactions, and slash commands through that same application pipeline. These must not be confused with the similarly named generated scaffolds.

## Assistant milestone completed

Assistant lifecycle routing, durable focus context, status, title, prompts and native streaming helpers now live in the handwritten [Assistant](../../src/Assistant.php) module. Both lifecycle events have typed payloads. The default store uses installation/DM/root-scoped conversation adapters; an explicit Slack metadata adapter offers Bolt-style compatibility without an unkeyed cache. [Assistant tests](../../tests/Assistant/) cover routing, middleware, authorization, context isolation and helper behavior. Laravel AI compatibility is application-level; there is no full Laravel package or agent-session protocol implementation.

## High-priority runtime gaps

| Surface | Current state | Evidence |
| --- | --- | --- |
| Slack custom functions | **Unimplemented.** No `App::function()`, typed `function_executed` listener, execution-ID extraction, auto-ack policy, or injected `complete` / `fail` callbacks. The raw Web API completion endpoints alone do not supply the runtime protocol. | [`CustomFunction.php`](../../src/Scaffold/Bolt/CustomFunction.php#L17) throws. The pinned surface includes the class and function-completion helpers in [`bolt-js-api-surface.md`](bolt-js-api-surface.md#L128). Endpoint bindings exist in [`Generated/WebApi/Functions.php`](../../src/Generated/WebApi/Functions.php#L19). |
| Durable/deferred execution | **Unimplemented framework capability.** HTTP and Socket Mode invoke handlers inline. HTTP only warns as processing approaches Slack’s acknowledgement deadline; it does not persist work before acknowledging, retry it, or deduplicate deliveries after a crash. | [`Http/Receiver.php`](../../src/Http/Receiver.php#L86), [`SocketMode/Runner.php`](../../src/SocketMode/Runner.php#L204), and the stated next milestone in [`README.md`](../../README.md#L185). |

## Receiver and lifecycle parity

The example's [`Demo\Queue`](../../examples/hello-bolt/app/Demo/Queue.php) does persist jobs before acknowledgement and deduplicate deliveries. It is an application-local spool, not a framework adapter; claimed jobs are intentionally not replayed after restart. A reusable retry/lease/recovery policy remains missing.

Thunder has a deliberately PHP-native HTTP entry point and Socket Mode worker, but not Bolt JS receiver-class parity:

- There is no public `App::init()`, `start()`, `stop()`, or `processEvent()` counterpart. Receiver lifecycle ownership is separate from `App` registration, middleware, and trusted-array `dispatch()` ([`App.php`](../../src/App.php#L105)).
- No Express-compatible adapter or AWS Lambda handler exists. Both receiver classes are explicit throwing scaffolds: [`ExpressReceiver.php`](../../src/Scaffold/Bolt/Receivers/ExpressReceiver.php#L17) and [`AwsLambdaReceiver.php`](../../src/Scaffold/Bolt/Receivers/AwsLambdaReceiver.php#L17).
- The real Socket Mode runner accepts only `events_api`, `interactive`, and `slash_commands` envelopes; other envelope families are logged and ignored ([`SocketMode/Runner.php`](../../src/SocketMode/Runner.php#L204)).
- Bolt’s configurable custom routes, receiver lifecycle/error callbacks, `processBeforeResponse`, and deferred acknowledgement model have no matching handwritten abstraction. The existing receiver returns one inline HTTP response ([`Http/Receiver.php`](../../src/Http/Receiver.php#L31)).

## Narrow or lightly covered runtime areas

- Events outside `app_mention`, `message`, `entity_details_requested`, `entity_comments_requested`, `assistant_thread_started` and `assistant_thread_context_changed` are preserved as [`Payloads\Event`](../../src/Payloads/Event.php), rather than receiving dedicated typed payload classes. Generic `event()` listeners remain usable for these events.
- The [`WorkflowStep`](../../src/Views/WorkflowStep.php) builder serializes the legacy `workflow_step` view. It is outbound representation only, with no workflow-step listener runtime; the builder coverage note says this directly ([`block-kit-coverage.md`](block-kit-coverage.md#L34)).
- The present suite has strong groups for HTTP, runtime dispatch, Socket Mode, OAuth, storage, views, Block Kit, and Web API, and Assistant fixtures. Custom-function execution still lacks runtime code and behavior tests. See the current test grouping in [`tests/`](../../tests/).

## How to read the scaffold count

`src/Scaffold/` is a migration inventory, excluded from Tempest discovery; it is not executable framework coverage ([`README.md`](../../README.md#L146)). The generated inventory contains **28 scaffold classes, 85 interfaces, 13 options/config DTOs, 147 declaration-only exports, and 86 unresolved re-exports** ([`api-parity.md`](api-parity.md#L34)). Of the 28 scaffold classes, **28 files contain 66 explicit `NotPortedYet` method/constructor throws** in this checkout.

That count is useful as a map of remaining design work, but it must not be read as “28 broken runtime classes”: the real `App`, HTTP receiver, conversation stores, and Socket Mode runner have intentionally different PHP implementations.

## Documentation debt

[`roadmap.md`](roadmap.md#L68) is useful for ordering the remaining work, but it predates the later playground/streaming work and retains an obsolete statement that no remote CI run has occurred ([`roadmap.md`](roadmap.md#L74)). It should be refreshed before being treated as a current release-status document. The top-level README also correctly remains conservative: it calls the package experimental and incomplete ([`README.md`](../../README.md#L7)).

## Sources and caveat

The checked-in reference archive is Bolt JS 5.1.0 at `ffbe0b6…`; extraction metadata records the exact source locations in [`scaffold-disposition.json`](scaffold-disposition.json). Generated SDK declarations demonstrate that an endpoint is callable; they do **not** demonstrate corresponding inbound routing, handler context, lifecycle behavior, or tested protocol parity.
