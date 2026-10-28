# Installation and authorization

Thunder Bolt supports OAuth v2 for workspace and Enterprise Grid installations. The framework stores bot and user credentials separately, selects the installation for each signed delivery, and refreshes expiring tokens before handlers run. A static `SLACK_BOT_TOKEN` remains sufficient for a single-workspace application.

## Enable OAuth

Set these values in your application's `.env`:

```dotenv
SLACK_SIGNING_SECRET=your-signing-secret
SLACK_CLIENT_ID=your-client-id
SLACK_CLIENT_SECRET=your-client-secret
SLACK_OAUTH_REDIRECT_URI=https://your-app.example/slack/oauth_redirect
SLACK_BOT_SCOPES=app_mentions:read,chat:write,commands
SLACK_USER_SCOPES=
```

All three client ID, client secret, and redirect URI values enable OAuth mode. That mode uses stored installation credentials; the static bot token is unnecessary. The signing secret still verifies incoming Slack requests.

Configure the same HTTPS redirect URI and scopes in Slack's OAuth settings or manifest. Keep event and interactivity request URLs pointed at `/slack/events`. Start your application and open `/slack/install` in a browser on the same host as the redirect URI. `php bolt doctor --format=json` reports the authorization mode without contacting Slack or printing secrets. Its readiness result describes configuration; delivery still requires a successful installation.

The two routes are:

| Route | Behavior |
| --- | --- |
| `GET /slack/install` | Create state, set its browser cookie, redirect to Slack |
| `GET /slack/oauth_redirect` | Verify and consume state, exchange the code, identify the bot, persist the installation |

Both routes return `503` when OAuth is disabled. The built-in callback renders a small success/failure response without tokens or authorization codes. For custom routes, reuse `Integration\OAuthHttpHandler` with per-install `InstallRequest` values and a typed `InstallResponse` responder. `OAuthConfig::hooks` supplies success/failure callbacks; successful installation is saved before the success hook runs. See [request options, hooks and host-route examples](research/oauth-install-requests.md).

You can also configure OAuth in PHP through `BoltConfig`, returned from a discovered `config/bolt.config.php` file. CLI and generated HTTP bootstraps both discover the project's `config/` directory:

```php
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\OAuth\OAuthConfig;

return new BoltConfig(
    signingSecret: $signingSecret,
    oauth: new OAuthConfig(
        clientId: $clientId,
        clientSecret: $clientSecret,
        redirectUri: 'https://your-app.example/slack/oauth_redirect',
        scopes: ['chat:write', 'commands'],
        userScopes: ['users:read'],
    ),
);
```

## State validation

Each install attempt creates independent random state and cookie values. The server stores a hash of the cookie binding with a ten-minute expiry. The callback must present both values and atomically consumes the state before any code exchange. Missing, mismatched, expired, or replayed state fails without exchanging a code. The cookie is `Secure`, `HttpOnly`, and `SameSite=Lax`; use HTTPS for the browser flow.

An `access_denied` cancellation returns a denial outcome without exchanging or storing credentials. Callback responses clear the browser's state cookie. State is server-backed, so no separate `stateSecret` or signed JWT configuration is needed. Each attempt has a distinct cookie name, so parallel tabs can finish independently. Custom routes must use `InstallRedirect::$cookieName`; the reusable handler also recognizes and clears legacy fixed-name cookies for pending flows. Valid denied callbacks consume their state; unbound cancellation carries no trusted metadata.

## Delivery-scoped clients

Handlers keep the normal constructor-injection style:

```php
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\WebApi\Client;

final readonly class MentionHandler
{
    public function __construct(private Client $client) {}

    #[Event('app_mention')]
    public function __invoke(AppMention $mention, Authorization $authorization): void
    {
        $this->client->chat()->postMessage([
            'channel' => $mention->channelId,
            'text' => 'Hello from '.$authorization->teamId,
        ]);
    }
}
```

For OAuth/custom authorization, Tempest resolves class handlers in a fresh credential scope. Constructor and method clients, `Reply`, and `Delivery::client` use the selected token. The application container's client remains unchanged between deliveries. `Authorization` exposes bot/user tokens, IDs, scopes, and expiry timestamps. Bot credentials take precedence; a matching user's token is the fallback. To deliberately act as that user, use `$client->withToken($authorization->userToken)` after checking that the token is present.

