<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\forms\CommentForm;
use common\repositories\CommentRepositoryInterface;
use common\repositories\CommentStatus;
use common\security\RateLimiterInterface;
use common\security\RateLimiterUnavailableException;
use common\services\CommentService;
use Override;
use Yii;
use yii\base\Module;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

/** Лента и форма комментария гостя. */
final class CommentController extends Controller
{
    public const int PER_PAGE = 20;

    /** Лимит отправок с одного IP: 5 за минуту. */
    private const int LIMIT = 5;

    private const int WINDOW_SECONDS = 60;

    /** Без `$config`: лимит параметров — 5 (phpcs), а настраивать контроллер нечем. */
    public function __construct(
        string $id,
        Module $module,
        private readonly CommentRepositoryInterface $comments,
        private readonly CommentService $service,
        private readonly RateLimiterInterface $limiter,
    ) {
        parent::__construct($id, $module);
    }

    #[Override]
    public function behaviors(): array
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['create' => ['POST']],
            ],
        ];
    }

    /** `$page` без типа: `?page[]=1` приходит массивом, и типизированный параметр дал бы 400 вместо первой страницы (REG-G-04). */
    public function actionIndex(mixed $page = '1'): string
    {
        return $this->renderFeed(Yii::createObject(CommentForm::class), is_string($page) ? (int) $page : 1);
    }

    public function actionCreate(): Response|string
    {
        $allowed = $this->spendAttempt();
        $form = Yii::createObject(CommentForm::class);
        $form->load((array) $this->request->getBodyParams());
        if (!$allowed) {
            $this->response->setStatusCode(429);

            return $this->renderFeed($form, 1, Yii::t('app', 'comment.rate_limited'));
        }

        if ($form->validate()) {
            $this->service->create($form);
            Yii::$app->session->setFlash('success', Yii::t('app', 'comment.created'));

            return $this->redirect(['comment/index']);
        }

        $this->response->setStatusCode(422);

        return $this->renderFeed($form, 1);
    }

    /**
     * Засчитывает отправку в лимит IP. Любой POST тратит попытку, даже невалидный. Если счётчики
     * недоступны, отправка проходит без лимита: комментарий гостя важнее защиты от флуда.
     */
    private function spendAttempt(): bool
    {
        try {
            return $this->limiter->hit('comment:' . $this->request->getRemoteIP(), self::LIMIT, self::WINDOW_SECONDS);
        } catch (RateLimiterUnavailableException $rateLimiterUnavailableException) {
            Yii::error('Отправка без лимита: счётчики недоступны. ' . $rateLimiterUnavailableException->getMessage(), __METHOD__);

            return true;
        }
    }

    /** Лента с формой. $alert — сообщение над формой, например об исчерпанном лимите. */
    private function renderFeed(CommentForm $form, int $page, ?string $alert = null): string
    {
        return $this->render('index', [
            'page' => $this->comments->page(CommentStatus::Active, $page, self::PER_PAGE),
            'model' => $form,
            'alert' => $alert,
        ]);
    }
}
