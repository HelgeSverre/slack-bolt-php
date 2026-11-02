# Production operation — 2 November 2026

Scope: observed process health and queue visibility, a runnable supervised deployment example, and sustained checks for duplicate delivery, concurrent claims, crashes around side effects, cooperative cancellation, reconnect behavior and token rotation. Reuse the named-job lease/recovery model; no new application base class or job backend.

- [x] `bolt status --format=json`, explicit required roles, stale observations and read-only queue inspection; no token, input, lease credential or connection URL output.
- [x] Built-in Tempest workers record role/state and throttle heartbeats. Direct library callers can opt in with RuntimeMonitor. Monitoring failures cannot change job outcomes.
- [x] Deployment example with private persistent state, non-root processes, health checks, graceful stop/restart and backup/recovery instructions.
- [x] Actual subprocess concurrency, forced crash, restart/recovery, cancellation and SIGTERM drain checks.
- [ ] Two-hour local reliability run, including native WebSocket polling and repeated isolated job/reconnect/OAuth rotation checks. Separate this evidence from live Slack verification.
- [ ] Fresh-project integration, CLI help/completion/bootstrap, Linux/macOS and Windows CLI checks, Mago, self-review, commit/push and CI.

Health is an observation of shared local state. A fresh connected socket or idle/running job worker satisfies that role; stale records never do. Queue failures and uncertain jobs are reported for operator attention, and do not trigger destructive recovery or automatic restarts from this diagnostic command. `doctor` remains a configuration check. HTTP host health belongs to its host.

Local evidence: 1,022 tests / 6,163 assertions; fresh production-dependency project passed; Mago format/lint/analyze have no errors (existing diagnostics retained). Live owner-only acceptance app reported one connected intake and one idle worker with fresh heartbeats, then both stopped cleanly on SIGTERM and status returned unhealthy with zero observed active processes. No test messages were sent in this slice.

The Docker production example built successfully, completed an offline local receipt, deduplicated enqueue, retained it across restart, and reported the stopped worker unhealthy. Private status observations remain in ignored work/live-acceptance/. Review fixed the heartbeat lock-contention race and added actual Socket Mode CLI SIGTERM coverage. A failed developmental soak was discarded; the clean two-hour run is in progress.
