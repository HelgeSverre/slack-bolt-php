# Bolt JS parity matrix

Generated from [the reviewed audit manifest](bolt-js-parity.json) by `node scripts/audit-bolt-parity.mjs`. The human verdict and priorities are in [the audit](bolt-js-parity-audit.md).

Baseline: Bolt JS 5.1.0, commit `ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d`. PHP revision: `28 October 2026 implementation, based on 4b03c1b`. Audited 2026-10-28.

Mapping presence is a bookkeeping check, not proof of behavioral equality. No percentage is reported: features differ in importance, and excluded Node APIs/type aliases are not missing PHP capabilities.

## Status definitions

- **covered**: Reviewed capability is implemented with source and behavioral-test evidence; not exhaustive differential conformance.
- **adapted**: PHP provides the relevant capability through a different interface or an explicit supported execution model.
- **partial**: Important behavior/configuration/typing is narrower; evidence documents current behavior, not the missing guarantee.
- **missing**: No packaged equivalent; custom user code is not implementation parity.
- **excluded**: Recommended deliberate non-goal, with PHP or legacy rationale.
- **extension**: Beyond the baseline; excluded from parity assessment.

## Capability evidence

### listeners

**Event, message and command listeners — covered.** Exact/string/regex routing, multiple listeners, typed attributes and generic events work. Use message('', handler) or event('message', handler) for an unfiltered message listener.

PHP: [src/App.php](../../src/App.php), [src/Routing/Matcher.php](../../src/Routing/Matcher.php), [src/Integration/BoltDiscovery.php](../../src/Integration/BoltDiscovery.php).

Behavioral evidence: [tests/Runtime/AppTest.php](../../tests/Runtime/AppTest.php), [tests/Runtime/CallableTargetTest.php](../../tests/Runtime/CallableTargetTest.php), [tests/Integration/BoltDiscoveryTest.php](../../tests/Integration/BoltDiscoveryTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), [src/types/events/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/events/index.ts), [src/types/command/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/command/index.ts).

### interactions

**Actions, shortcuts, options and views — covered.** Block/legacy selectors, first-action classification, regex constraints and submission-only view shorthand have explicit tests. view_closed is supported with explicit constraints.

PHP: [src/Invocation/Interaction.php](../../src/Invocation/Interaction.php), [src/Routing/InteractionConstraints.php](../../src/Routing/InteractionConstraints.php), [src/Invocation/AcknowledgementValidator.php](../../src/Invocation/AcknowledgementValidator.php).

Behavioral evidence: [tests/Http/InteractivityTest.php](../../tests/Http/InteractivityTest.php), [tests/Runtime/InteractivityRegistrationTest.php](../../tests/Runtime/InteractivityRegistrationTest.php), [tests/SocketMode/DeliveryIntegrationTest.php](../../tests/SocketMode/DeliveryIntegrationTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), [src/types/actions/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/actions/index.ts), [src/types/options/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/options/index.ts), [src/types/shortcuts/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/shortcuts/index.ts), [src/types/view/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/view/index.ts).

### pipeline

**Global/listener middleware and failures — adapted.** Ordered synchronous middleware with short-circuiting and duplicate-next guards; listeners run sequentially and later listeners still run after earlier failures. No Promise scheduling parity is claimed.

PHP: [src/Invocation/InvocationPipeline.php](../../src/Invocation/InvocationPipeline.php), [src/App.php](../../src/App.php).

Behavioral evidence: [tests/Runtime/AppTest.php](../../tests/Runtime/AppTest.php).

Upstream: [src/middleware/process.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/process.ts), [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts).

### captures

**Regex captures and retry metadata — covered.** Delivery exposes matches and per-constraint captures; HTTP and Socket Mode attach retry metadata. These are already implemented, not a missing Context feature.

PHP: [src/Invocation/Delivery.php](../../src/Invocation/Delivery.php), [src/Routing/Matcher.php](../../src/Routing/Matcher.php), [src/Http/Receiver.php](../../src/Http/Receiver.php), [src/SocketMode/Runner.php](../../src/SocketMode/Runner.php).

Behavioral evidence: [tests/Runtime/AppTest.php](../../tests/Runtime/AppTest.php), [tests/Http/ReceiverTest.php](../../tests/Http/ReceiverTest.php), [tests/SocketMode/RunnerTest.php](../../tests/SocketMode/RunnerTest.php).

Upstream: [src/middleware/builtin.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/builtin.ts), [src/types/middleware.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/middleware.ts).

### scratch-context

**Delivery-local custom middleware context — covered.** Context is a delivery-local mutable capability shared across middleware, method/constructor-injected handlers and assistants in GenericContainer scopes. Separate deliveries/tenants/jobs get fresh contexts; the context is non-serializable and print_r/var_dump omit its values. Stock-adapter context factories remain separate.

PHP: [src/Context.php](../../src/Context.php), [src/Invocation/Delivery.php](../../src/Invocation/Delivery.php), [src/Invocation/HandlerInvoker.php](../../src/Invocation/HandlerInvoker.php), [src/App.php](../../src/App.php), [src/Jobs/Worker.php](../../src/Jobs/Worker.php).

