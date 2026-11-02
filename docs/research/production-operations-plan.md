# Production operation — 2 November 2026

Scope: observed process health and queue visibility, a runnable supervised deployment example, and sustained checks for duplicate delivery, concurrent claims, crashes around side effects, cooperative cancellation, reconnect behavior and token rotation. Reuse the named-job lease/recovery model; no new application base class or job backend.

- [x] `bolt status --format=json`, explicit required roles, stale observations and read-only queue inspection; no token, input, lease credential or connection URL output.
- [x] Built-in Tempest workers record role/state and throttle heartbeats. Direct library callers can opt in with RuntimeMonitor. Monitoring failures cannot change job outcomes.
- [x] Deployment example with private persistent state, non-root processes, health checks, graceful stop/restart and backup/recovery instructions.
- [x] Actual subprocess concurrency, forced crash, restart/recovery, cancellation and SIGTERM drain checks.
- [x] Two-hour local reliability run, including native WebSocket polling and repeated isolated job/reconnect/OAuth rotation checks. Separate this evidence from live Slack verification.
- [ ] Fresh-project integration, CLI help/completion/bootstrap, Linux/macOS and Windows CLI checks, Mago, self-review, commit/push and CI.

Health is an observation of shared local state. A fresh connected socket or idle/running job worker satisfies that role; stale records never do. Queue failures and uncertain jobs are reported for operator attention, and do not trigger destructive recovery or automatic restarts from this diagnostic command. `doctor` remains a configuration check. HTTP host health belongs to its host.

Local evidence: 1,023 tests / 6,170 assertions; fresh production-dependency project passed; Mago format/lint/analyze have no errors (existing diagnostics retained). Live owner-only acceptance app reported one connected intake and one idle worker with fresh heartbeats, then both stopped cleanly on SIGTERM and status returned unhealthy with zero observed active processes. No test messages were sent in this slice.

The Docker production example built successfully, completed an offline local receipt, deduplicated enqueue, retained it across restart, and reported the stopped worker unhealthy. Private status observations remain in ignored work/live-acceptance/. Review fixed the heartbeat lock-contention race and added actual Socket Mode CLI SIGTERM coverage. A failed developmental soak was discarded. The clean run completed with exit 0 after 7,200 seconds: 107 rounds, 1,162,086 native WebSocket polls, 1,061,800 bytes maximum retained growth and 8,388,608 bytes peak PHP heap under a 128M limit. [Recorded result and scope](production-operations-soak.json). OAuth/reconnect responses are recorded fixtures; this is not a two-hour live Slack conformance claim.

Implementation CI passed on Linux, macOS, Windows CLI, reference generation and the offline Docker deployment check at b420bcc: [run 41452465305](https://github.com/HelgeSverre/slack-bolt-php/actions/runs/41452465305). Final self-review also isolated optional queue read failures from required socket health; its focused regression, full local suite and Mago passed. CI for that final diagnostic fix is pending.
