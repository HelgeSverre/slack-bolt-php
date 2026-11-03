# Thunder Bolt playground

Use this example with the framework checkout and a Slack app configured for Socket Mode. Register `/bolt`, `/kickensink` (or `/kitchensink`) in your app manifest; mentions work too.

Before importing the checked-in manifest for this Socket Mode walkthrough, set `settings.socket_mode_enabled` to `true`. The file retains HTTP placeholder URLs; Socket Mode does not use them. In Slack's **Socket Mode** settings, confirm it is enabled and use an app-level token with `connections:write`.

```sh
composer install
cp .env.example .env
php bolt socket:serve --verify-token
```

In separate terminals, from this directory:

```sh
php bolt demo:work
# AI, assistant lifecycle and the session tour:
php bolt jobs:work
```

Set `SLACK_APP_TOKEN` and `SLACK_BOT_TOKEN` in `.env`. Set `SLACK_TERMINAL_ALLOWED_USERS` to the comma-separated Slack member IDs allowed to use the terminal, and `OPENAI_API_KEY` to enable AI. `OPENAI_MODEL` defaults to `gpt-5.5` and can be changed to a model available to your API project.

## Try it in Slack

- `/kickensink` — open the interactive tour: a real durable session with streamed task progress, Stop, AI, Boltling, work items and a framework-details modal. `/kitchensink` is an alias. The tour itself uses no model; Ask AI runs a real Responses request.
- `/bolt pet` — meet Boltling. Everyone can feed, play, clean, and rest using the buttons. Contributors earn credit, and Boltling levels up and evolves. Stats decay over elapsed time; neglect is recoverable. Refresh reloads current state.
- `/bolt terminal` — start a local shell and reply in its thread to type. Ctrl+C and Close buttons control the session. Only its allowlisted owner can operate it.
- `/bolt ai hello` — introduce the assistant. Ask questions, request web searches, or ask it to check/feed the pet. Continue in the answer's thread. Search status is based on actual Responses tool output, and sources are clickable.
- `/bolt work Ship the PHP integration` — create a Work Object task preview with details and shared comments.
- `/bolt help` — show the available demos.

Mentions accept the same prefixes: `@Thunder Bolt pet`, `@Thunder Bolt terminal`, or `@Thunder Bolt ai what is the size of the Sun?`. Subscribe to `message.channels`, `message.groups`, `message.im`, and `message.mpim` with their history scopes for unmentioned thread replies. Invite the bot into the channel. If your manifest includes the `test_message` message shortcut, it asks the AI about the selected message.

## Local state and execution

Pet state is JSON at `.thunder-bolt/demo/pet.json`, partitioned by workspace, updated under a separate file lock and atomic replacement. Care has cooldowns and delivery deduplication; audit history and contributor counts record who did what. AI thread history and thread routing use the framework's file conversation adapter under `.thunder-bolt/demo/threads/`.

AI turns, assistant setup and the session tour use named jobs in `.thunder-bolt/jobs/queue.sqlite`. Intake commits before acknowledgement; workers resolve current credentials and history. AI uses one attempt by default because model calls and tool effects are not safe to repeat automatically. After a crash, an expired attempt becomes uncertain for inspection. Use `jobs:list`, `jobs:show`, `jobs:cancel` and `jobs:recover` to inspect or stop work. AI conversation locks prevent concurrent workers overwriting the same thread's history.

Pet, terminal and work-item handlers still use the local JSON spool and `demo:work`. An interrupted claimed entry remains in `running/` for inspection and is never automatically replayed. Completed identifiers are retained for up to 24 hours (at most 4,096). Old AI entries in this spool are rejected rather than silently replayed. Slack output is coalesced and requests have bounded timeouts.

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

`config/assistant.config.php` now uses `Assistant::forAgentView()` for human root DMs and thread replies. Keep `php bolt socket:serve --verify-token` and `php bolt jobs:work` running in separate terminals. In Slack app settings, enable **Agents → Agent experience**, use dynamic suggested prompts, and enable writable **App Home → Messages**. The demo manifest includes `features.agent_view.agent_description`, `assistant:write`, `chat:write`, `im:history`, and the App Home, focus, message, Stop and title events. Reinstall after scope changes. Feature access depends on your workspace; follow the [Agent View setup guide](../../docs/guides/agent-view.md).