Behavioral evidence: [tests/Runtime/ContextAndIdentityTest.php](../../tests/Runtime/ContextAndIdentityTest.php), [tests/Assistant/RuntimeTest.php](../../tests/Assistant/RuntimeTest.php), [tests/Jobs/JobRequestContextTest.php](../../tests/Jobs/JobRequestContextTest.php).

Upstream: [src/types/middleware.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/middleware.ts), [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts).

### host-context

**Adapter-supplied custom properties — partial.** dispatch(body, metadata) accepts host data in metadata, but the stock HTTP and Socket Mode adapters provide no context-extractor hook. Missing host enrichment is distinct from existing retry/capture support.

PHP: [src/App.php](../../src/App.php), [src/Http/Receiver.php](../../src/Http/Receiver.php), [src/SocketMode/Runner.php](../../src/SocketMode/Runner.php).

Behavioral evidence: [tests/Http/ReceiverTest.php](../../tests/Http/ReceiverTest.php).

Upstream: [src/types/receiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/receiver.ts), [src/receivers/HTTPReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPReceiver.ts), [src/receivers/SocketModeReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeReceiver.ts).

### builtin-families

**Built-in family and matcher middleware — adapted.** onlyEvents/onlyActions/etc and matchConstraints/message/command/event behavior live in typed registration and Matcher. Separate exported filter functions are unnecessary for this behavior.

PHP: [src/Routing/Matcher.php](../../src/Routing/Matcher.php), [src/Invocation/Interaction.php](../../src/Invocation/Interaction.php).

Behavioral evidence: [tests/Runtime/AppTest.php](../../tests/Runtime/AppTest.php), [tests/Http/InteractivityTest.php](../../tests/Http/InteractivityTest.php).

Upstream: [src/middleware/builtin.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/builtin.ts).

### subtype-mention

**subtype and directMention helpers — missing.** No reusable subtype/direct-mention filters exist. Applications can inspect payload.raw with custom middleware; add idiomatic reusable filters if these common operations should be first-class. Do not confuse app_mention with directMention filtering of message events.

PHP: [src/Middleware/Middleware.php](../../src/Middleware/Middleware.php), [src/Payloads/Events/Message.php](../../src/Payloads/Events/Message.php).

No dedicated behavioral test is cited for the missing/excluded contract; the source establishes its absence or scope.

Upstream: [src/middleware/builtin.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/builtin.ts).

### authorization

**Installation-scoped authorization — covered.** Static/custom authorization, tenant/user/enterprise identities, lifecycle exceptions and scoped clients work. User and bot grants remain separate.

PHP: [src/Authorization/AuthorizationSource.php](../../src/Authorization/AuthorizationSource.php), [src/Authorization/InstallationAuthorizer.php](../../src/Authorization/InstallationAuthorizer.php), [src/Invocation/InvocationScope.php](../../src/Invocation/InvocationScope.php).

Behavioral evidence: [tests/Authorization/AuthorizerTest.php](../../tests/Authorization/AuthorizerTest.php), [tests/Runtime/AuthorizationDispatchTest.php](../../tests/Runtime/AuthorizationDispatchTest.php), [tests/Integration/InstalledApplicationTest.php](../../tests/Integration/InstalledApplicationTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts).

### static-identity

**Static-token verification and self identity — adapted.** App.verifyToken explicitly verifies and caches identity per App, hydrates both bot identifiers and checks verified tenant identity. auth:verify reports identity; socket:serve --verify-token hydrates the worker before connecting. SLACK_BOT_USER_ID/SLACK_BOT_ID support network-free HTTP. Help/discovery and ordinary dispatch do not perform hidden lookups; the CLI does not warm another process.

PHP: [src/App.php](../../src/App.php), [src/BoltConfig.php](../../src/BoltConfig.php), [src/Integration/AppInitializer.php](../../src/Integration/AppInitializer.php), [src/Authorization/TokenIdentity.php](../../src/Authorization/TokenIdentity.php), [src/Console/AuthCommands.php](../../src/Console/AuthCommands.php).

Behavioral evidence: [tests/Runtime/ConversationDispatchTest.php](../../tests/Runtime/ConversationDispatchTest.php), [tests/Runtime/AuthorizationDispatchTest.php](../../tests/Runtime/AuthorizationDispatchTest.php), [tests/Runtime/ContextAndIdentityTest.php](../../tests/Runtime/ContextAndIdentityTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), [src/middleware/builtin.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/builtin.ts).

### oauth-core

**OAuth install, callback and state — covered.** OAuth v2, bot/user scopes, one-time browser-bound state, denial handling, grant storage and OAuth callback endpoint handling are implemented. Opaque stored state deliberately replaces signed state-secret format.

PHP: [src/OAuth/Installer.php](../../src/OAuth/Installer.php), [src/OAuth/State/StateStore.php](../../src/OAuth/State/StateStore.php), [src/Integration/OAuthController.php](../../src/Integration/OAuthController.php).

Behavioral evidence: [tests/OAuth/InstallerTest.php](../../tests/OAuth/InstallerTest.php), [tests/Integration/OAuthControllerTest.php](../../tests/Integration/OAuthControllerTest.php).

Upstream: [src/receivers/HTTPReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPReceiver.ts), [src/receivers/verify-redirect-opts.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/verify-redirect-opts.ts).

### oauth-options

