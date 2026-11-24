<?php

declare(strict_types=1);

namespace backend\controllers;

use backend\components\AdminAccessControl;
use backend\forms\LoginForm;
use backend\forms\LoginResult;
use common\security\SessionTerminator;
use Override;
use Yii;
use yii\base\Module;
use yii\filters\VerbFilter;
use yii\helpers\Url;
use yii\web\Controller;
use yii\web\Response;

/** Вход и выход админа. */
final class AuthController extends Controller
{
    /** Коды ответа на неудачный вход: форма с ошибкой показывается с этим статусом. */
    private const array FAILURE_STATUS = [
        LoginResult::Invalid->name => 422,
        LoginResult::TooManyAttempts->name => 429,
        LoginResult::Unavailable->name => 503,
    ];

    /** Путь от корня: после первого слэша нет второго слэша или обратного, во всём пути нет управляющих символов и пробелов. */
    private const string SAFE_PATH = '~^/(?![/\\\\])[^\x00-\x20\x7f]*$~D';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $id,
        Module $module,
        private readonly SessionTerminator $terminator,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    #[Override]
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AdminAccessControl::class,
                'rules' => [
                    ['actions' => ['login'], 'allow' => true],
                    ['actions' => ['logout'], 'allow' => true, 'roles' => ['@']],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['logout' => ['POST']],
            ],
        ];
    }

    public function actionLogin(): Response|string
    {
        if (!Yii::$app->user->getIsGuest()) {
            return $this->redirect(['comment/index']);
        }

        $form = Yii::createObject(LoginForm::class, [Yii::$app->user]);
        if (!$this->request->getIsPost()) {
            return $this->render('login', ['model' => $form]);
        }

        $form->load((array) $this->request->getBodyParams());
        $result = $form->login((string) $this->request->getRemoteIP());
        if ($result === LoginResult::Success) {
            return $this->redirect($this->safeReturnUrl());
        }

        $this->response->setStatusCode(self::FAILURE_STATUS[$result->name]);
        $form->password = '';

        return $this->render('login', ['model' => $form]);
    }

    /** WS закрывает соединения только этой сессии; id сессии читается до logout, который её уничтожает. */
    public function actionLogout(): Response
    {
        $this->terminator->sessionEnded(Yii::$app->session->getId());
        Yii::$app->user->logout();

        return $this->redirect(['auth/login']);
    }

    /**
     * Куда вернуть админа после входа: только путь на этом же хосте. Полный адрес этого хоста
     * сводится к пути, всё остальное (чужой хост, `//host`, `/\host`, `javascript:`, управляющие символы
     * и пробелы, которые браузер вырезает и получает `//host`) — на список.
     */
    private function safeReturnUrl(): string
    {
        $list = Url::to(['comment/index']);
        $returnUrl = Yii::$app->user->getReturnUrl($list);
        $ownHost = $this->request->getHostInfo() . '/';
        if (str_starts_with($returnUrl, $ownHost)) {
            $returnUrl = substr($returnUrl, strlen($ownHost) - 1);
        }

        return preg_match(self::SAFE_PATH, $returnUrl) === 1 ? $returnUrl : $list;
    }
}
