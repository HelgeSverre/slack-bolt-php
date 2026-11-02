# Slack app setup verification

Verified against Slack's public documentation on 2 November 2026. This is a
source-backed hand-off for the user-facing setup guide; it is not a manifest
template and contains no credentials.

## UI vocabulary verified in the current app settings

The current app-settings navigation uses these names: **Basic Information**,
**Socket Mode**, **Install App**, **App Home**, **Agents**, **Work Object
Previews (New)**, **Workflow Steps**, **Interactivity & Shortcuts**, **Slash
Commands**, **OAuth & Permissions**, **Event Subscriptions**, and **App
Manifest**. The browser inspection was read-only; the stable, normative detail
below is linked to Slack's documentation.

Secrets belong in a private `.env`, never in a manifest, README, issue, or
shell history. A signing secret is used only to validate HTTP deliveries; it
is not a Web API credential. Slack signs HTTP requests and documents the
secret under **Basic Information → App Credentials**. [Verify requests from
Slack](https://docs.slack.dev/authentication/verifying-requests-from-slack/)

## What this repository actually configures

| Surface | Runtime/source evidence | Slack configuration required |
| --- | --- | --- |
| Minimal new project | [`ProjectScaffold.php`](../../src/Console/ProjectScaffold.php) produces only `app_mentions:read`, `chat:write`, and `app_mention`. | Install a bot, add those two bot scopes, subscribe to `app_mention`; do not copy the demo's full scope set. |
| HTTP delivery | [`public/index.php`](../../examples/hello-bolt/public/index.php) receives the framework's default `/slack/events` route; generated `.env.example` has `SLACK_SIGNING_SECRET`. | Put the public HTTPS URL in **Event Subscriptions**, and in **Interactivity & Shortcuts** / each slash command only when those surfaces are used. Slack verifies the Events URL with a `url_verification` challenge. [HTTP Request URLs](https://docs.slack.dev/apis/events-api/using-http-request-urls/) |
| Socket Mode | [`SocketCommands.php`](../../src/Console/SocketCommands.php) requires `SLACK_APP_TOKEN`; the runner checks `connections:write`. | Enable **Socket Mode** and make an app-level token in **Basic Information → App-level tokens** with `connections:write`. Socket Mode replaces inbound HTTP delivery for events and interactive payloads; it does not replace the installed bot/OAuth token. [Socket Mode](https://docs.slack.dev/apis/events-api/using-socket-mode/) |
| Single-workspace bot token | Generated `.env.example` has `SLACK_BOT_TOKEN`; `php bolt auth:verify` validates it. | Install or reinstall in **Install App** after scopes change, then copy the Bot User OAuth token to the private environment. |
| Multi-workspace OAuth | [`OAuthConfig`](../../src/OAuth), [`OAuthHttpHandler`](../../src/Integration/OAuthHttpHandler.php), and [`docs/oauth-and-authorization.md`](../oauth-and-authorization.md) implement `/slack/install` and `/slack/oauth_redirect`, state validation, installation stores, and bot/user refresh. | Add the callback in **OAuth & Permissions → Redirect URLs**, set the same HTTPS URL in `SLACK_OAUTH_REDIRECT_URI`, and configure client ID/secret plus minimum bot/user scopes. [OAuth installation](https://docs.slack.dev/authentication/installing-with-oauth/) |
| Broad playground | [`examples/hello-bolt/slack-manifest.yaml`](../../examples/hello-bolt/slack-manifest.yaml) intentionally enables commands, interactivity, App Home, assistants, Work Objects, custom functions, and link unfurls. | Treat as a capability-by-capability demo manifest, not as a production starter or a list of default permissions. |

## Transport and credentials

### HTTP mode

1. In **Basic Information → App Credentials**, copy the Signing Secret into
   `SLACK_SIGNING_SECRET`.
2. Expose the application endpoint over public HTTPS and set
   `https://host.example/slack/events` under **Event Subscriptions**. Slack
   checks the URL before saving it. The framework handles the challenge and
   signature verification.
3. Turn on **Interactivity & Shortcuts** and give it the same endpoint only
   when the app handles buttons, modals, shortcuts, or external select options.
   The Options Load URL is optional and should be set only for handlers that
   return dynamic options.
4. Create each slash command under **Slash Commands** and set its Request URL
   (normally the same endpoint) when using HTTP delivery.

Interactions and modals are delivered to the Interactivity Request URL;
slash commands have their own Request URL. The interaction documentation also
confirms that `view_submission` uses the interactivity endpoint.
[Handling user interaction](https://docs.slack.dev/interactivity/handling-user-interaction/)

### Socket Mode

1. Toggle **Socket Mode** on.
2. Under **Basic Information → App-level tokens**, generate an `xapp` token
   with `connections:write`; put it in `SLACK_APP_TOKEN`.
3. Continue configuring event subscriptions and scopes. With Socket Mode
   enabled, Slack delivers events and interactive payloads over the WebSocket,
   so an Event Subscriptions Request URL is not required.
4. For ordinary bot events/messages, install the app and supply `SLACK_BOT_TOKEN`, or configure OAuth. The
   app-level token opens the socket; it cannot post as the bot. A function-only
   app can instead use `attachFunctionToken=true`: each `function_executed`
   delivery carries a scoped workflow token. This does not grant ordinary bot access.

Socket Mode is available only to granular-permission apps and cannot currently
be listed in the public Slack Marketplace. [Socket Mode](https://docs.slack.dev/apis/events-api/using-socket-mode/)

### Token rotation: two separate decisions

Slack can rotate granular bot, user, **and app-level** tokens. Rotated access
tokens expire after 12 hours and must be replaced using a single-use refresh
token. Enabling rotation is irreversible, so test before enabling it.
[Slack token rotation](https://docs.slack.dev/authentication/using-token-rotation/)

Thunder Bolt currently persists and refreshes OAuth installation **bot and
user** credentials through [`TokenRotator`](../../src/OAuth/TokenRotator.php).
It deliberately has no app-level-token store, refresh token field, or `xapp`
refresh path. Therefore, the setup guide must not recommend enabling
app-level-token rotation for `SLACK_APP_TOKEN` yet, even though Slack's UI
offers it. This is an implementation gap, not a Slack limitation.

## OAuth installation and scope changes

For a distributed app, configure minimum bot scopes in **OAuth & Permissions**
and route users to `/slack/install`. The OAuth callback:

1. validates one-time state;
2. receives Slack's short-lived code at `/slack/oauth_redirect`;
3. exchanges it with the client secret; and
4. stores the installation before delivery is authorised.

Redirect URLs must be HTTPS. When more than one Redirect URL is configured,
the exact same `redirect_uri` must be included in both the authorization and
token-exchange calls or Slack returns `bad_redirect_uri`. Slack also makes
scope grants additive; removing a scope requires revoking/reinstalling rather
than merely requesting fewer scopes. [OAuth installation](https://docs.slack.dev/authentication/installing-with-oauth/)

Any bot scope, event subscription, slash command, shortcut, unfurl domain,
App Home/agent feature, or manifest change that Slack marks as installation
affecting should be followed by **Install App → Reinstall to Workspace**.
The precise consent/review behaviour depends on workspace policy and
distribution status. State that as a Slack-controlled confirmation step, not a
guarantee from the PHP library.

## Capability matrix for the guide

| Capability | Minimum Slack configuration | Framework status and documentation note |
| --- | --- | --- |
| Mentions and messages | `app_mentions:read` + `app_mention`; add the relevant `message.*` event and history scope only when reading unmentioned messages. `chat:write` is needed to reply. | Starter covers mention/reply only. The demo's `message.im` is for direct messages. |
| Buttons, modals, view submissions | Enable **Interactivity & Shortcuts**. In HTTP mode set its Request URL; Socket Mode delivers the payload on the socket. | Implemented: action, options, shortcut, and view listeners. Acknowledge promptly; Slack allows about three seconds for an interaction acknowledgement. [Shortcuts](https://docs.slack.dev/interactivity/implementing-shortcuts/) |
| Dynamic select options | Same interactivity setup plus **Options Load URL** in HTTP mode, if Slack is to call it separately. | Implemented. Do not tell users to configure it for ordinary static selects. |
| Slash commands | Add `commands` bot scope; create command in **Slash Commands** with description/URL. | Implemented. Slack-created slash commands cannot be invoked inside message threads. [Slash commands](https://docs.slack.dev/interactivity/implementing-slash-commands/) |
| App Home | At least one bot scope; enable **App Home → Show Tabs → Home Tab**; subscribe to `app_home_opened` to publish on open; enable Interactivity for Home buttons. | Implemented and demonstrated by Boltling. `views.publish` is the delivery API. [App Home](https://docs.slack.dev/surfaces/app-home/) |
| Modern agent sessions | Enable **Agents**, include `assistant:write`, `chat:write`, `im:history`; subscribe to `message.im`, `app_home_opened`, `app_context_changed`, `agent_session_stopped`, and `agent_session_title_changed`. | The runtime supports assistant lifecycle, status, titles, prompts and stop handling. The demo manifest is incomplete for the current Agent guide: it lacks `app_context_changed` and does not declare a current Agent View feature. Treat Agent View/context as advanced and plan-gated until the manifest/setup path is updated. [Developing an agent](https://docs.slack.dev/ai/developing-agents/) |
| Legacy assistant lifecycle | `assistant:write`, `assistant_thread_started`, `assistant_thread_context_changed`, plus `message.im` for replies. | Compatibility support exists, but Slack documents the newer Agents surface as the recommended direction; avoid presenting legacy Assistant View as the default for new apps. [Agent updates](https://docs.slack.dev/changelog/2026/08/20/agent-updates/) |
| Custom workflow functions | Define the function/callback in **Workflow Steps** or the app manifest; set remote function runtime; make the app org-ready; add `function_executed`; then add the step to Workflow Builder. | Typed handler, completion/failure helpers exist. Slack says custom workflow steps cannot be developed on the Free plan, and eligibility/organisation policy still gates live tests. [Workflow steps](https://docs.slack.dev/workflows/workflow-steps/) |
| Link unfurls | `links:read`, `links:write`, `link_shared`, and one or more **App unfurl domains**. | Implemented in the demo. Domains must be owned/valid; changing them requires reinstall. [Unfurling links](https://docs.slack.dev/messaging/unfurling-links-in-messages/) |
| Work Objects and comments | First establish a Work Object unfurl, subscribe to `entity_details_requested`; add `entity_comments_requested` and enable Interactivity for comments. Configure the supported entity type in **Work Object Previews (New)**. | Implemented demo covers entity details/comments. Slack calls supplied external IDs and URLs untrusted: production handlers must authorise every read/write. [Work Objects](https://docs.slack.dev/messaging/work-objects-overview/) and [comments](https://docs.slack.dev/messaging/work-objects-comments/) |
| Message-metadata events | Subscribe to `message_metadata_posted`, `_updated`, and/or `_deleted`, and add `metadata_subscriptions` entries under `settings.event_subscriptions` for the app/event types to observe. | Outbound `MessageMetadata` is implemented, but inbound metadata events are currently generic payloads and are absent from the starter/demo manifest. Do not say metadata subscriptions are automatically configured. [Message metadata](https://docs.slack.dev/messaging/message-metadata/) |

## Specific manifest review findings

1. The demo manifest declares `functions.demo_format_message` and
   `function_executed`, but does **not** show `settings.function_runtime:
   remote`. Slack's manifest reference lists `function_runtime: remote`, and
   its workflow-step guide says the settings UI can add it when a remote step
   is created. The guide should give the UI-first path and label the checked-in
   demo as a development reference until it is validated in an eligible
   workspace.
2. The demo declares link scopes/events but no `features.unfurl_domains`. The
   guide must explicitly ask users to register a real owned domain in **App
   unfurl domains**, then reinstall. A placeholder domain must never be
   presented as deployable configuration.
3. The demo's work-object and custom-function features are feature-gated by
   Slack workspace/app availability. They should be separate optional sections
   with a visible "requires eligible Slack plan/workspace" note.
4. The demo does not declare metadata subscriptions. Do not add the broad
   `message_metadata_*` events to a minimal app merely because it sends
   metadata; add them only when it must react to metadata, with narrow
   `app_id`/`event_type` filters. Slack explicitly advises avoiding wildcards
   unless necessary.

## Screenshot plan for the user guide

Screenshots should show labels and URLs only—never secrets, tokens, client
credentials, installed-workspace names, or installed-user information. The
useful stable captures are:

- **Basic Information** showing the App Credentials and App-level tokens
  section headings, with values redacted.
- **Socket Mode** with the enable control, not an actual token dialog.
- **Event Subscriptions** with the Request URL field and bot-event picker.
- **Interactivity & Shortcuts** with Request URL/Options Load URL labels.
- **OAuth & Permissions** showing Redirect URLs and Bot Token Scopes labels.
- **App Home**, **Agents**, **Work Object Previews (New)**, and **Workflow
  Steps** as optional-capability landmarks.

Screenshots support orientation only; the linked Slack references remain the
source of truth because Slack changes its app-management UI independently of
this repository.
