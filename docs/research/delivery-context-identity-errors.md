# Delivery context, static identity and structured failures

Implemented 28 October 2026 following the first three gaps in the [Bolt JS parity audit](bolt-js-parity-audit.md). The public interface stays PHP-native; help, discovery, construction and ordinary dispatch do not run an implicit token-verification request.

## Static-token identity

`App::verifyToken()` explicitly calls [Slack auth.test](https://docs.slack.dev/reference/methods/auth.test/), validates identifiers and returns a credential-free `Authorization\TokenIdentity`. Successful identity is cached for that App instance and used by subsequent authorization/self-filtering. Failed lookups are not cached. Configured bot IDs must agree with the result. Verified workspace/enterprise identity is checked against incoming deliveries; custom authorizers and OAuth installations continue owning their own authorization and rotation, and cannot be replaced by this method.

```php
$identity = $app->verifyToken(); // Explicit network boundary; call before worker startup.
// $identity->userId, botId, teamId, enterpriseId
```

`php bolt auth:verify` performs the same explicit check and prints identity JSON. It does not print the token, edit `.env` or warm another process's cache. For HTTP deployments, copy `user_id` and `bot_id` into `SLACK_BOT_USER_ID` and `SLACK_BOT_ID` to hydrate identity without lookup on each request. Alternatively, a host that keeps an App instance alive can call `verifyToken()` at its startup boundary. For Socket Mode, `php bolt socket:serve --verify-token` verifies before connecting and keeps the identity in that worker. The flag deliberately targets static-token apps; it does not pre-authorize all OAuth installations.

`BoltConfig::botId` and `SLACK_BOT_ID` cover self-authored `bot_message` events that contain `bot_id` without a `user` field. Manually configured IDs still permit network-free operation. Verification remains explicit rather than copying Bolt JS's eager default, because PHP request lifetimes and console discovery differ.

## Request-local context

`Thunder\Bolt\Context` holds ephemeral values for one delivery. It supports `set`, `get`, `has`, `require` and `remove`; a class name can be used as a key for an application DTO. `has()` distinguishes a present null from a missing key, and `require()` throws for missing keys. Values can be live objects; the context is deliberately not serializable and print_r/var_dump output lists keys only.

```php
use Thunder\Bolt\Context;
use Thunder\Bolt\Invocation\Delivery;

$app->use(function (Delivery $delivery, Closure $next) use ($tickets): mixed {
    $delivery->context->set('ticket', $tickets->findFor($delivery->payload));
    return $next();
});

$app->event('app_mention', function (Context $context): void {
    $ticket = $context->require('ticket');
    // Use the record fetched by middleware.
});
```

The same object is shared through global/listener middleware, matching handlers and assistant handlers. Method injection receives that object directly. A handler with Context in its concrete constructor dependency graph gets a GenericContainer invocation scope. Existing authorization/job scopes also bind it; ordinary static listeners keep their original application singleton behavior. Custom containers still support typed method injection; constructor isolation requires GenericContainer. Opaque interface/factory dependencies cannot be inferred from a constructor graph: prefer method injection for request values in those cases. Separate dispatches, nested dispatches and tenants receive different objects. Job attempts get a fresh Context, never the originating request's context or a root-container value. Carry durable values in an explicit named job DTO; conversation/thread state remains a separate persistent capability.

There is no new host-specific context extractor in this slice. Custom hosts can enrich context in middleware, and `dispatch(body, metadata)` still accepts host metadata. A dedicated stock-adapter factory remains a separate P2 item.

## Structured failure policy

```php
use Thunder\Bolt\Errors\{ErrorHandling, FailureContext};

$app->onError(function (FailureContext $failure) use ($logger): ErrorHandling {
    $logger->error('Slack delivery failed.', $failure->summary());
    return ErrorHandling::Rethrow;
});
```

`FailureContext` retains the original throwable, the failure stage, available delivery/source, matching listener, metadata and whether a valid acknowledgement is buffered. Payload, authorization, middleware, listener and acknowledgement stages are distinguished; assistant handler errors retain their prepared assistant delivery. Policy runs once per captured error after independent matching listeners have run. A normal handled failure can permit an event acknowledgement, or preserve the one valid interaction response another listener returned. `Rethrow` preserves the original exception; multiple original errors aggregate. A throwing/invalid policy aggregates its error with the originals.

`Handled` does not bypass failed authorization or malformed-payload rejection, acknowledge failed durable intake, or manufacture a missing/duplicate interaction acknowledgement. Buffered response state is not a claim that the response was sent: `acknowledgement_sent` is false in these pre-response hooks. Error stages describe the captured failure; errors thrown by middleware after a successful `next()` remain middleware failures.

`onError()` takes precedence over the existing `error(aggregate, payload, metadata)` callback. The legacy callback retains its signature and aggregation behavior. `FailureContext::summary()`, JSON encoding and print_r/var_dump output omit exception messages, raw payloads, token objects and context values. Handlers can explicitly inspect the original throwable and live delivery when needed; do not log the full objects. Live failure context cannot be serialized.

The HTTP receiver additionally accepts an `errorHandler` constructor callback returning `Http\Response` for unexpected processing exceptions. It receives a receiver-stage FailureContext with source/transport identity; its response must have a 400–599 status. Invalid/throwing policies fall back to 500 and preserve both errors in logging. Signature, body-size and payload-rejection statuses remain fixed. Socket Mode keeps its existing no-ack-on-processing-failure behavior; App policy works on both transports. Route errors remain the PHP host's responsibility, and this slice does not introduce an early-ack mode or a timeout scheduler.

## Evidence and limits

- `tests/Runtime/ContextAndIdentityTest.php`: global/method/constructor context sharing, cross-tenant isolation, null semantics, redacted debug/serialization, explicit/cached/retryable identity lookup, malformed identities, self messages and tenant mismatch.
- `tests/Runtime/FailureContextTest.php`: listener identity, original/multiple errors, prepared response state, policy errors, middleware-after-next classification and fail-closed authorization/intake/acknowledgements.
- `tests/Assistant/RuntimeTest.php`: assistant context propagation and original listener identity.
- `tests/Jobs/JobRequestContextTest.php`: fresh job scopes and no inherited root context.
- `tests/Http/ReceiverFailurePolicyTest.php`: custom error response and rejection of success responses on failed intake.
- `composer test:project`: production dependency installation, the real `auth:verify` command with a configured fixture transport, signed interactions and durable recovery in a new process.

No live Slack messages or production databases are needed to verify this slice. Existing OAuth authorizers, legacy error callbacks, HTTP/Socket Mode delivery, durable jobs and assistant cancellation remain regression-test surfaces.

Validation passed 862 tests / 5,338 assertions, the fresh-project smoke check, seven reference-tooling tests, generated/transport/parity consistency checks and Mago formatting/lint/analysis with no errors. Advisory diagnostics remain. The installed development app also passed a real `auth:verify` call; it returned identity only and posted no messages. Independent standards and specification reviews passed after singleton-identity and missing-team regression checks were added.