**Advanced installation options and hooks — partial.** Installer.begin() has no per-install request options: no metadata round trip, target-team hint, per-request scopes, install/callback hooks or built-in configurable paths/pages. Applications can own controllers, but that is not packaged installer-option parity. Prioritize metadata and success/failure hooks over visual install pages.

PHP: [src/OAuth/OAuthConfig.php](../../src/OAuth/OAuthConfig.php), [src/OAuth/Installer.php](../../src/OAuth/Installer.php), [src/OAuth/State/State.php](../../src/OAuth/State/State.php), [src/Integration/OAuthController.php](../../src/Integration/OAuthController.php).

Behavioral evidence: [tests/OAuth/InstallerTest.php](../../tests/OAuth/InstallerTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), [src/receivers/HTTPReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPReceiver.ts).

### rotation

**Token rotation and migration — covered.** Both grants refresh independently with locked updates, expiry windows and explicit long-lived-token migration. OAuth exchanges remain single-attempt. Store adapters carry update locking responsibility.

PHP: [src/OAuth/TokenRotator.php](../../src/OAuth/TokenRotator.php), [src/OAuth/InstallationStore.php](../../src/OAuth/InstallationStore.php).

Behavioral evidence: [tests/OAuth/TokenRotatorTest.php](../../tests/OAuth/TokenRotatorTest.php), [tests/OAuth/TokenRotationProtocolTest.php](../../tests/OAuth/TokenRotationProtocolTest.php), [tests/OAuth/InstallationStoreTest.php](../../tests/OAuth/InstallationStoreTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), [src/receivers/HTTPReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPReceiver.ts).

### conversation

**Conversation state and expiry — adapted.** ConversationContext plus memory/file/SQLite stores implement read/update/expiry, with stronger installation scoping and explicit persistence rather than copying channel-only mutable JS state.

PHP: [src/Conversation/StoredContext.php](../../src/Conversation/StoredContext.php), [src/Conversation/ConversationStore.php](../../src/Conversation/ConversationStore.php).

Behavioral evidence: [tests/Conversation/ConversationStoreTest.php](../../tests/Conversation/ConversationStoreTest.php), [tests/Runtime/ConversationDispatchTest.php](../../tests/Runtime/ConversationDispatchTest.php).

Upstream: [src/conversation-store.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/conversation-store.ts).

### thread-store

**Durable thread storage — extension.** ThreadContext and durable adapters extend the Bolt baseline. Their presence is not evidence that unrelated Bolt features are implemented.

PHP: [src/Conversation/ThreadContext.php](../../src/Conversation/ThreadContext.php), [src/Conversation/DeliveryContext.php](../../src/Conversation/DeliveryContext.php).

Behavioral evidence: [tests/Conversation/DurableConversationTest.php](../../tests/Conversation/DurableConversationTest.php).

Upstream: [src/conversation-store.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/conversation-store.ts).

### replies

**say/respond utilities — adapted.** Reply provides channel/thread/ephemeral and response_url calls with typed messages. Helpers select explicit destinations; response URLs do not receive bot credentials. This replaces JS closures, not their names.

PHP: [src/Reply.php](../../src/Reply.php), [src/WebApi/Client.php](../../src/WebApi/Client.php).

Behavioral evidence: [tests/Runtime/AppTest.php](../../tests/Runtime/AppTest.php), [tests/WebApi/ResponseUrlTest.php](../../tests/WebApi/ResponseUrlTest.php).

Upstream: [src/context/create-say.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-say.ts), [src/context/create-respond.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-respond.ts), [src/types/utilities.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/utilities.ts).

### ack

**Acknowledgement behavior and slow work — adapted.** Typed return values produce one acknowledgement after inline processing. There is no early-ack callback, per-listener autoAcknowledge option or processBeforeResponse toggle. Named durable jobs are the supported slow-work path; a warning does not enforce Slack's deadline. Retain this PHP model and document the timing difference; any future async host needs a separate design.

PHP: [src/Http/Receiver.php](../../src/Http/Receiver.php), [src/Invocation/AcknowledgementValidator.php](../../src/Invocation/AcknowledgementValidator.php), [src/SocketMode/Runner.php](../../src/SocketMode/Runner.php), [src/Jobs.php](../../src/Jobs.php).

Behavioral evidence: [tests/Http/InteractivityTest.php](../../tests/Http/InteractivityTest.php), [tests/Jobs/DurableExecutionTest.php](../../tests/Jobs/DurableExecutionTest.php), [tests/SocketMode/DeliveryIntegrationTest.php](../../tests/SocketMode/DeliveryIntegrationTest.php).

Upstream: [src/receivers/HTTPResponseAck.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPResponseAck.ts), [src/receivers/SocketModeResponseAck.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeResponseAck.ts), [src/middleware/builtin.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/builtin.ts).

### http

**Signed HTTP ingress and URL verification — covered.** Raw-body signature/timestamp checks, body limits, signed challenge, JSON/forms, retry headers and explicit error statuses are implemented. This does not include every configurable upstream receiver hook.

PHP: [src/Http/RequestVerifier.php](../../src/Http/RequestVerifier.php), [src/Http/Receiver.php](../../src/Http/Receiver.php).

Behavioral evidence: [tests/Http/RequestVerifierTest.php](../../tests/Http/RequestVerifierTest.php), [tests/Http/ReceiverTest.php](../../tests/Http/ReceiverTest.php), [tests/Integration/HttpControllerTest.php](../../tests/Integration/HttpControllerTest.php).

