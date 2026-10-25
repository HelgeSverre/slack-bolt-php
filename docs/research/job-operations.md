# Durable job operations

Implemented 25 October 2026. This extends the initial [durable execution contract](durable-execution-spec.md) without changing named-job handlers, retry opt-in or delivery authorization.

## Retention and deduplication

```sh
php bolt jobs:prune --before=30d --dry-run
php bolt jobs:prune --before=30d --limit=1000
php bolt jobs:show JOB_ID --history-limit=100
php bolt jobs:migrate
```

`before` is a positive age in days or hours. Tempest also accepts named-argument notation (`before=30d`); generated completions include `--before=`, `--limit=` and `--dry-run`. Invalid ages, limits and help requests do not open storage. JSON goes to stdout; errors go to stderr without payloads, credentials or arbitrary transport messages.

Pruning selects only `succeeded`, `failed` and `cancelled` records whose `finished_at` is strictly older than the cutoff. It protects pending, running and uncertain jobs, even if a running lease has expired. It never performs recovery or retries. Completion time, rather than creation time, prevents a long-running job being pruned immediately after finishing. Retrying clears `finished_at`.

Each call previews or removes a batch of 1–1,000 jobs, oldest completion first. The JSON result reports eligible/pruned jobs, associated history entries, the cutoff, batch limit and `more_available`. Preview is a read-only query on an already-current schema; counts can change before a later destructive run. Real pruning selects eligible jobs, inserts receipts and deletes rows/history in one writer transaction. Concurrent retry, cancellation and intake serialize against that transaction.

The retained receipt contains tenant, job class, SHA-256 digest of the key, the original input/context/policy fingerprint, original job ID, last status and prune timestamp. It contains no inputs, raw key, conversation snapshot or lease. Repeated identical dispatch returns the original ID with `duplicate=true`; changed inputs still conflict. A pruned job cannot be retried because its inputs are gone. `find()` and `recent()` operate on retained job rows; `inspect()` / `jobs:show` also identify receipts as `status: pruned` with `last_status` and `history_pruned: true`.

Receipts are retained indefinitely. This bounds the larger inputs/history by age without silently weakening deduplication. It does not impose a fixed maximum database size: compact receipts still accumulate. Deleting receipts manually reopens old keys and can repeat external effects; an automated expiry policy is deferred until a business replay horizon is chosen. SQLite reuses freed pages; pruning does not run `VACUUM`, promise secure erasure or remove records from backups.

## History and recovery decisions

History records acceptance, claims, attempt outcomes, cancellation requests, manual retries and expired-lease recovery. Each entry includes attempt number, resulting status, occurrence time, reason, availability and any current lease expiry. It excludes input data, exception messages, raw deduplication keys and lease credentials. Heartbeat polling creates no history entries. Duplicate intake and repeated running cancellation requests do not inflate the history.

An `attempt_finished` entry can have `pending` status when another safe attempt is scheduled, or `cancelled` when a cancellation request wins the outcome race. Recovery reasons distinguish safe automatic retry, non-idempotent uncertainty, exhausted attempts and cancellation after an expired lease. Manual retries record whether the operator explicitly allowed repetition. Counters remain monotonic across manual retries.

State and history mutations share one transaction, so a failure appending history rolls back the corresponding claim/outcome. Stale attempts cannot append successful outcomes after lease recovery or pruning. Inspection reads summary and history from one consistent read snapshot. It returns the latest bounded history entries in chronological order and marks truncated history. Pruning removes the selected jobs' history through an enforced SQLite foreign-key cascade.

## Schema upgrades

The dedicated `thunder_bolt_job_schema` table versions this store without changing an application's `PRAGMA user_version`. Version 1 represents the original job layout; version 2 adds completion timestamps, history and receipts. Fresh stores and original unversioned files reach version 2 through the same additive migration path.

An unversioned file must match the original column layout. Existing IDs, fingerprints, inputs, contexts, retry policies, attempt counts, cancellation flags and live leases are preserved. Old terminal completion times use their last stored update time. History receives one `legacy_imported` snapshot per retained job, using its previously stored update timestamp and attempt counter with `earlier_history_unavailable`; earlier individual attempts cannot be reconstructed.

Migration uses `BEGIN IMMEDIATE` and commits DDL, data backfill and version changes together. Another opener rechecks the version after acquiring the writer lock. An error rolls everything back, and unsupported/newer or malformed version records fail closed. The existing short SQLite contention timeout remains; inbound intake fails rather than waiting indefinitely if migration or another writer holds the database.

Before deployment, stop old workers, take a consistent SQLite backup in a private location, deploy the new code, run `jobs:migrate`, inspect representative records and restart workers. Ordinary opens also migrate; `--dry-run` is a retention preview, not a schema-migration preview. Old binaries do not maintain the new history/completion fields, so mixed-version workers are not a supported upgrade strategy. There is no downgrade command; roll back application/database versions using the consistent backup.

## Validation scope

An independent original-schema SQL fixture exercises upgrade and reopen, all job states, existing deduplication and live leases. Tests inject migration/history/prune failures to verify rollback; exercise future/malformed versions, completion cutoffs, protected states, bounded dry runs, cross-connection deduplication, recovery reasons and redacted history. CLI tests run real subprocesses with a private isolated database, verify help/invalid-input laziness, JSON inspection/pruning and generated completion flags. The CLI installation fixture uses Unix symlinks and is skipped on Windows; store tests do not require those symlinks.

This milestone does not add a shared-store adapter, distributed scheduler, automatic pruning, key expiry or database compaction. Choose a maintenance schedule appropriate to the deployment, and repeat bounded batches while `more_available` is true.

Validation: 560 offline tests / 3,777 assertions pass. Mago formatting, lint and analysis pass with no errors; advisories remain visible. Independent standards and specification reviews found no remaining material issues. Failure injection exposed a retained statement lock on PHP 8.5.10; explicit cursor cleanup now makes rollback and subsequent writes work on both tested local PHP versions (8.5.10 and 8.5.12).

SQLite references: [transaction and snapshot semantics](https://www.sqlite.org/lang_transaction.html), [foreign-key enforcement and cascades](https://www.sqlite.org/foreignkeys.html), and [VACUUM and freed-page reuse](https://www.sqlite.org/lang_vacuum.html). Local failure-injection tests verify the corresponding behavior in the installed SQLite runtime.
