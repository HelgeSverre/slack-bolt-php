<?php

declare(strict_types=1);

namespace App\Demo\Work;

use Thunder\Bolt\WorkObjects\Actor;
use Thunder\Bolt\WorkObjects\Comment;
use Thunder\Bolt\WorkObjects\CommentBody;
use Thunder\Bolt\WorkObjects\CommentPage;
use Thunder\Bolt\WorkObjects\Comments\CommentDelete;
use Thunder\Bolt\WorkObjects\Comments\CommentOperation;
use Thunder\Bolt\WorkObjects\Comments\CommentProvider;
use Thunder\Bolt\WorkObjects\Comments\CommentQuery;
use Thunder\Bolt\WorkObjects\Comments\CommentWrite;
use Thunder\Bolt\WorkObjects\ExternalReference;
use Thunder\Bolt\WorkObjects\Sender;

/**
 * A small file-backed Work Objects provider for the hello-bolt demo.
 *
 * @phpstan-type Item array{id: string, title: string, type: string, comments: array<string, array<array-key, mixed>>, deliveries: array<string, array{operation: string, actor: string, comment: array<array-key, mixed>|null}>}
 * @phpstan-type Document array{version: 1, teams: array<string, array{items: array<string, Item>}>}
 */
final class WorkItemStore implements CommentProvider
{
    private const string REFERENCE_TYPE = 'bolt-work-item';
    private const int MAX_COMMENTS = 1_000;
    private const int MAX_DELIVERIES = 500;
    private const int MAX_DOCUMENT_BYTES = 1_500_000;

    /** @var \Closure(): int|null */
    private readonly ?\Closure $clock;

    public function __construct(
        private readonly string $path,
        ?callable $clock = null,
    ) {
        if ($path === '') {
            throw new \InvalidArgumentException('A work item store path is required.');
        }

        $this->clock = $clock === null ? null : static fn(): int => (int) $clock();
    }

    /** @return array{id: string, title: string, comment_count: int, latest_timestamp: int|null} */
    public function ensureItem(Actor $actor, string $id, string $title): array
    {
        self::text($id, 'Work item ID');
        self::text($title, 'Work item title');
        $reference = new ExternalReference($id, self::REFERENCE_TYPE);

        /** @var array{id: string, title: string, comment_count: int, latest_timestamp: int|null} $summary */
        $summary = $this->locked(function (array $document) use ($actor, $reference, $title): array {
            $item = $this->findItem($document, $actor->teamId, $reference);
            if ($item === null) {
                $item = [
                    'id' => $reference->id,
                    'title' => $title,
                    'type' => self::REFERENCE_TYPE,
                    'comments' => [],
                    'deliveries' => [],
                ];
                $document['teams'][$actor->teamId]['items'][$reference->key()] = $item;
            }
            /** @var Item $item */

            return [$document, $this->itemSummary($item), true];
        });

        return $summary;
    }

    /** @return array{id: string, title: string, comment_count: int, latest_timestamp: int|null}|null */
    public function item(Actor $actor, ExternalReference $reference): ?array
    {
        if (!$this->isWorkItem($reference)) {
            return null;
        }

        /** @var array{id: string, title: string, comment_count: int, latest_timestamp: int|null}|null $summary */
        $summary = $this->locked(function (array $document) use ($actor, $reference): array {
            $item = $this->findItem($document, $actor->teamId, $reference);
            if ($item === null) {
                return [$document, null, false];
            }
            /** @var Item $item */

            return [$document, $this->itemSummary($item), false];
        });

        return $summary;
    }

    public function allows(
        Actor $actor,
        ExternalReference $reference,
        CommentOperation $operation,
        ?string $commentId = null,
    ): bool {
        if (!$this->isWorkItem($reference)) {
            return false;
        }

        /** @var bool $allowed */
        $allowed = $this->locked(function (array $document) use ($actor, $reference, $operation, $commentId): array {
            $item = $this->findItem($document, $actor->teamId, $reference);
            if ($item === null) {
                return [$document, false, false];
            }
            /** @var Item $item */

            return [$document, $this->permitted($item, $actor, $operation, $commentId), false];
        });

        return $allowed;
    }