Upstream: [src/receivers/verify-request.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/verify-request.ts), [src/receivers/verify-signing-secret.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/verify-signing-secret.ts), [src/receivers/HTTPReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPReceiver.ts).

### ingress

**Trusted custom-host ingress — adapted.** App.dispatch(body, metadata) already provides trusted parsed ingress, and Receiver.handle provides request/response verification. A new processEvent clone or generic receiver abstraction is not required. Future PSR/Laravel/Symfony adapters should reuse these methods.

PHP: [src/App.php](../../src/App.php), [src/Http/Receiver.php](../../src/Http/Receiver.php).

Behavioral evidence: [tests/Runtime/AppTest.php](../../tests/Runtime/AppTest.php), [tests/Integration/HttpControllerTest.php](../../tests/Integration/HttpControllerTest.php).

Upstream: [src/types/receiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/receiver.ts), [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts).

### routing-lifecycle

**Routes, ports, server lifecycle and initialization — adapted.** Tempest owns HTTP routes and serving; SocketMode.Runner owns socket lifecycle. Custom routes can be normal Tempest controllers; relocating the supplied Slack/OAuth paths requires an application controller. No universal App.start/stop/init belongs in PHP-FPM.

PHP: [src/Integration/HttpController.php](../../src/Integration/HttpController.php), [src/Integration/OAuthController.php](../../src/Integration/OAuthController.php), [src/Console/BoltCommands.php](../../src/Console/BoltCommands.php), [src/Console/SocketCommands.php](../../src/Console/SocketCommands.php).

Behavioral evidence: [tests/Console/ProjectScaffoldTest.php](../../tests/Console/ProjectScaffoldTest.php), [tests/Console/SocketCommandsTest.php](../../tests/Console/SocketCommandsTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), [src/receivers/custom-routes.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/custom-routes.ts).

### errors

**Application/receiver error context and policy — adapted.** App.onError receives original errors with listener/source/stage and buffered acknowledgement context; explicit Handled/Rethrow policy preserves originals, aggregates failures and cannot suppress failed authorization, durable intake or missing/duplicate acknowledgements. Legacy App.error remains. The HTTP receiver accepts non-success processing error responses; verification/rejection statuses and host route policy remain host-owned, with no early-ack/unhandled timer.

PHP: [src/App.php](../../src/App.php), [src/Exceptions/HandlerFailures.php](../../src/Exceptions/HandlerFailures.php), [src/Http/Receiver.php](../../src/Http/Receiver.php), [src/SocketMode/Runner.php](../../src/SocketMode/Runner.php), [src/Errors/FailureContext.php](../../src/Errors/FailureContext.php), [src/Errors/ErrorHandling.php](../../src/Errors/ErrorHandling.php).

Behavioral evidence: [tests/Runtime/AppTest.php](../../tests/Runtime/AppTest.php), [tests/Http/InteractivityTest.php](../../tests/Http/InteractivityTest.php), [tests/SocketMode/RunnerTest.php](../../tests/SocketMode/RunnerTest.php), [tests/Runtime/FailureContextTest.php](../../tests/Runtime/FailureContextTest.php), [tests/Http/ReceiverFailurePolicyTest.php](../../tests/Http/ReceiverFailurePolicyTest.php).

Upstream: [src/errors.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/errors.ts), [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), [src/receivers/HTTPReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPReceiver.ts).

### logging

**Logging interfaces — adapted.** PSR-3 logger injection replaces @slack/logger, log-level enums and app.logger. Public logger property spelling is not required; normal Tempest logging configuration owns levels.

PHP: [src/App.php](../../src/App.php), [src/Integration/AppInitializer.php](../../src/Integration/AppInitializer.php), [src/SocketMode/Runner.php](../../src/SocketMode/Runner.php).

Behavioral evidence: [tests/Http/ReceiverTest.php](../../tests/Http/ReceiverTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), [src/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/index.ts).

### socket

**Socket Mode delivery and reconnection — covered.** Envelope dispatch/ack payloads, hello/disconnect, health, bounds, reconnect/backoff and cooperative signal shutdown are implemented. No assertion of parity with every option in the separate Node Socket Mode SDK.

PHP: [src/SocketMode/Runner.php](../../src/SocketMode/Runner.php), [src/SocketMode/Config.php](../../src/SocketMode/Config.php), [src/SocketMode/Transport/AmpConnection.php](../../src/SocketMode/Transport/AmpConnection.php).

Behavioral evidence: [tests/SocketMode/RunnerTest.php](../../tests/SocketMode/RunnerTest.php), [tests/SocketMode/Transport/AmpLocalServerTest.php](../../tests/SocketMode/Transport/AmpLocalServerTest.php), [tests/SocketMode/DeliveryIntegrationTest.php](../../tests/SocketMode/DeliveryIntegrationTest.php).

Upstream: [src/receivers/SocketModeReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeReceiver.ts), [src/receivers/SocketModeFunctions.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeFunctions.ts).

### assistant

**Assistant lifecycle and context helpers — covered.** Started/context-changed/user-message routing, scoped context, status/title/prompts, say and streaming helpers have behavioral coverage. Local durable context is the default; explicit Slack-metadata storage provides upstream-compatible storage behavior.

