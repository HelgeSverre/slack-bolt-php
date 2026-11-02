<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Assistant;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\Assistant\MessageStream;
use Thunder\Bolt\Assistant\Streaming\Blocks;
use Thunder\Bolt\Assistant\Streaming\MarkdownText;
use Thunder\Bolt\Assistant\Streaming\PlanUpdate;
use Thunder\Bolt\Assistant\Streaming\TaskStatus;
use Thunder\Bolt\Assistant\Streaming\TaskUpdate;
use Thunder\Bolt\Assistant\Thread;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\BlockKit\URLSourceElement;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Testing\BoltTest;
use Thunder\Bolt\Testing\FakeSlack;
use Thunder\Bolt\WebApi\Client;

final class StreamingChunksTest extends TestCase
{
    public function test_typed_and_raw_chunks_share_one_wire_mode_and_identity(): void
    {
        $fake = new FakeSlack();
        $stream = $this->stream($fake);
        $task = TaskUpdate::make('search', 'Search docs')->details('Reading the reference');
        $done = $task
            ->status(TaskStatus::Complete)
            ->output('Found the method')
            ->sources(URLSourceElement::make('https://docs.slack.dev', 'Slack'));
        $stream->chunks([PlanUpdate::make('Find an answer'), $task, MarkdownText::make('Looking…')]);
        $stream->chunks([
            $done,
            Blocks::make(Section::text('Reference found')),
            ['type' => 'future_chunk', 'custom' => true],
        ]);
        $stream->stop();
        $calls = $fake->calls();
        self::assertSame(['chat.startStream', 'chat.appendStream', 'chat.stopStream'], array_column($calls, 'method'));
        self::assertSame('in_progress', $task->toArray()['status']);
        self::assertArrayNotHasKey('output', $task->toArray());
        self::assertSame('complete', $calls[1]->arguments['chunks'][0]['status']);
        self::assertSame('Slack', $calls[1]->arguments['chunks'][0]['sources'][0]['text']);
        self::assertSame('Reading the reference', $calls[1]->arguments['chunks'][0]['details']);
        self::assertSame(['type' => 'future_chunk', 'custom' => true], $calls[1]->arguments['chunks'][2]);
        self::assertSame($calls[0]->arguments['thread_ts'], '1.001');
        self::assertSame($stream->timestamp(), $calls[1]->arguments['ts']);
        self::assertSame($stream->timestamp(), $calls[2]->arguments['ts']);
        self::assertSame($done->toArray(), json_decode(json_encode($done, JSON_THROW_ON_ERROR), true));
    }

    public function test_total_text_limit_counts_unicode_across_typed_raw_and_append_calls_before_network(): void
    {
        $fake = new FakeSlack();
        $stream = $this->stream($fake);
        $stream->append(str_repeat('ø', 6000));
        $stream->chunks([['type' => 'markdown_text', 'text' => str_repeat('界', 6000)]]);
        try {
            $stream->chunks([MarkdownText::make('x')]);
            self::fail('Expected whole-stream text limit.');
        } catch (\LengthException) {
            self::assertCount(2, $fake->calls());
        }
        $stream->chunks([TaskUpdate::make('still-allowed', 'Finish')])->stop();
        self::assertCount(4, $fake->calls());
    }

    public function test_unique_task_limit_allows_updates_and_rejected_batches_do_not_reserve_ids(): void
    {
        $fake = new FakeSlack();
        $stream = $this->stream($fake);
        $tasks = array_map(static fn(int $id): TaskUpdate => TaskUpdate::make((string) $id, 'Task'), range(1, 49));
        $stream->chunks($tasks);
        try {
            $stream->chunks([TaskUpdate::make('50', 'Task'), TaskUpdate::make('51', 'Task')]);
            self::fail('Expected unique-task limit.');
        } catch (\LengthException) {
            self::assertCount(1, $fake->calls());
        }
        $stream->chunks([TaskUpdate::make('51', 'Task'), $tasks[0]->status(TaskStatus::Complete)])->stop();
        self::assertCount(3, $fake->calls());
    }

    public function test_testing_toolkit_captures_real_streamed_handler_calls(): void
    {
        $test = BoltTest::make();
        $test->app->assistant(Assistant::forAgentView()->userMessage(static function (Thread $thread): void {
            $thread
                ->sayStream()
                ->chunks([TaskUpdate::make('tool', 'Read docs')])
                ->append('Found it.')
                ->stop();
        }));
        $result = $test->event('message', ['channel_type' => 'im', 'channel' => 'D1', 'user' => 'U1', 'ts' => '1.001']);
        $result
            ->assertApiCalled('chat.startStream')
            ->assertApiCalled('chat.appendStream')
            ->assertApiCalled('chat.stopStream');
        self::assertCount(3, $result->calls());
        $this->expectException(\Thunder\Bolt\Testing\AssertionFailed::class);
        $result->assertNoMessagesSent();
    }

    public function test_failure_is_terminal_for_typed_chunks_and_final_block_limit_is_local(): void
    {
        $fake = new FakeSlack();
        $stream = $this->stream($fake);
        $stream->append('Ready');
        try {
            $stream->stop(...array_fill(0, 51, Section::text('Too many')));
            self::fail('Expected final block limit.');
        } catch (\LengthException) {
            self::assertCount(1, $fake->calls());
        }
        $fake->respondsWith('chat.appendStream', ['ok' => false, 'error' => 'internal_error']);
        try {
            $stream->chunks([TaskUpdate::make('tool', 'Read')]);
            self::fail('Expected Slack failure.');
        } catch (\Thunder\Bolt\WebApi\SlackApiException) {
            self::assertCount(2, $fake->calls());
        }
        $this->expectException(\LogicException::class);
        $stream->chunks([TaskUpdate::make('tool', 'Retry')]);
    }

    #[DataProvider('invalidChunks')]
    public function test_invalid_builders_fail_locally(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    public static function invalidChunks(): iterable
    {
        yield 'empty text' => [static fn() => MarkdownText::make('')];
        yield 'long text' => [static fn() => MarkdownText::make(str_repeat('x', 12001))];
        yield 'empty plan' => [static fn() => PlanUpdate::make(' ')];
        yield 'empty task' => [static fn() => TaskUpdate::make('', 'Task')];
        yield 'bad status' => [static fn() => TaskUpdate::make('id', 'Task', 'done')];
        yield 'long details' => [static fn() => TaskUpdate::make('id', 'Task')->details(str_repeat('x', 12001))];
        yield 'long output' => [static fn() => TaskUpdate::make('id', 'Task')->output(str_repeat('x', 12001))];
        yield 'many sources' => [
            static fn() => TaskUpdate::make('id', 'Task')->sources(...array_fill(
                0,
                51,
                URLSourceElement::make('https://example.com', 'Example'),
            )),
        ];
        yield 'empty blocks' => [static fn() => Blocks::make()];
        yield 'many blocks' => [static fn() => Blocks::make(...array_fill(0, 51, Section::text('hello')))];
    }

    private function stream(FakeSlack $fake): MessageStream
    {
        return new MessageStream(
            new Client($fake, 'fixture'),
            new ThreadIdentity(new ConversationKey('D1', teamId: 'T1', threadTs: '1.001'), 'U1'),
            ['task_display_mode' => 'plan'],
        );
    }
}
