# CLI/payload gap closure and async investigation

24 October 2026. Follow-up to the [codebase inventory](codebase-inventory-2026-10-24.md). This audit distinguishes completed changes from architecture still under discussion.

## Implemented

- `make:listener --event` accepts general selectors, chooses native payloads for known events and uses `Payloads\Event` for unknown events. Non-chat event scaffolds deliberately make no automatic Slack call; lifecycle events have no usable reply destination or credential.
- `make:assistant [name]` writes an assistant listener and configuration without overwriting existing files. It prints opt-in Slack scopes/events instead of broadening the manifest silently. The command is included in the CLI allowlist and generated completion metadata.
- Native payloads and dispatch validation now cover `reaction_added`, `reaction_removed`, `member_joined_channel`, `member_left_channel`, `link_shared`, `file_shared`, `app_uninstalled`, `tokens_revoked`, `agent_session_stopped`, and `agent_session_title_changed`. Existing generic listeners still accept these payloads. Unknown fields remain in `raw`.
- Event occurrence timestamps are not invented message/thread destinations. `FileShared` exposes its documented user/channel identifiers without inventing a message timestamp. Membership events retain their ignore-self exception; lifecycle events still bypass vanished credential lookup.
- README, architecture, runtime audit, SDK escape-hatch wording, parity map and roadmap were corrected. Historical milestone audit counts remain historical.

## Evidence

- Combined PHPUnit suite: **489 tests, 3,248 assertions**, passing on PHP 8.5.12.
- Mago format check passes; lint has zero errors (24 warnings, 39 help findings); analysis has zero errors (154 warnings, 45 help findings). Four new mixed-input advisories occur in validated raw JSON parsing; this is not a zero-advisory claim or completion of the broader triage task.
- All seven generator tests pass; verification still confirms 273 model pairs and all generated ownership records.
- CLI help exposes `make:assistant`; generated completion coverage includes it. `git diff --check` passes.

Generated classes are discovered and dispatched in tests, including reaction, lifecycle and channelless generic events. Assistant scaffolds are invoked with fixture responses; existing-config and existing-listener cases leave files intact. Typed event tests cover input shapes, optional fields, raw preservation, generic/specific listeners, incompatible registrations and self-event policy.

Official source contracts: [file_shared](https://docs.slack.dev/reference/events/file_shared/), [agent_session_stopped](https://docs.slack.dev/reference/events/agent_session_stopped/), [agent_session_title_changed](https://docs.slack.dev/reference/events/agent_session_title_changed/). Other event-family references are the matching official Slack event pages; typed shape checks do not claim exhaustive wire-schema validation.

## Investigated, not yet implemented

The [durable-execution explainer](durable-execution-explained.md) contains the context model, acknowledgement/job diagrams, concurrency experiment, failure/recovery tradeoffs and application-composition recommendation. Three source-backed reports compare locked Tempest 3.20, Laravel and Bolt JS.

Recommendation: explicit named jobs, durable SQLite execution records, atomic claims/leases, bounded known-safe retries and visible uncertain outcomes; reuse Tempest config/DI/discovery/commands. A queue is separate from conversation state. A broad Laravel-like Application superclass duplicates the existing host; revisit a narrow bootstrap builder after the worker creates a concrete shared setup requirement.

The async dispatch preference was presented to the user. Until it is resolved, no durable worker public interface or second application kernel is claimed as implemented. Modern agent-session lifecycle helpers and cooperative cancellation still need the execution foundation; typed stop events alone cannot stop a PHP task.

## Remaining gaps

Durable execution/recovery, integrated session cancellation, release/package installation validation, selected paid-plan live workflow tests, Mago advisory triage, and demand-led deployment/shared-store adapters remain open. No package release, live Slack setting change, or broad data-store replacement is part of this follow-up.
