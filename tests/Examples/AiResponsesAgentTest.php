<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Ai\ResponsesAgent;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;

final class AiResponsesAgentTest extends TestCase
{
    public function test_it_posts_a_responses_request_and_preserves_reasoning_in_history(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], json_encode([
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Hello <@U123|Ada>.',
                        'annotations' => [],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR))]);
        $agent = new ResponsesAgent($http, 'sk-test', 'gpt-test');

        $result = $agent->ask('How is the pet?', [], static fn(): array => ['unused' => true]);

        self::assertTrue($result->succeeded());
        self::assertSame('Hello @Ada.', $result->text);
        self::assertSame('https://api.openai.com/v1/responses', (string) $http->requests[0]->getUri());
        self::assertSame('Bearer sk-test', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('gpt-test', $http->sentPayload()['model']);
        self::assertSame('web_search', $http->sentPayload()['tools'][0]['type']);
        self::assertTrue($http->sentPayload()['tools'][1]['strict']);
        $wire = json_decode((string) $http->requests[0]->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $wire->tools[1]->parameters->properties);
        self::assertSame('reasoning', $result->history[1]['type']);
    }

    public function test_it_runs_function_calls_then_sends_call_outputs_with_their_call_ids(): void
    {
        $http = new RecordingHttpClient([
            new Response(200, [], json_encode([
                'output' => [
                    ['type' => 'reasoning', 'id' => 'rs_1'],
                    [
                        'type' => 'function_call',
                        'name' => 'care_for_pet',
                        'call_id' => 'call_1',
                        'arguments' => '{"action":"feed"}',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'The pet has been fed.', 'annotations' => []]],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $calls = [];

        $result = new ResponsesAgent($http, 'sk-test')->ask('Feed the pet', [], static function (
            string $name,
            array $arguments,
        ) use (&$calls): array {
            $calls[] = [$name, $arguments];

            return ['ok' => true, 'meal' => 'berries'];
        });

        self::assertSame([['care_for_pet', ['action' => 'feed']]], $calls);
        self::assertSame('The pet has been fed.', $result->text);
        self::assertCount(2, $http->requests);
        $secondInput = $http->sentPayload(1)['input'];
        self::assertSame('reasoning', $secondInput[1]['type']);
        self::assertSame('function_call', $secondInput[2]['type']);
        self::assertSame(
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"ok":true,"meal":"berries"}'],
            $secondInput[3],
        );
    }

    public function test_it_does_not_retry_a_pet_care_action_in_one_request(): void
    {
        $care = [
            'type' => 'function_call',
            'name' => 'care_for_pet',
            'call_id' => 'call_1',
            'arguments' => '{"action":"play"}',
        ];
        $http = new RecordingHttpClient([
            new Response(200, [], json_encode(['output' => [$care]], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['output' => [$care]], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Playtime is complete.', 'annotations' => []]],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $calls = 0;

        new ResponsesAgent($http, 'sk-test')->ask('Play with the pet', [], static function () use (&$calls): array {
            $calls++;

            return ['ok' => true];
        });

        self::assertSame(1, $calls);
        self::assertSame(
            '{"error":"That pet-care action was already attempted for this request."}',
            $http->sentPayload(2)['input'][4]['output'],
        );
    }

    public function test_it_reports_completed_web_searches_and_formats_citations_as_slack_links(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], json_encode([
            'output' => [
                [
                    'type' => 'web_search_call',
                    'status' => 'completed',
                    'action' => ['type' => 'search', 'query' => 'hamster care'],
                ],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Hamsters need fresh water.',
                        'annotations' => [[
                            'type' => 'url_citation',
                            'url' => 'https://example.com/hamsters',
                            'title' => 'Hamster guide',
                        ]],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR))]);
        $progress = [];

        $result = new ResponsesAgent($http, 'sk-test')->ask(
            'Search for hamster care',
            [],
            static fn(): array => [],
            static function (string $message) use (&$progress): void {
                $progress[] = $message;
            },
        );

        self::assertSame(['Searched the web for “hamster care”.'], $progress);
        self::assertSame(
            "Hamsters need fresh water.\n\nSources:\n• <https://example.com/hamsters|Hamster guide>",
            $result->text,
        );
    }

    public function test_it_returns_a_local_missing_key_message_without_an_http_request(): void
    {
        $http = new RecordingHttpClient();

        $result = new ResponsesAgent($http, '')->ask('Hello', [], static fn(): array => []);

        self::assertSame('missing_api_key', $result->error);
        self::assertStringContainsString('OPENAI_API_KEY', $result->text);
        self::assertSame([], $http->requests);
    }

    public function test_it_returns_a_safe_error_for_api_failures(): void
    {
        $http = new RecordingHttpClient([new Response(500, [], '{"error":{"message":"invalid API key"}}')]);

        $result = new ResponsesAgent($http, 'sk-test')->ask('Hello', [], static fn(): array => []);

        self::assertSame('openai_request_failed', $result->error);
        self::assertSame('The assistant could not reach OpenAI right now. Please try again shortly.', $result->text);
    }

    public function test_it_bounds_input_and_output_without_sending_an_oversized_prompt(): void
    {
        $inputHttp = new RecordingHttpClient();
        $tooLarge = new ResponsesAgent($inputHttp, 'sk-test')->ask(
            str_repeat('a', 12_001),
            [],
            static fn(): array => [],
        );
        self::assertSame('input_too_large', $tooLarge->error);
        self::assertSame([], $inputHttp->requests);

        $outputHttp = new RecordingHttpClient([new Response(200, [], json_encode([
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => str_repeat('x', 12_500), 'annotations' => []]],
            ]],
        ], JSON_THROW_ON_ERROR))]);
        $shortened = new ResponsesAgent($outputHttp, 'sk-test')->ask('Hello', [], static fn(): array => []);

        self::assertSame(12_000, strlen($shortened->text));
        self::assertStringEndsWith('...', $shortened->text);
    }
}
