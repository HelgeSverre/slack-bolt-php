# Adopter readiness — 30 October 2026

User scope: execute the live Slack acceptance slice and polish application setup; investigate naming/distribution without publishing, renaming, or changing repository visibility.

- [x] Live browser OAuth on the existing development app, server-side account metadata and stored installation.
- [x] A real Slack button opens a modal; submission reads typed state and queues a durable job with authorized context.
- [x] A separate/restarted worker completes the live job; running cancellation stops work at a checkpoint.
- [x] Record actual live results separately from recorded protocol fixtures. Keep credentials private and test state isolated.
- [x] Add explicit HTTP/Socket and token/OAuth scaffolding, aligned manifests, setup defaults and next steps.
- [x] Make doctor mode-aware, reject placeholders, distinguish runtime configuration from installation/worker verification, inspect local storage, and return meaningful JSON/exit codes.
- [ ] Verify CLI behavior, completions/help, fresh-project integration, Mago and CI; review before committing.
- [x] Check primary registry/package sources and authenticated repository visibility; propose distribution choices without registration/publication. See distribution-and-naming-options.md.

Live results (30 October): browser OAuth persisted account metadata and installation; a real Slack modal queued a job completed by a new process. A second job was verified running before cancellation, then ended cancelled at a checkpoint without completing. Both messages were sent only to the verified owner's development DM. The callback URL and Slack consent were explicitly approved. Private evidence and credentials remain under ignored work/live-acceptance/; none are committed.

Cleanup exposed Socket Mode memory exhaustion at 128 MiB. A real local WebSocket reproducer retained 6.7 MiB over 400 idle polls before automatic garbage collection. Periodic collection of Amp cancellation cycles bounds this growth; this is covered independently of live Slack access. Live soak and final CI results will be recorded below.

Post-fix live soak: the same isolated Socket Mode application stayed connected to Slack for over 12 minutes under memory_limit=128M. Process RSS sampled at 39,520 and 39,632 KiB; it then stopped cleanly on SIGTERM. This bounded soak supplements, rather than replaces, the fast 400-poll regression.

Local validation: 1,005 tests / 6,022 assertions; fresh production-dependency project passed; Mago format/lint/analyze have no errors (existing warnings retained); generator, transport and parity inventory checks passed. Review caught and fixed public symlink storage detection and fallback help. CI result is pending.
