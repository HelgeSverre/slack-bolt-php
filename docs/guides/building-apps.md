# Build handlers

Thunder Bolt discovers listener methods in `app/` and resolves typed method arguments from Tempest. Attribute registration is the usual starting point; fluent `App` registration is useful for an embedded application or dynamically assembled listeners. Both routes use the same matcher and duplicate registrations fail at boot.

## Events and commands

An event does not need an initial Slack response. A slash command does:

```php
use Thunder\Bolt\Attributes\{Event, SlashCommand};
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\CommandResponse;

final readonly class Greeting
{
    #[Event('app_mention')]
    public function mention(AppMention $event, Reply $reply): void
    {
        $reply->inThread("Hello <@{$event->userId}>.");
    }

    #[SlashCommand('/ping')]
    public function ping(): CommandResponse
    {
        return CommandResponse::ephemeral('Pong.');
    }
}
```

The app must also be configured in Slack for the matching event or slash command. [Slack app setup](slack-app-setup.md) covers where that configuration lives; listener scaffolding writes PHP only.

## Acknowledge interactions

Actions, shortcuts, external-select options, and views are interaction deliveries. Exactly one matching listener must return the response family for that delivery; returning the wrong kind, returning twice, or returning nothing is an error.

| Listener attribute | Payload | Required response |
| --- | --- | --- |
| `#[Action]` | `Payloads\Action` | `ActionResponse` |
| `#[Shortcut]` | `Payloads\Shortcut` | `ShortcutResponse` |
| `#[Options]` | `Payloads\Options` | `OptionsResponse` |
| `#[View]` | `Payloads\View` | `ViewResponse` |

Keep acknowledgement work inside Slack's three-second window. A returned response is buffered until dispatch finishes, so it is not a way to continue executing after Slack has received an acknowledgement. `Reply` is for follow-up delivery through a response URL or the selected bot credential; it has the same timing reality.

```php
use Thunder\Bolt\Attributes\Action as OnAction;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\ActionResponse;

final readonly class Approve
{
    #[OnAction('approve_request')]
    public function __invoke(Action $action, Reply $reply): ActionResponse
    {
        $reply->replaceOriginal('Approved.');

        return ActionResponse::empty();
    }
}
```

`Reply` derives its channel, thread, user, and response URL from the delivery. It throws when Slack did not provide the required context, such as a channel for a global shortcut. This is safer than guessing from unrelated payload fields. For modal validation, return `ViewResponse::errors(['block_id' => 'Message'])`; for external choices, return `OptionsResponse::options(...)`. The [interactivity reference](../http-interactivity.md) covers modals, selectors, legacy forms, and generated listener commands.

## Use request context, not global state

`Context` carries values for the current delivery only. It is shared by global middleware and matching listeners, then discarded. Use it for authenticated request facts or correlation information—not conversations, credentials, or a live client captured for a later worker.

```php
use Closure;
use Thunder\Bolt\Context;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Middleware\Middleware;

final readonly class RequestLabel implements Middleware
{
    public function handle(Delivery $delivery, Closure $next): mixed
    {
        $delivery->context->set('request_label', $delivery->listener?->source);

        return $next($delivery);
    }
}

final readonly class UsesLabel
{
    public function __invoke(Context $context): void
    {
        $label = $context->require('request_label');
        // Use $label for this delivery only.
    }
}
```

Register global middleware with `$app->use(new RequestLabel())`. Listener middleware is supplied before a handler in fluent registration. `Context::set()`, `get()`, `has()`, `require()`, and `remove()` are explicit; it cannot be serialized. For durable state, use [conversation/thread storage](../conversation-and-thread-storage.md); for deferred work, use [named jobs](durable-operations.md).

## Handle failures deliberately

Use the structured error hook when an application needs a policy decision:

```php
use Thunder\Bolt\Errors\{ErrorHandling, FailureContext};

$app->onError(static function (FailureContext $failure): ErrorHandling {
    error_log(json_encode($failure->summary(), JSON_THROW_ON_ERROR));

    return ErrorHandling::Handled;
});
```

`summary()` is intentionally redacted metadata: stage, listener, installation/channel/user identifiers when available, and delivery IDs. Do not log raw payloads, authorization headers, OAuth codes, response URLs, or tokens. `ErrorHandling::Rethrow` preserves the original failure. The older `$app->error(...)` aggregate callback remains available for compatibility, but structured handling makes the failure stage and acknowledgement state visible.

## Compose UI and call Slack

Builders are immutable. Capture each fluent result:

```php
use Thunder\Bolt\BlockKit\{Actions, Button, Section};
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\Requests\ChatPostMessage;
use Thunder\Bolt\WebApi\Client;

$message = Message::blocks(
    Section::markdown('*Deploy ready*'),
    Actions::make(Button::make('Approve', 'approve_request')->primary()),
)->fallbackText('Deploy ready');

$client->chat()->postMessage(new ChatPostMessage('C123', $message));
```

Factories take required values; fluent calls set optional values. `#[NoDiscard]` helps catch ignored builder updates. Use `RawBlock::fromArray()` or raw request arrays as deliberate forward-compatibility escape hatches, not as proof the framework validates a newly introduced Slack schema.

The injected `WebApi\Client` selects the authorized installation token for a delivery. In background/CLI work, choose bounded retries consciously with `$client->retrying(...)`; default calls send once. Uploads use `Upload::path()`, `bytes()`, or `stream()`. Read the [Block Kit guide](../sdk-and-block-kit.md) and [Web API client guide](../web-api-client.md) before using retries, response URLs, streaming uploads, or pagination.

## Test the contract you own

Test a signed fixture through your HTTP receiver or dispatch a typed payload through the application; assert the acknowledgement and recorded outgoing calls. Use a recorded PSR-18 transport rather than a real workspace by default. The [testing, security, and troubleshooting guide](testing-security-troubleshooting.md) covers project checks and the opt-in live suite.
