# Opt-in live acceptance fixture

These templates are not discovered by the test runner. Copy them into a disposable generated app to exercise real Slack consent, modal submission and durable workers. Automated protocol coverage remains in `scripts/smoke-project.php`; `composer test` does not contact Slack.

1. Scaffold with `--transport=socket --auth=oauth`, install dependencies and copy `.env.example` to `.env`.
2. Copy the five `App` templates into `app/`, removing `.stub` from each filename. Replace `config/bolt.config.php` with `config.php.stub`; remove the generated greeting listener from this disposable app.
3. Set Slack OAuth client credentials, signing secret, app-level token and HTTPS callback in the private `.env`. Set `BOLT_ACCEPTANCE_OWNER` to your own Slack user ID and `BOLT_ACCEPTANCE_CHANNEL` to your own verified app DM. Keep this app and its `.thunder-bolt/` outside version control.
4. Register that exact callback URL in the development Slack app. Serve the app over HTTPS, then open `/acceptance/install` and complete consent. This fixture associates a fixed test account, 42, on the server; it does not implement production account authentication.
5. Confirm `.thunder-bolt/acceptance/account.json` contains account 42 and the installed team. Run `php bolt doctor --transport=socket --auth=oauth --workers --format=json`, then `php bolt socket:serve`.
6. Using the installed app, post a launcher only to your own DM with the blocks below. Click its button, enter a note and submit. Confirm the queued receipt and sanitized `evidence.jsonl` entry.
7. Stop/restart the worker process independently of intake: `php bolt jobs:work --once`. After roughly a minute the receipt should change to “Acceptance complete”. The handler checks that request-local account context did not leak into the new worker attempt.
8. Submit a second form. Start a worker, verify the job is running with `php bolt jobs:list`, then click its cancel button. Expect terminal cancellation at the next checkpoint and no `job_completed` entry for that ID. Another `jobs:work --once` should find no pending work.
9. Stop the test processes and remove the disposable installation when finished. Do not publish its OAuth records, SQLite files or credentials.

Launcher blocks for `chat.postMessage` (provide your verified DM as `channel` and a text fallback):

```json
[
  {"type":"section","text":{"type":"plain_text","text":"Thunder Bolt live acceptance"}},
  {"type":"actions","elements":[{"type":"button","text":{"type":"plain_text","text":"Open acceptance form"},"action_id":"acceptance.open"}]}
]
```

Recorded run: 30 October 2026, existing development workspace. Browser OAuth persisted server-side metadata and installation; a real modal submitted typed state; a new process completed one job; a second job was cancelled after its running state was confirmed. This is evidence for these paths, not comprehensive live Slack API coverage.
