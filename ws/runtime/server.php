<?php

declare(strict_types=1);

/*
 * Entrypoint WS-сервера: только связывает статику Workerman с логикой из ws\*.
 * Линтер запрещает здесь ветвления и циклы и ограничивает файл 60 строками.
 * Пока каркас: сервер принимает соединения на WS_LISTEN и ничего не делает. Логика приходит в срезе 04.
 */

use Workerman\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var Closure(string): string $env */
$env = require dirname(__DIR__, 2) . '/common/config/env.php';

$worker = new Worker($env('WS_LISTEN'));
$worker->name = 'comments-ws';
$worker->count = 1;

Worker::$logFile = '/dev/stderr';
Worker::$pidFile = sys_get_temp_dir() . '/comments-ws.pid';

Worker::runAll();
