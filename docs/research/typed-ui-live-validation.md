# Typed UI and rich-text live acceptance

Verified on 4 November 2026 in the established **Helge Sverre** Slack workspace (`T02DB4YSA`), using **Thunder Bolt Dev** (`A0CCM894C64`). This covers the typed UI requests, interaction fixtures and modern rich-text references implemented in commit `2a1582a`. The live run used the same runtime files before that commit was created, based on `00cc1c5`.

The fixture recorded runtime digest `563275910bc227fde18c6ce55df186a60efd6cbc9f573f2aceab1e85ddb0350a`. This covers the request helpers and rich-text runtime files selected by the fixture, not the whole repository. Run ID: `20261104-ui-1`.

[Live acceptance message](https://helgesverre.slack.com/archives/D0CBLL7LF51/p1793824437624579). Local receipts and screenshots are under ignored `work/ui-operations-live/`; credentials are never written to receipts.

## Actual Slack results

API results were checked alongside browser rendering and actual user interactions.

| Case | Observed outcome |
| --- | --- |
| Open a native modal | `ViewRequest::open()` displayed the acceptance modal with a working text input. |
| Update by view ID | The hash-checked update changed the modal text and preserved the entered note. |
| Update by external ID | A second hash-checked update changed the text again and preserved the note. |
| Push a view | A second modal opened; Back returned to the original input with its value intact. |
| Submit a view | Verify closed the modal; the receiver recorded the expected note, `Typed fixtures verified`. |
| Update a message | `ChatUpdate` replaced the existing panel text while retaining its controls and references. |
| Send an ephemeral message | `ChatPostEphemeral` appeared in the requested thread with Slack's “Only visible to you” label. |
| Publish and refresh Home | `ViewRequest::publish()` rendered the acceptance Home. Refresh used the current view hash and completed successfully. |
| Rich-text tag | The blue, bold “In progress” tag rendered with its selected styling. |
| Message mention | Slack rendered the source link/author and hydrated the referenced acceptance message preview. |
| Work Object mention | Slack rendered the application icon and display text linking to the synthetic object's URL. |

No API or handler failures were recorded. The isolated Socket Mode listener was stopped after the run. Boltling's existing Home was restored and visually checked; no pet-care actions were performed. No Slack scopes or application settings were changed.

## Scope and limits

The Work Object mention used a synthetic object identity and `fullSizePreview(false)`. This verifies inline link/icon rendering and app association, not access checks or hydrated details for a registered external object. A separate synthetic entity post did not display a native Task preview; that is not counted as a successful preview test. The builder does not register or fetch external objects.

Unit tests cover the full color enum, modern style flags, invalid code styling, request surface validation and immutable composition. The live run did not visually exercise every color/style permutation or every Slack client.

Interaction fixtures and structured assertions were verified through signed receiver tests and a fresh production-dependency project. Synthetic fixtures themselves do not require live Slack delivery.

This record is not release approval for unrelated changes. The earlier bug-hunt batch remains unreleased; live OAuth token-rotation acceptance, in particular, is not established by this UI run. Every release must still satisfy the [live release gate](../development.md#live-release-verification).

## Local checks

- PHPUnit: **1,223 tests, 6,771 assertions**, all passing.
- Fresh path and VCS projects passed. The VCS proof exercises the new helpers and testing toolkit without PHPUnit installed in the generated project.
- Generated-reference, parity and documentation checks passed.
- Mago formatting passed; lint and analysis completed without errors, with nonfatal advisories retained.

## Repeat the run

Use [the opt-in fixture](../../scripts/smoke-ui-operations.php), loading credentials from a local environment file rather than putting tokens on the command line:

```sh
export SLACK_LIVE_TESTS=1
export SLACK_LIVE_WRITES=1
export SLACK_LIVE_TEAM=T02DB4YSA
export SLACK_LIVE_CHANNEL=D0CBLL7LF51
export SLACK_LIVE_USER=U02DB4YSC
export SLACK_LIVE_APP=A0CCM894C64
export SLACK_LIVE_RUN=choose-a-new-run-name
export SLACK_LIVE_REVISION="$(git rev-parse HEAD)"
export SLACK_LIVE_ENV_FILE="$PWD/examples/hello-bolt/.env"

php scripts/smoke-ui-operations.php post
php scripts/smoke-ui-operations.php listen
```

Use a fresh run name for changed runtime files. The fixture verifies the workspace through `auth.test` before writing. Pause competing demo listeners before testing the controls. In Slack, open the acceptance message, exercise its modal/message/Home controls, submit the note, and inspect the ephemeral thread output. Then stop the listener and run `php scripts/smoke-ui-operations.php inspect` to review its receipt. Restore the application's normal Home after testing; the fixture intentionally publishes a temporary acceptance Home.
