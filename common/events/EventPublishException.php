<?php

declare(strict_types=1);

namespace common\events;

use RuntimeException;

/** Событие не опубликовано: брокер недоступен. */
final class EventPublishException extends RuntimeException {}
