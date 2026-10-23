# Interactivity CLI validation

Validated locally on PHP 8.5.10 on 2026-10-23 using a freshly scaffolded project, a path-repository install of this checkout, and the project-local `vendor/bin/bolt` executable. Composer resolved dependencies during installation, which may use package-network traffic. The smoke made no Slack Web API calls, contacted no Slack workspace, and used no real credentials.

The checked-in hello example declares `/ping`; after importing its manifest or adding the `commands` scope to an installed Slack app, reinstall the app so Slack grants the new scope.

`make:listener` generated and PHP-linted all four interactivity handlers:

- `--action=approve_request --type=block_actions`
- `--shortcut=open_modal --type=shortcut`
- `--options=search_users --type=block_suggestion`
- `--view=request_modal --type=view_closed`

`listener:list --format=json` reported the starter event listener plus each of the four generated interaction descriptors. After `discovery:clear`, the command took 0.95s; after `discovery:generate`, it took 0.35s. Both JSON outputs were byte-for-byte identical and contained five listeners.

Project-local completion generation worked. `vendor/bin/bolt-complete` suggested `make:listener` for `ma`, and suggested `--view=` after `make:listener --v`.

A local PHP development server received signed, form-encoded Slack interaction payloads at `/slack/events` with `SLACK_BOT_TOKEN=xoxb-local-test` and a test signing secret. Results were:

| Payload | Result |
| --- | --- |
| `block_actions` / `approve_request` | `200`, empty acknowledgement |
| `shortcut` / `open_modal` | `200`, empty acknowledgement |
| `block_suggestion` / `search_users` with required `block_id` | `200`, `{"options":[]}` |
| `view_closed` / `request_modal` | `200`, empty acknowledgement |

The initial options fixture omitted Slack's required `block_id` and correctly received `400 Malformed Slack delivery payload`; the corrected payload above passed.
