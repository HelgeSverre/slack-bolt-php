# Hello Bolt

A connected HTTP example for events, commands, actions, shortcuts, external options, and modal validation. It uses a Composer path repository pointing at this checkout.

## Run locally

Use PHP 8.5+ and install the framework's dependencies first. From this directory:

```sh
composer install
cp .env.example .env
php bolt listener:list
php bolt doctor
php bolt serve
```

Set `SLACK_BOT_TOKEN` and `SLACK_SIGNING_SECRET` in `.env`. Expose the development server through your HTTPS tunnel, replace every `https://example.test/slack/events` URL in `slack-manifest.yaml`, and use that manifest to configure your Slack app. Install or reinstall with the declared scopes, including `commands`, and invite the bot to a channel. `doctor` checks local configuration; it does not contact Slack.

For OAuth installation across workspaces, fill the optional client ID, client secret, and HTTPS redirect URI fields in `.env`, configure that callback in Slack, then visit `/slack/install` through the same HTTPS host. The static bot token is unnecessary in OAuth mode. Keep the listed bot scopes and see the [OAuth guide](../../docs/oauth-and-authorization.md) for state, installation storage, and automatic token rotation.

## Try the flow

1. Mention the bot in a channel. `GreetOnMention` posts a reply with an **Approve** button. Clicking it routes to `ApproveRequest`, which replaces that message through its response URL.
2. Choose **Open request** from Slack's shortcuts. `OpenRequestModal` uses the shortcut's trigger ID to open a modal.
3. Search the optional assignee field. `SearchAssignees` returns filtered demo choices for Ada, Grace, and Margaret.
4. Submit the modal. `SubmitRequest` rejects a whitespace-only summary with an inline block error and acknowledges a valid summary. Add your application's persistence or workflow at that point.
5. Run `/ping` to receive its initial ephemeral response.

Keep handlers and outbound calls within Slack's three-second acknowledgement deadline. The example uses inline processing. See the [interactivity guide](../../docs/http-interactivity.md) for closure listeners, compound selectors, legacy forms, and response contracts.

`tests/Examples/HelloBoltInteractivityTest.php` exercises the connected flow with a recorded HTTP transport. A real workspace is only contacted when you run the configured example and invoke its Slack features.

## Remember a conversation

`RememberConversation` is another `app_mention` listener. It injects `ConversationContext` to count mentions in the channel and uses `ThreadContext` when Slack supplied a known thread root. Both contexts are delivery-scoped and use the configured store, so the handler can update state without assembling channel or tenant keys itself.
