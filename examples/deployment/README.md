# Running intake and workers

This runnable example builds a small Socket Mode/token application from the local framework checkout. Nothing is published or downloaded from this private repository during the build. Dependencies come from Composer. It contains the generated mention listener and an idempotent local-receipt job for checking deployment without Slack traffic.

From this directory:

```sh
cp .env.example .env
# Set the installed app's bot token, app token and bot identity in .env.
docker compose build
docker compose run --rm --no-deps jobs php bolt doctor --transport=socket --workers
docker compose run --rm --no-deps jobs php bolt jobs:migrate
docker compose up -d
docker compose exec jobs php bolt status --format=json --require=socket,jobs
```

Both processes run as UID/GID 10001 in Tempest production mode, share the named local state volume at `.thunder-bolt/state`, and have separate commands, logs and health checks. Queue, installation, context and heartbeat paths point into that volume. Framework discovery caches stay outside it, so a new image starts with caches matching its code. The build context allowlist excludes `.env`, local stores, vendor directories and Git history. Copy your own `app/` and reviewed `config/` into the image when adapting this example. Supply secrets at runtime. Pin image digests and commit application lockfiles in your actual deployment.

`php bolt status` observes every process sharing this state directory, rather than just the container running the command. With multiple replicas, a healthy sibling can satisfy a required role. It is useful for an application-level check; it is not a per-container watchdog. Compose records an unhealthy state but its restart policy restarts exited processes, not merely unhealthy ones. Use an external monitor for alerts and a supervisor for restart policy. There is no HTTP-server health probe in this command. An inline handler blocking beyond the heartbeat freshness window intentionally makes intake appear stale; move slow work into jobs. Long jobs must call checkpoints and renew their leases. Increase `--stale-after` only when that latency is intentional; a stale record does not prove a process has died.

To check only durable work, use an ignored `.env` containing `SLACK_BOT_TOKEN=local-fixture-token` and leave the app token empty. Start only jobs:

```sh
docker compose up -d --no-deps jobs
docker compose exec jobs php bolt demo:enqueue first-check
docker compose exec jobs php bolt jobs:list
docker compose exec jobs php bolt status --format=json --require=jobs
# Reusing the same name returns the same job receipt.
docker compose exec jobs php bolt demo:enqueue first-check
```

The job writes its receipt into the state volume and makes no Slack request. The dummy token is an offline fixture value, not a Slack credential. Use a real installed app for Socket Mode. OAuth installations additionally need HTTPS install/callback routes and the same persistent installation store; this example does not expose an HTTP host.

## Deploying a new version

1. Stop intake: `docker compose stop socket`. Socket Mode finishes its current inline delivery, closes the connection, then exits. Put slow work in named jobs to keep acknowledgements prompt.
2. Stop workers: `docker compose stop jobs`. SIGTERM prevents another claim after the current handler finishes. It does not cancel that job. Handlers should checkpoint for cancellation and explicitly heartbeat before their lease expires; checkpointing alone does not renew the lease.
3. Back up the state volume consistently after all writers have stopped. For a live SQLite backup use SQLite's backup API; copying only a live database file can miss journal/WAL data. Preserve installation, context and queue state together.
4. Build the new image, run `jobs:migrate` with all older workers stopped, then start workers and intake. Check `status` and job outcomes before resuming normal traffic. Keep the previous image and consistent backup for rollback; migrations do not have an automatic down path.

The example allows 90 seconds before Compose forcibly kills a process. Set `stop_grace_period` longer than your application's maximum cooperative drain time. A forced kill leaves its last heartbeat and an expiring job lease. Recovery automatically retries only jobs explicitly marked safe to repeat. Other ambiguous outcomes become uncertain and require inspection before an explicit retry. No cross-process runtime can promise exactly-once external side effects.

Completed processes remove their observation files. Hard kills leave stale records; they remain visible for diagnosis. Clean old stale observation files as deployment housekeeping after confirming their processes are gone. These records do not own job leases and removing one does not cancel or recover work.

Run `php scripts/smoke-deployment.php` from the framework checkout for a disposable offline build/run check. It verifies UID 10001, production mode, job completion, the receipt file, deduplication, stopped-worker status and restart persistence, then removes its own test container, volume and image.

References: [Compose service health checks, restart policy and stop grace periods](https://docs.docker.com/reference/compose-file/services/), [official PHP image source](https://github.com/docker-library/php).
