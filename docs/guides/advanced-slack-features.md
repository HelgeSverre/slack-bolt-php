# Advanced Slack features

Assistants, custom functions, and Work Objects are opt-in Slack capabilities. They are not part of a minimal app, can have Slack workspace/plan eligibility requirements, and need both application code and Slack-side configuration. Verify availability in the target workspace before designing a production dependency around any one of them.

Use the [Slack app setup guide](slack-app-setup.md) for the current UI locations and required scopes/events. The checked-in playground is useful for code patterns, but its wide manifest is a demo surface rather than a baseline application manifest.

## Assistants and agent sessions

Thunder Bolt has a typed assistant runtime compatible with the retained Bolt JS assistant-thread contract, plus modern session controls for status and titles. Register exactly one assistant:

```php
use App\Listeners\WorkspaceAssistant;
use Thunder\Bolt\Assistant;

return Assistant::make()
    ->threadStarted([WorkspaceAssistant::class, 'started'])
    ->userMessage([WorkspaceAssistant::class, 'message']);
```

Handlers receive an `Assistant\Thread` scoped to the current DM/thread. It supplies `say()`, `sayStream()`, context persistence, suggested prompts, and `session()` controls:

```php
use Thunder\Bolt\Assistant\{SuggestedPrompt, Thread};
use Thunder\Bolt\Payloads\Events\AssistantThreadStarted;

final readonly class WorkspaceAssistant
{
    public function started(AssistantThreadStarted $event, Thread $thread): void
    {
        $thread->saveContext();
        $thread->setStatus('Preparing workspace help');
        $thread->say('Tell me what you are trying to do.');
        $thread->setSuggestedPrompts([
            new SuggestedPrompt('Explain a change', 'Explain the latest change.'),
        ]);
        $thread->setStatus('');
    }
}
```

`session()` calls the modern `agents.sessions.*` APIs and requires the current Agents experience/configuration; it is not enabled merely by subscribing to legacy assistant-thread events. The sample above uses the retained assistant status API.

Slow model or tool work belongs in a named job. A Stop event does not cancel arbitrary PHP code: the app must request cancellation of its own work, use bounded I/O, reach `JobExecution::checkpoint()`, and then settle the session status. Respect user-edited titles rather than overwriting them on every response.

Slack's current Agents surface evolves independently of the legacy assistant event contract retained for Bolt JS compatibility. In particular, Slack now documents an `app_context_changed` event and Agent View setup, while the example application currently demonstrates the older `assistant_thread_*` flow and a root-DM adapter. Treat Agent View/context integration as an advanced, workspace-gated integration to validate against the current Slack UI; do not assume importing the demo manifest completes it. The [native assistant playground notes](../../examples/hello-bolt/README.md#native-slack-assistant) state the demo's current behavior and limitations.

Thunder Bolt does not bundle an AI model provider. Laravel AI, OpenAI, or another provider belongs in application code. Persist the provider's conversation identity in application state; do not confuse assistant focus context with conversation history or permission to read the viewed channel.

## Custom workflow functions

Match a Workflow Builder function by callback ID and complete or fail its execution explicitly:

```php
use Thunder\Bolt\Attributes\{FromInputs, SlackFunction};
use Thunder\Bolt\Functions\Execution;

final readonly class FormatInputs
{
    public function __construct(public string $message, public bool $uppercase = false) {}
}

final readonly class FormatMessage
{
    #[SlackFunction('format_message')]
    public function __invoke(#[FromInputs] FormatInputs $inputs, Execution $execution): void
    {
        $message = $inputs->uppercase ? mb_strtoupper($inputs->message) : $inputs->message;
        $execution->complete(['message' => $message]);
    }
}
```

`Execution` exposes strict inputs, the scoped client, typed execution context, `complete()`, and `fail()`. A terminal response can happen only once per delivery. If its network call fails, the local state becomes uncertain; do not automatically send the opposite terminal result.

Workflow tokens are delivery-scoped and expire with the function execution. By default they select the delivery client without entering the global client pool. Finish the workflow before its token expires, and make completion the last Slack call that depends on it. `SLACK_ATTACH_FUNCTION_TOKEN=false` instead requires an authorized installation credential.

Do not serialize `Execution` or place its token in a normal job payload. If work must outlive acknowledgement, persist a protected, short-lived execution reference with explicit tenant and execution binding, then make terminal completion idempotent in the application. The playground's Tempest async sample illustrates that boundary; Tempest's async bus is experimental and is not Thunder Bolt's durable job system.

Custom functions require Workspace/Workflow Builder configuration and may not be available to every Slack plan. Configure and test them in an eligible development workspace before treating them as a deployment prerequisite.

## Work Objects

Work Objects publish external domain records as Slack previews and synchronize details/comments through an application-owned provider:

```php
use Thunder\Bolt\WorkObjects\{Entity, EntityField, ExternalReference};

$task = Entity::task(
    new ExternalReference('TASK-42', 'task'),
    'https://tasks.example.test/TASK-42',
    'Ship the integration',
)->field('status', EntityField::status('In progress', 'blue'))->comments(0);
```

The Web API publisher sends entities, detail responses, unfurls, and comment results. Your `CommentProvider` owns durable data. Your authorization callback must enforce read/post/edit/delete permissions on every operation—not merely hide buttons—because Slack events are a transport, not an authorization decision.

Enable Work Object previews, register the entity type, subscribe to detail/comment requests, and configure app unfurl domains/scopes only if your app actually needs unfurls. Availability and comment synchronization can be restricted by Slack or the workspace. The [playground Work Objects section](../../examples/hello-bolt/README.md#work-objects) shows a deliberately simple JSON-backed provider; replace it with your application's persistence and policy layer.

## Message metadata subscriptions

Thunder Bolt can attach outbound `MessageMetadata`. Receiving metadata lifecycle events is a separate Slack capability: subscribe to only the needed `message_metadata_posted`, `message_metadata_updated`, or `message_metadata_deleted` events and add matching `metadata_subscriptions` filters for the app/event types the application actually observes. The starter and playground manifests do not enable those subscriptions, and inbound metadata events have typed posted/updated/deleted payloads; see the [typed-event guide](typed-events.md). Do not enable broad metadata wildcards merely because an app sends metadata.

## Capability checklist

Before enabling any advanced feature in production:

1. Confirm that the Slack workspace and plan expose it.
2. Add only the scopes, events, manifest features, and request routes the feature needs; reinstall the app.
3. Store credentials, context, work records, and job data outside public storage.
4. Exercise a real development-workspace delivery, including failure/cancellation paths.
5. Keep a fallback path for disabled or unavailable Slack features.
