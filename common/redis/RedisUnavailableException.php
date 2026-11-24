<?php

declare(strict_types=1);

namespace common\redis;

use RuntimeException;

/** Redis не ответил: нет соединения или команда не прошла. */
final class RedisUnavailableException extends RuntimeException {}
