# HTTP interactivity parity: Bolt JS 5.1.0

**Scope.** This is the HTTP contract for actions, shortcuts, option loading, and
modal views. It is pinned to Bolt JS `v5.1.0`, commit
[`ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d`](https://github.com/slackapi/bolt-js/tree/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d).
It is a design and compatibility record, not a claim that every listed payload
is already implemented in Thunder\Bolt.

Slack sends HTTP POST form data with a JSON `payload` field. A valid
interaction needs an HTTP 200 acknowledgement within three seconds; a
`response_url` is a separate, short-lived message capability, not the HTTP
acknowledgement. [Slack's interaction guide](https://docs.slack.dev/interactivity/handling-user-interaction/)
documents both the form encoding and the three-second rule.

## Routing contract 🟢

| Registration | Bolt JS string / regex shorthand | Object constraints | Incoming payload types |
| --- | --- | --- | --- |
| `action()` | `action_id` | `action_id`, `block_id`, `callback_id`, `type` | `block_actions`, `interactive_message`, `dialog_submission` |
| `shortcut()` | `callback_id` | `callback_id`, `type` | `shortcut`, `message_action` |
| `options()` | `action_id` | `action_id`, `block_id`, `callback_id`, `type` | `block_suggestion`, legacy `interactive_message`, `dialog_suggestion` |
| `view()` | `callback_id`, **and defaults to `view_submission`** | `callback_id`, `type` | `view_submission`, `view_closed` |

The normalisations and accepted action/shortcut/view keys are in
[`App.ts` lines 682–881](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L682-L881).
Bolt permits only `view_submission` and `view_closed` for a view listener.
`view_closed` is opt-in both at Slack (the view was opened with
`notify_on_close: true`) and in routing: `view('ticket')` deliberately does
not receive close events. [Slack's view reference](https://docs.slack.dev/reference/interaction-payloads/view-interactions-payload/)
and the [Bolt JS view guide](https://docs.slack.dev/tools/bolt-js/concepts/view-submissions/)
confirm that explicit `type: 'view_closed'` requirement.

String constraints are literal equality. A `RegExp` uses JavaScript
`String.match`; successful match arrays are exposed as `blockIdMatches`,
`actionIdMatches`, or `callbackIdMatches` in the Bolt context. The matcher
also conjunctively applies every supplied constraint. See
[`matchConstraints()` lines 142–222](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/builtin.ts#L142-L222).

**First-action rule.** For `block_actions` and legacy
`interactive_message`, Bolt exposes only `body.actions[0]` as the `action` /
`payload` used for `block_id` and `action_id` matching; it does not iterate
all actions. The complete body remains available. This is explicit in
[`App.ts` lines 1013–1025](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L1013-L1025).
Thunder\Bolt should preserve that default for Bolt parity and expose the full
actions array on the typed envelope so an application can intentionally make a
different choice.

### Implemented PHP surface 🟢

Thunder\Bolt keeps the four Bolt verbs and uses one immutable constraint value.
Handlers receive a typed payload and return the response family accepted by
that payload; `Reply` provides an optional response-URL capability.

```php
use Thunder\Bolt\Routing\InteractionConstraints;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\OptionsResponse;
use Thunder\Bolt\Responses\ViewResponse;

$app->action('approve_order', function (Action $action): ActionResponse {
    return ActionResponse::empty();
});

$app->action(
    InteractionConstraints::action(actionId: 'approve_order', blockId: 'orders'),
    function (Action $action, Reply $reply): ActionResponse {
        $reply->replaceOriginal('Approved');

        return ActionResponse::empty();
    },
);

$app->options(
    InteractionConstraints::options(callbackId: 'legacy_picker', type: 'dialog_suggestion'),
    fn (Options $suggestion): OptionsResponse => OptionsResponse::fromArray([
        'options' => [['label' => 'Ada Lovelace', 'value' => 'U123']],
    ]),
);

$app->view('ticket_form', fn (View $view): ViewResponse => ViewResponse::empty());
$app->view(
    InteractionConstraints::view(callbackId: 'ticket_form', type: 'view_closed'),
    fn (View $view): ViewResponse => ViewResponse::empty(),
);
```

`InteractionConstraints::action()` and `::options()` permit a null
`actionId`, so legacy callback-only routes use the named `callbackId` argument.
The HTTP response is the one returned acknowledgement value; the optional
`Reply` response URL sends a separate webhook request. A global shortcut has
no response URL; a message shortcut does. [Slack's shortcut reference](https://docs.slack.dev/reference/interaction-payloads/shortcuts-interaction-payload/)
states this distinction directly.

## Payload and acknowledgement matrix 🟢

| Source | Routed as | Immediate ack body | Delayed response route |
| --- | --- | --- | --- |
| `block_actions` | action; first `actions[0]` | empty | top-level `response_url`, when present |
| `interactive_message` action | action; first `actions[0]` | empty or legacy message response | top-level `response_url` |
| `dialog_submission` | action; payload is whole body | empty or `{errors: [{name, error}]}` | top-level `response_url` |
| `shortcut` | shortcut | empty | none |
| `message_action` | shortcut | empty | top-level `response_url` |
| `block_suggestion` | options | `{options: [...]}` or `{option_groups: [...]}` | no response URL contract |
| legacy `interactive_message` suggestion | options | `{options: [...]}` or `{option_groups: [...]}` | no response URL contract |
| `dialog_suggestion` | options | `{options: [{label, value}]}` or legacy string-labelled groups | no response URL contract |
| `view_submission` | view | empty, or `response_action` (`errors`, `update`, `push`, `clear`) | first `response_urls[0].response_url`, if enabled |
| `view_closed` | view only when explicitly constrained | empty | none |

The three option shapes are declared in
[`types/options/index.ts` lines 153–180](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/options/index.ts#L153-L180).
The action acknowledgement distinction, including legacy interactive message
and dialog validation, is in
[`types/actions/index.ts` lines 71–79](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/actions/index.ts#L71-L79).
The view acknowledgement types and per-element response URL metadata are in
[`types/view/index.ts` lines 11–70](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/view/index.ts#L11-L70).

For a view submission, Bolt chooses the first entry from `response_urls`; it
does not choose by block or action ID. See
[`App.ts` lines 1135–1141](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L1135-L1141).
Thunder\Bolt preserves the complete payload in `View::$raw` and its `Reply`
selects the first entry for parity. It deliberately has no convenience
per-element response-URL selector yet. Slack documents that `response_urls`
exist only when a view element is configured with `response_url_enabled`, and
carry block, action, and channel identifiers.

`response_url` calls may be made five times during 30 minutes and default to
ephemeral messages; they remain distinct from the required ack. See
[Slack's response URL guidance](https://docs.slack.dev/interactivity/handling-user-interaction/#message-responses).

## Legacy boundary 🟡

Bolt JS 5.1.0 supports legacy `interactive_message` actions and suggestions,
`dialog_submission`, and `dialog_suggestion`. Its source classifies options
by `name` or `block_suggestion`, actions by `actions` or
`dialog_submission`, then separately classifies shortcuts and views:
[`helpers.ts` lines 78–109](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/helpers.ts#L78-L109).

`dialog_cancellation` is a deliberate gap in Bolt JS 5.1.0: Slack still can
send it when legacy dialog `notify_on_cancel` is true, with `callback_id`,
`state`, and `response_url`, but the Bolt classifier and public action union
do not include it. Slack's [legacy dialog documentation](https://docs.slack.dev/legacy/legacy-dialogs/#dialog-cancellations)
shows the payload. Keep it as an explicit **unsupported** status for literal
Bolt parity, or add it as a clearly marked PHP extension with its own typed
listener. It must never be silently mistaken for `dialog_submission`.

## Error and lifecycle decisions 🟡

* Bolt rejects unknown registration keys for `action`, `shortcut`, and `view`
  by logging and declining to attach the listener; view also rejects unknown
  view types. It does **not** validate option object keys before registering
  them. PHP should improve this: every public constraint factory should
  validate accepted fields and throw `InvalidListener` synchronously. This
  prevents a misspelled legacy callback constraint from becoming a broad
  listener.
* An unrecognised payload type causes Bolt to log a warning and return without
  calling a listener or `ack`; see
  [`App.ts` lines 905–912](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L905-L912).
  A recognised payload with no matching listener behaves equivalently: the
  framework dispatches all candidates but none calls `ack`.
* On the default HTTP receiver, an unacknowledged request times out after
  3001 ms and is ended with 404. See
  [`HTTPResponseAck.ts` lines 62–93](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPResponseAck.ts#L62-L93)
  and [`HTTPModuleFunctions.ts` lines 224–240](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPModuleFunctions.ts#L224-L240).
  Thunder\Bolt intentionally differs today: unsupported interaction families
  return 501, and a recognised interaction with no returned acknowledgement
  raises `MissingAcknowledgement`, which the HTTP receiver reports as 500.
  This makes a missing route visible immediately, instead of Bolt's delayed
  404 after the timeout.
* An HTTP Bolt ack is single-use and throws `ReceiverMultipleAckError` on the
  second call. Socket Mode currently logs and ignores a second ack instead.
  [HTTP source](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPResponseAck.ts#L96-L116),
  [Socket Mode source](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeResponseAck.ts#L28-L41).
  Thunder\Bolt accepts exactly one returned acknowledgement value and raises
  its receiver-independent `DoubleAcknowledgement` for more than one.
* Bolt runs every matching listener pipeline concurrently rather than choosing
  a winner. Each matching handler must therefore not compete to ack the same
  request. See
  [`App.ts` lines 1160–1207](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L1160-L1207).
  PHP's inline runtime runs matching handlers in deterministic registration
  order and preserves the one-ack invariant.
* Bolt can write an HTTP acknowledgement immediately when `ack()` is called
  (its default `processBeforeResponse: false`) while listener work continues.
  Thunder\Bolt's current inline design emits the acknowledgement only after
  every matching handler returns. Slow synchronous handler work can therefore
  miss Slack's three-second deadline; return the acknowledgement promptly and
  move follow-up work to a worker when that matters. This is an intentional
  execution-model deviation, not Bolt JS timing parity.

## Compatibility tests 🟢

Use the fixtures in `tests/Fixtures/interactions/` to prove the boundaries:

1. literal and regex matching, including capture availability;
2. a multi-action body routes by the first entry and retains the entire body;
3. legacy option requests route by callback ID and return their source-specific
   option shapes;
4. a string view registration accepts submission but not close; explicit close
   registration accepts close only;
5. a view submission exposes all `response_urls`, while Bolt-compatible
   `respond` selects the first;
6. no match, unknown type, duplicate ack, and late ack follow the documented
   receiver policy; and
7. `dialog_cancellation` is either rejected as unsupported or handled only by
   an explicitly documented extension.

Fixtures use Slack documentation examples with identifiers and webhook paths
redacted. They are payload bodies after form decoding, not literal HTTP bodies.
