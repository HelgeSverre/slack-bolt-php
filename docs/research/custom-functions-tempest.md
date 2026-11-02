Implementation status: the final public capability is `Functions\Execution`, with `inputs()` and `context`. The application-owned Tempest handoff below is implemented in the hello-bolt demo. Inline receivers acknowledge after dispatch returns; only queued business work runs after that acknowledgement. This research distinguishes the desired protocol from that receiver limitation.

# Custom functions and Tempest async commands

## Decision

Implement the first custom-function runtime as an **inline, auto-acknowledged**
`function_executed` listener with an explicit execution object. Keep completion
and failure independent from acknowledgement. Do not make Tempest's command bus
the framework's default background-runtime abstraction yet.

That is Bolt JS parity without pretending that a process-local handler is a
durable workflow worker. An optional application adapter can hand an execution
off to Tempest (or another queue), but it must be deliberately configured.

## Implementation status (2026-10-24)

The framework now has the small synchronous runtime described here: a
`function_executed` delivery creates a non-serializable
`Functions\ExecutionContext` and `Functions\Execution`. The handler receives
the execution, can use `inputs()`, `client()`, `complete()`, `fail()`, and
`state()`, and gets the function workflow token through its delivery client.
`ExecutionContext::snapshot()` is token-free; `workflowToken()` is an explicit
sensitive accessor for an application-owned protected handoff.

The Tempest adapter in `examples/hello-bolt` is a demo of that boundary, not a
new core queue. It persists a protected record before dispatching an opaque
`#[Async]` command, while the existing HTTP/Socket receivers emit their normal
empty transport acknowledgement after the inline listener returns. In other
words, this implementation guarantees **persist before receiver acknowledgement**;
it does not claim that the HTTP acknowledgement is written before the listener
runs.

## Bolt and Slack contract

`App::function($callbackId, $handler)` is a listener for the event whose
`function.callback_id` matches `$callbackId`. Slack requires the step to end
through exactly one of `functions.completeSuccess` (outputs) or
`functions.completeError` (an error string). The initial acknowledgement merely
confirms receipt; it does **not** complete the workflow.

This matches Bolt JS' default: `App#function()` constructs its listener with
`autoAcknowledge: true`, puts `autoAcknowledge` before the handler, and injects
`inputs`, `complete`, and `fail`. It also injects those helpers into
function-scoped `block_actions`, so a custom step may deliberately remain in
progress until a person clicks a later action.

Slack's public example event contains all of the useful durable identifiers:

- `event.function_execution_id` — the natural execution/idempotency key;
- `event.workflow_execution_id` — useful correlation, but not a replacement for
  the function execution ID;
- `event.function.callback_id` and `event.inputs` — routing and user data;
- `event.bot_access_token` — a workflow token for this execution.

