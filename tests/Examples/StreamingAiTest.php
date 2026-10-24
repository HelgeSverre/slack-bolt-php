<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Ai\AgentEvent;
use App\Demo\Ai\ResponsesAgent;
use App\Demo\Ai\SseDecoder;
use App\Demo\Ai\StreamText;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Tests\Support\StreamingResponsesHttp;

final class StreamingAiTest extends TestCase
{
    public function test_text_and_search_events_arrive_before_response_completion_and_keep_citations(): void
    {
        $http = new StreamingResponsesHttp([[
            ['type' => 'response.web_search_call.in_progress', 'item_id' => 'ws_1'],
            ['type' => 'response.web_search_call.completed', 'item_id' => 'ws_1'],
            ['type' => 'response.output_text.delta', 'delta' => 'Diameter ≈ '],
            ['type' => 'response.output_text.delta', 'delta' => '1.4 million km.'],
            $this->completed('Diameter ≈ 1.4 million km.', [[
                'type' => 'url_citation',
                'url' => 'https://science.nasa.gov/sun/',
                'title' => 'NASA',
            ]]),
        ]]);
        $events = [];
        $result = new ResponsesAgent($http, 'test-key', 'test-model')->ask(
            'Search: how big is the Sun?',
            [],
            static fn(): array => [],
            onEvent: static function (AgentEvent $event) use ($http, &$events): void {
                self::assertTrue($http->delivering, 'Progress must arrive during the request.');
                $events[] = $event;
            },
        );
        self::assertTrue($result->succeeded());
        self::assertSame(['in_progress', 'complete'], [$events[0]->status, $events[1]->status]);
        self::assertSame('Diameter ≈ 1.4 million km.', $events[2]->text . $events[3]->text);
        self::assertSame('NASA', $result->citations[0]['title']);
        $payload = json_decode((string) $http->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['stream']);
        self::assertSame('text/event-stream', $http->requests[0]->getHeaderLine('Accept'));
    }

    public function test_function_progress_brackets_execution_and_failed_tools_get_error_cards(): void
    {
        $http = new StreamingResponsesHttp([
            [
                [
                    'type' => 'response.completed',
                    'response' => [
                        'output' => [[
                            'type' => 'function_call',
                            'name' => 'care_for_pet',
                            'call_id' => 'call_1',
                            'arguments' => '{"action":"feed"}',
                        ]],
                    ],
                ],
            ],
            [$this->completed('The pet store is unavailable.')],
        ]);
        $events = [];
        $result = new ResponsesAgent($http, 'test-key')->ask(
            'Feed the pet',
            [],
            static function () use (&$events): array {
                self::assertSame('in_progress', $events[0]->status);
                throw new \RuntimeException('private filesystem details');
            },
            onEvent: static function (AgentEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        self::assertTrue($result->succeeded());
        self::assertSame('error', $events[1]->status);
        self::assertSame($events[0]->id, $events[1]->id);
        $second = json_decode((string) $http->requests[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('{"error":"The pet tool was unavailable."}', $second['input'][2]['output']);
    }

    public function test_eof_and_incomplete_events_are_failures_even_after_some_text_arrived(): void
    {
        foreach ([[], [['type' => 'response.incomplete', 'response' => ['status' => 'incomplete']]]] as $ending) {
            $http = new StreamingResponsesHttp([[
                ['type' => 'response.output_text.delta', 'delta' => 'Partial answer'],
                ...$ending,
            ]]);
            $result = new ResponsesAgent($http, 'private-key')->ask(
                'Hello',
                [],
                static fn(): array => [],
                onEvent: static function (): void {},
            );
            self::assertFalse($result->succeeded());
            self::assertSame('openai_request_failed', $result->error);
            self::assertStringNotContainsString('private-key', $result->text);
        }
    }

    public function test_decoder_handles_multiline_data_comments_and_single_byte_fragments(): void
    {
        $decoder = new SseDecoder();
        $events = [];
        $wire = ": comment\r\nevent: ignored\r\ndata: {\r\ndata: \"type\": \"example\", \"text\": \"≈\"}\r\n\r\ndata: [DONE]\n\n";
        foreach (str_split($wire) as $byte) {
            $decoder->push($byte, static function (array $event) use (&$events): void {
                $events[] = $event;
            });
        }
        self::assertSame([['type' => 'example', 'text' => '≈']], $events);
    }

    public function test_decoder_rejects_oversized_unterminated_events(): void
    {
        $this->expectException(\JsonException::class);
        new SseDecoder()->push('data: ' . str_repeat('x', 1_048_577), static function (): void {});
    }

    public function test_citation_markers_split_across_deltas_do_not_leak_into_slack_text(): void
    {
        $text = new StreamText();
        $output = '';
        foreach ([
            'The Sun is big. ',
            "\u{E200}cite",
            "\u{E202}source",
            "\u{E201}",
            ' About 109 Earths across.',
        ] as $delta) {
            $output .= $text->append($delta);
        }
        self::assertSame('The Sun is big.  About 109 Earths across.', $output);
    }

    private function completed(string $text, array $annotations = []): array
    {
        return [
            'type' => 'response.completed',
            'response' => [
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => $annotations]],
                ]],
            ],
        ];
    }
}
