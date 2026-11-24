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
use PHPUnit\Framework\Attributes\TestDox;

final class ActiveRecordCommentRepositoryTest extends Unit
{
    protected UnitTester $tester;

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
