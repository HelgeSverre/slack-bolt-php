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
