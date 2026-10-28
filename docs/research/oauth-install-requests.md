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

After successful state validation, `InstallResult::$request` contains the original effective request, including metadata. Invalid state and unbound cancellation have no request. A valid denied callback consumes its state and can expose its validated request; replay cannot. Metadata is not automatically added to the stored `Installation`: use the installation success hook to persist application associations (next checkpoint).

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
