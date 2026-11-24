<?php

declare(strict_types=1);

namespace common\tests\unit\messages;

use Codeception\Test\Unit;
use PHPUnit\Framework\Attributes\TestDox;

final class MessageCatalogTest extends Unit
{
    #[TestDox('UNIT-MSG-01: ключи и тексты messages.json и переводов Yii совпадают в обе стороны')]
    public function testCatalogMatchesYiiTranslations(): void
    {
        /** @var array<string, string> $catalog */
        $catalog = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/tests/_data/messages.json'), true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, string> $translations */
        $translations = require dirname(__DIR__, 3) . '/messages/ru-RU/app.php';

        ksort($catalog);
        ksort($translations);

        self::assertSame($catalog, $translations);
    }
}
