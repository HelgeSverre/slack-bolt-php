# Application composition and async execution research

## Objective

Determine how Thunder Bolt should expose convenient application composition and asynchronous/durable work without duplicating Tempest, conflating the Slack App with a general application kernel, or making unsafe acknowledgement/retry guarantees. Close independent CLI/documentation gaps while keeping architecture choices reviewable before implementation.

## Design questions

1. What does Bolt JS own versus its receiver, JavaScript event loop and application job system?
2. Which boot, container, configuration, command-registration, worker and async facilities already exist in locked Tempest 3.20?
3. Which Laravel Application/queue ideas simplify the PHP interface, and which would duplicate our host framework?
4. How should synchronous Slack acknowledgements relate to explicitly deferred business work?
5. What context can be serialized safely, what must be reconstructed, and which credentials expire?
6. How do in-process concurrency, durable queuing, retries and cooperative cancellation differ?
7. Is a new base Application class justified now, or should existing App/Tempest composition be made easier first?

## Systems

| System | Source | Scope |
| --- | --- | --- |
| Thunder Bolt | Current repository source and tests | Actual seams, pain points, current demo queue |
| Bolt JS 5.1.0 | Pinned source and official Slack documentation | App/receiver responsibilities, ack and async contract |
| Tempest 3.20 | Locked vendor source and tempestphp.com | Kernel, DI/config/discovery/commands, async command bus and workers |
| Laravel | Official framework docs/source | Application builder, lifecycle, queues/concurrency and integration tradeoffs |

## Report contract

Each report: concise overview, terminology, actual data/context flow, a Mermaid workflow, existing facilities and missing guarantees, candidate PHP interfaces, recommendation and primary-source links. Mark verified source facts versus inferences and unknowns. Prefer locked code over assumptions about latest releases. Never claim durable/retry/exactly-once behavior from an async attribute alone. Reports own separate files; no dependencies or runtime changes during research.