Sources: [Slack custom steps](https://docs.slack.dev/tools/bolt-js/concepts/custom-steps/),
[function_executed event](https://docs.slack.dev/reference/events/function_executed/),
and pinned Bolt JS [`CustomFunction.ts`](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/CustomFunction.ts).

## Recommended small PHP surface

```php
$app->function('vendor.create', function (FunctionExecution $execution): void {
    $vendor = Vendor::create($execution->inputs->string('name'));

    $execution->complete(outputs: ['vendor_id' => $vendor->id]);
});

$app->action('approve_vendor', function (Action $action, FunctionExecution $execution): ActionResponse {
    $execution->complete(outputs: ['approved_by' => $action->userId]);

    return ActionResponse::empty();
});
```

`FunctionExecution` should be a delivery-scoped, one-shot capability rather
than two unrelated closure arguments. Its useful minimum is:

- readonly `id`, `callbackId`, `workflowExecutionId`, and typed/raw `inputs`;
- `complete(array $outputs = []): FunctionsCompleteSuccessResponse`;
- `fail(string $error): FunctionsCompleteErrorResponse`;
- `isSettled(): bool` (or `hasBeenCompleted()` / `hasBeenFailed()` if the
  public naming needs to mirror Bolt JS exactly).

It should reject a second terminal call locally. This is friendlier than Bolt
JS' `hasBeenCalled()` only flag and prevents a handler from sending both a
success and failure by accident. It is not distributed idempotency: the remote
outcome can still be unknown after a transport failure.

The existing typed bindings already cover
`FunctionsCompleteSuccessRequest` and `FunctionsCompleteErrorRequest`. The
new object should use the delivery's client, selected from the function's
`bot_access_token` for the live delivery, as Bolt JS does. It must not silently
fall back to an installed bot token when an execution supplies a workflow token.

## Acknowledge before execution

Bolt JS auto-acknowledges before the user handler. Thunder Bolt's present HTTP
and Socket Mode receivers write their acknowledgement only after
`App::dispatch()` returns. The implemented Tempest demo keeps that inline phase
short—durably storing the protected execution and dispatching its opaque
command—then returns so the receiver can acknowledge. A future true
"ack-before-handler" receiver seam remains a distinct transport improvement.

For function-scoped interactive payloads, preserve normal action/view/shortcut
acknowledgement rules and expose the same `FunctionExecution` when
`function_data.execution_id` is present. This makes the documented
"post a button now, complete on click later" flow possible without special
state machinery.

## Safe optional handoff

If a user needs slow work, add an opt-in interface such as
`FunctionExecutionDispatcher` / `FunctionJobDispatcher`; make synchronous
execution the default. Its persisted payload should be a small data transfer
object:

```php
final readonly class QueuedFunctionExecution
{
    public function __construct(
        public string $executionId,
        public string $callbackId,
        public array $inputs,
        public ?string $workflowExecutionId,
        public InstallationIdentity $installation,
        public string $credentialReference,
        public string $deliveryId, // Slack event_id when supplied
    ) {}
}
```

`InstallationIdentity` is a new scalar-only value (`teamId`, `enterpriseId`,
`userId`, conversation ID, and enterprise-install flag); it must not reuse
`AuthorizationSource`, whose `raw` envelope would serialize the signed event.
The receiving worker resolves that identity through the app's installation
store/authorizer again, makes a new delivery-scoped client, invokes the
registered handler, then calls `complete()` or `fail()`.

The function's `bot_access_token` is special: Bolt JS defaults
`attachFunctionToken` to true and deliberately uses that unique workflow token
instead of its pooled installed-client token. A cross-process handoff therefore
needs a short-lived, encrypted credential-vault record keyed by
`credentialReference`; the queued command contains only that reference. The
worker retrieves the token only to construct the execution client and deletes
the vault record once a terminal result is safely recorded. Do **not** put a
bot/workflow token, `Authorization`, PSR client, closure authorizer, `Reply`, or
raw signed request into a PHP-serialized job. Apart from disclosure at rest,
those values can be stale or unserializable.

Use `executionId` as the unique key in a tiny durable execution ledger. Its
state should at least distinguish `accepted`, `running`, `succeeded`, `failed`,
and `completion_unknown`; store the terminal output/error before (or as part of)
the remote completion attempt. Claim work atomically and retain the result so a
Slack retry or worker recovery cannot repeat user-visible work. If the call to
Slack times out after it may have succeeded, record `completion_unknown` and
reconcile/retry under an explicitly documented policy; do not report a new,
different terminal result.

This is intentionally an execution ledger, not a framework-wide queue. It is
the small durable boundary Slack's split acknowledgement/completion protocol
actually needs.

## What installed Tempest 3 provides

This checkout has `tempest/framework` **v3.20.0**. It includes a real async
command feature:

- `#[Tempest\CommandBus\Async]` on a command or handler stores the command;
- `tempest command:monitor` is a required daemon and launches up to five child
  `command:handle` processes;
- repositories are file (the default), memory, database, or Redis;
- Tempest documents the feature as **experimental**, outside its backwards
  compatibility promise.

Reference: [Tempest command bus — async commands](https://tempestphp.com/3.x/features/command-bus#async-commands);
the pinned implementation is
[`AsyncCommandMiddleware.php`](https://github.com/tempestphp/tempest-framework/blob/e114a7f8ebf9f8653e51039cd0b83ff4c298e18a/packages/command-bus/src/AsyncCommandMiddleware.php),
[`MonitorAsyncCommands.php`](https://github.com/tempestphp/tempest-framework/blob/e114a7f8ebf9f8653e51039cd0b83ff4c298e18a/packages/command-bus/src/MonitorAsyncCommands.php),
and [`HandleAsyncCommand.php`](https://github.com/tempestphp/tempest-framework/blob/e114a7f8ebf9f8653e51039cd0b83ff4c298e18a/packages/command-bus/src/HandleAsyncCommand.php).

It is persistence plus a polling worker, not yet a full durable-work guarantee:

- every backend serializes the command with PHP `serialize()`;
- a command is marked done only after the handler returns; a crash can therefore
  replay it;
- a thrown handler error is immediately marked failed; there is no retry count,
  backoff, delay, visibility lease, or dead-letter retry flow;
- the monitor reads pending commands before spawning workers, with no repository
  claim/reservation operation; concurrent monitors should therefore not be
  treated as single-delivery workers;
- Tempest's `#[Idempotent]` support can deduplicate command dispatch by payload
  or a `HasIdempotencyKey`, but async dispatch considers the command handled as
  soon as its handler has been queued, not when the background handler has
  completed.

### Practical Tempest adapter boundary

An application that accepts those constraints may write an adapter command such
as `#[Async] #[Idempotent] RunQueuedFunctionExecution`, implement
`HasIdempotencyKey` with `executionId`, and configure the database or Redis
repository plus `command:monitor` as a supervised daemon. The command itself
should contain only the safe DTO above. The handler should use the application's
execution ledger to acquire its own claim before performing side effects.

The hello-bolt example implements this explicit handoff with a protected vault
and reference-only Tempest command. It does not claim Tempest supplies a complete
Slack retry or recovery contract.

## Tests that establish the contract

1. `function_executed` with a matching callback selects a typed execution and
   exposes inputs without requiring an explicit event response value. The queued
   example returns after durable handoff, before its worker's terminal API call.
2. `complete()` sends exactly `function_execution_id` and `outputs` to
   `functions.completeSuccess`; `fail()` sends the ID and error to
   `functions.completeError`; a second terminal call fails locally.
3. The delivery client uses the function workflow token; it is redacted in
   diagnostics and absent from any queued DTO.
4. A function-scoped action receives the same execution capability, still
   requires its ordinary action acknowledgement, and can settle the original
   function.
5. Duplicate `event_id` / `function_execution_id` and crash recovery exercise
   the application ledger, not a best-effort in-memory guard.
