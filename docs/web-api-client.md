# Web API client

`WebApi\Client` uses an injected PSR-18 transport. Named resources preserve Slack's hierarchy (`$client->admin()->conversations()->archive(...)`); `call()` accepts a dynamic method name. Arrays remain supported for forward compatibility. Generated request and response models follow the pinned Web API source, with native PHP properties and raw response data available through `data` and `get()`.

## Retry deliberately

The default client sends once. Inline Slack handlers must acknowledge within three seconds, so a rate limit raises `RateLimited` with its `retryAfter` value. A queue worker or CLI command can use an immutable retrying copy:

```php
use Thunder\Bolt\WebApi\RetryPolicy;

$background = $client->retrying(
    RetryPolicy::rateLimits(maxRetries: 3, maxTotalWait: 60),
);

$result = $background->conversations()->list(['limit' => 100]);
```

This policy retries HTTP 429 responses and respects `Retry-After`. It never shortens Slack's requested delay: if the delay would exceed the cumulative wait budget, the original rate-limit error is raised. Invalid or missing retry headers use a one-second delay. `maxRetries` counts additional sends after the first attempt. A zero-second delay still consumes a retry.

A separate policy opts into replaying network failures and HTTP 5xx responses:

```php
$background = $client->retrying(RetryPolicy::transientErrors(
    maxRetries: 3,
    initialDelay: 0.5,
    maxDelay: 10,
    maxTotalWait: 30,
));
```

Network failures and server errors can occur after a write succeeded. Use this policy for reads or operations whose duplicate effects your application can tolerate or deduplicate. It uses exponential backoff with downward jitter; disable jitter for deterministic scheduling. JSON decoding errors, Slack platform errors in HTTP 200 responses, and ordinary HTTP 4xx errors are not retried.

OAuth exchanges and OpenID token requests always send once, including on HTTP 429, because authorization codes and rotating credentials can be single-use. Response URLs and external file-content uploads also send once. These are deliberate differences from a blanket retry of every outgoing request.

The wait budget bounds sleep time; configure connect/read timeouts on the PSR-18 client to bound the requests themselves. `withToken()` preserves the retry policy and transport settings, so installation-scoped clients behave consistently. The root client remains unchanged.

Tests and runtimes can supply a sleeper accepting seconds:

```php
$client = $client->retrying(
    RetryPolicy::rateLimits(),
    sleep: static function (float $seconds): void {
        // Advance a fake clock in tests, or wait through your runtime scheduler.
    },
);
```

The supplied sleeper must wait for the requested duration in production. The framework does not enqueue failed requests or coordinate rate limits across PHP workers.

## Upload files

The `files.uploadV2` name is a Node SDK convenience helper, not a Slack API endpoint. PHP exposes `upload()`, `uploadMany()`, and the parity alias `files()->uploadV2()` with typed `Uploads\Upload` values. Calling `call('files.uploadV2', ...)` fails before sending a request.

```php
use Thunder\Bolt\WebApi\Uploads\Upload;

$result = $client->upload(
    Upload::path('/var/reports/monthly.pdf')
        ->title('Monthly report')
        ->toChannel('C123')
        ->comment('The report is ready.'),
);

$result = $client->uploadMany(
    Upload::bytes('first report', 'first.txt')->toChannel('C123'),
    Upload::bytes('second report', 'second.txt')->toChannel('C123'),
);
```

The helper requests upload URLs, transfers content without a bearer token, and completes the uploads. Completion groups follow shared channel/thread/comment metadata. Each result retains the completion responses and uploaded file IDs. `Upload::stream()` supports a caller-owned readable stream with an explicit byte length; `path()` and `bytes()` manage their own streams.

Failures propagate with their phase and completed progress where available; a failed batch is not an atomic Slack transaction. Do not blindly repeat a partially completed batch. Presigned upload URLs are credentials: avoid logging them. An optional `uploadHttp` constructor argument supplies a separate PSR-18 transport for the content transfers.

## Multipart fields

For API methods with binary form fields, use an explicit `MultipartFile`:

```php
use Thunder\Bolt\WebApi\MultipartFile;

$client->files()->remote()->add([
    'external_id' => 'report-42',
    'external_url' => 'https://example.com/report-42',
    'title' => 'Report',
    'preview_image' => MultipartFile::path('/var/reports/preview.png', contentType: 'image/png'),
]);
```

Binary parts remain streamed. Nonbinary arrays/objects become JSON form values; tokens stay in the bearer header. Bytes and paths can reopen for an opted-in retry. Caller-owned streams use `MultipartFile::stream($stream, $length, $filename)`, retain the current byte window, and disable retries for that request. Multipart field names and filenames cannot inject headers.

## Source and verification

Protocol behavior follows the versioned source manifest in [the research directory](research/upstream-manifest.json), especially `WebClient.ts`, `file-upload.ts`, and `retry-policies.ts`. Slack's [Node Web API guide](https://docs.slack.dev/tools/node-slack-sdk/web-api/) documents the corresponding upstream helpers. Recorded transports test the protocol without contacting Slack; live workspace validation remains a separate release check.
