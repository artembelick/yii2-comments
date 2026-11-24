<?php

declare(strict_types=1);

namespace common\web;

/** Имена cookie сессий. У публичной части своё имя: сессия гостя не открывает админку (REG-G-31). */
final class SessionCookie
{
    public const string PUBLIC_SITE = 'comments-public';
}