    public function page(CommentQuery $query): CommentPage
    {
        /** @var CommentPage $page */
        $page = $this->locked(function (array $document) use ($query): array {
            $item = $this->requiredItem($document, $query->actor, $query->reference, CommentOperation::Read);
            /** @var Item $item */
            $comments = $this->commentsFor($item, $query->threadRootId);
            /** @var list<Comment> $comments */
            $cursor = $this->decodeCursor($query->cursor, $query->reference, $query->threadRootId);
            $comments = $this->afterCursor($comments, $cursor, $query->threadRootId !== null);
            /** @var list<Comment> $comments */
            $page = array_slice($comments, 0, $query->limit);
            $last = $page[count($page) - 1] ?? null;
            $next = count($comments) > $query->limit
                ? $this->encodeCursor(
                    $last ?? throw new \LogicException('A non-empty page needs a final comment.'),
                    $query->reference,
                    $query->threadRootId,
                )
                : null;

            return [$document, new CommentPage($page, $next), false];
        });

        return $page;
    }

    public function post(CommentWrite $write): Comment
    {
        if ($write->commentId !== null) {
            throw new \InvalidArgumentException('Posting a comment cannot select an existing comment ID.');
        }

        /** @var Comment $comment */
        $comment = $this->locked(function (array $document) use ($write): array {
            $item = $this->requiredItem($document, $write->actor, $write->reference, CommentOperation::Post);
            /** @var Item $item */
            $duplicate = $this->duplicateComment($item, $write->deliveryId, CommentOperation::Post, $write->actor);
            if ($duplicate !== null) {
                return [$document, $duplicate, false];
            }
            $this->assertFreshDelivery($item, $write->deliveryId, CommentOperation::Post);
            if (count($item['comments']) >= self::MAX_COMMENTS) {
                throw new \RuntimeException('This demo work item has reached its comment limit.');
            }
            if ($write->threadRootId !== null) {
                $root = $this->comment($item, $write->threadRootId);
                if ($root === null || $root->threadRootId !== null) {
                    throw new \InvalidArgumentException('A reply must belong to an existing root comment.');
                }
            }

            $comment = Comment::make(
                $this->commentId($write->actor, $write->reference, $write->deliveryId),
                Sender::slack($write->actor->userId),
                $write->body,
                $this->now(),
            );
            if ($write->threadRootId !== null) {
                $comment = $comment->inThread($write->threadRootId);
            }
            $this->assertCommentSize($comment);
            $item['comments'][$comment->id] = $comment->toArray();
            $this->remember($item, $write->deliveryId, CommentOperation::Post, $write->actor->userId, $comment);
            $document['teams'][$write->actor->teamId]['items'][$write->reference->key()] = $item;

            return [$document, $comment, true];
        });

        return $comment;
    }

    public function edit(CommentWrite $write): Comment
    {
        if ($write->commentId === null) {
            throw new \InvalidArgumentException('Editing a comment requires its ID.');
        }

        /** @var Comment $comment */
        $comment = $this->locked(function (array $document) use ($write): array {
            $item = $this->requiredItem(
                $document,
                $write->actor,
                $write->reference,
                CommentOperation::Edit,
                $write->commentId,
            );
            /** @var Item $item */
            $duplicate = $this->duplicateComment($item, $write->deliveryId, CommentOperation::Edit, $write->actor);
            if ($duplicate !== null) {
                return [$document, $duplicate, false];
            }
            $this->assertFreshDelivery($item, $write->deliveryId, CommentOperation::Edit);
            $existing = $this->comment($item, $write->commentId);
            if ($existing === null) {
                throw new \RuntimeException('The comment no longer exists.');
            }
            $comment = Comment::make($existing->id, $existing->sender, $write->body, $existing->timestamp);
            if ($existing->threadRootId !== null) {
                $comment = $comment->inThread($existing->threadRootId);
            }
            $comment = $comment->editedAt($this->now());
            $this->assertCommentSize($comment);
            $item['comments'][$comment->id] = $comment->toArray();
            $this->remember($item, $write->deliveryId, CommentOperation::Edit, $write->actor->userId, $comment);
            $document['teams'][$write->actor->teamId]['items'][$write->reference->key()] = $item;

            return [$document, $comment, true];
        });

        return $comment;
    }

