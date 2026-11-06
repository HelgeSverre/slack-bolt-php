# Changelog

## Unreleased

- Shared `Bootstrap` for HTTP and CLI with `BOLT_RUNTIME_PATH`, private-directory checks and consistent project-relative store paths; no release-local symlink is needed for new entry points.
- Preserve intentional application HTTP error responses, including their bodies and headers, while keeping exception rendering and route-binding 404 handling.
- Optional `doctor --sessions` and non-rotating `key:generate` with explicit `--force`, algorithm-aware keys, atomic private writes and no key output.
- Coherent `bolt new --profile=mentions|interactive|agent` starters, with matching classes, scopes, events, OAuth redirects and transport-specific manifest URLs.
- Typed slash-command `triggerId`, complete command testing fixtures, expanded generated-project and browser-session smoke checks, and runtime/extensibility documentation.

## v0.8.0 — 2026-11-05

- Update locked Symfony Filesystem and YAML dependencies to patched v8.1.11 releases.
- Resolve current workspace credentials for playground spool jobs and terminal screen updates; keep Agent View replies in owner-guarded terminal threads out of the model.
- Typed view-operation, message-update and ephemeral request values accepting native builders, explicit destinations and optimistic view hashes.
- Immutable interaction fixtures, strict nested API/acknowledgement assertions and end-to-end composition tests through the real receiver.
- Native rich-text tags, message references and Work Object references, with supported modern styles and enum/named tag colors.
- Return coherent current bot/user credentials from combined token rotation without carrying an unselected user's grant.
- Keep HTTP acknowledgement serialization inside receiver error handling.
- Reject invalid container collapse states and empty children, alerts over 200 characters and cards with more than three actions.
- Refuse dangling symlink file targets in generators and show an actionable diagnostic when project dependencies are missing.
- Preserve uncertain AI message-delivery outcomes instead of automatically posting duplicate-prone fallbacks.
- Recorded the [bug-hunt findings and ranked small additions](docs/research/bug-hunt-2026-11-04.md).

## v0.7.0 — 2026-11-04

- Native immutable Container, Layout, DataTable and DataVisualization builders, with typed rows/items/cells, pie/bar/area/line chart values and `Blocks` convenience factories.
- Validation for nested block budgets, layout widths/fallbacks, table shape and character budgets, chart categories/series and unsupported modal placements. Message-wide chart/table checks include attachments; typed streaming chunks validate their own batch.
- Expanded Block Kit catalogs and executable examples, distinguishing native coverage from Slack workspace/surface restrictions.
- Explicit plain-text emoji settings fix Slack's `internal_error` for containers nested in streamed layouts; verified start, append, completion, rendering and interactions in the development workspace.
- Hard live Slack acceptance gate before every release, with a reusable modern-block fixture and recorded results.

## v0.6.0 — 2026-11-03

- Modern `make:assistant --agent-view` scaffolding with typed streaming and native Stop routing, retaining the legacy default and overwrite guards.
- Agent View demo with separate durable focus-snapshot commands, typed task progress, cancellable model execution and unchanged legacy queued-job JSON.
- Modern suggested prompts omit `thread_ts`, as required by Slack's current Agent View behavior.
- Refreshed parity matrix, roadmap, setup/migration guides and narrow live acceptance evidence for DM intake, focus, streaming and Stop.

## v0.5.0 — 2026-11-02

- Immutable typed streaming text, task, plan and Block Kit chunks, with native task statuses and source builders. Existing raw arrays remain supported.
- Whole-stream text/task accounting, local builder/final-block validation, and unchanged terminal failure behavior.
- AI and kitchen-sink demos use typed task progress; the public fake captures streaming writes and negative message assertions detect them.
- Streaming examples, limits, failure/testing guidance and current `^0.5` installation instructions.

## v0.4.0 — 2026-11-02

- Opt-in `Assistant::forAgentView()` with Messages-tab opening, focus changes, human root DMs and thread replies, preserving legacy assistant behavior.
- Typed ordered app-context entities on messages, App Home and `app_context_changed`, with per-delivery injection and forward-compatible raw fields.
- Fresh focus semantics: no stale cache fallback, invented thread anchors, automatic content fetching or public legacy metadata.
- Agent View configuration, migration and testing guidance; default generated project constraint updated to `^0.4`.

