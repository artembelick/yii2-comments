<?php

declare(strict_types=1);

namespace common\security;

use RuntimeException;

/** Счётчики лимита недоступны: вызывающий код решает, пропустить запрос или отказать. */
final class RateLimiterUnavailableException extends RuntimeException {}
