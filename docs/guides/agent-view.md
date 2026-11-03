# Agent View and current app context

Use `Assistant::forAgentView()` for Slack's current agent messaging experience. It receives human DM roots and replies, plus optional Messages-tab opening and focus-change handlers. `Assistant::make()` retains the legacy `assistant_thread_*` lifecycle and its context stores.

Generate a listener with `php bolt make:assistant WorkspaceAgent --agent-view`. Without the flag, the generator retains the legacy assistant flow. It refuses to overwrite an existing listener or assistant config, and does not rewrite your manifest.

The resulting `config/assistant.config.php` uses:

```php
use App\Listeners\WorkspaceAgent;
use Thunder\Bolt\Assistant;

return Assistant::forAgentView()
    ->opened([WorkspaceAgent::class, 'opened'])
    ->appContextChanged([WorkspaceAgent::class, 'focusChanged'])
    ->userMessage([WorkspaceAgent::class, 'message']);
```

Only `userMessage` is required. Each registration accepts middleware before its handler, like the legacy builder. The application still registers one assistant configuration.

```php
use Thunder\Bolt\Assistant\{AppContext, Thread};
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Events\{AppContextChanged, AppHomeOpened, Message};

final readonly class WorkspaceAgent
{
    public function opened(AppHomeOpened $event, AppContext $context, Delivery $delivery): void
    {
        // Messages tab only. There is no message root, so no Thread is injected.
        // Send onboarding via the delivery's authorized client when appropriate.
    }

    public function focusChanged(AppContextChanged $event, AppContext $context): void
    {
        // Observe focus without fetching private content automatically.
    }

    public function message(Message $message, Thread $thread, AppContext $context): void
    {
        $focusedChannel = $context->first()?->channelId();
        $thread->session()->processing();
        try {
            $thread->say($focusedChannel === null ? 'What would you like to explore?' : 'I have your current focus.');
        } finally {
            $thread->session()->active();
        }
    }
}
```

For slow model/tool calls, enqueue [durable jobs](durable-operations.md) and return promptly. The small synchronous example only illustrates routing. Persist `$context->toArray()` explicitly in a new job payload if queued work needs that exact snapshot. Never add required properties to an existing queued command without considering already persisted records.

## Context semantics

`AppContext::$entities` preserves Slack's relevance order. `first()` returns the highest ranked entity; `first(ContextEntity::CHANNEL)` selects the first entity of that type. Known types include channels, canvases, lists and message references. `ContextEntity` exposes `type`, `value`, `teamId`, `enterpriseId`, `channelId()` and `messageTs()`. Unknown future types and extension fields survive `toArray()`.

| Delivery | Typed access | Thread helper |
| --- | --- | --- |
| `message.im` | `Message::$appContext`, injected `AppContext`, `Thread::appContext()` | Root is `thread_ts`, or the incoming `ts` for a new DM |
| `app_home_opened`, `tab=messages` | `AppHomeOpened::$appContext`, injected `AppContext` | None; no timestamp is invented |
| `app_context_changed` | `AppContextChanged::$context`, injected `AppContext` | None; focus is not a message root |

Slack omits context from messages and App Home when nothing is selected. The runtime injects an empty `AppContext` in that case; it never falls back to an older selection. `Message::$appContext` remains nullable so callers can distinguish omitted wire data. App Home's existing nullable array `$context` stays available.

An entity's workspace/organization IDs describe focus. Authorization still comes from the delivery's selected installation. Context is a hint, not permission to read a channel, canvas, list or message. The runtime makes no context-fetching API calls.

In Agent View mode, `Thread::context()` returns null and `saveContext()` throws: legacy thread metadata is not the modern focus store. `say()` does not attach focus as public message metadata. Use application conversation/thread storage for your own durable state. Existing legacy assistants and their metadata stores are unchanged.

Messages from bots, message edits/deletions, channel messages and other App Home tabs fall through to ordinary listeners. Missing optional lifecycle handlers also fall through. Registered modern handlers receive the normal authorized client, delivery context, jobs capability, middleware and error handling. `AppContext` is resolved in a fresh Tempest `GenericContainer` invocation scope, including constructor injection.

## Slack configuration

In [your Slack app settings](https://api.slack.com/apps), enable the current Agents/Agent View feature for your app. Feature availability depends on workspace access and plan. In **OAuth & Permissions**, grant `assistant:write`, `chat:write` and `im:history`; reinstall after scope changes. In **Event Subscriptions → Subscribe to bot events**, add `message.im`, `app_home_opened` and `app_context_changed`. Add `agent_session_stopped` and `agent_session_title_changed` if your app implements cancellation/title tracking. Keep Socket Mode enabled or configure the verified HTTP request URL for your chosen transport.

The generated listener includes modern open/context/message handlers, typed streaming, and a native Stop handler. When durable jobs are configured, Stop calls the delivery's `cancelThread()` capability and settles session status. Its quick reply runs inline; move model/tool work into named jobs as demonstrated by `examples/hello-bolt`. Do not mix `threadStarted`, `threadContextChanged` or `contextStore` into the modern builder.

In **Agents**, select dynamic suggested prompts. `Thread::setSuggestedPrompts()` omits `thread_ts` in Agent View mode; Slack's current method documentation says a legacy thread timestamp silently prevents prompts from being applied to a modern agent. Static prompts in app configuration prevent dynamic updates.

A manifest fragment for the current view is:

```yaml
features:
  agent_view:
    agent_description: Ask questions and work with your Slack context.
  app_home:
    messages_tab_enabled: true
    messages_tab_read_only_enabled: false
```

Merge this with your own manifest and the scopes/events above. `agent_description` is required; there is no `agent_view.enabled` setting. Slack documents migration from legacy Assistant View as one-way; review an installed app's configuration before migrating it. See the [suggested-prompts method](https://docs.slack.dev/reference/methods/assistant.threads.setSuggestedPrompts/) and [manifest reference](https://docs.slack.dev/reference/app-manifest/).

Use [Slack's current agent guide](https://docs.slack.dev/ai/developing-agents/) for feature setup and focus-delivery rules, and [context management](https://docs.slack.dev/ai/agent-context-management/) for selecting what to pass to a model. The [acceptance record](../research/agent-view-starter-validation.md) distinguishes live DM/focus/stream/Stop checks from fixture-only variants.
