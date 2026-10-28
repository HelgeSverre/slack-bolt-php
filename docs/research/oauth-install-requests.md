# Installation requests and durable OAuth state

`Installer::begin()` retains the configured defaults. Supply an immutable request to override scopes for one flow:

```php
$redirect = $installer->begin(
    InstallRequest::make()
        ->scopes('commands', 'chat:write')
        ->userScopes('users:read')
        ->team('T123456')
        ->metadata(['account_id' => $account->id]),
);
```

An omitted scope list inherits configuration. Calling `scopes()` or `userScopes()` with no arguments explicitly requests an empty list. Scope lists, workspace hints and metadata are validated before any state is stored. `team()` accepts workspace or enterprise IDs as hints; authorization results determine the actual installed workspace. Confirm that result against application policy before linking an installation to an account.

Metadata contains portable JSON values, at most 64 KiB and 32 nesting levels. It is detached from caller references and kept in state storage, never included in the Slack authorization URL. Treat metadata as application data rather than a place for credentials. Debug output redacts metadata; explicit `toArray()` returns it for storage.

After successful state validation, `InstallResult::$request` contains the original effective request, including metadata. Invalid state and unbound cancellation have no request. A valid denied callback consumes its state and can expose its validated request; replay cannot. Metadata is not automatically added to the stored `Installation`: use the installation success hook to persist application associations .

## State adapter compatibility

The existing `StateStore` interface remains valid for default installations. Both built-in stores now implement `RequestStateStore`:

```php
interface RequestStateStore extends StateStore
{
    public function consumeRequest(string $value, string $cookieBinding, int $now): ?InstallRequest;
}
```

Custom adapters must persist `State::$request` with the cookie hash and expiry. `consumeRequest()` must atomically retrieve and delete the record, then return the request only if cookie binding and expiry match. Expired, mismatched and malformed records must also be consumed. Do not implement this as separate public `get()` and `delete()` operations: two callbacks could exchange the same code. `consume()` can delegate to `consumeRequest() !== null`.

Per-install options with an old adapter throw before starting the flow instead of silently dropping metadata. File records created before this change remain readable with an empty/default request. The file adapter serializes all reads/deletions through its directory lock, including across PHP processes. Deployments with multiple hosts need shared storage with equivalent atomic semantics.

## Browser flows

Each new redirect has a `cookieName` as well as `cookieValue`, expiry and max age. Host routes must use that name rather than the former fixed constant. Cookie names are derived from an opaque random state value; values remain independently random browser bindings. Set secure, HTTP-only, SameSite=Lax cookies. Built-in routes support concurrent tabs and recognize legacy fixed-name cookies for already-pending flows. Duplicate or malformed flow cookies fail validation without falling back to a legacy cookie. Each callback clears its own flow cookie.

Evidence: `InstallRequestTest`, `InstallationFlowTest`, `StateStoreTest` (including two real PHP processes), and `OAuthControllerTest` (multiple tabs and ambiguous cookies).

Primary protocol reference: [Slack OAuth installation guide](https://docs.slack.dev/authentication/installing-with-oauth/).

## Installation hooks and host routes

Configure immutable hooks alongside OAuth credentials. Both hooks receive an `InstallResult`; only validated state supplies a non-null `request`. Success runs after `InstallationStore::save()` has returned:

```php
new OAuthConfig(
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: 'https://example.com/integrations/slack/callback',
    scopes: ['commands', 'chat:write'],
    hooks: InstallHooks::make()
        ->onSuccess(function (InstallResult $result) use ($accounts): void {
            $accounts->linkSlackInstallation(
                $result->request->metadata['account_id'],
                $result->installation,
            );
        })
        ->onFailure(function (InstallResult $result) use ($logger): void {
            $logger->warning('Slack installation did not finish', [
                'status' => $result->status->name,
                'error' => $result->error === null ? null : $result->error::class,
            ]);
        }),
);
```

Validate the installation's actual workspace/enterprise against your application policy before linking it. The example `$accounts` is application-owned persistence, not a Bolt service. Metadata survives the round trip but is not automatically written into the credential store. Hooks are synchronous; dispatch explicit named jobs if onboarding is slow. Make application writes idempotent: hooks are not a durable transaction spanning the credential store and your database.

`InstallStatus::Failed` carries an `error` when state storage, Slack exchange, identity lookup or installation storage fails. Its `request` is null if state could not be validated. This extends `complete()`'s previous behavior: exchange/storage errors now return a failed result and notify the failure hook rather than throwing directly. Update exhaustive status matches. State is consumed before exchange; restarting an unsuccessful installation requires a new `begin()`.

If a hook throws, `InstallHookFailed` retains the determined `result` and the hook exception as `getPrevious()`. A success-hook exception does not undo the saved installation or invoke the failure hook as though installation failed. Replaying that callback encounters invalid state and cannot exchange the code or invoke success again. Invalid or replayed callbacks can still invoke the failure hook with their own distinct outcome.

The stock routes delegate to `Thunder\Bolt\Integration\OAuthHttpHandler`. Host-owned routes can reuse it:

```php
$http = new OAuthHttpHandler(
    $installer,
    respond: fn (InstallResult $result): InstallResponse =>
        $result->status === InstallStatus::Installed
            ? InstallResponse::redirect('/settings/integrations?slack=installed')
            : InstallResponse::text('Please start the Slack installation again.', 400),
);

// In a stateless, authenticated application install route:
return $http->install(InstallRequest::make()->metadata(['account_id' => $account->id]));

// In the stateless callback route configured as OAuthConfig::redirectUri:
return $http->callback($request);
```

Use the existing authenticated account when constructing metadata; do not copy arbitrary query parameters into account associations. The handler sets secure HTTP-only SameSite=Lax cookies and clears the selected flow cookie even when a hook or responder throws. A response callback runs after hooks. Exceptions produce a generic 502 without exposing exception messages. `InstallResponse::redirect()` accepts explicit HTTPS URLs or local absolute paths, rejects credentials, control characters, backslashes and protocol-relative URLs, and manages the redirect status. Pick application-controlled destinations rather than reflecting untrusted return URLs. `InstallResponse::text()` sends plain text. The handler always manages state cookies; response callbacks cannot replace them.

`Installer` itself stays independent of HTTP requests and responses. Other PHP hosts may use `begin()` and `complete()` directly, following the same cookie contract. Tempest routes using the reusable handler must be `#[Stateless]` so OAuth does not acquire Tempest's unrelated encrypted-cookie/signing-key requirements.
