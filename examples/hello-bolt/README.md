# Thunder Bolt playground

Use this example with the framework checkout and a Slack app configured for Socket Mode. Register `/bolt` in your app manifest; mentions work too.

```sh
composer install
cp .env.example .env
php bolt socket:serve
```

In a second terminal, from this directory:

```sh
php bolt demo:work
```

Set `SLACK_APP_TOKEN` and `SLACK_BOT_TOKEN` in `.env`. Set `SLACK_TERMINAL_ALLOWED_USERS` to the comma-separated Slack member IDs allowed to use the terminal, and `OPENAI_API_KEY` to enable AI. `OPENAI_MODEL` defaults to `gpt-5.5` and can be changed to a model available to your API project.

## Try it in Slack

- `/bolt pet` — meet Boltling. Everyone can feed, play, clean, and rest using the buttons. Contributors earn credit, and Boltling levels up and evolves. Stats decay over elapsed time; neglect is recoverable. Refresh reloads current state.
- `/bolt terminal` — start a local shell and reply in its thread to type. Ctrl+C and Close buttons control the session. Only its allowlisted owner can operate it.
- `/bolt ai hello` — introduce the assistant. Ask questions, request web searches, or ask it to check/feed the pet. Continue in the answer's thread. Search status is based on actual Responses tool output, and sources are clickable.
- `/bolt work Ship the PHP integration` — create a Work Object task preview with details and shared comments.
- `/bolt help` — show the available demos.

Mentions accept the same prefixes: `@Thunder Bolt pet`, `@Thunder Bolt terminal`, or `@Thunder Bolt ai what is the size of the Sun?`. Subscribe to `message.channels`, `message.groups`, `message.im`, and `message.mpim` with their history scopes for unmentioned thread replies. Invite the bot into the channel. If your manifest includes the `test_message` message shortcut, it asks the AI about the selected message.

## Local state and execution

Pet state is JSON at `.thunder-bolt/demo/pet.json`, partitioned by workspace, updated under a separate file lock and atomic replacement. Care has cooldowns and delivery deduplication; audit history and contributor counts record who did what. AI thread history and thread routing use the framework's file conversation adapter under `.thunder-bolt/demo/threads/`.

Handlers enqueue small JSON jobs before acknowledging Slack. The demo worker claims jobs in order; an interrupted claimed job stays in `running/` for inspection and is never automatically replayed. Completed-job identifiers are retained for up to 24 hours (at most 4,096) to suppress redelivery. This local spool demonstrates the seam for background work; it does not replace a production queue. Terminal/AI output updates are best-effort and coalesced; Slack requests have bounded timeouts.

The terminal is a real shell on the worker host, with a dedicated initial working directory and a stripped child environment. It is **not a sandbox**. Its output is visible to the channel, so use a development host and an explicit allowlist. Sessions expire after inactivity and close with the worker; shell processes do not survive its restart. Pet and AI state do survive restarts. AI tools are limited to web search and pet care; the model has no terminal tool.

Never commit `.env` or `.thunder-bolt/`. Neither demo code nor logs should print API credentials.

AI replies use OpenAI's Responses event stream and Slack's native message streaming APIs. Text is batched, and web searches and pet tools appear as task cards with running, complete, or error states. Answers include source links when available. New `/bolt ai` conversations create a root message and stream the answer in its thread; follow-ups stay in that thread. If Slack streaming fails, the worker posts the complete answer without replaying tools.

The writing instructions live in `app/Demo/Ai/Voice.php`: compact plain English, brief context where needed, sparse formatting, meaningful symbols, and no emoji or fictional persona. Try `/bolt ai Search the web: how big is the Sun?`, then ask `How does that compare with Earth?` in the same thread.

Open the app in Slack and select **Home** for Boltling's dashboard: four stats, level and XP progress, the eight most recent care actions, and the top five caretakers. Feed, Play, Clean, Rest, and Refresh work directly in Home and use the same workspace pet as channel cards and AI tools. Care credit, cooldowns, and delivery deduplication apply on every surface. Each person's Home refreshes on opening or clicking a button; changes from other people appear on the next refresh.

For a new Slack app, enable **App Home → Home Tab** and subscribe to the **`app_home_opened` bot event** under Event Subscriptions. Both are included in `slack-manifest.yaml`. Existing interactivity and the demo worker are also required. Home actions do not need a channel ID, extra OAuth scopes, or a new slash command.

## Work Objects

Enable **Work Object Previews** in your Slack app settings, add the **Task** entity type (`slack#/entities/task`), and subscribe to the `entity_details_requested` and `entity_comments_requested` bot events. Interactivity and both local workers must be running. Slack may restrict comment syncing by app or workspace availability.

Run `/bolt work Ship the PHP integration`, then open its preview and Comments. The demo stores work items and rich-text comments in `.thunder-bolt/demo/work-items.json`, with file locking and atomic replacement. Members of the same workspace can read and post; only a comment's author can edit or delete it. The store checks those permissions again inside each write, partitions data by workspace and object, deduplicates deliveries, and supports paginated parent comments and threaded replies. This policy is intentionally simple; real applications should connect their own object access rules.

`SLACK_WORK_OBJECT_BASE_URL` defaults to `https://example.com/thunder-bolt/work-items`. These are stable example identifiers; this demo does not host an external task website. Set it to your own task URL base to make external links useful. To test shared-link unfurls, also subscribe to `link_shared`, install with `links:read` and `links:write`, and register the base URL's domain under **App unfurl domains**. Only existing local work-item URLs are unfurled.

## Native Slack assistant

`config/assistant.config.php` registers typed assistant lifecycle and IM-thread handlers. Enable **Agents → Agent experience** in Slack, install with `assistant:write`, `chat:write` and `im:history`, and subscribe to `assistant_thread_started`, `assistant_thread_context_changed` and `message.im`. Keep Socket Mode and the demo worker running. Slack's current Agent experience may expose a different entry point from the older assistant container; the runtime targets Bolt JS's assistant-thread events and IM thread replies.

When Slack sends `assistant_thread_started`, the demo queues a greeting, title and three suggested prompts. In the current Agent experience, send a DM to start: an application handler anchors the conversation to that message, saves empty focus context and queues the answer. The first answer sets its title and prompts; subsequent IM-thread replies use the core assistant runtime. Both paths reuse the existing Responses streaming implementation, web search and pet tools. Status is cleared on success and failure. Focus context is saved locally before acknowledgement at `.thunder-bolt/demo/assistant-context/`; it is not conversation history and is not a grant to read the channel the user is viewing. The AI example does not fetch that channel's messages.

Assistant handlers own IM thread replies. Run the other playground demos in channels when testing this mode. To use ordinary IM-thread listeners instead, remove the assistant config. Slow model execution stays in the worker; lifecycle events and message deliveries are deduplicated by the local queue.
