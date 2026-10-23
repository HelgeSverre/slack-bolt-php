# HTTP interactivity

Actions, shortcuts, external options, and modal views use the same signed `/slack/events` endpoint as events and commands. Enable interactivity in your Slack app's manifest/settings and set a publicly reachable HTTPS request URL. The checked-in `examples/hello-bolt` application demonstrates the complete flow.

The receiver verifies the original form bytes before decoding Slack's JSON `payload` field. Handler methods receive readonly payload objects and return the appropriate acknowledgement value:

| Listener | Payload | Initial response |
| --- | --- | --- |
| `action()` / `#[Action]` | `Payloads\Action` | `ActionResponse` |
| `shortcut()` / `#[Shortcut]` | `Payloads\Shortcut` | `ShortcutResponse` |
| `options()` / `#[Options]` | `Payloads\Options` | `OptionsResponse` |
| `view()` / `#[View]` | `Payloads\View` | `ViewResponse` |

Every delivery needs exactly one returned response across its matching listeners. Other matching listeners can run middleware or side effects without returning a response. A missing response, a second response, or a response from the wrong family fails clearly. Unsupported payload families return `501`; a recognised delivery without an acknowledgement returns `500`.

**Execution remains inline.** The HTTP response is emitted after dispatch finishes. Returning a response does not flush it early; keep processing and outbound calls within Slack's three-second deadline. `Reply` makes outbound calls independently of the initial response.

## Button clicks and message updates

```php
use Thunder\Bolt\Attributes\Action as OnAction;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\ActionResponse;

final readonly class ApproveRequest
{
    #[OnAction('approve_request')]
    public function __invoke(Action $action, Reply $reply): ActionResponse
    {
        $reply->replaceOriginal('Request approved.');

        return ActionResponse::empty();
    }
}
```

Block Kit clicks acknowledge with an empty initial response. `Reply::respond()`, `replaceOriginal()`, and `deleteOriginal()` post through the incoming `response_url`; they do not send your bot token to the webhook. `respond()` accepts a string, `Message`, or raw response array with flags such as `response_type`. These helpers require a response URL in the payload. Actions originating in a modal may not have one.

`inChannel()`, `inThread()`, and `ephemeral()` remain available when the interaction provides the corresponding channel/user context. Global shortcuts have neither a channel nor a response URL; helpers that require missing context throw `MissingReplyContext`.

`Action` exposes `actionId`, `blockId`, `callbackId`, `triggerId`, and the first element as `action`. The entire envelope, including all action elements, remains in `raw`. As in Bolt JS, automatic selector matching uses only `actions[0]`.

## Shortcuts and modal opening

Both global and message shortcuts use `#[Shortcut('callback_id')]`. Their payload exposes `triggerId` for opening a modal through a constructor-injected `WebApi\Client`:

```php
$client->views()->open([
    'trigger_id' => $shortcut->triggerId,
    'view' => [
        'type' => 'modal',
        'callback_id' => 'submit_request',
        'title' => ['type' => 'plain_text', 'text' => 'New request'],
        'submit' => ['type' => 'plain_text', 'text' => 'Submit'],
        'blocks' => $blocks,
    ],
]);

return ShortcutResponse::empty();
```

Declare the global/message shortcut and callback ID in Slack's manifest as well as the PHP listener. Slack requires the [`commands` bot scope](https://docs.slack.dev/reference/scopes/commands/) for shortcuts and slash commands; reinstall the app after adding that scope. Message shortcuts preserve their originating message/thread context. Global shortcuts expose no invented channel.

## External select options

```php
use Thunder\Bolt\Attributes\Options as LoadOptions;
use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Responses\OptionsResponse;

final readonly class SearchAssignees
{
    #[LoadOptions('search_assignees')]
    public function __invoke(Options $request): OptionsResponse
    {
        // Filter your data using $request->value.
        return OptionsResponse::options(
            Option::text('Ada', 'ada'),
            Option::text('Linus', 'linus'),
        );
    }
}
```

`OptionsResponse::empty()` serializes as `{"options":[]}`. `groups(OptionGroup::make('People', ...$options))` emits grouped choices. Option values and groups are immutable and validate Slack's length/count limits. The reply contains exactly one of `options` or `option_groups`.