PHP: [src/Assistant.php](../../src/Assistant.php), [src/Assistant/Runtime.php](../../src/Assistant/Runtime.php), [src/Assistant/Thread.php](../../src/Assistant/Thread.php), [src/Assistant/MessageStream.php](../../src/Assistant/MessageStream.php).

Behavioral evidence: [tests/Assistant/RuntimeTest.php](../../tests/Assistant/RuntimeTest.php), [tests/Assistant/ThreadTest.php](../../tests/Assistant/ThreadTest.php), [tests/Assistant/MessageStreamTest.php](../../tests/Assistant/MessageStreamTest.php), [tests/Assistant/SlackMetadataThreadContextStoreTest.php](../../tests/Assistant/SlackMetadataThreadContextStoreTest.php).

Upstream: [src/Assistant.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/Assistant.ts), [src/AssistantThreadContextStore.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/AssistantThreadContextStore.ts), [src/context/create-say-stream.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-say-stream.ts), [src/context/create-set-status.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-set-status.ts).

### functions

**Custom function routing, inputs and terminal helpers — covered.** Named callbacks, strict typed inputs, function/interactivity execution context, workflow-scoped clients and complete/fail have behavioral tests. Execution guards duplicate and uncertain terminal calls per delivery; it does not promise global exactly-once completion across processes.

PHP: [src/Functions/Execution.php](../../src/Functions/Execution.php), [src/Functions/ExecutionContext.php](../../src/Functions/ExecutionContext.php), [src/Functions/Inputs.php](../../src/Functions/Inputs.php), [src/App.php](../../src/App.php).

Behavioral evidence: [tests/Functions/RuntimeTest.php](../../tests/Functions/RuntimeTest.php), [tests/Functions/ExecutionTest.php](../../tests/Functions/ExecutionTest.php), [tests/Functions/InputsTest.php](../../tests/Functions/InputsTest.php).

Upstream: [src/CustomFunction.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/CustomFunction.ts), [src/context/create-function-complete.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-function-complete.ts), [src/context/create-function-fail.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-function-fail.ts).

### function-policy

**Workflow-token attachment policy — partial.** Function workflow tokens take precedence when present. No attachFunctionToken=false option exists. Expose an explicit per-app policy if applications need ordinary installation credentials instead; this is independent of typed input/completion support.

PHP: [src/App.php](../../src/App.php), [src/BoltConfig.php](../../src/BoltConfig.php), [src/Functions/ExecutionContext.php](../../src/Functions/ExecutionContext.php).

Behavioral evidence: [tests/Functions/RuntimeTest.php](../../tests/Functions/RuntimeTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts).

### payload-types

**Inbound native payload and state typing — partial.** Common events have dedicated classes and unknown events preserve raw data. Many JS action unions, view-state value variants and the @slack/types event universe remain generic/raw, not native PHP DTO equivalents. Routing those payloads is supported; claiming complete typed inbound coverage would be inaccurate.

PHP: [src/Payloads/Event.php](../../src/Payloads/Event.php), [src/Payloads/Action.php](../../src/Payloads/Action.php), [src/Payloads/View.php](../../src/Payloads/View.php), [src/App.php](../../src/App.php).

Behavioral evidence: [tests/Payloads/InteractionPayloadTest.php](../../tests/Payloads/InteractionPayloadTest.php), [tests/Runtime/TypedEventDispatchTest.php](../../tests/Runtime/TypedEventDispatchTest.php).

Upstream: [src/types/actions/block-action.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/actions/block-action.ts), [src/types/view/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/view/index.ts), [src/types/events/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/events/index.ts).

### client

**Web API calls and models — covered.** 273 pinned/supplemental endpoint pairs and generic call(), uploads, pagination and explicit retries are implemented. A Web API transport audit is separate evidence; model coverage is not full Bolt runtime parity or coverage of every future Slack endpoint.

PHP: [src/WebApi/Client.php](../../src/WebApi/Client.php), [src/WebApi/MethodTransport.php](../../src/WebApi/MethodTransport.php).

Behavioral evidence: [tests/WebApi/GeneratedSurfaceTest.php](../../tests/WebApi/GeneratedSurfaceTest.php), [tests/WebApi/TransportContractTest.php](../../tests/WebApi/TransportContractTest.php), [tests/WebApi/UploadTest.php](../../tests/WebApi/UploadTest.php), [tests/WebApi/RetryPolicyTest.php](../../tests/WebApi/RetryPolicyTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts), [src/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/index.ts).

### node-receivers

**Express, Node server and AWS event classes — excluded.** Do not port Express middleware, Node server objects, raw Node request/response buffers or Lambda callback/event types. A concrete PHP serverless adapter can be added if requested, reusing existing ingress.

PHP: [src/Http/Receiver.php](../../src/Http/Receiver.php), [src/Integration/HttpController.php](../../src/Integration/HttpController.php).

No dedicated behavioral test is cited for the missing/excluded contract; the source establishes its absence or scope.

Upstream: [src/receivers/ExpressReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/ExpressReceiver.ts), [src/receivers/AwsLambdaReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/AwsLambdaReceiver.ts), [src/receivers/BufferedIncomingMessage.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/BufferedIncomingMessage.ts), [src/receivers/ParamsIncomingMessage.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/ParamsIncomingMessage.ts), [src/receivers/HTTPModuleFunctions.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPModuleFunctions.ts).

