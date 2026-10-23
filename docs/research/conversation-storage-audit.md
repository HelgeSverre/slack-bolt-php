# Conversation and thread storage audit

Verified locally on 23 October 2026 with PHP 8.5.12 and Tempest 3.20.0. This milestone implements conversation state, generic thread-scoped state, durable adapters, and delivery context. The [source mapping](conversation-storage.md) records pinned Bolt JS 5.1.0 semantics; the [user guide](../conversation-and-thread-storage.md) describes the public PHP interface.

## Implemented requirements

| Requirement | Implementation and evidence |
| --- | --- |
| Retain conversation-store behavior | Whole-value read/replacement, default memory for directly constructed Apps, disable option, exact absolute-millisecond expiry, missing/expired distinction, forgiving load behavior |
| Durable adapters | Private hashed files with lock/atomic replacement and SQLite with parameterized queries/write transactions; shared adapter contract tests |
| Delivery context | Conversation/thread method injection and Delivery access after authorization/self filtering, before global middleware; one snapshot shared by matching listeners |
| Thread state | Known root timestamps from messages, nested changes/replies/deletes, message actions/shortcuts; precise string timestamps; no guessed reaction root |
| Canonical tenancy | Workspace keys use team identity; Grid keys use enterprise identity without splitting on contextual workspace; channel and thread records remain separate |
| Missing context | Global shortcuts/modal callbacks remain unavailable even with stray channel fields; disabled contexts read defaults and reject writes |
| Consistent portable values | JSON scalars/lists/maps, null-presence distinction, detached snapshots, float preservation, rejection of objects/non-finite/cyclic/excessively nested inputs |
| Concurrency | Atomic latest-value transforms, callback rollback behavior, recursive-access guards, independent PHP worker tests for both durable adapters |
| Configuration and diagnostics | File default in Tempest, optional SQLite/memory/disabled, generated environment fields, driver reporting and SQLite requirements in doctor |
| Connected example | `RememberConversation` records channel/thread mention counts without additional Slack API calls |

## Final local validation

- PHPUnit: **194 tests, 662 assertions**, passing without skips or notices.
- PHPStan level 6: **207 source files**, no errors.
- Laravel Pint: passing.
- Strict Composer validation: package and example passing; the fresh smoke project also passed strict validation.
- CLI syntax/version checks: passing; Linux/macOS CI now checks the extensionless CLI explicitly.
- Node tooling: **6 tests**, passing; generated-code ownership verification remains consistent at **259 Web API methods, 34 root groups, 94 resources, 126 declaration scaffolds, 359 export mappings**.
- `git diff --check`: passing.

`tests/Conversation/ConversationStoreTest.php` crosses the memory/file/SQLite seam for canonical keys, JSON round trips, strict expiry boundaries, updates, errors, and restart behavior. `StoredContextTest.php` verifies null snapshots, native date conversion, load/write failure semantics, and recursive-update rejection. Delivery and dispatch tests cover extraction, isolation, middleware timing, self filtering, disabled state, and unavailable contexts.

`DurableConversationTest.php` starts two independent PHP workers for each durable adapter. A synchronized start and delayed read/modify/write callbacks overlap ten updates per worker. A new adapter reads the expected twenty updates afterward. Its separate SQLite regression inserts a fresh record between an expired read and cleanup, proving that cleanup cannot delete the concurrent fresh value.

## Fresh installed application over HTTP

A new application was scaffolded and installed from this checkout. Discovery was generated before starting local development servers. Four modes were tested using signed Slack payloads: disabled, memory, file, and SQLite.

Across **26 signed requests**, the smoke verified:

1. A root mention and reply share one thread while increasing the channel counter.
2. Another root has a separate thread counter within the same channel.
3. Another workspace with the same channel/root strings has separate state.
4. Slash commands read channel state without inventing a thread.
5. Global shortcuts with stray top-level channel fields still have unavailable context.
6. Disabled mode persists nothing and reads unavailable state; memory state lasts only for the current application object.
7. File and SQLite state survive both separate HTTP requests and development-server restarts.
8. CLI doctor reports the configured mode and cached discovery remains usable.

Invalid signatures also returned `401` during server readiness checks. The temporary servers/application were removed. No real credentials or Slack API calls were used; Composer installation can use package-network traffic. Remote CI and real-workspace validation have not run.

The smoke caught a CLI parse regression from formatting an extensionless PHP file: imports preceded `declare(strict_types=1)`. The declaration order was restored and explicit CLI syntax/version checks were added to CI. Independent review also caught and resolved canonical Grid key partitioning, an SQLite expiry cleanup race, and self-filter parity for bot messages and membership events.

## Deliberate PHP differences and remaining scope

Keys add tenant isolation to Bolt's bare conversation-ID store. Generic thread records, native expiration dates, JSON portability, atomic transforms, and snapshot refresh after explicit writes are PHP extensions. Tempest applications use durable files by default because conventional PHP requests create fresh application instances; directly constructed Apps preserve Bolt's memory default.

Load failures retain Bolt's debug-log-and-continue behavior. Writes are explicit and propagate failures; successful writes are not rolled back by a later handler error. Expiration is evaluated at store access, not continually against an already-loaded delivery snapshot.

File and SQLite adapters coordinate storage shared by local workers. Deployments using independent hosts need shared backend adapters implementing the same contract. Automatic retention sweeps, retry deduplication, durable queues, and Assistant-specific metadata-backed context remain separate work. The next planned milestone is SDK and Block Kit completeness.