Delivery-specific values also support method injection: `AuthorizationSource` retains the incoming team, enterprise, user, conversation, and original envelope; `Authorization` describes the resolved credentials. Neither requires a global request binding.

Use ordinary discovered or class-registered listeners for constructor injection. A preconstructed object or closure retains dependencies supplied by its caller; request its client as a method argument instead of capturing a global client. Custom Tempest container implementations are rejected when enabling an authorizer because they do not provide the framework's credential-scope behavior.

## Installation stores

The default `FileInstallationStore` persists outside `public/`, under `.thunder-bolt/oauth/installations`. Records use hashed names and private file permissions. Writes use temporary files and atomic replacement; updates lock the selected credential across workers sharing the directory. State records live under the adjacent `states/` directory. Set `SLACK_INSTALLATION_STORE_PATH` to relocate the OAuth storage root.

`MemoryInstallationStore` and `State\InMemoryStateStore` are useful in tests. For multiple application hosts, bind shared implementations of `InstallationStore` and `StateStore` in the Tempest container before the application is initialized. They must preserve atomic update/consumption semantics across those hosts. File locking coordinates processes sharing a filesystem; it does not coordinate independent local disks.

The installation store interface provides `save()`, `fetch()`, `delete()`, and `update()`. Its query explicitly identifies a workspace or organization and can select one user's grant:

```php
use Thunder\Bolt\OAuth\InstallationQuery;

$workspace = new InstallationQuery(teamId: 'T123');
$organization = new InstallationQuery(
    enterpriseId: 'E123',
    isEnterpriseInstall: true,
);
$userGrant = $store->fetch($workspace->forUser('U123'));
```

Workspace and organization lookups remain separate. The authorizer combines the current bot record with the requesting user's record, so one user's credential is never substituted for another's. Reinstalling one user preserves other users' records. Deleting a workspace/organization query removes its bot and user records; deleting a user query removes only that user's record.

`app_uninstalled` and `tokens_revoked` listeners bypass installation lookup because credentials may already be gone. They still receive `AuthorizationSource`, so application handlers can delete the appropriate installation or user records. Cleanup policy remains explicit in your listeners.

## Token rotation

When Slack issues refresh tokens, the installer persists access tokens, refresh tokens, scopes, and absolute UTC expiry timestamps for each grant. `InstallationAuthorizer` invokes `TokenRotator` before dispatch. It refreshes grants at or within two hours of expiry, matching the pinned Bolt JS default.

Each refresh reads the latest record under an exclusive lock, calls `oauth.v2.access`, and persists the returned credential before releasing the lock. Bot and user refreshes commit independently, preserving a successful bot refresh if the user refresh fails. Failures propagate to the delivery; HTTP handlers are not run with a failed authorization result. OAuth exchange/refresh requests carry client credentials in form data and no bot bearer token.

You can also rotate outside an incoming request by resolving `TokenRotator` from the container:

```php
$rotator->refresh(new InstallationQuery(teamId: 'T123', userId: 'U123'));
```

To migrate an existing stored long-lived credential after enabling Slack token rotation, explicitly call `migrateBot($query)` or `migrateUser($query->forUser('U123'))`. These use the actual `oauth.v2.exchange` endpoint, preserving the old token as a form field. Migration is an explicit operation; ordinary authorization refreshes credentials that already have refresh metadata. Configure rotation in Slack and follow its [token rotation guide](https://docs.slack.dev/authentication/using-token-rotation/).

## Custom authorization

Embedding applications can supply an `Authorizer` or callback:

```php
$app->authorizeUsing(function (AuthorizationSource $source): Authorization {
    $installation = $this->installations->forWorkspace($source->teamId);

    return new Authorization(
        botToken: $installation->token,
        botUserId: $installation->botUserId,
        teamId: $source->teamId,
    );
});
```

An empty credential result fails. A lookup or rotation failure reaches the application's error handler and cannot be converted into an authorized listener invocation. Multi-workspace and Grid source extraction follows Bolt's Events API authorization precedence, slash-command organization flag, and Slack Connect modal team override.

The modern interface supports OAuth v2. Classic OAuth v1, browser-cookie bypass modes, organization-admin installs that omit state, and OpenID Connect are outside this milestone. See the [pinned-source parity reference](research/oauth-authorization.md) for exact contracts and deliberate differences.
