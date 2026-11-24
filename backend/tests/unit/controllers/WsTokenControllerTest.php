<?php

declare(strict_types=1);

namespace backend\tests\unit\controllers;

use Codeception\Test\Unit;
use common\models\User;
use common\redis\RedisClientInterface;
use common\redis\RedisUnavailableException;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

final class WsTokenControllerTest extends Unit
{
    private const int ADMIN_ID = 7;

    #[TestDox('UNIT-WTC-01: create выдаёт токен на id админа, id сессии и его auth_key и отвечает JSON с полем token, Cache-Control: no-store')]
    public function testCreateIssuesTokenForAdminSession(): void
    {
        $stored = [];
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('setNxEx')->willReturnCallback(static function (string $key, string $claims) use (&$stored): void {
            $stored = [$key, $claims];
        });
        Yii::$container->set(RedisClientInterface::class, $redis);
        Yii::$app->user->setIdentity(new User(['id' => self::ADMIN_ID, 'auth_key' => 'auth-key-of-anna']));
        Yii::$app->session->open();
        Yii::$app->request->headers->set('X-Http-Method-Override', 'POST');

        $response = Yii::$app->runAction('ws-token/create');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame([200, 'no-store'], [$response->statusCode, $response->headers->get('Cache-Control')]);
        self::assertSame(
            ['ws-token:' . $this->tokenOf($response), json_encode(['userId' => self::ADMIN_ID, 'sessionId' => Yii::$app->session->getId(), 'authKey' => 'auth-key-of-anna'])],
            $stored,
        );
    }

    #[TestDox('UNIT-WTC-02: Redis недоступен → 503 и JSON с error = "unavailable"')]
    public function testRedisFailureIsUnavailable(): void
    {
        $redis = self::createStub(RedisClientInterface::class);
        $redis->method('setNxEx')->willThrowException(new RedisUnavailableException('connection refused'));
        Yii::$container->set(RedisClientInterface::class, $redis);
        Yii::$app->user->setIdentity(new User(['id' => self::ADMIN_ID, 'auth_key' => 'auth-key-of-anna']));
        Yii::$app->request->headers->set('X-Http-Method-Override', 'POST');

        $response = Yii::$app->runAction('ws-token/create');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame([503, ['error' => 'unavailable']], [$response->statusCode, $response->data]);
    }

    #[TestDox('UNIT-WTC-03: create только через POST: GET → 405')]
    public function testCreateAcceptsOnlyPost(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::never())->method('setNxEx');
        Yii::$container->set(RedisClientInterface::class, $redis);
        Yii::$app->user->setIdentity(new User(['id' => self::ADMIN_ID, 'auth_key' => 'auth-key-of-anna']));

        $this->expectException(MethodNotAllowedHttpException::class);

        Yii::$app->runAction('ws-token/create');
    }

    #[TestDox('UNIT-WTC-03: create только для вошедших: гость получает 401, токен не выдаётся')]
    public function testCreateIsForSignedInOnly(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::never())->method('setNxEx');
        Yii::$container->set(RedisClientInterface::class, $redis);
        Yii::$app->user->setIdentity(null);
        Yii::$app->request->setUrl('/api/ws-token');
        Yii::$app->request->headers->set('X-Http-Method-Override', 'POST');

        Yii::$app->runAction('ws-token/create');

        self::assertSame(401, Yii::$app->response->statusCode);
    }

    private function tokenOf(Response $response): string
    {
        /** @var array{token: string} $body */
        $body = $response->data;

        return $body['token'];
    }
}
