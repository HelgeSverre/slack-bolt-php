<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Live;

use Dotenv\Dotenv;
use GuzzleHttp\Client as HttpClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Assistant\Stores\SlackMetadataThreadContextStore;
use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\SlackApiException;

/** Explicit opt-in only. No workspace content or credentials are printed. */
#[Group('live')]
final class ConversationTransportTest extends TestCase
{
    private Client $client;
    private string $channel;

    protected function setUp(): void
    {
        if (getenv('SLACK_LIVE_TESTS') !== '1') {
            self::markTestSkipped('Set SLACK_LIVE_TESTS=1 to run against a real Slack workspace.');
        }
        $file = self::setting('SLACK_LIVE_ENV_FILE');
        if ($file !== '') {
            if (!is_file($file)) {
                self::fail('SLACK_LIVE_ENV_FILE must point to an existing .env file.');
            }
            try {
                Dotenv::createImmutable(dirname($file), basename($file))->load();
            } catch (\Throwable) {
                self::fail('Cannot load SLACK_LIVE_ENV_FILE. Check its permissions and syntax.');
            }
        }
        $token = self::setting('SLACK_LIVE_TOKEN') ?: self::setting('SLACK_BOT_TOKEN');
        $this->channel = self::setting('SLACK_LIVE_CHANNEL');
        if ($token === '' || $this->channel === '') {
            self::fail('Configure SLACK_LIVE_TOKEN (or SLACK_BOT_TOKEN) and SLACK_LIVE_CHANNEL.');
        }
        $this->client = new Client(new HttpClient(['timeout' => 15, 'connect_timeout' => 5]), $token);
    }

    public function test_conversation_reads_and_bounded_cursor_pagination_against_slack(): void
    {
        $root = self::setting('SLACK_LIVE_THREAD_TS');
        if ($root === '') {
            self::fail('SLACK_LIVE_THREAD_TS must identify an existing thread in SLACK_LIVE_CHANNEL.');
        }
        $this->check(function () use ($root): void {
            $info = $this->client->conversations()->info(['channel' => $this->channel, 'include_locale' => false]);
            self::assertSame($this->channel, $info->data['channel']['id'] ?? null);
            $history = $this->client
                ->conversations()
                ->history([
                    'channel' => $this->channel,
                    'include_all_metadata' => true,
                    'inclusive' => false,
                    'limit' => 2,
                ]);
            self::assertIsArray($history->get('messages'));

            // Filters can produce an empty page with a non-empty cursor.
            $listed = 0;
            foreach ($this->client->paginate('conversations.list', [
                'limit' => 1,
                'exclude_archived' => false,
            ]) as $page) {
                self::assertIsArray($page->get('channels'));
                if (++$listed === 2) {
                    break;
                }
            }
            self::assertGreaterThan(0, $listed);
            $members = $this->client
                ->conversations()
                ->members([
                    'channel' => self::setting('SLACK_LIVE_MEMBERS_CHANNEL') ?: $this->channel,
                    'limit' => 2,
                ]);
            self::assertIsArray($members->get('members'));

            $pages = 0;
            $found = false;
            foreach ($this->client->paginate('conversations.replies', [
                'channel' => $this->channel,
                'ts' => $root,
                'include_all_metadata' => true,
                'inclusive' => true,
                'oldest' => $root,
                'limit' => 1,
            ]) as $page) {
                $messages = $page->get('messages');
                self::assertIsArray($messages);
                $found =
                    $found
                    || array_any(
                        $messages,
                        static fn(mixed $message): bool => is_array($message) && ($message['ts'] ?? null) === $root,
                    );
                if (++$pages === 2) {
                    break;
                }
            }
            self::assertTrue($found, 'Slack must return the requested root without losing timestamp precision.');
        });
    }

