<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Work\WorkItemStore;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\RichTextSection;
use Thunder\Bolt\BlockKit\RichTextText;
use Thunder\Bolt\WorkObjects\Actor;
use Thunder\Bolt\WorkObjects\CommentBody;
use Thunder\Bolt\WorkObjects\Comments\CommentDelete;
use Thunder\Bolt\WorkObjects\Comments\CommentOperation;
use Thunder\Bolt\WorkObjects\Comments\CommentQuery;
use Thunder\Bolt\WorkObjects\Comments\CommentWrite;
use Thunder\Bolt\WorkObjects\ExternalReference;

final class WorkItemStoreTest extends TestCase
{
    private string $path;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'thunder-bolt-work-');
        if ($path === false) {
            self::fail('Cannot prepare a work item store path.');
        }
        unlink($path);
        $this->path = $path;
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '.lock'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_posts_edits_and_deletes_comments_durably(): void
    {
        $store = $this->store();
        $actor = new Actor('T-one', 'U-ada');
        $reference = $this->item($store, $actor);
        $posted = $store->post(new CommentWrite($actor, $reference, CommentBody::markdown('*First*'), 'post-1'));
        self::assertLessThanOrEqual(25, strlen($posted->id));
        $this->now++;
        $edited = $store->edit(
            new CommentWrite($actor, $reference, CommentBody::text('Edited'), 'edit-1', $posted->id),
        );

        self::assertSame('Edited', $edited->body->toArray()['value']);
        self::assertSame($this->now, $edited->toArray()['last_edit_ts']);
        self::assertSame(1, $this->store()->item($actor, $reference)['comment_count']);

        $store->delete(new CommentDelete($actor, $reference, $posted->id, 'delete-1'));
        $store->delete(new CommentDelete($actor, $reference, $posted->id, 'delete-1'));

        self::assertSame(0, $store->item($actor, $reference)['comment_count']);
        self::assertSame([], $store->page(new CommentQuery($actor, $reference))->comments);
    }

    public function test_scope_and_authorization_stay_inside_team_and_author(): void
    {
        $store = $this->store();
        $ada = new Actor('T-one', 'U-ada');
        $grace = new Actor('T-one', 'U-grace');
        $otherTeam = new Actor('T-two', 'U-ada');
        $reference = $this->item($store, $ada);
        $comment = $store->post(
            new CommentWrite($ada, $reference, CommentBody::text('Private author rights'), 'post-1'),
        );

        self::assertTrue($store->allows($grace, $reference, CommentOperation::Read));
        self::assertTrue($store->allows($grace, $reference, CommentOperation::Post));
        self::assertFalse($store->allows($grace, $reference, CommentOperation::Edit, $comment->id));
        self::assertFalse($store->allows($otherTeam, $reference, CommentOperation::Read));
        self::assertNull($store->item($otherTeam, $reference));
        $this->expectException(\RuntimeException::class);
        $store->edit(new CommentWrite($grace, $reference, CommentBody::text('Nope'), 'edit-1', $comment->id));
    }

    public function test_delivery_deduplication_survives_restart_and_keeps_rich_text(): void
    {
        $actor = new Actor('T-one', 'U-ada');
        $store = $this->store();
        $reference = $this->item($store, $actor);
        $body = CommentBody::richText(RichText::make(RichTextSection::make(RichTextText::make('Durable rich text'))));
        $first = $store->post(new CommentWrite($actor, $reference, $body, 'delivery-1'));
        $again = $this->store()->post(
            new CommentWrite($actor, $reference, CommentBody::text('Ignored retry body'), 'delivery-1'),
        );

        self::assertSame($first->id, $again->id);
        self::assertSame($first->body->toArray(), $again->body->toArray());
        self::assertSame(1, $store->item($actor, $reference)['comment_count']);
    }

    public function test_threads_have_root_previews_and_opaque_stable_pagination(): void
    {
        $store = $this->store();
        $ada = new Actor('T-one', 'U-ada');
        $grace = new Actor('T-one', 'U-grace');
        $reference = $this->item($store, $ada);
        $first = $store->post(new CommentWrite($ada, $reference, CommentBody::text('First root'), 'post-1'));
        $this->now++;
        $second = $store->post(new CommentWrite($grace, $reference, CommentBody::text('Second root'), 'post-2'));
        $this->now++;
        $store->post(new CommentWrite($grace, $reference, CommentBody::text('Reply one'), 'reply-1', null, $first->id));
        $this->now++;
        $store->post(new CommentWrite($ada, $reference, CommentBody::text('Reply two'), 'reply-2', null, $first->id));

        $roots = $store->page(new CommentQuery($ada, $reference, limit: 1));
        self::assertSame($second->id, $roots->comments[0]->id);
        self::assertNotNull($roots->cursor);
        $following = $store->page(new CommentQuery($ada, $reference, cursor: $roots->cursor));
        self::assertSame($first->id, $following->comments[0]->id);
        self::assertSame(2, $following->comments[0]->toArray()['reply_count']);
        self::assertCount(2, $following->comments[0]->toArray()['reply_users']);

        $thread = $store->page(new CommentQuery($ada, $reference, $first->id));
        self::assertSame(
            ['Reply one', 'Reply two'],
            array_map(static fn($comment): string => $comment->body->toArray()['value'], $thread->comments),
        );
        $this->expectException(\InvalidArgumentException::class);
        $store->post(
            new CommentWrite($ada, $reference, CommentBody::text('Nested'), 'nested-1', null, $thread->comments[0]->id),
        );
    }

    public function test_corruption_fails_closed_without_replacing_data(): void
    {
        file_put_contents($this->path, '{corrupt');
        $store = $this->store();
        $this->expectException(\RuntimeException::class);
        try {
            $store->ensureItem(new Actor('T-one', 'U-ada'), 'WI-1', 'Item');
        } finally {
            self::assertSame('{corrupt', file_get_contents($this->path));
        }
    }

    private function store(): WorkItemStore
    {
        return new WorkItemStore($this->path, fn(): int => $this->now);
    }

    private function item(WorkItemStore $store, Actor $actor): ExternalReference
    {
        $summary = $store->ensureItem($actor, 'WI-1', 'Ship durable comments');
        self::assertSame('WI-1', $summary['id']);

        return new ExternalReference('WI-1', 'bolt-work-item');
    }
}
