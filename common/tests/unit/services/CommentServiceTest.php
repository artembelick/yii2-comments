<?php

declare(strict_types=1);

namespace common\tests\unit\services;

use Codeception\Test\Unit;
use common\forms\CommentForm;
use common\models\Comment;
use common\repositories\CommentRepositoryInterface;
use common\services\CommentService;
use common\text\CommentTextNormalizer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Clock\MockClock;

final class CommentServiceTest extends Unit
{
    private const string NOW = '2026-11-24 10:15:30+00:00';

    #[TestDox('UNIT-SVC-01: create передаёт в репозиторий нормализованные имя и текст и время из часов')]
    public function testCreateStoresNormalizedCommentAtClockTime(): void
    {
        $form = new CommentForm(new CommentTextNormalizer(), ['name' => '  Ян  ', 'text' => "\r\nпервая\r\nвторая \n"]);
        $created = new Comment();
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('create')
            ->with('Ян', "первая\nвторая", new DateTimeImmutable(self::NOW))
            ->willReturn($created);

        $comment = (new CommentService($comments, new MockClock(self::NOW)))->create($form);

        self::assertSame($created, $comment);
    }
}
