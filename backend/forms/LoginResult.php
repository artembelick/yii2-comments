<?php

declare(strict_types=1);

namespace backend\forms;

/** Итог попытки входа. Контроллер отвечает по нему кодом: 302, 422, 429 или 503. */
enum LoginResult
{
    case Success;
    case Invalid;
    case TooManyAttempts;
    case Unavailable;
}
