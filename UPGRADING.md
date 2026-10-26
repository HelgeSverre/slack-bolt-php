# Upgrading to v0.1.0

Requires PHP 8.5+ and Composer 2. SQLite jobs and SQLite context storage require `pdo_sqlite`. Gzipped enterprise analytics additionally requires `zlib` and is limited to 32 MiB of decompressed data per response.

For an existing checkout and its generated apps:

1. Stop HTTP intake and workers before switching code. Back up the job database consistently while workers are stopped, and preserve private `.env`, OAuth installation and conversation stores. Store backups outside the public web directory.
2. Check out `v0.1.0` and run `composer install` in the framework checkout. Generated apps still use a local path repository: keep that checkout in place and run `composer update thunder/bolt --with-dependencies` inside each app. Commit the application's resulting lock file.
3. From each app, run `php bolt jobs:migrate`, then `php bolt doctor` and inspect recent jobs with `php bolt jobs:list`. Opening a job store also upgrades its schema automatically; the explicit command makes that step visible before workers restart.
4. Restart intake and workers on the same version. Do not mix older workers with the migrated store. There is no down migration; rollback requires restoring the consistent database backup together with the previous code.

Read-only methods now follow the reviewed GET contracts; custom transports must accept query arguments and empty bodies. Two form-only endpoints (`auth.teams.list` and `admin.apps.activities.list`) now receive URL-encoded POST bodies. Token overrides remain in the Authorization header. External upload alternate text now uses Slack's `alt_txt`; fluent `altText()` calls are unchanged.

Pruning is optional. Preview a bounded batch with `php bolt jobs:prune --before=30d --dry-run`. Pending, running and uncertain jobs are protected. Terminal inputs and history are removed by a real prune, while compact deduplication receipts remain indefinitely. Pruning does not shrink the database file or erase backups.

For a new app, follow the README. `composer test:project` validates a disposable installation without Slack credentials; `composer test:live` separately opts into a real development workspace. Neither command should be run against a production database.