## Modal submissions and closures

```php
use Thunder\Bolt\Attributes\View as OnView;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\ViewResponse;

final readonly class SubmitRequest
{
    #[OnView('submit_request')]
    public function __invoke(View $view): ViewResponse
    {
        $summary = $view->value('summary', 'title');
        if (! is_string($summary) || trim($summary) === '') {
            return ViewResponse::errors(['summary' => 'Enter a summary.']);
        }

        return ViewResponse::empty();
    }
}
```

`value($blockId, $actionId)` reads a text input's scalar value, including `null`; for other control types it returns the raw state cell. `state()` always returns the raw state cell or `null`. `values`, `view`, and `raw` preserve the full submitted data.

`ViewResponse::errors()` addresses errors by block ID. `update($modal)`, `push($modal)`, and `clear()` implement the other response actions. Modal objects use native arrays until the complete typed view model lands.

A plain view selector listens only for `view_submission`. To receive closures, use `#[OnView('submit_request', type: 'view_closed')]` and enable `notify_on_close` in the modal. A closure accepts only `ViewResponse::empty()`. Explicit fluent constraints with `type: null` match both submission and closure, mirroring Bolt's constraint-object behavior.

When a submission has `response_urls`, `Reply::respond()` uses the first entry, as Bolt JS does. The full list remains in `View::raw`.

## Fluent selectors and compound constraints

```php
use Thunder\Bolt\Routing\InteractionConstraints;
use Thunder\Bolt\Routing\Regex;

$app->action(
    InteractionConstraints::action(
        Regex::of('/^approve_(?<kind>order|invoice)$/'),
        blockId: 'approvals',
        type: 'block_actions',
    ),
    ApproveRequest::class,
);

$app->view(
    InteractionConstraints::view('submit_request', type: 'view_closed'),
    OnRequestClosed::class,
);
```

Use unannotated handlers for fluent registration. Registering the same handler and equivalent selector through discovery and fluent registration is a duplicate error. All supplied constraints must match. Strings compare exactly; `Regex` values capture matches. An optional `Invocation\Delivery` parameter exposes per-field captures under `constraintMatches['actionId'|'blockId'|'callbackId']`; `matches` exposes the primary capture set. Payload and capture values remain isolated between matching listeners.

Action/options shorthand selects `action_id`; shortcut/view shorthand selects `callback_id`. `InteractionConstraints` supports named `actionId`, `blockId`, `callbackId`, and `type` arguments. Shortcut/view registrations accept only callback and type constraints. Invalid combinations/types fail during registration.

## Legacy interaction forms

Legacy `interactive_message` actions and suggestions, `dialog_submission`, and `dialog_suggestion` are supported. Use callback constraints for forms without an `action_id`:

```php
$app->options(
    InteractionConstraints::options(callbackId: 'legacy_picker', type: 'dialog_suggestion'),
    LoadLegacyChoices::class,
);
```

Typed options automatically serialize into the source's legacy label/text shape. `OptionsResponse::fromArray()` supports explicit legacy option/group arrays. Legacy message actions can return `ActionResponse::message()` or `ActionResponse::fromArray()` for attachments and replacement flags; dialog validation uses `ActionResponse::errors(['field_name' => 'Error message'])`.

`dialog_cancellation` is explicitly unsupported by the pinned Bolt JS classifier and returns `501`. The [parity reference](research/http-interactivity.md) records exact source mappings and intentional PHP differences.

## CLI

```sh
php bolt make:listener ApproveRequest --action=approve_request
php bolt make:listener OpenRequestModal --shortcut=open_request_modal
php bolt make:listener SearchAssignees --options=search_assignees
php bolt make:listener SubmitRequest --view=submit_request
php bolt make:listener OnRequestClosed --view=submit_request --type=view_closed
php bolt listener:list --format=json
```

Generated handlers use the correct payload and response family. Scaffolding creates PHP files; configure matching shortcuts, interactive elements, and request URLs in Slack. See the connected application in `examples/hello-bolt/` for a complete starting point.