    public function delete(CommentDelete $delete): void
    {
        $this->locked(function (array $document) use ($delete): array {
            $item = $this->findItem($document, $delete->actor->teamId, $delete->reference);
            if ($item === null) {
                throw new \RuntimeException('Comment access is not permitted for this work item.');
            }
            /** @var Item $item */
            if ($this->hasDelivery($item, $delete->deliveryId, CommentOperation::Delete, $delete->actor)) {
                return [$document, null, false];
            }
            if (!$this->permitted($item, $delete->actor, CommentOperation::Delete, $delete->commentId)) {
                throw new \RuntimeException('Comment access is not permitted for this work item.');
            }
            $this->assertFreshDelivery($item, $delete->deliveryId, CommentOperation::Delete);
            $comment = $this->comment($item, $delete->commentId);
            if ($comment === null) {
                throw new \RuntimeException('The comment no longer exists.');
            }
            unset($item['comments'][$comment->id]);
            if ($comment->threadRootId === null) {
                foreach ($item['comments'] as $id => $data) {
                    if (is_string($data['thread_root_id'] ?? null) && $data['thread_root_id'] === $comment->id) {
                        unset($item['comments'][$id]);
                    }
                }
            }
            $this->remember($item, $delete->deliveryId, CommentOperation::Delete, $delete->actor->userId);
            $document['teams'][$delete->actor->teamId]['items'][$delete->reference->key()] = $item;

            return [$document, null, true];
        });
    }

