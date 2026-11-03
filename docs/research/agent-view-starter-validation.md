# Agent View starter and durable demo validation

Verified 3 November 2026 in the Thunder Bolt Dev development app. This is a narrow live acceptance record, not a claim of exhaustive Slack/Bolt conformance.

## Implementation

- `make:assistant Name --agent-view` generates modern configuration, open/focus/message handlers, typed streaming and native Stop routing. The default command still generates legacy handlers. Existing files and manifests are not overwritten.
- The demo uses `Assistant::forAgentView()` and a new readonly `AgentAnswer` command. Its bounded JSON inputs preserve the incoming focus snapshot without changing already persisted `Answer` records.
- Workers select credentials from trusted job context, use typed task progress, and reuse transport cancellation, checkpoints, thread locks, history and session settlement. Model input receives the bounded snapshot at user-message priority; it grants no content access.
- Suggested prompts omit the legacy `thread_ts` in modern mode. The demo's Messages-tab handler can set prompts before any root message exists.

## Live checks

| Check | Observed result |
| --- | --- |
| Configuration | Agent experience and dynamic prompts were already enabled. Added the missing `app_context_changed` subscription; existing scopes and Stop/title subscriptions sufficed. |
| Durable intake | With the worker stopped, a human root DM created exactly one pending `AgentAnswer` with zero attempts. A separate `jobs:work --once` process completed it. |
| Empty focus | The first response explicitly reported an empty snapshot. No focus lookup or legacy context metadata was needed. |
| Typed tool progress | A follow-up searched NASA sources, rendered a Search the web task card, streamed a cited answer and completed successfully. |
| Native Stop | Clicked Slack's Stop control during another running web-search turn. Slack rendered `(stopped)`; SQLite recorded cancellation requested, status `cancelled`, one attempt and `JobCancelled`. |
| Nonempty focus | Opened Thunder through Agents & tools → Open in split view, then selected a channel. The next job retained one channel entity, while its destination remained the app DM. The response identified the same channel ID without fetching messages. |

Test-only messages remain in the development app DM as reviewable evidence. Raw private logs, queue files and full-page screenshots are not committed. These observations verify one workspace and transport; they do not establish Grid, cross-workspace focus, canvas/list focus or every plan's feature availability.

## Automated checks

`ProjectScaffoldTest` executes the generated modern listener through the public harness and checks Stop without requiring a queue, overwrite guards and manifest preservation. Existing tests retain the legacy path. `AgentViewDemoTest` covers root/follow-up snapshots, retry deduplication, trusted installation identity versus focus hints, dynamic prompts and the old JSON contract. `OpenAiHttpCancellationTest` runs both command types through the real worker and dispatches a native Stop fixture while the Amp response body is waiting; cancellation closes the body and the worker does not replay the turn.

Core tests cover constructor injection, empty-focus isolation, unknown entities, middleware/errors, typed/raw streaming and lifetime limits. Recorded tests establish those variants independently of the live workspace.

Validation passed: **1,109 PHP tests / 6,504 assertions**, Mago formatting/lint/static-analysis error gates, documentation checks, reference-tooling tests, generated SDK/transport/parity verification, and fresh path/VCS production projects. The path-project smoke check also runs the real `--agent-view` command, verifies completion metadata and manifest preservation, then boots the installed generated handler through streaming and Stop with installation-scoped credentials. Existing nonfatal Mago warnings remain visible.

## Remaining deliberate limits

The generated quick reply runs inline; slow application/model work belongs in named jobs. Stop is cooperative and cannot undo a completed tool or interrupt arbitrary synchronous PHP. The demo uses one attempt for outbound message work; crash recovery does not promise exactly-once effects. Unknown or large focus snapshots remain durable data, but snapshots over the model-input bound are explicitly omitted from the prompt. No Laravel AI bridge or general model-provider abstraction is introduced.