### js-types

**JavaScript types, re-exports and coded-error names — excluded.** Do not clone TypeScript utility types, Promise helpers, SDK/logger re-exports or each JS error class. PHP native types, exceptions, PSR interfaces and its package-owned SDK are the replacement. Behavioral errors remain in scope under errors/ack.

PHP: [src/Exceptions/HandlerFailures.php](../../src/Exceptions/HandlerFailures.php), [composer.json](../../composer.json).

No dedicated behavioral test is cited for the missing/excluded contract; the source establishes its absence or scope.

Upstream: [src/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/index.ts), [src/types/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/index.ts), [src/types/utilities.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/utilities.ts), [src/errors.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/errors.ts).

### unsafe-switches

**Unsigned production ingress and dev aliases — excluded.** Keep signature verification mandatory in the signed HTTP receiver. Do not port an off switch merely for matching options. developerMode is a Node convenience preset; explicit PHP env/CLI configuration is sufficient.

PHP: [src/Http/Receiver.php](../../src/Http/Receiver.php), [src/Http/RequestVerifier.php](../../src/Http/RequestVerifier.php), [src/Integration/bolt.config.php](../../src/Integration/bolt.config.php).

No dedicated behavioral test is cited for the missing/excluded contract; the source establishes its absence or scope.

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts).

### legacy-ssl

**Slash-command ssl_check probe — missing.** No dedicated ssl_check branch exists. Bolt returns an empty 200 for a sanitized probe; the PHP receiver rejects unsigned probes or reports an unsupported signed payload. Add this narrow compatibility path without allowing it to dispatch listener payloads or bypass verification for real deliveries. This is a Slack protocol gap, not a Node-only non-goal.

PHP: [src/Http/Receiver.php](../../src/Http/Receiver.php).

No dedicated behavioral test is cited for the missing/excluded contract; the source establishes its absence or scope.

Upstream: [src/receivers/HTTPModuleFunctions.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPModuleFunctions.ts), [src/receivers/ExpressReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/ExpressReceiver.ts).

### jobs-sessions

**Durable jobs, agent sessions and Work Objects — extension.** Named SQLite jobs, recovery/cancellation/retention, modern agent sessions and higher-level Work Object publishing are PHP product capabilities. They do not compensate for baseline gaps, and core function completion is only guarded per delivery.

PHP: [src/Jobs.php](../../src/Jobs.php), [src/Jobs/SqliteJobStore.php](../../src/Jobs/SqliteJobStore.php), [src/Assistant/Session.php](../../src/Assistant/Session.php), [src/WorkObjects/Publisher.php](../../src/WorkObjects/Publisher.php).

Behavioral evidence: [tests/Jobs/DurableExecutionTest.php](../../tests/Jobs/DurableExecutionTest.php), [tests/Jobs/JobOperationsTest.php](../../tests/Jobs/JobOperationsTest.php), [tests/Assistant/SessionTest.php](../../tests/Assistant/SessionTest.php), [tests/WorkObjects/PublisherTest.php](../../tests/WorkObjects/PublisherTest.php).

Upstream: [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts).

## All AppOptions

