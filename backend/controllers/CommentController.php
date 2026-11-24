<?php

declare(strict_types=1);

namespace backend\controllers;

use backend\components\AdminAccessControl;
use common\repositories\CommentRepositoryInterface;
use common\repositories\CommentStatus;
use Override;
use Yii;
use yii\base\Module;
use yii\web\BadRequestHttpException;
use yii\web\Controller;

/** Список комментариев в админке: вкладки «Активные» и «Удалённые». */
final class CommentController extends Controller
{
    public const int PER_PAGE = 20;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $id,
        Module $module,
        private readonly CommentRepositoryInterface $comments,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    #[Override]
    public function behaviors(): array
    {
        return ['access' => AdminAccessControl::class];
    }

    /**
     * @throws BadRequestHttpException неизвестная вкладка
     */
    public function actionIndex(string $status = 'active', string $page = '1'): string
    {
        $tab = CommentStatus::tryFrom($status) ?? throw new BadRequestHttpException(Yii::t('app', 'admin.unknown_tab'));

        return $this->render('index', [
            'status' => $tab,
            'page' => $this->comments->page($tab, (int) $page, self::PER_PAGE),
        ]);
    }
}
