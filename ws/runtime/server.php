<?php

declare(strict_types=1);

/*
 * Entrypoint WS-сервера: только связывает статику Workerman с логикой из ws\*.
 * Линтер запрещает здесь ветвления и циклы и ограничивает файл 60 строками.
 * Один процесс: реестр соединений живёт в его памяти (docs/test-plans/unit.md, «WS-сервер»).
 */

use common\repositories\UserRepositoryInterface;
use common\security\WsTokenService;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;
use ws\ActiveAdminChecker;
use ws\ConnectionRegistry;
use ws\MessageHandler;
use ws\OriginPolicy;
use ws\runtime\WorkermanConnection;
use ws\runtime\WorkermanTimer;

/** @var Closure(string): string $env */
$env = require dirname(__DIR__, 2) . '/common/config/env.php';

defined('YII_DEBUG') || define('YII_DEBUG', $env('YII_DEBUG') === '1');
defined('YII_ENV') || define('YII_ENV', $env('YII_ENV'));

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
require dirname(__DIR__, 2) . '/common/config/bootstrap.php';

new yii\console\Application(require dirname(__DIR__) . '/config/main.php');

$handler = new MessageHandler(
    new ConnectionRegistry(),
    Yii::$container->get(WsTokenService::class),
    new ActiveAdminChecker(Yii::$container->get(UserRepositoryInterface::class), Yii::$app->getDb()),
    new WorkermanTimer(),
    new OriginPolicy(array_map(trim(...), explode(',', $env('WS_ALLOWED_ORIGINS')))),
);

// Кадр больше 8 КБ Workerman закрывает до буферизации (по умолчанию копит до 10 МБ); 4 КБ протокола — в MessageHandler.
TcpConnection::$defaultMaxPackageSize = 8192;
$worker = new Worker($env('WS_LISTEN'));
$worker->name = 'comments-ws';
$worker->count = 1;
$worker->onWebSocketConnect = static fn(TcpConnection $connection, Request $request) => $handler->onConnect(
    new WorkermanConnection($connection),
    $request->header('origin'),
);
$worker->onMessage = static fn(TcpConnection $connection, string $data) => $handler->onMessage(new WorkermanConnection($connection), $data);
$worker->onClose = static fn(TcpConnection $connection) => $handler->onClose(new WorkermanConnection($connection));

// Без daemonize Workerman сам печатает лог в stdout контейнера; файл лога не нужен.
Worker::$logFile = '/dev/null';
Worker::$pidFile = sys_get_temp_dir() . '/comments-ws.pid';

Worker::runAll();
