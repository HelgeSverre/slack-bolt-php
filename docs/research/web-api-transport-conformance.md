# Web API transport conformance

Verified 25 October 2026. This scoped audit follows the live agent-session demo, where a JSON POST to `conversations.replies` failed with missing `channel` and `ts` errors despite both fields being in the JSON body. Slack's current method pages list GET for these reads (their content-type tables also list JSON). The fix follows the documented verb and places arguments in the query string; it does not claim every Slack method rejects JSON POST.

## Audited methods

| Method | Wire contract | Evidence |
| --- | --- | --- |
| `conversations.history` | GET/query | [Official reference](https://docs.slack.dev/reference/methods/conversations.history/), recorded transport tests, live SDK call |
| `conversations.replies` | GET/query | [Official reference](https://docs.slack.dev/reference/methods/conversations.replies/), generated request model, metadata adapter, live cursor round trip |
| `conversations.info` | GET/query | [Official reference](https://docs.slack.dev/reference/methods/conversations.info/), recorded transport tests, live SDK call |
| `conversations.list` | GET/query | [Official reference](https://docs.slack.dev/reference/methods/conversations.list/), empty filtered pages, opaque cursor escaping, live iteration |
| `conversations.members` | GET/query | [Official reference](https://docs.slack.dev/reference/methods/conversations.members/), recorded transport tests, live SDK call |

Confidence: high for these five methods, based on current primary references and live calls. The explicit method set in `WebApi\Client` is intentionally narrow. Generated method/model coverage is not evidence that every endpoint's transport contract has been audited. Unlisted methods retain JSON POST; OAuth form and multipart uploads retain their established behavior.

## Request and pagination guarantees

- Bearer tokens, including per-call overrides, stay in the Authorization header and never enter the query or body.
- GET requests have an empty body and no entity Content-Type header. Query encoding uses RFC 3986, preserves opaque cursor characters and timestamp precision, includes explicit false/zero values, and omits nulls.
- These five read contracts accept scalar arguments. Arrays, objects, files and non-finite numbers fail before HTTP rather than becoming accidental PHP bracket notation.
- Typed generated request models and dynamic array calls use the same transport. Unknown future method names retain the existing POST behavior; method names alone do not select a verb.
- Pagination remains lazy, preserves the original filters, follows a cursor after an empty filtered page, and detects cycles. Resumed iterators seed the cycle guard with their initial cursor.
- Retry behavior is unchanged. An explicit background retrying client replays the same GET URL, headers and empty body. Default clients send once.

## Live suite

`composer test` excludes the `live` group. `composer test:live` also requires `SLACK_LIVE_TESTS=1`; without that environment opt-in, tests skip before loading any token file or opening a connection. The optional `SLACK_LIVE_ENV_FILE` names a private .env file. Configure `SLACK_LIVE_TOKEN` or `SLACK_BOT_TOKEN`, `SLACK_LIVE_CHANNEL` and an existing `SLACK_LIVE_THREAD_TS`. `SLACK_LIVE_MEMBERS_CHANNEL` can select a different conversation for members.

The read smoke test calls all five audited methods and bounds list/reply iteration to two pages. It does not print workspace message content, token values or raw HTTP exceptions. Read/history scopes must match the selected conversation, and the app must have access. Workspace/app distribution affects Slack's history/reply rate limits; a rate limit fails the smoke check explicitly rather than hiding it with a long retry loop.

`SLACK_LIVE_WRITES=1` separately enables a temporary metadata fixture. It creates a parent and two replies, saves context through `SlackMetadataThreadContextStore`, fetches it with a fresh adapter/client, verifies parent text preservation and real cursor traversal, and deletes only the messages it created in reverse order. Two replies are needed because Slack can include the parent alongside the first reply with `limit=1`. Cleanup is attempted even after an assertion or request failure; failed cleanup is reported. A timeout with an unknown creation outcome can still require manual fixture inspection.

Live validation in the development workspace passed **2 tests / 24 assertions**, covering all five SDK reads, persistence, fresh reads, text preservation and fixture cleanup. No bot credentials were added to source or CI. The metadata adapter needed no implementation changes after its underlying read transport was corrected; new offline tests also cover multi-page lookups, trusted bot identity, block preservation, unrelated-metadata protection and cursor cycles.

Offline validation passed **545 tests / 3,563 assertions**. Mago formatting, lint and analysis pass with no errors; advisory findings remain visible. Seven reference-tooling tests pass, and generated ownership checks verify 273 endpoint/model pairs without modifying generated code. Independent standards and specification reviews found no remaining material issues. The preceding agent-session commit's Linux/macOS and generated-code CI run also completed successfully.

## Remaining release work

Audit method-specific transport for the rest of the SDK before claiming exhaustive conformance. This suite does not cover OAuth redirect flows, uploads, every Slack surface, paid-plan workflows, or full Bolt JS behavior. Broader live coverage should remain opt-in and target a dedicated development workspace. Protocol fixes belong in the handwritten client; generated declarations remain source-owned.
