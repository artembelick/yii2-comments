<?php

declare(strict_types=1);

namespace backend\controllers;

use backend\components\AdminAccessControl;
use common\forms\CommentTextForm;
use common\models\Comment;
use common\models\User;
use common\repositories\CommentRepositoryInterface;
use common\repositories\CommentStatus;
use common\services\CommentIsDeletedException;
use common\services\CommentNotFoundException;
use common\services\CommentSerializer;
use common\services\CommentService;
use common\services\UpdateResult;
use Override;
use Yii;
use yii\base\Module;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Комментарии в админке: список с вкладками «Активные» и «Удалённые», правка текста, удаление и восстановление.
 * JSON-список той же страницы клиент перечитывает после переподключения WS: события, пропущенные за время
 * разрыва, не хранятся (ADR 0002).
 */
final class CommentController extends Controller
{
    public const int PER_PAGE = 20;

    /** Без `$config`: лимит параметров — 5 (phpcs), а настраивать контроллер нечем. */
    public function __construct(
        string $id,
        Module $module,
        private readonly CommentRepositoryInterface $comments,
        private readonly CommentSerializer $serializer,
        private readonly CommentService $service,
    ) {
        parent::__construct($id, $module);
    }

    #[Override]
    public function behaviors(): array
    {
        return [
            'access' => AdminAccessControl::class,
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'update' => ['GET', 'POST'],
                    'delete' => ['POST'],
                    'restore' => ['POST'],
                ],
            ],
        ];
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
            'serializer' => $this->serializer,
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

    /**
     * Правка текста: GET — форма с текущим текстом, POST — сохранение и возврат в список.
     *
     * @throws NotFoundHttpException в том числе если комментарий исчез между чтением и сохранением
     * @throws ConflictHttpException комментарий удалён: сначала его восстанавливают
     */
    public function actionUpdate(int $id): Response|string
    {
        $comment = $this->findComment($id);
        if ($comment->isDeleted()) {
            throw new ConflictHttpException(Yii::t('app', 'admin.edit_deleted'));
        }

        $form = Yii::createObject(CommentTextForm::class);
        if (!$this->request->getIsPost()) {
            $form->text = $comment->text;

            return $this->render('update', ['comment' => $comment, 'model' => $form]);
        }

        // Без поля text форма остаётся пустой и не проходит проверку: молча оставить старый текст было бы «Изменений нет».

        $form->load((array) $this->request->getBodyParams());
        if (!$form->validate()) {
            $this->response->setStatusCode(422);

            return $this->render('update', ['comment' => $comment, 'model' => $form]);
        }

        try {
            $result = $this->service->updateText($id, $form, $this->admin());
        } catch (CommentIsDeletedException) {
            throw new ConflictHttpException(Yii::t('app', 'admin.edit_deleted'));
        } catch (CommentNotFoundException $commentNotFoundException) {
            throw $this->notFound($commentNotFoundException);
        }

        $this->flash($result === UpdateResult::Updated, 'admin.updated', 'admin.unchanged');

        return $this->redirect(['comment/index']);
    }

    /**
     * Удаление. Повторное удаление безопасно: флэш «уже удалён», удалившим остаётся первый админ.
     *
     * @throws NotFoundHttpException
     */
    public function actionDelete(int $id): Response
    {
        try {
            $deleted = $this->service->delete($id, $this->admin());
        } catch (CommentNotFoundException $commentNotFoundException) {
            throw $this->notFound($commentNotFoundException);
        }

        $this->flash($deleted, 'admin.deleted', 'admin.already_deleted');

        return $this->backToList();
    }

    /**
     * Восстановление. Повторное восстановление безопасно: флэш «уже активен».
     *
     * @throws NotFoundHttpException
     */
    public function actionRestore(int $id): Response
    {
        try {
            $restored = $this->service->restore($id);
        } catch (CommentNotFoundException $commentNotFoundException) {
            throw $this->notFound($commentNotFoundException);
        }

        $this->flash($restored, 'admin.restored', 'admin.already_active');

        return $this->backToList();
    }

    /**
     * @throws NotFoundHttpException
     */
    private function findComment(int $id): Comment
    {
        return $this->comments->findById($id) ?? throw new NotFoundHttpException();
    }

    /**
     * 404 без текста, как у findComment: сообщение исключения сервиса — для лога, а не для страницы ошибки.
     */
    private function notFound(CommentNotFoundException $commentNotFoundException): NotFoundHttpException
    {
        return new NotFoundHttpException(previous: $commentNotFoundException);
    }

    /** Вошедший админ: действия открыты только им (AdminAccessControl). */
    private function admin(): User
    {
        $admin = Yii::$app->user->getIdentity();
        assert($admin instanceof User);

        return $admin;
    }

    /**
     * Итог действия флэшем: изменение — success, «ничего не изменилось» — info. Вид флэша — класс алерта в layout.
     */
    private function flash(bool $changed, string $changedKey, string $noopKey): void
    {
        if ($changed) {
            Yii::$app->session->setFlash('success', Yii::t('app', $changedKey));
        } else {
            Yii::$app->session->setFlash('info', Yii::t('app', $noopKey));
        }
    }

    /**
     * Назад на ту вкладку и страницу, где админ нажал кнопку: форма строки присылает их скрытыми полями.
     * Неизвестная вкладка или не число — первая страница «Активных».
     */
    private function backToList(): Response
    {
        $status = $this->request->post('status');
        $page = $this->request->post('page');
        $tab = is_string($status) ? CommentStatus::tryFrom($status) : null;

        return $this->redirect([
            'comment/index',
            'status' => ($tab ?? CommentStatus::Active)->value,
            'page' => is_string($page) ? max(1, (int) $page) : 1,
        ]);
    }
}
