# Durable named jobs: accepted implementation contract

The user selected explicit named jobs and SQLite, with retries, recovery and cancellation, reusing Tempest where appropriate. This refines the earlier application/async investigation; it does not introduce a second application kernel.

## Interface

- A named readonly class implements `Jobs\Job` and exposes public promoted constructor data. JSON scalars/arrays and backed enum values are supported; live clients, containers, closures and PHP object serialization are not.
- Tempest discovers ordinary `#[CommandHandler]` methods. The worker invokes that handler with a fresh scoped container; it does not pass through Tempest's experimental async queue/middleware.
- Listeners receive delivery-bound `Thunder\Bolt\Jobs`. `dispatch()` commits before returning. Events API event IDs supply a default duplicate key; other sources require an explicit stable key. Keys are scoped by tenant and job type, and conflicting inputs/destinations are rejected.
- Synchronous Slack command, options and view responses remain synchronous. No whole-listener deferral or process-wide concurrency is implied.

## Persistence and recovery

- A file-backed SQLite store uses atomic intake and atomic claims, bounded lock waiting, expiring attempt tokens and conditional completion/heartbeat. A stale worker cannot settle a recovered attempt.
- Default policy is one attempt. `RetryPolicy::idempotent()` explicitly permits bounded automatic retries and lease-expiry recovery with exponential capped backoff.
- Expired non-repeatable attempts become `uncertain`; they are not automatically replayed. Explicit uncertain outcomes also remain held. Manual retry preserves lifetime attempt counts, grants a new bounded attempt budget and requires explicit repeat permission for unsafe work.
- No exactly-once external-effects promise. Lease ownership does not fence external APIs, and cancellation does not undo an action already performed.

## Scope and cancellation

- Persist only portable installation/channel/thread/user/correlation identities and job inputs. Reauthorize at execution time using the current authorizer, construct a new client/reply scope and load current conversation state. Never persist a live delivery snapshot or response URL.
- Temporary custom-function credentials require an explicit protected application reference, as in the existing function demo. Ambient function deliveries are rejected by normal job dispatch rather than serialized or silently converted to installation credentials.
- Pending cancellation prevents claims. Running cancellation is cooperative through `JobExecution::checkpoint()`/`heartbeat()`. Cancellation wins over a concurrent retry settlement; expired cancelled attempts remain uncertain when no worker confirmed stopping.
- Bound cancellation is tenant scoped; thread cancellation uses installation/channel/root identity. Business permission policy belongs to the application listener. CLI operations are local operator capabilities.

## Operations and evidence

- Tempest commands: `make:job`, `jobs:work`, `jobs:list`, `jobs:show`, `jobs:cancel`, `jobs:recover`, `jobs:retry`. Lazy store initialization keeps help/discovery usable before SQLite is needed.
- Worker signals finish the current attempt before exiting; handlers must cooperate for timely cancellation/heartbeat and use bounded I/O. Multiple processes may consume the same local database.
- Tests must cover file persistence, duplicate intake, competing processes, abandoned claims, backoff/limits, stale owners, cancellation races, tenant/context isolation, scope reconstruction, HTTP/Socket persist-before-ack including failed intake, generated handlers and cached project discovery.
- Metadata summaries exclude inputs and lease credentials. Stored failure diagnostics identify exception types without persisting arbitrary exception messages.