## v0.3.0 — 2026-11-02

- Packagist-first project scaffolding, with explicit path/Git sources retained.
- Typed App Home, channel lifecycle and message-metadata events from one shared dispatch/scaffolding catalog.
- Public application testing toolkit with signed fixtures, an in-memory Slack transport, reply/acknowledgement assertions, and real isolated SQLite job intake.

- Public Composer identity `helgesverre/slack-bolt`, preserving `Thunder\Bolt` imports; aligned generated applications, example lockfile and launchers.
- Public installation instructions, status badges and a minimal generated README header.
- Updated the locked Symfony Mime dependency to its patched release.

- Observed runtime status for Socket Mode and durable workers, with heartbeat freshness, queue backlog and uncertain/expired outcome visibility. Diagnostics are read-only and redact sensitive execution data. Optional queue failures preserve independent Socket Mode observations.
- Non-root Docker Compose deployment example with persistent state, separate intake/workers, health checks and graceful restart guidance.
- Graceful SIGTERM handling for one-shot job workers, real process crash/drain/concurrency tests and an opt-in sustained reliability harness.

- HTTP/Socket Mode and static-token/OAuth project profiles, with aligned manifests, environment examples and onboarding instructions.
- Mode-aware local diagnostics, placeholder and private-storage checks, machine-readable bootstrap failures and meaningful readiness exit codes.
- Live development-workspace acceptance of OAuth/account metadata, typed modal submission, separate-process durable completion and running cancellation; reusable opt-in fixtures.
- Bound idle Socket Mode cancellation cycles before deep worker stacks exhaust PHP memory; real WebSocket regression coverage.

## v0.2.0 — 2026-10-28

Experimental compatibility preview; not full Bolt JS conformance.

- Immutable installation requests with per-flow scopes, workspace hints and server-side metadata; atomic request/state consumption and independent browser-tab cookies.
- Typed installation success/failure hooks, persisted installations before onboarding, reusable host-route handling and safe custom responses/redirects.
- Authorized HTTP and Socket Mode context factories, fresh delivery context and critical factory-failure reporting.
- Shared typed action/form readers for selections, identifiers, dates/times, files and rich text, preserving existing payload readers and raw fields.
- Fresh-project recorded coverage from OAuth/account association through both transports and typed forms to durable job recovery in a new process. Development aliases now use 0.2.x-dev.

- Composable `MessageFilter::subtype()` and `directMention()` middleware, explicit workflow-token attachment policy, and sanitized SSL-check receipt without application dispatch.

- Explicit cached static-token verification through `App::verifyToken()`, `auth:verify` and `socket:serve --verify-token`, plus `SLACK_BOT_ID` for network-free self-message filtering.
- Delivery-local `Context` shared by middleware and handlers, with isolated constructor scopes when needed and fresh job-attempt context.
- Structured `App::onError()` policy with original errors, listener/delivery/stage identity and buffered acknowledgement state; safe HTTP processing-error responses. Legacy `error()` remains supported.
- Updated parity assessments and evidence for the first three compatibility gaps.

## v0.1.0 — 2026-10-26

First experimental preview. The namespace and interfaces may change before 1.0.

- PHP 8.5+ applications on Tempest 3, with discovered typed listeners, middleware, HTTP and Socket Mode delivery.
- Actions, shortcuts, external options, views, OAuth installations, state validation and token rotation.
- Immutable message/view builders, 273 SDK request/response pairs, cursor pagination, upload helpers and explicit bounded retries.
- Conversation/thread stores, assistant threads, modern agent sessions, Work Objects and custom-function execution helpers.
- Named SQLite jobs with durable acceptance, deduplication, retry policies, lease recovery, cancellation, migrations, history and bounded retention.
- Project/listener/job scaffolding and shell completions; a demo covering AI streaming, tool progress and a shared durable pet.
- Transport evidence for all 273 catalogued endpoints. Expanded GET handling, form-only POST support, corrected external-upload `alt_txt`, and bounded gzip analytics decoding.
- Fresh production-dependency installation smoke test covering discovery, signed deliveries and worker recovery across processes, alongside Linux/macOS CI.

This is not a claim of complete Bolt JS parity. Paid-plan custom functions and enterprise-only methods have fixture coverage, not comprehensive live verification. Distribution remains through this Git checkout; the package is not registered on Packagist.
