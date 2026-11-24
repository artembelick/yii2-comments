<?php

declare(strict_types=1);

namespace common\tests\unit\web;

use Codeception\Test\Unit;
use common\web\PlaceholderController;
use PHPUnit\Framework\Attributes\TestDox;
use yii\base\Module;
use yii\web\Request;
use yii\web\Response;

final class PlaceholderControllerTest extends Unit
{
    #[TestDox('UNIT-SKEL-01: index отдаёт текст «Каркас работает»')]
    public function testIndexAnswersWithPlaceholderText(): void
    {
        $controller = new PlaceholderController('placeholder', new Module('test'), [
            'request' => new Request(),
            'response' => new Response(['charset' => 'UTF-8', 'version' => '1.1']),
        ]);

        $page = $controller->actionIndex();

        self::assertSame('Каркас работает', $page);
    }
}