Opening Messages installs three dynamic prompts without inventing a thread timestamp. Each message queues a separate `AgentAnswer` with bounded text and an exact `AppContext` JSON snapshot before model work begins. Slack delivery identity deduplicates retries. The worker resolves current installation credentials, streams text and typed `TaskUpdate` progress, persists history, and settles session status. It shares the existing Responses implementation, web search and scoped pet tools.

Focus is pinned to that turn. Missing context becomes empty; no older selection is restored from local state. The worker passes a bounded snapshot as user-level model input, not system instructions or authorization. It does not fetch channel contents automatically or publish focus as legacy assistant message metadata. Large snapshots are omitted from model input explicitly. Model history lives under `.thunder-bolt/demo/threads/`, separately from the queue. The existing `Answer` command and legacy handler source remain available so already queued slash-command/legacy work can finish without a JSON schema migration.

Agent View handlers own human IM roots and replies. Run other playground demos in channels when testing this mode. To use ordinary IM listeners instead, remove the assistant config. Model execution stays in the durable worker; opened/focus events do not create model jobs. [Live verification and limits](../../docs/research/agent-view-starter-validation.md).

Slack's native Stop and the tour's Stop button flag jobs for the delivering installation and thread. Running AI work polls that flag, renews its lease, cancels the Amp HTTP transport during connection or stream reads, and checkpoints before tools. The response body closes and the session leaves processing. Stop cannot undo an already completed tool or interrupt synchronous PHP code; bounded Slack calls can finish before the next checkpoint. User title edits are retained rather than overwritten on follow-ups.

## Durable named-job showcase

Run `php bolt jobs:work` in another terminal, then mention the app with `durable hello from SQLite`. `Listeners\DurableJobs` saves a named `PublishNote` job; its Tempest-discovered handler posts the note in the original thread. Existing mention listeners may also greet you immediately. Use `jobs:list`, `jobs:show`, `jobs:cancel`, `jobs:recover` and `jobs:retry` to inspect the lifecycle. The note uses the default single attempt because sending a new message is not safe to repeat automatically.

The default database is `.thunder-bolt/jobs/queue.sqlite`, separate from conversation and playground storage. Set `SLACK_JOB_STORE_PATH` for another private local file. This worker requires `pdo_sqlite`; help and discovery remain usable without opening the database.

The `agent_session_stopped` listener cancels named work for the stopped thread and settles native session status. It now includes AI turns and the session tour. Pet and terminal spool entries still use `demo:work`; the protected custom-function example below uses `command:monitor`.

## Custom functions and Tempest async

The manifest declares `demo_format_message`: one required string input, `message`, and one string output with the same name. It intentionally does **not** declare a remote `function_runtime`; configure the appropriate remote runtime, org-readiness, and `function_executed` subscription in Slack before testing. Add **Format a message** to a Workflow Builder workflow, provide its input, and use its output in a later step. Slack rejected creating custom functions in our free development workspace; these settings require an eligible workspace before a live workflow run is possible.

Run the Socket Mode worker and Tempest's command worker from this directory:

```sh
php bolt socket:serve
# A separate terminal; this is distinct from demo:work.
php bolt command:monitor
```

`FormatMessageFunction` saves the execution before dispatching a `#[Async]` data command. The command contains only a reference. Its worker returns `Formatted: <message>` through `functions.completeSuccess`; invalid business input uses `functions.completeError`. Slack's delivery acknowledgement and workflow completion are separate.

The demo requires `ext-sodium`. `.thunder-bolt/demo/function-executions/` holds a private local encryption key and atomic execution records. Workflow credentials are encrypted, expire after 14 minutes, and are removed on known terminal outcomes. References are scoped by the trusted delivery tenant and execution ID; duplicate commands do not repeat work. A claimed or uncertain execution is never automatically replayed after a crash. Inspect such records manually; this is a local demonstration, not a retry/recovery system.

Tempest 3.20's async bus is experimental. Its default file repository stores reference-only command payloads inside the installed command-bus package; configure an appropriate repository for deployment. Keep the vault outside `public/` and out of source control. Tests exercise the real command bus with a memory repository and recorded Slack HTTP responses, including redelivery, expiry and completion failure.

The minimal Thunder app icon is saved in `resources/branding/thunder-bolt-dev.png` and is installed on the development Slack app. [Brand assets](resources/branding/README.md) include the SVG masters, size exports, palette and reproduction command.
