# OAuth, authorization, installations, and token rotation

**Status:** implementation contract for the OAuth milestone. This records Bolt JS 5.1.0 and
`@slack/oauth` 4.0.0 behaviour, then identifies deliberate PHP improvements. It is not a
claim that every item is implemented yet.

**Pinned upstream:** Bolt commit
[`ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d`](https://github.com/slackapi/bolt-js/tree/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d)
and OAuth commit
[`70e15b8442745d215f9ae259a2c970b306aff51a`](https://github.com/slackapi/node-slack-sdk/tree/70e15b8442745d215f9ae259a2c970b306aff51a).

## The boundary: delivery to authorization

Every verified Slack HTTP delivery needs one immutable `AuthorizationSource` before listener
matching or Web API use:

```php
final readonly class AuthorizationSource
{
    public function __construct(
        public ?string $teamId = null,
        public ?string $enterpriseId = null,
        public ?string $userId = null,
        public ?string $conversationId = null,
        public bool $isEnterpriseInstall = false,
        public array $raw = [],
    ) {}
}
```

An enterprise install requires `enterpriseId`; a normal workspace install requires `teamId`.
`AuthorizationSource` preserves the payload's contextual team ID even for an enterprise delivery
(notably `view.app_installed_team_id` in Slack Connect) so a custom authorizer can use it. Its
`query()` method must strip that contextual team ID when creating the strict enterprise-keyed
`InstallationQuery`. `userId` and `conversationId` refine a lookup; they do not replace
installation identity.

| Incoming family | Team / enterprise source | User / conversation source | Notes |
| --- | --- | --- | --- |
| Events API | First `authorizations[]` entry, falling back to envelope `team_id` / `enterprise_id` | Event `user` (string or object), then channel creator or subteam creator; channel, `channel_id`, or item channel | `authorizations[0].is_enterprise_install` controls the organization flag. |
| Slash command | `team_id`, `enterprise_id` | `user_id`, `channel_id` | `is_enterprise_install` is the string `'true'`, not a JSON boolean. |
| Block action, options, shortcut | `team.id`, falling back to `user.team_id`; `enterprise.id`, falling back to `team.enterprise_id` | `user.id`, `channel.id` when present | A global shortcut has no conversation. A message shortcut can have one. |
| View submission / close | Same, except `view.app_installed_team_id` wins for team | `user.id`; no conversation | This is required for modals opened through Slack Connect. |
| Legacy dialog submission | Action-family extraction | Action-family extraction | It remains an action delivery for source selection. |

This is literal Bolt derivation: [family and conversation detection](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/helpers.ts#L39-L135) and [team,
enterprise, user construction](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L1430-L1597).
Bolt deliberately bypasses authorization only for Events API `app_uninstalled` and
`tokens_revoked`, while still deriving identity; retain that exception for lifecycle handling.
See [the dispatch path](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L894-L975).

The authorization result must retain independently optional bot and user credentials. Bot is
the default API credential when both exist; a user token is the fallback. Bot ID and bot user ID
are also authorization data, not presentation fields. This matches [Bolt's public source/result
contracts](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L162-L189)
and [its token selection](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L1606-L1612).

**Confidence: 🟢** — pinned source directly defines this behaviour.

## Installation store contract and merge rules

The implemented deep store boundary returns immutable installations. Its `update()` callback is
the token-rotation transaction seam:

```php
interface InstallationStore
{
    public function fetch(InstallationQuery $query): ?Installation;
    public function save(Installation $installation): void;
    public function delete(InstallationQuery $query): void;

    /** Runs under the selected credential record's exclusive lock. */
    public function update(InstallationQuery $query, Closure $updater): ?Installation;
}
```

The current PHP `Installation` persists workspace or enterprise identity, the
`isEnterpriseInstall` flag, installed-at UTC timestamp, and independently optional grants:

* a **bot grant** with token, bot ID, bot user ID, scopes, refresh token, and expiry; and
* a **user grant** with user ID, token, scopes, refresh token, and expiry.

Expiries are UTC Unix seconds. The raw normalized OAuth payload is retained for provider fields
that are not first-class PHP properties in this milestone, such as app ID, token type, incoming
webhook, and enterprise URL.

That is the normalized OAuth model, including the deliberately separate grants, in
[`installation.ts`](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/installation.ts#L31-L109).
The query also carries `teamId`, `enterpriseId`, `userId`, `conversationId`, and
`isEnterpriseInstall` ([source](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/installation-query.ts#L1-L12)).

`fetch()` must select an organization installation by enterprise ID and a workspace installation
by team ID. When a user ID is available, it may load that user's grant *for the same installation*
and merge it into the app/bot record. It must never return one user's user token for another user.
Bolt's file store demonstrates this: it first reads the app record, then substitutes only the
requesting user's grant; an absent user record clears the user credential while preserving the bot
credential ([source](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/installation-stores/file-store.ts#L61-L95)).

The in-memory Bolt store keys an organization install by enterprise ID and a workspace install by
team ID; it explicitly does **not** support per-user tokens and is development-only
([source](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/installation-stores/memory-store.ts#L4-L60)).
Keep the same visible lookup result, but make the PHP in-memory store support the documented
selector shape so it is useful in tests.

The file store keeps separate bot and user records under a per-record advisory lock. `update()`
holds that lock through the refresh HTTP call and then replaces the matching immutable record,
which prevents two workers from consuming the same refresh token. A bot refresh and a later user
refresh intentionally commit independently, so a successful bot rotation survives a user refresh
failure. The in-memory store has the same public semantics for tests and ephemeral processes.

An install/reinstall must merge only information that Slack has actually returned: preserve a
previously stored grant that the current exchange did not return, replace a returned grant in
full, and never erase a user grant merely because an ordinary bot-only installation did not
include it. Keep that policy behind `save()`/`update()`, not in the controller and rotator.

**Confidence: 🟢** for upstream shape and lookup; **🟡** for the safer reinstall merge policy,
which is a PHP design decision.

## OAuth v2 browser flow

The public PHP shape should be intentionally small:

```php
$installer = new Installer($config, $client, $installations, $states, $clock);

// GET /slack/install: issue state and return an authorization redirect.
$redirect = $installer->begin();

// GET /slack/oauth_redirect: validate/consume state, exchange the code, save installation.
$outcome = $installer->complete($state, $stateCookie, $code, $error);
```

The Tempest integration exposes those operations at `GET /slack/install` and
`GET /slack/oauth_redirect`. `OAuthController` is `#[Stateless]`: it writes the
opaque state cookie as an explicit `Set-Cookie` header and reads it from the raw
`Cookie` header. This deliberately excludes Tempest's session and cookie-encryption
middleware, so installation does not require `SIGNING_KEY` and the callback reads
the same plaintext browser value that the install route issued.

The Bolt-parity authorization URL has `client_id`, comma-separated `scope`, optional
`redirect_uri`, optional `team`, optional comma-separated `user_scope`, and `state`. These are the
same fields [Bolt emits](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L415-L474).
The initial PHP `begin()` surface covers the configured redirect URI, scopes, user scopes, and
state; add a typed optional workspace hint when preselecting an install workspace is in scope.
Use `oauth.v2.access` with `code`, `client_id`, `client_secret`, and the same `redirect_uri` on
the callback ([Bolt exchange](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L607-L614)).
Slack's current guides cover [installation with OAuth](https://docs.slack.dev/authentication/installing-with-oauth/)
and [the token rotation exchange](https://docs.slack.dev/authentication/using-token-rotation/).

Required callback order:

1. For strict Bolt parity, treat `error=access_denied` as a cancelled authorization before code or
   state validation; reject a missing `code` otherwise.
2. Require state when state verification is enabled.
3. Compare the request's state with the browser-bound state cookie using constant-time comparison,
   then atomically consume the server-side state record.
4. Once verification is attempted, delete the state cookie on both success and failure. Bolt's
   early cancellation path precedes verification, so it does not perform that cleanup.
5. Exchange only after those checks, normalize the OAuth v2 response, save the installation, then
   run the post-install response hook.

Bolt's equivalent path and its status mapping are [callback verification](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L485-L560),
[installation normalization](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L617-L701),
and [the default 400/500 failures](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/callback-options.ts#L125-L159).

Bolt's default TTL and cookie name are 600 seconds and `slack-app-oauth-state`; it emits
`Secure; HttpOnly; Path=/; Max-Age=600` ([source](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L87-L142),
[cookie source](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L751-L760)).
Retain the 600-second default. Add `SameSite=Lax` and use an opaque random state stored as a hash
with expiry and atomic consumption. Bolt's built-in `ClearStateStore` is a signed, expiring JWT;
it validates age but cannot itself provide one-time consumption
([source](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/state-stores/clear-state-store.ts#L18-L44)).
This is an intentional hardening, not a parity break in the application-facing API.

Do not expose Bolt's `legacyStateVerification`: it intentionally skips the browser-cookie check
and upstream warns that it is not proper CSRF protection
([source](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L517-L541)).
Permit disabling state verification only as an explicit, named advanced configuration for a
documented organization-admin flow.

**Confidence: 🟢** for Bolt mechanics; **🟡** for PHP cookie/state hardening, which exceeds Bolt.

## Token rotation

An OAuth v2 response can return bot and user refresh information separately. Persist
`expiresAt = floor(now UTC seconds) + expires_in` for the matching grant. Bot data is in the top
level response; user data is in `authed_user`; scopes are comma-separated and must become a list.
Bolt's normalization makes this explicit
([source](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L617-L668)).

For parity, refresh a grant when it has both a refresh token and expiry, and its expiry is at most
7,200 seconds away (including already expired). Send `grant_type=refresh_token` and that grant's
refresh token to `oauth.v2.access`; replace access token, refresh token, and expiry with the
response values. The two-hour window and inclusive comparison are pinned
[here](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L861-L892);
the request and update sequence is [here](https://github.com/slackapi/node-slack-sdk/blob/70e15b8442745d215f9ae259a2c970b306aff51a/packages/oauth/src/install-provider.ts#L228-L321).

The PHP `TokenRotator` refreshes from the record under the file store's exclusive `update()` lock,
then replaces its matching grant before releasing it. This prevents a stale concurrent writer from
overwriting a newly issued refresh token. A successful bot refresh remains stored when a later user
refresh fails. If a refresh fails for an already expired grant, authorization fails rather than
silently sending the expired token. Bolt filters failed parallel refresh attempts and may continue
with the old grant, which is a compatibility trap rather than behaviour to copy.

`expires_in` is a duration in seconds; persisted `expiresAt` is an absolute UTC Unix timestamp in
seconds. Keep those names distinct throughout the API.

**Confidence: 🟢** for Bolt's threshold and response handling; **🟡** for atomicity and failure
behaviour, which intentionally improve it.

## Compatibility tests that define the milestone

1. Derive the same source for an event, command, action, options request, global and message
   shortcut, view submission, view close, and legacy dialog submission. Cover the command string
   flag and `view.app_installed_team_id` override.
2. Assert `app_uninstalled` and `tokens_revoked` skip installation lookup while other deliveries
   authorize normally.
3. Fetch a team installation, organization installation, and per-user installation; ensure a
   missing user grant never removes the bot grant or leaks another user's grant.
4. Assert the authorization URL parameters, a missing code, missing/mismatched/expired/replayed
   state, cancellation, and state-cookie deletion on failures.
5. Normalize bot-only, user-only, and both-grant OAuth v2 responses, including scopes and UTC
   expiry conversion.
6. Refresh at exactly two hours, just over two hours, and after expiry; exercise bot and user
   grants together, record-lock concurrency, and a refresh failure.
7. Preserve an enterprise delivery's `view.app_installed_team_id` on `AuthorizationSource`, while
   ensuring `AuthorizationSource::query()` performs the enterprise-keyed lookup without it.

## Capability disposition

| Capability | Milestone expectation |
| --- | --- |
| OAuth v2 install/callback and state cookie | Required |
| Bot and user grants; workspace and Grid installs | Required |
| Per-user installation lookup and token rotation | Required |
| OAuth v1/classic apps | Unsupported in the modern PHP surface; keep a documented migration boundary |
| Bolt's signed clear-text state JWT and legacy cookie-bypass mode | Deliberately not copied; opaque one-time state is stronger |
