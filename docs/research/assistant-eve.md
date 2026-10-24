# Vercel Eve patterns for a Thunder Bolt Assistant runtime

Research date: 24 October 2026. This note evaluates Eve as an adjacent Slack-agent implementation. It does **not** describe Slack's Assistant API protocol; Eve's documented Slack surface is an Events API channel for mentions, DMs, commands, shortcuts, interactions and HITL controls.

## Evidence

### Channel and session ownership — high confidence

Eve puts the Slack-specific mechanics in a small channel adapter: it verifies inbound Slack requests, resolves workspace-scoped credentials, routes mentions/DMs/commands/interactions, replies in threads, and exposes raw Slack API access. It recommends a credential provider which receives the installation `teamId`; its docs explicitly warn that the installation workspace can differ from the actor/content workspace in Slack Connect, and that a missing team ID must not select a default token. [Slack channel documentation](https://github.com/vercel/eve/blob/main/docs/channels/slack.mdx)

Its durable session is keyed by the conversation target. A message without a `threadTs` starts with a temporary continuation token; the first agent post anchors the session to that Slack message timestamp. Follow-ups in an active thread resume that session. This is a useful model for ordinary agent conversations, but is not a substitute for Slack Assistant threads and their metadata-backed context.

**Recommendation:** keep the proposed Assistant runtime inside Thunder Bolt's existing authorized delivery pipeline. Its durable context key should include at least the *installation/workspace identity*, channel, and assistant root timestamp. Never use the actor's team or a fallback token as an installation selector. Use the existing authorization source as the canonical installation source.

### Route policy and loop prevention — high confidence

Eve handles `app_mention`, direct message and generic message hooks in a precedence order; only the first accepting handler runs. It discards messages written by its own installed app before routing, and makes thread follow-up routing an explicit active-session policy. Its docs note that loaded thread history is scope-dependent, bounded and potentially stale, so an unavailable history should not be interpreted as proof that no participant exists. [Inbound event routing](https://github.com/vercel/eve/blob/main/docs/channels/slack.mdx#how-the-channel-handles-inbound-events)

**Recommendation:** for the generic agent demo and future assistant bridge, use explicit predicates for assistant thread events and self-message suppression. Do not fetch or inject arbitrary conversation history from the Assistant lifecycle itself. Slack Assistant context is a distinct protocol payload; generic history belongs in the existing conversation store.

### Progress delivery — high confidence

Eve publishes a compact sequence: an initial thinking state, working state, then coalesced reasoning/tool labels. It deliberately batches small streamed deltas and makes custom event delivery replace the default, preventing duplicate progress messages. Long final answers become files rather than unbounded messages. [Delivery behavior](https://github.com/vercel/eve/blob/main/docs/channels/slack.mdx#delivery)

**Recommendation:** provide a single, explicit `AssistantThread` helper with `status()`, `title()`, and `suggestedPrompts()`; let application code decide whether to map model-stream events to those calls. Status updates should be throttled by the caller or a small optional adapter, never hidden inside generic handler routing. Reuse the framework's existing message streaming APIs for content rather than making Assistant status responsible for response text.

### Authorization and private interaction data — high confidence

Eve treats valid Slack signatures as transport authentication, not authorization. Its examples require authorization on every inbound route and again in sensitive tools. For sign-in challenges, it separates a public, link-free status from private delivery of credentials; its restricted context has no public post or raw API access. [Authorization guidance](https://github.com/vercel/eve/blob/main/docs/channels/slack.mdx#restrict-who-can-invoke-the-agent)

**Recommendation:** retain Thunder Bolt's authorization source and normal middleware for every Assistant event. Context stores must receive trusted installation/thread identifiers, rather than raw user-controlled event fields. A future Laravel AI adapter should see the principal/context supplied by the application, not a Slack token or unfiltered event JSON.

## What not to copy

| Eve feature | Why it does not belong in the core Assistant runtime |
| --- | --- |
| Eve sessions and workflow replay | It is an agent runtime and durable-workflow policy, not part of Slack Assistant's protocol. Thunder Bolt should expose a context-store seam and leave retries/queues to an application or future job module. |
| Eve's multi-channel router | It would make the Slack package responsible for Discord/Teams-style abstractions unrelated to Bolt parity. |
| Automatic agent/model execution | The user requested Slack Assistant runtime parity. Model selection, tools, streaming, and provider billing should remain application-owned or live in an optional integration. |
| Connect-specific credential management | Thunder Bolt already has OAuth installations and authorization. Its value is the rule—resolve a token by trusted installation identity—not a Vercel dependency. |

## Concrete design influence

The most valuable Eve-inspired shape is a narrow delivery-context module:

```php
final readonly class AssistantThread
{
    public function reply(string|Message $message): Response;
    public function status(string $text): Response;
    public function title(string $text): Response;
    public function suggestedPrompts(SuggestedPrompt ...$prompts): Response;
    public function context(): AssistantContext;
    public function saveContext(AssistantContext $context): void;
}
```

This keeps Slack protocol calls, trusted identifiers, context persistence, and validation behind one interface while leaving the model/agent system outside it. The caller receives it through typed Assistant events in the same way existing handlers receive `Reply`, `Client`, `ConversationContext`, and `ThreadContext`.

The optional Laravel AI integration can adapt an agent stream to this module: set a short status before running, relay answer text through `reply()`, set title/prompts once the response is complete, and persist only application-chosen conversation state. It should not require Laravel or recreate Eve's durable-session abstraction.
