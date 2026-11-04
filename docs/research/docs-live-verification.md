# Documentation and live Slack verification

Verified 4 November 2026 using the existing Thunder Bolt Dev app in the same development workspace as the earlier smoke tests. This supplements the three independent code/documentation reviews; it is a focused acceptance check, not exhaustive Slack conformance.

## Settings checked in the live UI

| Surface | Observed result |
| --- | --- |
| Create New App | **Paste a manifest** under **From scratch**, followed by **Continue**. The next **Create from a manifest** step contains JSON/YAML tabs and the Workspace selector together. No app was created. |
| Socket Mode | Enabled; the page describes socket delivery replacing public Request URLs. An existing app-level token has `connections:write`. |
| Event Subscriptions | Enabled; the Socket Mode notice says no Request URL is needed. Bot events include `app_context_changed`, `app_home_opened`, `message.im`, native Stop/title events and Work Object events. |
| Interactivity & Shortcuts | Enabled. Socket Mode notices remove the need for Request URL and Options Load URL. |
| Slash Commands | `/bolt`, `/kickensink` and `/kitchensink` exist. The unsaved Create New Command form has Command, Short Description and Usage Hint fields and a Socket Mode notice. The form was cancelled. |
| App Home | Home and Messages are enabled; sending commands/messages from Messages is allowed. |
| Agents | Agent experience is enabled; Dynamic suggested prompts are selected; Slack MCP Server is disabled. |
| OAuth & Permissions | Required agent/message scopes are present, with bot/user installation credentials and the previously approved acceptance redirect. Redirect controls remain Add New Redirect URL and Save URLs. |
| Work Object Previews | Enabled with the Task entity type `slack#/entities/task`. |
| Workflow Steps | Add Step is disabled with explicit prerequisites for remote function runtime and the `function_executed` subscription. These prerequisites were not changed. |

No app settings, scopes, credentials or installation grants were changed. No new token was generated, rotation enabled or consent accepted. The older app-level-token creation form and HTTP-only configuration screens were not exercised in this Socket Mode review.

## Live delivery checks

The application ran the documented `socket:serve --verify-token`, `jobs:work` and `demo:work` commands from the playground directory.

| Check | Evidence |
| --- | --- |
| Durable root-DM intake | With the job worker stopped, one new `AgentAnswer` was pending with zero attempts. Starting the worker produced one short reply in the root's thread; its receipt became succeeded with one attempt. |
| Streaming and tools | A thread follow-up requested an actual web search. Slack rendered a Search the web task card, a short streamed answer and NASA source links. The durable receipt succeeded. |
| Native Stop | During a longer search request, clicking **Stop Thunder Bolt** produced `(stopped)`. The receipt became cancelled with one attempt, cancellation requested and `JobCancelled`. |
| Current focus | With the agent open in split view beside `general`, a new turn received that channel's entity in its stored focus snapshot and replied with its identifier. The reply destination remained the app DM. The earlier ordinary DM turn had no focus entities. |
| Boltling Home | Feed, Play, Clean and Rest updated stats, activity and caretaker credit. An action inside the 15-second cooldown produced the cooldown notice without credit. Four successful care actions increased XP from 60 to 108 and level from 1 to 2. Refresh republished the dashboard. |
| Slash command and session tour | `/kickensink` acknowledged with the queued notice, then posted the tour. Its named job and streamed progress job both succeeded. |
| Actions and modal | The tour's Framework details action opened the Under the hood modal. Done closed it. |
| Work Objects | Create work item posted a native Task preview. Comments opened Slack's synced comment panel. A labeled test comment was created and edited by its author, with the edited content displayed in the panel. |

Test messages, the generated work item and its comment remain in the development workspace for inspection. Care changes remain in the development pet state. Screenshots of live replies and application data are kept in ignored local review artifacts; only the credential-free app-creation dialog is added to the setup guide.

Before shutdown, `status --format=json --require=socket,jobs` reported healthy workers with zero pending/running jobs and no expired leases. The pre-existing failed-job count was unchanged. All three review-owned processes were then stopped with SIGTERM and exited successfully. Documentation verification passed for 82 owned Markdown files, 823 local links and 75 PHP snippets; `git diff --check` was clean.

## Corrections and limits

The live review found renamed app-creation choices: the guide now uses Paste a manifest and Pick features, and explains the combined manifest/workspace step. The [setup guide](../guides/slack-app-setup.md) has a fresh screenshot. The [Agent View guide](../guides/agent-view.md) also explains that modern suggested prompts appear at the top of Messages, following Slack's [current method documentation](https://docs.slack.dev/reference/methods/assistant.threads.setSuggestedPrompts/).

Workflow execution was not attempted: the app still lacks its remote-runtime/event prerequisites, and Slack's [workflow documentation](https://docs.slack.dev/workflows/workflow-steps/) excludes custom-step development on Free plans. A disabled Add Step control alone does not prove plan eligibility. No plan or organizational settings were changed.

This review did not repeat OAuth consent, credential rotation, HTTP delivery, every select/shortcut/form variant, comment deletion, cross-user comment authorization, terminal execution, or legacy assistant lifecycle acceptance. Dynamic prompt configuration was inspected, but prompt placement was checked against Slack's documentation rather than a fresh empty DM. Native title editing was opened without an editable form appearing; edited-title preservation remains covered by automated tests rather than this live run. Earlier evidence remains in the [Agent View starter validation](agent-view-starter-validation.md) and [operations plan](production-operations-plan.md).