    public function test_metadata_context_survives_a_fresh_adapter_read_and_preserves_messages(): void
    {
        if (self::setting('SLACK_LIVE_WRITES') !== '1') {
            self::markTestSkipped('Set SLACK_LIVE_WRITES=1 to create and clean up a temporary metadata fixture.');
        }
        $created = [];
        $this->check(function () use (&$created): void {
            try {
                $auth = $this->client->call('auth.test');
                $team = $auth->get('team_id');
                $bot = $auth->get('user_id');
                self::assertIsString($team);
                self::assertIsString($bot);
                $text = 'Thunder transport smoke test. Temporary context fixture.';
                $root = $this->client->chat()->postMessage(['channel' => $this->channel, 'text' => $text])->get('ts');
                self::assertIsString($root);
                $created[] = $root;
                $reply = $this->client
                    ->chat()
                    ->postMessage([
                        'channel' => $this->channel,
                        'thread_ts' => $root,
                        'text' => 'Temporary pagination fixture.',
                    ])
                    ->get('ts');
                self::assertIsString($reply);
                $created[] = $reply;
                // Slack can return the parent alongside the first reply even with limit=1.
                $secondReply = $this->client
                    ->chat()
                    ->postMessage([
                        'channel' => $this->channel,
                        'thread_ts' => $root,
                        'text' => 'Second temporary pagination fixture.',
                    ])
                    ->get('ts');
                self::assertIsString($secondReply);
                $created[] = $secondReply;
                $identity = new ThreadIdentity(
                    new ConversationKey($this->channel, teamId: $team, threadTs: $root),
                    $bot,
                    $bot,
                );
                $probe = bin2hex(random_bytes(8));
                new SlackMetadataThreadContextStore()->save(
                    $identity,
                    new ThreadContext(extra: ['probe' => $probe]),
                    $this->client,
                );
                $freshClient = $this->client->withToken(
                    self::setting('SLACK_LIVE_TOKEN') ?: self::setting('SLACK_BOT_TOKEN'),
                );
                $restored = new SlackMetadataThreadContextStore()->get($identity, $freshClient);
                self::assertSame($probe, $restored?->toArray()['probe'] ?? null);

                $pages = 0;
                $timestamps = [];
                foreach ($this->client->paginate('conversations.replies', [
                    'channel' => $this->channel,
                    'ts' => $root,
                    'limit' => 1,
                    'include_all_metadata' => true,
                ]) as $page) {
                    $messages = $page->get('messages');
                    self::assertIsArray($messages);
                    foreach ($messages as $message) {
                        if (is_array($message)) {
                            $timestamps[] = $message['ts'] ?? null;
                            if (($message['ts'] ?? null) === $root) {
                                self::assertSame($text, $message['text'] ?? null);
                            }
                        }
                    }
                    if (++$pages === 3) {
                        break;
                    }
                }
                self::assertContains($root, $timestamps);
                self::assertContains($reply, $timestamps);
                self::assertContains($secondReply, $timestamps);
                self::assertGreaterThanOrEqual(2, $pages, 'The fixture must exercise a real cursor round trip.');
            } finally {
                $failed = false;
                foreach (array_reverse($created) as $timestamp) {
                    try {
                        $this->client->chat()->delete(['channel' => $this->channel, 'ts' => $timestamp]);
                    } catch (\Throwable) {
                        $failed = true;
                    }
                }
                if ($failed) {
                    self::fail('Could not clean up a temporary live fixture. Check the configured Slack conversation.');
                }
            }
        });
    }

    private static function setting(string $name): string
    {
        $value = getenv($name);
        return $value !== false ? $value : (is_string($_ENV[$name] ?? null) ? $_ENV[$name] : '');
    }

    private function check(callable $run): void
    {
        try {
            $run();
        } catch (\PHPUnit\Framework\Exception $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $reason = $exception instanceof SlackApiException
                ? preg_replace('/[^a-zA-Z0-9_]/', '', $exception->error)
                : $exception::class;
            self::fail('Live Slack check failed: ' . $reason . '. Check scopes, conversation access and rate limits.');
        }
    }
}
