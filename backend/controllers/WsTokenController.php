<?php

declare(strict_types=1);

namespace backend\controllers;

use backend\components\AdminAccessControl;
use common\redis\RedisUnavailableException;
use common\security\WsTokenService;
use Override;
use Yii;
use yii\base\Module;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

/**
 * Одноразовый токен для подключения админки к WS-серверу (ADR 0002). В токене id админа, id сессии и auth_key
 * админа: WS-сервер не пустит по нему после logout этой сессии или после смены пароля.
 */
final class WsTokenController extends Controller
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $id,
        Module $module,
        private readonly WsTokenService $tokens,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    #[Override]
    public function behaviors(): array
    {
        return [
            'access' => AdminAccessControl::class,
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['create' => ['POST']]],
        ];
    }

    public function actionCreate(): Response
    {
        try {
            $admin = Yii::$app->user->getIdentity();
            $token = $this->tokens->issue((int) $admin?->getId(), Yii::$app->session->getId(), (string) $admin?->getAuthKey());
        } catch (RedisUnavailableException) {
            $this->response->setStatusCode(503);

            return $this->asJson(['error' => 'unavailable']);
        }

        return $this->asJson(['token' => $token]);
    }
}