    /** @param callable(Document): array{Document, mixed, bool} $operation */
    private function locked(callable $operation): mixed
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException("Cannot create work item store directory: {$directory}");
            }
            chmod($directory, 0775);
        }
        $lock = fopen($this->path . '.lock', 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open the work item store lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock the work item store.');
            }
            $result = $operation($this->read());
            if (!is_array($result) || !is_array($result[0] ?? null) || !is_bool($result[2] ?? null)) {
                throw new \RuntimeException('The work item transaction returned an invalid result.');
            }
            $document = $result[0];
            if ($result[2]) {
                $this->write($document);
            }

            return $result[1];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return Document */
    private function read(): array
    {
        if (!file_exists($this->path)) {
            return ['version' => 1, 'teams' => []];
        }
        $json = file_get_contents($this->path);
        if ($json === false || strlen($json) > self::MAX_DOCUMENT_BYTES) {
            throw new \RuntimeException('The work item store cannot be read safely.');
        }
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'The work item store JSON is corrupt; it was left untouched.',
                previous: $exception,
            );
        }
        if (!is_array($document) || ($document['version'] ?? null) !== 1 || !is_array($document['teams'] ?? null)) {
            throw new \RuntimeException('The work item store schema is invalid; it was left untouched.');
        }
        foreach ($document['teams'] as $team) {
            if (!is_array($team) || !is_array($team['items'] ?? null)) {
                throw new \RuntimeException('The work item store schema is invalid; it was left untouched.');
            }
            foreach ($team['items'] as $item) {
                $this->validateItem($item);
            }
        }

        /** @var Document $document */
        return $document;
    }

    /** @param Document $document */
    private function write(array $document): void
    {
        try {
            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Cannot encode the work item store.', previous: $exception);
        }
        if (strlen($json) > self::MAX_DOCUMENT_BYTES) {
            throw new \RuntimeException('The work item store reached its safe payload limit.');
        }
        $temporary = tempnam(dirname($this->path), basename($this->path) . '.');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot prepare an atomic work item update.');
        }
        $file = fopen($temporary, 'wb');
        if ($file === false) {
            unlink($temporary);
            throw new \RuntimeException('Cannot write an atomic work item update.');
        }
        try {
            if (fwrite($file, $json . PHP_EOL) === false || !fflush($file)) {
                throw new \RuntimeException('Cannot flush the work item update.');
            }
            if (function_exists('fsync') && !fsync($file)) {
                throw new \RuntimeException('Cannot synchronize the work item update.');
            }
        } finally {
            fclose($file);
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $this->path)) {
            unlink($temporary);
            throw new \RuntimeException('Cannot replace the work item store atomically.');
        }
    }

    /** @param Document $document @return Item|null */
    private function findItem(array $document, string $teamId, ExternalReference $reference): ?array
    {
        if (!$this->isWorkItem($reference)) {
            return null;
        }
        $item = $document['teams'][$teamId]['items'][$reference->key()] ?? null;
        if (!is_array($item)) {
            return null;
        }
        return $item;
    }

    /** @param Document $document @return Item */
    private function requiredItem(
        array $document,
        Actor $actor,
        ExternalReference $reference,
        CommentOperation $operation,
        ?string $commentId = null,
    ): array {
        $item = $this->findItem($document, $actor->teamId, $reference);
        /** @var Item|null $item */
        if ($item === null) {
            throw new \RuntimeException('Comment access is not permitted for this work item.');
        }
        if (!$this->permitted($item, $actor, $operation, $commentId)) {
            throw new \RuntimeException('Comment access is not permitted for this work item.');
        }

        return $item;
    }

    /** @param Item $item */
    private function permitted(array $item, Actor $actor, CommentOperation $operation, ?string $commentId): bool
    {
        if ($operation === CommentOperation::Read || $operation === CommentOperation::Post) {
            return true;
        }
        if ($commentId === null) {
            return false;
        }
        $comment = $this->comment($item, $commentId);

        return $comment !== null && ($comment->sender->toArray()['user_id'] ?? null) === $actor->userId;
    }

    /** @param Item $item @return list<Comment> */
    private function commentsFor(array $item, ?string $threadRootId): array
    {
        $comments = array_map(fn(array $data): Comment => Comment::fromArray($data), $item['comments']);
        if ($threadRootId !== null) {
            $root = $comments[$threadRootId] ?? null;
            if (!$root instanceof Comment || $root->threadRootId !== null) {
                throw new \InvalidArgumentException('A thread must name an existing root comment.');
            }
            $comments = array_filter(
                $comments,
                static fn(Comment $comment): bool => $comment->threadRootId === $threadRootId,
            );
            usort(
                $comments,
                static fn(Comment $left, Comment $right): int => (
                    [$left->timestamp, $left->id] <=> [$right->timestamp, $right->id]
                ),
            );

            return array_values($comments);
        }
        $roots = array_filter($comments, static fn(Comment $comment): bool => $comment->threadRootId === null);
        usort(
            $roots,
            static fn(Comment $left, Comment $right): int => (
                [$right->timestamp, $right->id] <=> [$left->timestamp, $left->id]
            ),
        );

        return array_values(array_map(fn(Comment $root): Comment => $this->withReplyPreview($root, $comments), $roots));
    }

    /** @param array<string, Comment> $comments */
    private function withReplyPreview(Comment $root, array $comments): Comment
    {
        $replies = array_values(array_filter(
            $comments,
            static fn(Comment $comment): bool => $comment->threadRootId === $root->id,
        ));
        if ($replies === []) {
            return $root;
        }
        usort(
            $replies,
            static fn(Comment $left, Comment $right): int => (
                [$left->timestamp, $left->id] <=> [$right->timestamp, $right->id]
            ),
        );
        $users = array_map(static fn(Comment $reply): Sender => $reply->sender, array_slice($replies, 0, 2));

        return $root->replies(count($replies), $replies[array_key_last($replies)]->timestamp, ...$users);
    }

    /** @param list<Comment> $comments @param array{timestamp: int, id: string}|null $cursor @return list<Comment> */
    private function afterCursor(array $comments, ?array $cursor, bool $ascending): array
    {
        if ($cursor === null) {
            return $comments;
        }
        return array_values(array_filter($comments, static function (Comment $comment) use ($cursor, $ascending): bool {
            $comparison = [$comment->timestamp, $comment->id] <=> [$cursor['timestamp'], $cursor['id']];

            return $ascending ? $comparison > 0 : $comparison < 0;
        }));
    }

    private function encodeCursor(Comment $comment, ExternalReference $reference, ?string $threadRootId): string
    {
        return rtrim(
            strtr(
                base64_encode(json_encode([
                    'v' => 1,
                    'k' => $reference->key(),
                    'r' => $threadRootId,
                    't' => $comment->timestamp,
                    'i' => $comment->id,
                ], JSON_THROW_ON_ERROR)),
                '+/',
                '-_',
            ),
            '=',
        );
    }

    /** @return array{timestamp: int, id: string}|null */
    private function decodeCursor(?string $cursor, ExternalReference $reference, ?string $threadRootId): ?array
    {
        if ($cursor === null) {
            return null;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($json === false) {
            throw new \InvalidArgumentException('The comment cursor is invalid.');
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The comment cursor is invalid.', previous: $exception);
        }
        if (
            !is_array($data)
            || ($data['v'] ?? null) !== 1
            || ($data['k'] ?? null) !== $reference->key()
            || ($data['r'] ?? null) !== $threadRootId
            || !is_int($data['t'] ?? null)
            || !is_string($data['i'] ?? null)
        ) {
            throw new \InvalidArgumentException('The comment cursor does not match this query.');
        }

        return ['timestamp' => $data['t'], 'id' => $data['i']];
    }

    /** @param Item $item */
    private function duplicateComment(
        array $item,
        string $deliveryId,
        CommentOperation $operation,
        Actor $actor,
    ): ?Comment {
        $delivery = $item['deliveries'][$deliveryId] ?? null;
        if ($delivery === null) {
            return null;
        }
        if (
            $delivery['operation'] !== $operation->value
            || $delivery['actor'] !== $actor->userId
            || !is_array($delivery['comment'] ?? null)
        ) {
            throw new \RuntimeException('A delivery ID cannot be reused for a different comment operation.');
        }

        return Comment::fromArray($delivery['comment']);
    }

    /** @param Item $item */
    private function hasDelivery(array $item, string $deliveryId, CommentOperation $operation, Actor $actor): bool
    {
        $delivery = $item['deliveries'][$deliveryId] ?? null;
        if ($delivery === null) {
            return false;
        }
        if ($delivery['operation'] !== $operation->value || $delivery['actor'] !== $actor->userId) {
            throw new \RuntimeException('A delivery ID cannot be reused for a different comment operation.');
        }

        return true;
    }

    /** @param Item $item */
    private function assertFreshDelivery(array $item, string $deliveryId, CommentOperation $operation): void
    {
        if (isset($item['deliveries'][$deliveryId])) {
            throw new \RuntimeException("The {$operation->value} delivery was already handled.");
        }
    }

    /** @param Item $item @param-out Item $item */
    private function remember(
        array &$item,
        string $deliveryId,
        CommentOperation $operation,
        string $actorId,
        ?Comment $comment = null,
    ): void {
        $item['deliveries'][$deliveryId] = [
            'operation' => $operation->value,
            'actor' => $actorId,
            'comment' => $comment?->toArray(),
        ];
        if (count($item['deliveries']) > self::MAX_DELIVERIES) {
            $item['deliveries'] = array_slice($item['deliveries'], -self::MAX_DELIVERIES, null, true);
        }
    }

    /** @param Item $item */
    private function comment(array $item, string $id): ?Comment
    {
        $data = $item['comments'][$id] ?? null;

        return is_array($data) ? Comment::fromArray($data) : null;
    }

    /** @param Item $item @return array{id: string, title: string, comment_count: int, latest_timestamp: int|null} */
    private function itemSummary(array $item): array
    {
        $latest = null;
        foreach ($item['comments'] as $data) {
            $timestamp = $data['timestamp'] ?? null;
            if (is_int($timestamp) && ($latest === null || $timestamp > $latest)) {
                $latest = $timestamp;
            }
        }

        return [
            'id' => $item['id'],
            'title' => $item['title'],
            'comment_count' => count($item['comments']),
            'latest_timestamp' => $latest,
        ];
    }

    private function commentId(Actor $actor, ExternalReference $reference, string $deliveryId): string
    {
        return 'c_' . substr(hash('sha256', $actor->teamId . "\0" . $reference->key() . "\0" . $deliveryId), 0, 22);
    }

    private function isWorkItem(ExternalReference $reference): bool
    {
        return $reference->type === self::REFERENCE_TYPE;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    private static function text(string $value, string $field): void
    {
        if (trim($value) === '' || mb_strlen($value) > 255) {
            throw new \InvalidArgumentException("{$field} needs between 1 and 255 characters.");
        }
    }

    private function assertCommentSize(Comment $comment): void
    {
        try {
            $encoded = json_encode($comment->toArray(), JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The comment cannot be persisted.', previous: $exception);
        }
        if (strlen($encoded) > 60_000) {
            throw new \InvalidArgumentException('A demo comment is too large to persist.');
        }
    }

    private function validateItem(mixed $item): void
    {
        if (
            !is_array($item)
            || !is_string($item['id'] ?? null)
            || !is_string($item['title'] ?? null)
            || ($item['type'] ?? null) !== self::REFERENCE_TYPE
            || !is_array($item['comments'] ?? null)
            || !is_array($item['deliveries'] ?? null)
            || count($item['comments']) > self::MAX_COMMENTS
            || count($item['deliveries']) > self::MAX_DELIVERIES
        ) {
            throw new \RuntimeException('The work item store schema is invalid; it was left untouched.');
        }
        $comments = [];
        foreach ($item['comments'] as $id => $data) {
            if (!is_string($id) || !is_array($data)) {
                throw new \RuntimeException('The work item store schema is invalid; it was left untouched.');
            }
            try {
                $comment = Comment::fromArray($data);
            } catch (\InvalidArgumentException $exception) {
                throw new \RuntimeException(
                    'The work item store schema is invalid; it was left untouched.',
                    previous: $exception,
                );
            }
            if ($comment->id !== $id || !is_string($comment->sender->toArray()['user_id'] ?? null)) {
                throw new \RuntimeException('The work item store schema is invalid; it was left untouched.');
            }
            $comments[$id] = $comment;
        }
        foreach ($comments as $comment) {
            if (
                $comment->threadRootId !== null
                && (
                    !isset($comments[$comment->threadRootId])
                    || $comments[$comment->threadRootId]->threadRootId !== null
                )
            ) {
                throw new \RuntimeException(
                    'The work item store has an invalid comment thread; it was left untouched.',
                );
            }
        }
        foreach ($item['deliveries'] as $delivery) {
            if (
                !is_array($delivery)
                || !is_string($delivery['operation'] ?? null)
                || !is_string($delivery['actor'] ?? null)
                || !in_array($delivery['operation'], array_column(CommentOperation::cases(), 'value'), true)
                || ($delivery['comment'] ?? null) !== null && !is_array($delivery['comment'])
            ) {
                throw new \RuntimeException('The work item store schema is invalid; it was left untouched.');
            }
        }
    }
}