| Upstream | Status | Capability | PHP assessment |
| --- | --- | --- | --- |
| `signingSecret` | covered | [http](#http) | Raw-body HMAC verifier. |
| `endpoints` | adapted | [routing-lifecycle](#routing-lifecycle) | Stock route is /slack/events; custom controller can call Receiver.handle. No endpoint-list config knob. |
| `port` | adapted | [routing-lifecycle](#routing-lifecycle) | Tempest server/controller configuration. |
| `customRoutes` | adapted | [routing-lifecycle](#routing-lifecycle) | Tempest server/controller configuration. |
| `processBeforeResponse` | adapted | [ack](#ack) | Always process inline before response; explicit jobs replace slow inline work. No early-ack mode. |
| `signatureVerification` | excluded | [unsafe-switches](#unsafe-switches) | Verification cannot be disabled on the signed HTTP receiver. |
| `clientId` | covered | [oauth-core](#oauth-core) | OAuthConfig and InstallationStore with installation-scoped grants. |
| `clientSecret` | covered | [oauth-core](#oauth-core) | OAuthConfig and InstallationStore with installation-scoped grants. |
| `redirectUri` | covered | [oauth-core](#oauth-core) | OAuthConfig and InstallationStore with installation-scoped grants. |
| `scopes` | covered | [oauth-core](#oauth-core) | OAuthConfig and InstallationStore with installation-scoped grants. |
| `installationStore` | covered | [oauth-core](#oauth-core) | OAuthConfig and InstallationStore with installation-scoped grants. |
| `stateSecret` | adapted | [oauth-core](#oauth-core) | Opaque one-time state and browser binding replace signed state-secret encoding. |
| `installerOptions` | partial | [oauth-options](#oauth-options) | Basic v2 flow exists; advanced per-install metadata, hooks and install controls do not. |
| `convoStore` | adapted | [conversation](#conversation) | ConversationStore adapter or false, with stronger key scoping. |
| `token` | covered | [authorization](#authorization) | BoltConfig token and Socket Mode Config appToken. |
| `appToken` | covered | [authorization](#authorization) | BoltConfig token and Socket Mode Config appToken. |
| `botId` | covered | [static-identity](#static-identity) | Static BoltConfig.botId, SLACK_BOT_ID and explicit verification hydration. |
| `botUserId` | covered | [static-identity](#static-identity) | Explicit BoltConfig value; no automatic token-only hydration. |
| `authorize` | covered | [authorization](#authorization) | Authorizer or App.authorizeUsing callback. |
| `receiver` | adapted | [ingress](#ingress) | Compose Receiver.handle and App.dispatch with the host. |
| `logger` | adapted | [logging](#logging) | PSR-3 dependency. |
| `logLevel` | adapted | [logging](#logging) | Logger/host configuration. |
| `ignoreSelf` | adapted | [static-identity](#static-identity) | Filters supplied or explicitly verified bot identity; no hidden boot/network lookup. |
| `clientOptions` | adapted | [client](#client) | PSR-18 client plus explicit retry/upload configuration instead of Node options. |
| `socketMode` | adapted | [socket](#socket) | Explicit socket:serve command and Socket Mode Config. |
| `developerMode` | excluded | [unsafe-switches](#unsafe-switches) | No need for the Node convenience preset. |
| `tokenVerificationEnabled` | adapted | [static-identity](#static-identity) | Explicit App.verifyToken, auth:verify and socket:serve --verify-token replace eager initialization. |
| `deferInitialization` | adapted | [routing-lifecycle](#routing-lifecycle) | No equivalent async startup lifecycle to defer. Help/discovery need no token verification. |
| `extendedErrorHandler` | adapted | [errors](#errors) | Typed App.onError(FailureContext):ErrorHandling plus safe HTTP processing-error response hook; legacy error callback retained. |
| `attachFunctionToken` | partial | [function-policy](#function-policy) | Workflow token attachment is automatic with no opt-out. |

## All public App member names (overloads grouped)

| Upstream | Status | Capability | PHP assessment |
| --- | --- | --- | --- |
| `client` | adapted | [client](#client) | Inject WebApi.Client or Delivery.client instead of reading App.client. |
| `logger` | adapted | [logging](#logging) | Inject PSR-3 logger. |
| `(call signature)` | adapted | [authorization](#authorization) | Constructor/container setup with typed BoltConfig. |
| `init` | adapted | [routing-lifecycle](#routing-lifecycle) | Host and runners own lifecycle; no Node server lifecycle clone. Token verification remains a separate gap. |
| `start` | adapted | [routing-lifecycle](#routing-lifecycle) | Host and runners own lifecycle; no Node server lifecycle clone. Token verification remains a separate gap. |
| `stop` | adapted | [routing-lifecycle](#routing-lifecycle) | Host and runners own lifecycle; no Node server lifecycle clone. Token verification remains a separate gap. |
| `webClientOptions` | adapted | [client](#client) | Configure the PSR-18 transport and explicit retry policy. |
| `use` | covered | [scratch-context](#scratch-context) | Ordered pipeline with shared delivery-local Context. |
| `assistant` | covered | [assistant](#assistant) | Assistant builder and scoped runtime. |
| `function` | partial | [function-policy](#function-policy) | Function routing/completion exist; attachment policy and explicit auto-ack control differ. |
| `event` | covered | [listeners](#listeners) | Typed/fluent registration; unfiltered messages use an empty selector or event(message). |
| `message` | covered | [listeners](#listeners) | Typed/fluent registration; unfiltered messages use an empty selector or event(message). |
| `command` | covered | [listeners](#listeners) | Typed/fluent registration; unfiltered messages use an empty selector or event(message). |
| `action` | covered | [interactions](#interactions) | Typed/fluent registration with constraints. |
| `shortcut` | covered | [interactions](#interactions) | Typed/fluent registration with constraints. |
| `options` | covered | [interactions](#interactions) | Typed/fluent registration with constraints. |
| `view` | covered | [interactions](#interactions) | Typed/fluent registration with constraints. |
| `error` | adapted | [errors](#errors) | Legacy error callback remains; onError supplies typed delivery/stage/listener/ack context with explicit policy. |
| `processEvent` | adapted | [ingress](#ingress) | Existing trusted App.dispatch replaces the Node ingress shape. |

## All built-in middleware exports

| Upstream export | Reviewed capability |
| --- | --- |
| `isSlackEventMiddlewareArgsOptions` | [builtin-families](#builtin-families) |
| `onlyActions` | [builtin-families](#builtin-families) |
| `onlyShortcuts` | [builtin-families](#builtin-families) |
| `onlyCommands` | [builtin-families](#builtin-families) |
| `onlyOptions` | [builtin-families](#builtin-families) |
| `onlyEvents` | [builtin-families](#builtin-families) |
| `onlyViewActions` | [builtin-families](#builtin-families) |
| `autoAcknowledge` | [ack](#ack) |
| `matchConstraints` | [interactions](#interactions) |
| `matchMessage` | [listeners](#listeners) |
| `matchCommandName` | [listeners](#listeners) |
| `matchEventType` | [listeners](#listeners) |
| `ignoreSelf` | [static-identity](#static-identity) |
| `subtype` | [subtype-mention](#subtype-mention) |
| `directMention` | [subtype-mention](#subtype-mention) |

## All extracted source modules

Every module is assigned for review. This does not turn every TypeScript alias/re-export in the module into an implemented PHP class. The separate scaffold inventory still describes declarations only.

| Upstream module | Reviewed capabilities |
| --- | --- |
| [src/App.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts) | [listeners](#listeners), [authorization](#authorization), [errors](#errors), [static-identity](#static-identity), [function-policy](#function-policy) |
| [src/Assistant.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/Assistant.ts) | [assistant](#assistant) |
| [src/AssistantThreadContextStore.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/AssistantThreadContextStore.ts) | [assistant](#assistant) |
| [src/CustomFunction.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/CustomFunction.ts) | [functions](#functions) |
| [src/context/create-function-complete.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-function-complete.ts) | [functions](#functions) |
| [src/context/create-function-fail.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-function-fail.ts) | [functions](#functions) |
| [src/context/create-respond.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-respond.ts) | [replies](#replies), [captures](#captures), [scratch-context](#scratch-context) |
| [src/context/create-say-stream.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-say-stream.ts) | [assistant](#assistant) |
| [src/context/create-say.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-say.ts) | [replies](#replies), [captures](#captures), [scratch-context](#scratch-context) |
| [src/context/create-set-status.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-set-status.ts) | [assistant](#assistant) |
| [src/context/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/index.ts) | [replies](#replies), [captures](#captures), [scratch-context](#scratch-context) |
| [src/conversation-store.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/conversation-store.ts) | [conversation](#conversation) |
| [src/errors.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/errors.ts) | [errors](#errors), [js-types](#js-types) |
| [src/helpers.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/helpers.ts) | [authorization](#authorization), [interactions](#interactions), [ingress](#ingress) |
| [src/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/index.ts) | [js-types](#js-types) |
| [src/middleware/builtin.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/builtin.ts) | [builtin-families](#builtin-families), [subtype-mention](#subtype-mention), [ack](#ack), [static-identity](#static-identity) |
| [src/middleware/process.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/process.ts) | [pipeline](#pipeline) |
| [src/receivers/AwsLambdaReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/AwsLambdaReceiver.ts) | [node-receivers](#node-receivers), [http](#http), [legacy-ssl](#legacy-ssl) |
| [src/receivers/BufferedIncomingMessage.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/BufferedIncomingMessage.ts) | [node-receivers](#node-receivers), [http](#http), [legacy-ssl](#legacy-ssl) |
| [src/receivers/ExpressReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/ExpressReceiver.ts) | [node-receivers](#node-receivers), [http](#http), [legacy-ssl](#legacy-ssl) |
| [src/receivers/HTTPModuleFunctions.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPModuleFunctions.ts) | [node-receivers](#node-receivers), [http](#http), [legacy-ssl](#legacy-ssl) |
| [src/receivers/HTTPReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPReceiver.ts) | [http](#http), [ack](#ack), [routing-lifecycle](#routing-lifecycle), [oauth-options](#oauth-options) |
| [src/receivers/HTTPResponseAck.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPResponseAck.ts) | [http](#http), [ack](#ack), [routing-lifecycle](#routing-lifecycle), [oauth-options](#oauth-options) |
| [src/receivers/ParamsIncomingMessage.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/ParamsIncomingMessage.ts) | [node-receivers](#node-receivers), [http](#http), [legacy-ssl](#legacy-ssl) |
| [src/receivers/SocketModeFunctions.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeFunctions.ts) | [socket](#socket), [ack](#ack) |
| [src/receivers/SocketModeReceiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeReceiver.ts) | [socket](#socket), [ack](#ack) |
| [src/receivers/SocketModeResponseAck.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeResponseAck.ts) | [socket](#socket), [ack](#ack) |
| [src/receivers/custom-routes.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/custom-routes.ts) | [http](#http), [ack](#ack), [routing-lifecycle](#routing-lifecycle), [oauth-options](#oauth-options) |
| [src/receivers/verify-redirect-opts.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/verify-redirect-opts.ts) | [http](#http), [ack](#ack), [routing-lifecycle](#routing-lifecycle), [oauth-options](#oauth-options) |
| [src/receivers/verify-request.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/verify-request.ts) | [http](#http), [ack](#ack), [routing-lifecycle](#routing-lifecycle), [oauth-options](#oauth-options) |
| [src/receivers/verify-signing-secret.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/verify-signing-secret.ts) | [http](#http), [ack](#ack), [routing-lifecycle](#routing-lifecycle), [oauth-options](#oauth-options) |
| [src/types/actions/block-action.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/actions/block-action.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/actions/dialog-action.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/actions/dialog-action.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/actions/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/actions/index.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/actions/interactive-message.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/actions/interactive-message.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/command/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/command/index.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/events/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/events/index.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/index.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/middleware.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/middleware.ts) | [scratch-context](#scratch-context), [captures](#captures), [host-context](#host-context) |
| [src/types/options/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/options/index.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/receiver.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/receiver.ts) | [ingress](#ingress), [host-context](#host-context), [ack](#ack) |
| [src/types/shortcuts/global-shortcut.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/shortcuts/global-shortcut.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/shortcuts/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/shortcuts/index.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/shortcuts/message-shortcut.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/shortcuts/message-shortcut.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/utilities.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/utilities.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
| [src/types/view/index.ts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/view/index.ts) | [payload-types](#payload-types), [interactions](#interactions), [js-types](#js-types) |
