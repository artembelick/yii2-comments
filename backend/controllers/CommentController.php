<?php

declare(strict_types=1);

namespace backend\controllers;

use backend\components\AdminAccessControl;
use common\repositories\CommentRepositoryInterface;
use common\repositories\CommentStatus;
use common\services\CommentSerializer;
use Override;
use Yii;
use yii\base\Module;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;

/**
 * Список комментариев в админке: вкладки «Активные» и «Удалённые». JSON-список той же страницы клиент
 * перечитывает после переподключения WS: события, пропущенные за время разрыва, не хранятся (ADR 0002).
 */
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
        private readonly CommentSerializer $serializer,
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

    /** JSON-страница вкладки для клиента админки: те же записи, что видит index. */
    public function actionList(string $status = 'active', string $page = '1'): Response
    {
        $tab = CommentStatus::tryFrom($status);
        if ($tab === null) {
            $this->response->setStatusCode(400);

            return $this->asJson(['error' => 'invalid_status']);
        }

        $comments = $this->comments->page($tab, (int) $page, self::PER_PAGE);

        return $this->asJson([
            'items' => array_map($this->serializer->toArray(...), $comments->items),
            'page' => $comments->page,
            'pageCount' => $comments->pageCount,
            'totalCount' => $comments->totalCount,
        ]);
    }
}
