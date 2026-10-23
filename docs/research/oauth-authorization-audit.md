# OAuth installation and authorization audit

Verified locally on 23 October 2026 with PHP 8.5.12 and the locked Tempest 3.20.0 dependency set. This milestone implements modern OAuth v2 installation, state validation, installation stores, delivery authorization, and bot/user token rotation. The [source reference](oauth-authorization.md) maps behavior to pinned Bolt JS 5.1.0 and `@slack/oauth` 4.0.0; the [user guide](../oauth-and-authorization.md) documents the actual PHP interface.

## Implemented behavior

| Requirement | Implementation and evidence |
| --- | --- |
| OAuth browser installation | `/slack/install` redirects with configured bot/user scopes; `/slack/oauth_redirect` exchanges the code, enriches bot ID through `auth.test`, and persists the installation |
| State validation | Cryptographic state and independent browser binding, ten-minute default expiry, constant-time hash comparison, one-time atomic consumption, explicit secure cookies, denial/missing-code/replay cases |
| Installation stores | Immutable installation values; memory and durable file adapters; workspace/Grid isolation, per-user records, atomic credential updates, protected records, and whole-installation deletion |
| Authorization | Literal Bolt source precedence for Events API authorizations, form organization flags, Slack Connect modal team context, and conversation identity; bot token preference and matching-user fallback |
| Scoped clients | Constructor/method clients and Reply use delivery credentials; root client stays unchanged; custom containers without credential-scope support fail explicitly |
| Token rotation | Configurable two-hour default window, separate bot/user grants, fresh access/refresh/expiry validation, atomic read/refresh/write, partial-success preservation, explicit `oauth.v2.exchange` migration |
| Lifecycle handling | Uninstall and token-revocation events bypass credential lookup while exposing authorization source for explicit cleanup listeners |
| Tempest integration | Stateless OAuth routes own their opaque cookies; `config/` files load through both CLI and generated HTTP bootstraps; custom PSR-18 transports and store bindings are respected |

## Final checks

- PHPUnit: **164 tests, 539 assertions**, passing without notices.
- PHPStan level 6: **192 files**, no errors.
- Laravel Pint: passing.
- Strict Composer validation: passing for the package; the fresh application installed and validated its dependency graph during smoke testing.
- Node tooling: **6 tests**, passing.
- Generated ownership verification: **259 Web API methods, 34 root groups, 94 resources, 126 declaration scaffolds, 359 export mappings**, matching the inventory.
- `git diff --check`: passing.

`tests/Integration/InstalledApplicationTest.php` covers installation followed by separate bot/user refresh, persistence, dispatcher authorization, outbound replies, user isolation, and replay rejection with a recorded transport. `tests/Runtime/AuthorizationDispatchTest.php` checks constructor/method injection, root-client isolation, authorization failures, self filtering, lifecycle bypass, and rejection of unsupported container scopes.

`tests/OAuth/FileRotationConcurrencyTest.php` starts two independent PHP processes against the same durable installation. A delayed recorded refresh holds the file-store lock; both workers finish successfully and the single-use refresh token is redeemed exactly once. Other rotation tests cover expiry boundaries, malformed refresh responses, migration form data, and preservation of a successful bot refresh when user rotation fails.

## Fresh installed application over HTTP

A temporary Composer application was scaffolded and installed from this checkout. It used fake OAuth credentials and a custom recorded PSR-18 transport loaded from `config/`. Discovery was cached before starting the actual local development server.

| Request | Observed result |
| --- | --- |
| Start installation | `302` to Slack's authorization URL; Secure/HttpOnly/SameSite=Lax cookie |
| Callback with invalid state | `400`, before code exchange |
| Callback with matching state/cookie | `200`, installation persisted |
| Replay the successful callback | `400`, no second exchange |
| Signed delivery for the installed workspace | `200`; expired bot credential refreshed, constructor-injected client used the fresh token |
| Signed delivery for an unknown workspace | `500`; no listener call or credential substitution |

The successful smoke recorded four transport calls: token-free OAuth exchange, bot-token `auth.test`, token-free refresh, and a message request bearing the refreshed bot token. The temporary server and application were removed afterward. No real credentials or Slack messages were used. Real-workspace installation and remote CI remain pending.

The HTTP smoke caught and resolved two integration defects that direct controller tests could not detect: Tempest cookie encryption interfering with the opaque OAuth state cookie, and generated `config/` files missing from discovery. The stateless routes and shared CLI/HTTP configuration discovery are included in the final implementation.

## Deliberate differences and remaining scope

Opaque server-backed, one-time state replaces Bolt's default signed state JWT. Atomic grant updates improve refresh concurrency, and rotation failures stop authorization instead of proceeding with stale credentials. Callback denial clears its browser cookie. Responses avoid rendering codes or tokens.

This milestone supports OAuth v2. Classic OAuth v1, OpenID Connect, state-free organization-admin installs, and legacy cookie-bypass modes are outside the modern interface. The built-in callback has a small fixed success/failure response; custom onboarding routes can use `Installer` directly. File adapters coordinate workers sharing one filesystem; multi-host deployments need shared store adapters implementing the same atomic contracts. Conversation/thread stores and durable background workers remain separate work.
