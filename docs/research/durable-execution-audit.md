# Durable named jobs implementation audit

Implemented 24 October 2026 after the user selected explicit named jobs and SQLite. [Accepted contract](durable-execution-spec.md); [earlier explanation and diagrams](durable-execution-explained.md).

## Implemented interface

- `Thunder\Bolt\Jobs`: delivery-bound intake, explicit-context dispatch and tenant/thread cancellation. JSON job inputs never include ambient delivery credentials. Events API event IDs are propagated from both transports; Socket Mode envelope IDs remain correlation identifiers rather than durable duplicate keys.
- `Jobs\Job`: named readonly public promoted constructor data. The codec accepts JSON data/backed enums, checks the host handler allowlist and rejects live objects, private/captured state and Tempest `#[Async]` jobs. Schema drift is an observable permanent failure; drain or explicitly migrate persisted data before renaming job classes/properties.
- `Jobs\SqliteJobStore`: file persistence with FULL synchronous mode, bounded lock waiting, transactional duplicate intake, atomic claims, attempt tokens/expiry, conditional heartbeat/outcomes, cancellation and recovery. Construction is private: production uses the file-backed `open()` factory, which rejects memory/URI shortcuts and non-private POSIX directories, including for sidecar protection. `forTesting()` is an explicitly ephemeral test factory.
- `Jobs\Worker`: fresh per-attempt DI scope, current authorizer/credentials, current conversation and thread state, discovered Tempest command handler, and conservative terminal/retry handling. Tempest supplies config, discovery, container and console; its command-bus queue/middleware are deliberately not a second execution ledger.
- `JobExecution`: checkpoint, heartbeat and cancellation query. Lost leases cannot be renewed or settled by the old attempt. A later cancellation cannot race with failure handling and recreate a pending job.
- Commands: `make:job`, `jobs:work`, `jobs:list`, `jobs:show`, `jobs:cancel`, `jobs:recover`, `jobs:retry`. Generated files refuse overwrite. Output contains lifecycle metadata rather than inputs or lease credentials.
- The hello-bolt named-note showcase uses the new queue and worker. Other playground spools and the protected function handoff remain explicit separate examples, not silently migrated work.

## Failure policy

Default policy: one attempt. An explicit `RetryPolicy::idempotent()` promise enables bounded automatic retries and expired-lease recovery with exponential capped backoff. Permanent failures stop; explicit uncertain outcomes remain held. Network failures without repeat permission and expired non-repeatable attempts are uncertain. Automatic recovery never claims that an external effect happened exactly once.

Manual retry accepts only failed, uncertain or cancelled records. Unsafe work requires `allowRepeat` / `--allow-repeat`. Lifetime attempt counts remain monotonic; manual retry grants a new bounded budget. Duplicate keys remain associated with the original record, including after completion/retry/cancellation. Records are retained; retention/schema migration tooling is not part of this initial implementation.

Cancellation stops pending claims or signals running work. Handlers cooperate through checkpoints/heartbeats; leases do not interrupt blocking I/O or fence an external API. Expired cancelled work is uncertain unless a live worker confirms its outcome. Operator signals stop after the current attempt; supervise/restart workers and configure bounded I/O/appropriate leases.

An `IntakeFailed` exception remains an error even when an application's general error hook returns normally. The receiver therefore does not turn failed storage acceptance into a successful HTTP or Socket Mode acknowledgement. Application code must still return the appropriate synchronous interaction response and must not deliberately catch/ignore intake failure while claiming work was accepted.

## Context and credential limits

Persist installation/workspace/org, user, channel, known root, correlation and explicit job inputs. Re-fetch authorization when executing; `AuthorizationSource::raw` is intentionally empty in jobs, so custom authorizers must work from portable identity. Reply helpers and stores are rebuilt, not replayed snapshots. Enterprise storage keys remain canonical across contextual workspaces. Do not put tokens, response URLs or other secret capabilities in public job fields.

Normal job dispatch rejects ambient custom-function context. Applications may queue a named job containing an opaque protected-function reference with an explicitly selected `JobContext`; the existing vault example remains responsible for token encryption, expiry and terminal workflow completion. This queue does not stretch a temporary Slack credential's lifetime.

Jobs are authenticated Slack work, not a replacement for every host application scheduler. Tempest remains the sole kernel. No broad Application superclass, Redis/Postgres adapter, listener-wide deferral, native agent-session status protocol or automatic AI-spool migration is claimed.

## Validation

- Signed HTTP: commit visible to a second connection before listener return; post-intake failure followed by redelivery deduplicates; a held SQLite writer causes a concrete `SQLITE_BUSY` failure and non-success response, including with a returning app error hook. The configured lock wait is 250 ms; wall-clock scheduler latency is not a protocol guarantee.
- Socket Mode: two distinct envelopes with the same Events API event ID acknowledge only after persistent intake and create one job.
- Two competing PHP processes: exactly one claim; process exit without settlement leaves an expired attempt which recovers to uncertain.
- Storage/runtime: tenant isolation, duplicate conflicts, scheduling, lease renewal/expiry, stale settlement, bounded backoff, manual recovery budgets, pending/running/thread cancellation, cancellation-vs-retry race, explicit uncertainty, fresh constructor services/current state, and metadata redaction.
- Scaffolding: generated job/handler passes real Tempest command discovery and worker execution; overwrite protection and completion suggestions are tested.
- Fresh Composer-installed project: generated job, custom producer command, worker completion and duplicate intake succeeded with both cold and cached discovery. No Slack API request was sent by that smoke test.

Final validation: **515 tests / 3,365 assertions pass** on PHP 8.5.12. Mago formatting passes; lint reports zero errors (24 existing warnings, 45 help findings, one note), and analysis reports zero errors (162 warnings, 46 help findings). Composer strict validation passes. All seven tooling tests and verification of 273 generated model pairs pass. Independent standards and spec reviews approved the remediation of container, private-store and failed-intake issues.

Fixture tests and local process tests do not establish performance under arbitrary disk contention or a live production crash campaign. SQLite is a local file backend: use one shared local database, not a network filesystem, and do not assume separate hosts coordinate through independent copies.
