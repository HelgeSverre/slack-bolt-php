# Testing, security, and troubleshooting

Start with local, recorded checks. A passing test suite verifies the behavior represented by its fixtures; it does not prove a Slack workspace is configured, an OAuth installation exists, or a worker is running.

## Verify the framework and a generated project

From the framework checkout:

```sh
composer test
composer analyse
composer lint
composer format:check
composer test:project
composer test:vcs-project
```

Mago runs analysis, linting, and formatting. `composer test` excludes live tests. `composer test:project` creates a disposable app, installs its production dependencies, exercises signed recorded deliveries and durable-job recovery, then removes the temporary project. It needs package-repository network access but no Slack credentials.

Node is only needed for maintainer tooling, including the pinned reference generators and documentation verification:

```sh
npm ci
npm run test:tooling
npm run verify:generated
npm run verify:docs
```

Generated SDK output is owned by those generators; do not format or hand-edit it. [Development](../development.md) explains the Mago and generation policy.

## Use live checks sparingly

The opt-in live suite is for a dedicated development workspace and conversation, never production:

```sh
SLACK_LIVE_TESTS=1 \
SLACK_LIVE_ENV_FILE=/absolute/path/to/.env \
SLACK_LIVE_CHANNEL=C123 \
SLACK_LIVE_THREAD_TS=1700000000.000001 \
composer test:live
```

The suite reads Slack data and is subject to the token's scopes, conversation access, rate limits, and the workspace's policies. `SLACK_LIVE_WRITES=1` enables a narrow metadata-storage write fixture that removes only its own test messages. Treat this as an explicit change to a development workspace and review the test before enabling it.

## Protect credentials and state

- Keep `.env`, `.thunder-bolt/`, OAuth installation records, conversation data, job databases, and backup files outside public web directories and version control.
- Never put bot, user, app-level, OAuth, response-URL, presigned-upload, or workflow-execution tokens in logs, stack traces, screenshots, examples, or support tickets.
- Use a separate app/workspace and a least-privilege manifest for development. The wide `hello-bolt` manifest exists to exercise features, not to prescribe production scopes.
- Verify HTTP signatures on the original request bytes. The built-in receiver does this before decoding Slack payloads; do not route Slack through a middleware layer that rewrites those bytes first.
- OAuth state is server-backed and one-time. Keep the browser flow on HTTPS and preserve the generated secure cookie behavior if you own the routes.
- Treat file paths, external IDs, user-provided Block Kit text, and response URLs as untrusted inputs. Validate authorization within application storage writes, not only when presenting UI controls.

`FailureContext::summary()` is the safe shape for failure telemetry. Raw payloads can contain messages, authorization context, and capability URLs. The job CLI similarly omits inputs and lease tokens from its operator output.

## Diagnose configuration

```sh
php bolt doctor --format=json
php bolt doctor --transport=socket --auth=oauth --workers
php bolt auth:verify
php bolt listener:list --format=json
```

`doctor` checks local configuration, storage locations, selected delivery transport, and optionally SQLite job-worker prerequisites without contacting Slack. It exits nonzero when that local profile is not ready. `auth:verify` is the deliberate exception: it contacts Slack to validate a configured static token and identify the bot. It is not applicable to an OAuth-only app without a static token.

Typical local failures:

| Symptom | Check |
| --- | --- |
| HTTP events fail before reaching a listener | Signing secret, original request body, public HTTPS URL, Event Subscriptions and Interactivity URLs |
| Socket Mode cannot connect | App-level `xapp` token, `connections:write`, Socket Mode enabled, installed bot/OAuth authorization |
| A slash command or shortcut is absent | Command/shortcut exists in Slack, `commands` scope is installed, and the callback/request URL matches the selected transport |
| OAuth callback is rejected | Exact HTTPS redirect URI, client credentials, state cookie, persistent writable installation store |
| Context disappears after a request | Do not use the memory store for a normal HTTP app; select file/SQLite storage on persistent private media |
| Jobs stay pending or become uncertain | `pdo_sqlite`, `jobs:work`, shared path, job handler discovery, lease/heartbeat/cancellation behavior; inspect before retrying |
| `status` reports stale or missing roles | Start the relevant worker, check it shares the configured state directory, and remember `status` cannot prove Slack or HTTP health |

For UI settings and scopes, use [Slack app setup](slack-app-setup.md). For transport-specific handlers, use [building apps](building-apps.md). For durable storage and recovery, use [durable operations](durable-operations.md).

## Collect useful evidence

When asking for help, include the command and redacted output, the selected transport/auth profile, PHP/Composer versions, and whether the failure is HTTP, Socket Mode, OAuth, or a worker. Include listener names and Slack error codes, but redact every token, signing secret, OAuth code, response URL, full payload, and private message text. State whether the app is single-workspace/static-token or OAuth/multi-workspace; the authorization path changes the diagnosis.
