# Test Slack applications

`Thunder\Bolt\Testing\BoltTest` exercises real listeners through signed requests and the real HTTP receiver. Its Slack client uses an in-memory PSR-18 transport. The harness does not load `.env`, boot the application kernel, or create file stores. Register the listener classes your test exercises and bind their ordinary dependencies explicitly.

The toolkit ships with the production package and has no PHPUnit or Pest dependency. Its assertion failures throw `Testing\AssertionFailed`; any PHP test runner can use it. Under PHPUnit/Pest, successful expectations also increment the runner's assertion count, so an ordinary test needs no manual assertion bookkeeping.

## A mention and its reply

```php
use App\Listeners\GreetOnMention;
use Thunder\Bolt\Testing\BoltTest;

$bolt = BoltTest::make()->listeners(GreetOnMention::class);

$bolt->mention('hello', user: 'U123', channel: 'C123')
    ->assertAcknowledged()
    ->assertThreadReply('Hello <@U123>!')
    ->assertNoJobsQueued();
```

Each harness owns its own container, fake client, and memory conversation store. Each result captures only that delivery's outgoing calls and newly accepted jobs. A later delivery cannot accidentally satisfy an assertion using an earlier reply.

`$bolt->app` is the ordinary Thunder Bolt application, so fluent registration, middleware, custom authorization, assistant configuration, and error policies remain available.

## Commands and interactions

```php
use App\Listeners\Ping;
use Thunder\Bolt\Testing\BoltTest;

BoltTest::make()->listeners(Ping::class)
    ->command('/ping')
    ->assertAcknowledged(['text' => 'Pong.', 'response_type' => 'ephemeral'])
    ->assertNoMessagesSent();
```

`assertAcknowledged()` checks HTTP 200 and any supplied response fields. It checks the returned acknowledgement independently from outbound message calls.

| Fixture | Meaning |
| --- | --- |
| `mention($text, user:, channel:, threadTs:, team:)` | An `app_mention` with a string timestamp and unique event ID |
| `message($text, user:, channel:, threadTs:, team:)` | A `message` event for message listeners and filters |
| `event($type, $data, team:, eventId:)` | An Events API envelope around your exact event fields |
| `command($name, $text, user:, channel:, team:)` | A signed form-encoded slash command |
| `interaction($payload)` | A signed form containing Slack's JSON `payload` field |
| `send($payload, form: false)` | Lower-level signed request, including negative cases |

Convenience fixtures require HTTP 200 before returning a result. A missing acknowledgement or failing handler therefore cannot quietly pass a “no messages” assertion. For negative tests, use `send(...)->assertStatus(400)` or another expected status. Results expose `response` and the captured `error`; a failed status assertion retains the original processing error as its previous exception.

Interactions accept complete Slack-shaped payloads so tests remain explicit about action IDs, view state, team/user objects, response URLs, and missing context. Use `assertApiCalled('response_url', ...)` for response-URL updates. Captured calls omit request headers and destination URLs; their debug output contains only the method name.

## Stub Web API responses

Common message, view and streaming writes receive a minimal successful response by default. Streaming start returns a synthetic timestamp; the fake does not simulate Slack protocol state. Other methods fail unless explicitly stubbed; nothing falls through to a real network client.

```php
use Thunder\Bolt\Testing\{BoltTest, SlackCall};
use Thunder\Bolt\WebApi\Client;

$bolt = BoltTest::make();
$bolt->slack->respondsWith('users.info', [
    'ok' => true,
    'user' => ['id' => 'U123', 'name' => 'ada'],
]);

$bolt->app->event('app_mention', static function (Client $client): void {
    $client->users()->info(['user' => 'U123']);
});
$bolt->mention('Who is Ada?')
    ->assertApiCalled('users.info',
        static fn (SlackCall $call): bool => $call->arguments['user'] === 'U123',
        times: 1,
    );
```

Responses are consumed in order per method. Configure errors and rate limits through the same method:

```php
$bolt->slack->respondsWith(
    'chat.postMessage',
    ['ok' => false, 'error' => 'ratelimited'],
    status: 429,
    headers: ['Retry-After' => '2'],
);
```

Default write responses are conveniences for handler tests, not a Slack protocol simulator. Stub additional fields such as view IDs or hashes when application logic depends on them. `calls()` exposes captured method/argument values; argument inspection supports JSON, GET query, and URL-encoded requests. Multipart file-body inspection is outside this first interface.

## Bind application dependencies

```php
use Thunder\Bolt\Testing\BoltTest;

$bolt = BoltTest::make()
    ->bind($fakeReports, as: Reports::class)
    ->listeners(DeploymentListener::class);
```

Only dependencies used by these listeners need wiring. The harness does not replace unrelated application services automatically. Keep external service calls behind your own test adapters. Configure the captured Slack client with `respondsWith()` rather than replacing its container binding.

## Assert durable acceptance

```php
use App\Jobs\{PublishNote, PublishNoteHandler};
use App\Listeners\QueueNote;
use Thunder\Bolt\Jobs\JobRecord;
use Thunder\Bolt\Testing\BoltTest;

$bolt = BoltTest::make(jobs: true)
    ->listeners(QueueNote::class)
    ->jobHandlers(PublishNoteHandler::class);

$bolt->mention('Publish the report')
    ->assertJobQueued(PublishNote::class,
        static fn (JobRecord $job): bool => $job->payload['text'] === 'Publish the report',
    )
    ->assertNoMessagesSent();
```

This uses the real job codec and an isolated in-memory SQLite store, requiring `pdo_sqlite`. Register ordinary `#[CommandHandler]` classes; intake assertions do not run the handlers. To test duplicate delivery, supply the same `eventId:` and event data to two `event()` calls. A duplicate receipt does not count as a newly queued job.

Start a new harness per test. Intake snapshots are bounded: reaching 200 stored jobs fails explicitly instead of silently truncating assertions. Worker execution, lease recovery, real storage adapters, Socket Mode envelopes, and full application boot are separate integration-test concerns.

## Assertions

| Assertion | Checks |
| --- | --- |
| `assertStatus($status)` | Receiver HTTP status |
| `assertAcknowledged($fields = [])` | HTTP 200 and matching acknowledgement fields |
| `assertMessageSent($text, channel: null)` | A matching `chat.postMessage` call |
| `assertThreadReply($text)` | Text, channel, and the fixture's known thread root |
| `assertEphemeralReply($text)` | Text, channel, and user |
| `assertApiCalled($method, $predicate = null, times: null)` | Matching calls, optionally an exact count |
| `assertNoMessagesSent()` | No message posts, edits, deletes, ephemeral, streaming, or response-URL calls |
| `assertNoApiCalls()` | No outgoing Slack calls |
| `assertJobQueued($class, $predicate = null)` | A newly accepted job and optional record predicate |
| `assertNoJobsQueued()` | No newly accepted jobs |

Use the [broader testing guide](testing-security-troubleshooting.md) for framework tests, recorded transports, opt-in live checks, and deployment verification.
