<?php

declare(strict_types=1);

namespace common\tests\unit\repositories;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\Comment;
use common\repositories\ActiveRecordCommentRepository;
use common\repositories\CommentPage;
use common\repositories\CommentStatus;
use common\tests\UnitTester;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;

final class ActiveRecordCommentRepositoryTest extends Unit
{
    protected UnitTester $tester;

    #[TestDox('UNIT-REPO-01: create сохраняет автора, текст и created_at с микросекундами, поля правки и удаления пустые')]
    public function testCreateStoresNewActiveComment(): void
    {
        $repository = new ActiveRecordCommentRepository();

        $created = $repository->create('Ян', "первая\nвторая", new DateTimeImmutable('2026-11-24 10:15:30.123456+00:00'));

        $stored = Comment::findOne($created->id);
        self::assertInstanceOf(Comment::class, $stored);
        self::assertSame(
            [
                'author' => 'Ян',
                'text' => "первая\nвторая",
                'created_at' => '2026-11-24 10:15:30.123456+00',
                'updated_at' => null,
                'updated_by' => null,
                'deleted_at' => null,
                'deleted_by' => null,
            ],
            $stored->getAttributes(['author', 'text', 'created_at', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by']),
        );
    }

    #[TestDox('UNIT-REPO-02: findById для несуществующего id возвращает null')]
    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $existing = $this->tester->haveComment();
        $unknownId = $existing->id + 1_000_000;
        $repository = new ActiveRecordCommentRepository();

        $found = $repository->findById($unknownId);

        self::assertNull($found);
    }

    #[TestDox('UNIT-REPO-03: updateText активного меняет текст, updated_at и updated_by и возвращает true')]
    public function testUpdateTextChangesActiveComment(): void
    {
        $admin = $this->tester->haveAdmin();
        $comment = $this->tester->haveComment(['author' => 'Ян', 'text' => 'Старый текст']);
        $repository = new ActiveRecordCommentRepository();

        $updated = $repository->updateText($comment->id, 'Новый текст', $admin->id, new DateTimeImmutable('2026-11-24 12:00:00.5+00:00'));

        self::assertTrue($updated);
        self::assertSame(
            ['author' => 'Ян', 'text' => 'Новый текст', 'updated_at' => '2026-11-24 12:00:00.5+00', 'updated_by' => $admin->id],
            $this->stored($comment->id, ['author', 'text', 'updated_at', 'updated_by']),
        );
    }

    #[TestDox('UNIT-REPO-03: updateText удалённого ничего не меняет и возвращает false')]
    public function testUpdateTextLeavesDeletedComment(): void
    {
        $admin = $this->tester->haveAdmin();
        $comment = $this->tester->haveComment(['text' => 'Старый текст', 'deleted_at' => '2026-11-24 11:00:00+00', 'deleted_by' => $admin->id]);
        $repository = new ActiveRecordCommentRepository();

        $updated = $repository->updateText($comment->id, 'Новый текст', $admin->id, new DateTimeImmutable('2026-11-24 12:00:00+00:00'));

        self::assertFalse($updated);
        self::assertSame(
            ['text' => 'Старый текст', 'updated_at' => null, 'updated_by' => null],
            $this->stored($comment->id, ['text', 'updated_at', 'updated_by']),
        );
    }

    #[TestDox('UNIT-REPO-04: markDeleted — первый вызов true, второй другим админом false, deleted_by остаётся за первым')]
    public function testMarkDeletedIsConditional(): void
    {
        $first = $this->tester->haveAdmin();
        $second = $this->tester->haveAdmin();
        $comment = $this->tester->haveComment();
        $repository = new ActiveRecordCommentRepository();

        $results = [
            $repository->markDeleted($comment->id, $first->id, new DateTimeImmutable('2026-11-24 12:00:00+00:00')),
            $repository->markDeleted($comment->id, $second->id, new DateTimeImmutable('2026-11-24 12:05:00+00:00')),
        ];

        self::assertSame([true, false], $results);
        self::assertSame(
            ['deleted_at' => '2026-11-24 12:00:00+00', 'deleted_by' => $first->id],
            $this->stored($comment->id, ['deleted_at', 'deleted_by']),
        );
    }

    #[TestDox('UNIT-REPO-05: markRestored очищает deleted_at и deleted_by и возвращает true')]
    public function testMarkRestoredClearsDeletion(): void
    {
        $admin = $this->tester->haveAdmin();
        $comment = $this->tester->haveComment(['deleted_at' => '2026-11-24 11:00:00+00', 'deleted_by' => $admin->id]);
        $repository = new ActiveRecordCommentRepository();

        $restored = $repository->markRestored($comment->id);

        self::assertTrue($restored);
        self::assertSame(['deleted_at' => null, 'deleted_by' => null], $this->stored($comment->id, ['deleted_at', 'deleted_by']));
    }

    #[TestDox('UNIT-REPO-05: markRestored активного возвращает false')]
    public function testMarkRestoredOfActiveReturnsFalse(): void
    {
        $comment = $this->tester->haveComment();
        $repository = new ActiveRecordCommentRepository();

        $restored = $repository->markRestored($comment->id);

        self::assertFalse($restored);
        self::assertSame(['deleted_at' => null, 'deleted_by' => null], $this->stored($comment->id, ['deleted_at', 'deleted_by']));
    }

    #[TestDox('UNIT-REPO-06: page фильтрует по статусу, сортирует новые сверху и отдаёт perPage записей')]
    public function testPageFiltersSortsAndLimits(): void
    {
        $this->tester->haveNoComments();
        $admin = $this->tester->haveAdmin();
        $older = $this->tester->haveComment(['created_at' => '2026-11-24 09:00:00+00']);
        $newer = $this->tester->haveComment(['created_at' => '2026-11-24 10:00:00+00']);
        $newest = $this->tester->haveComment(['created_at' => '2026-11-24 11:00:00+00']);
        $deleted = $this->tester->haveComment([
            'created_at' => '2026-11-24 12:00:00+00',
            'deleted_at' => '2026-11-24 13:00:00+00',
            'deleted_by' => $admin->id,
        ]);
        $repository = new ActiveRecordCommentRepository();

        $pages = [
            'active' => $repository->page(CommentStatus::Active, 1, 2),
            'deleted' => $repository->page(CommentStatus::Deleted, 1, 2),
        ];

        self::assertSame(
            [
                'active' => ['ids' => [$newest->id, $newer->id], 'totalCount' => 3, 'page' => 1, 'pageCount' => 2],
                'deleted' => ['ids' => [$deleted->id], 'totalCount' => 1, 'page' => 1, 'pageCount' => 1],
            ],
            array_map($this->summary(...), $pages),
        );
        self::assertNotContains($older->id, array_column($pages['active']->items, 'id'));
    }

    #[TestDox('UNIT-REPO-07: страница за пределами → последняя, 0 и отрицательная → первая, пустая выборка → одна страница')]
    #[DataProvider('outOfRangePages')]
    public function testPageNumberIsClamped(int $comments, int $requested, int $expectedPage, int $expectedPageCount): void
    {
        $this->tester->haveNoComments();
        $this->tester->haveComments($comments);

        $repository = new ActiveRecordCommentRepository();

        $page = $repository->page(CommentStatus::Active, $requested, 2);

        self::assertSame([$expectedPage, $expectedPageCount], [$page->page, $page->pageCount]);
    }

    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function outOfRangePages(): array
    {
        return [
            'страница 999 из 2 → последняя' => [3, 999, 2, 2],
            'страница 0 → первая' => [3, 0, 1, 2],
            'страница -1 → первая' => [3, -1, 1, 2],
            'пустая выборка → страница 1 из 1' => [0, 5, 1, 1],
        ];
    }

    /**
     * Колонки комментария, как они лежат в БД после действия.
     *
     * @param list<string> $columns
     *
     * @return array<string, mixed>
     */
    private function stored(int $id, array $columns): array
    {
        $stored = Comment::findOne($id);
        self::assertInstanceOf(Comment::class, $stored);

        return $stored->getAttributes($columns);
    }

    /**
     * @return array{ids: list<int>, totalCount: int, page: int, pageCount: int}
     */
    private function summary(CommentPage $page): array
    {
        return [
            'ids' => array_map(static fn(Comment $comment): int => $comment->id, $page->items),
            'totalCount' => $page->totalCount,
            'page' => $page->page,
            'pageCount' => $page->pageCount,
        ];
    }
}
