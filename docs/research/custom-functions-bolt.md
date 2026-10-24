# Custom functions: Bolt JS contract and PHP seam

Research date: 2026-10-24. Scope: the pinned Bolt JS source, current Slack
documentation, and the existing generated completion requests. This is a
contract note, not an implementation plan.

## Decision summary

🟢 Model a workflow execution as one small `FunctionExecution` module. It owns
the execution ID, the short-lived workflow token, terminal-state protection,
and `complete(outputs)` / `fail(message)`. Handlers should never assemble the
completion request or select an authorization token themselves.

🟢 Preserve Bolt's `app.function($callbackId, $handler)` routing parity, but
inject the same execution module into every *function-scoped* interaction
payload, including `view_submission`. Bolt JS does this for actions but not
views; Slack documents the fields for both.

🟢 Treat a receive acknowledgement and execution completion as distinct. ACK
is delivery control; `functions.completeSuccess` / `functions.completeError`
are the terminal workflow state transitions.

## Verified inbound contract

| Delivery | Routing / data | Handler obligations |
| --- | --- | --- |
| `function_executed` event | Match `event.function.callback_id`; use `event.inputs`, `event.function_execution_id`, and `event.bot_access_token`. Slack's complete event shape also includes the workflow execution ID. | ACK the delivery (Bolt auto-ACKs by default); eventually call exactly one terminal completion method unless the execution is intentionally waiting for user interaction. |
| Function-scoped `block_actions` | `function_data.execution_id`, `function_data.function.callback_id`, `function_data.inputs`, and top-level `bot_access_token`. | ACK the interaction promptly, then complete/fail the original execution. |
| Function-scoped `view_submission` | Slack documents the same top-level `function_data` and `bot_access_token` fields. View state is additional user input, not a replacement for function inputs. | ACK with the normal view response and then complete/fail the original execution. |

Slack's event reference provides the canonical `function_executed` payload,
including callback ID, inputs, execution ID, and event-scoped bot token:
[function_executed](https://docs.slack.dev/reference/events/function_executed/).
The interaction references explicitly mark `function_data` and
`bot_access_token` as function-only fields for
[block actions](https://docs.slack.dev/reference/interaction-payloads/block_actions-payload/)
and [view submissions](https://docs.slack.dev/reference/interaction-payloads/view-interactions-payload/).

## Bolt JS parity, precisely

🟢 `App#function()` creates a `CustomFunction` listener. Its chain is
`onlyEvents -> function_executed -> callback_id match -> optional auto ACK ->
handler`; the default is `autoAcknowledge: true`.
[Pinned source: `App.ts`](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L520-L550),
[`CustomFunction.ts`](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/CustomFunction.ts#L48-L101).

🟢 Bolt exposes `inputs`, `complete`, `fail`, and an event `ack` for the
`function_executed` handler. `complete` maps to `functions.completeSuccess`
with `{function_execution_id, outputs}`; `fail` maps to
`functions.completeError` with `{function_execution_id, error}`. The helpers
only track `hasBeenCalled()` locally; they do not make duplicate terminal calls
safe across requests/processes.
[Pinned source: success helper](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-function-complete.ts),
[failure helper](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-function-fail.ts).

🟡 The pinned app extracts function context for both events and `function_data`
payloads, and replaces its normal Web API client token with this context's
function token by default. It injects `complete`, `fail`, and `inputs` into
action handlers, but *not* into view handlers. This is an observed Bolt JS
implementation gap, not a Slack limitation.
[Pinned extraction/injection](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L978-L1115),
[token selection](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L1606-L1645).

## ACK is not completion

`ack()` answers the inbound HTTP/Socket delivery. It must happen quickly for
interactive payloads, and Bolt normally auto-acknowledges function events.
It does **not** change the workflow execution state. `complete()` and `fail()`
are separate Web API calls which make that state terminal. Slack says a custom
step that waits for user interaction may omit terminal completion in its
function handler, but the eventual interaction handler must finalize it.
[Bolt custom-step guidance](https://docs.slack.dev/tools/bolt-js/concepts/custom-steps/).

`functions.completeSuccess` requires the execution ID plus an outputs object
conforming to the manifest output schema; `functions.completeError` requires
the execution ID plus a user-readable error.
[Success method](https://docs.slack.dev/reference/methods/functions.completeSuccess/),
[error method](https://docs.slack.dev/reference/methods/functions.completeError/).
Both can return `execution_not_in_running_state` or
`function_execution_not_found`. Therefore an application should regard the
execution ID as an idempotency/correlation key, protect duplicate terminal
calls in-process, and surface a clear terminal-state exception for a rejected
second completion.

## Authorization boundary

The payload token is a workflow/JIT token, not the installation's durable bot
token. It is sent as `event.bot_access_token` for `function_executed` and as
top-level `bot_access_token` for function-scoped interactions. Bolt calls it
`functionBotAccessToken`; the requested `function_bot_access_token` is a good
PHP context property name, but the wire field is `bot_access_token`.

Slack says workflow tokens begin with `xwfp-`, cannot be refreshed, expire in
15 minutes or immediately after successful/error completion, and may have
borrowed channel visibility. They must be treated as sensitive, ephemeral
credentials: do not log them, put them in a reusable token/client pool, or
persist them in normal job payloads. [Token lifecycle and borrowed
visibility](https://docs.slack.dev/authentication/tokens/).

The existing generated `FunctionsCompleteSuccessRequest` and
`FunctionsCompleteErrorRequest` already have the required wire fields and a
sensitive `token` argument. The runtime execution module should supply it
internally, so ordinary handler code cannot accidentally fall back to an
installation token.

## Recommended PHP interface

Keep the external seam small and make the lifecycle implementation deep:

```php
$app->function('summarize_document', function (
    FunctionInput $input,
    FunctionExecution $execution,
): void {
    $execution->complete([
        'summary' => $input->string('document'),
    ]);
});

$app->action('approve_summary', function (
    ActionRequest $request,
    FunctionExecution $execution,
): void {
    $request->ack();
    $execution->complete(['approved_by' => $request->userId]);
});
```

`FunctionExecution` should be absent from ordinary actions/views and required
only when trusted, verified payload extraction found both an execution ID and
workflow token. It should expose `complete(array $outputs = [])` and
`fail(string $message)`, plus a read-only `isTerminal()` for exceptional
control flow. A typed input wrapper can offer `string()`, `int()`, `bool()`,
and `object()` while retaining `all()` for manifest-defined Slack types.

For a Tempest-style deferred command, ACK first and dispatch only safe business
data to the queue. A terminal completion requires the expiring execution
credential, so a durable job must either execute before token expiry using a
proper secret transport or reject deferral explicitly. Do not imply that a
normal durable queue can resume a custom function after the JIT token expires.

## Test matrix

1. Callback ID dispatches only its matching `function_executed` event; regular
   event listeners retain normal behavior.
2. Event, action, and view function-scoped payload variants each create the
   same execution context from their documented field locations.
3. JIT token overrides the installation token for messages and terminal calls;
   it is redacted and never cached.
4. A plain action/view receives no function execution helper.
5. ACK and complete/fail are independently observable; an interaction can ACK
   before terminal completion.
6. Output payloads and error messages serialize exactly to the generated Web
   API requests; a second local terminal call fails before an outbound request.
