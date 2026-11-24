<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;

// Запускается в режиме --dry-run: rector не правит код, а роняет lint, если нашёл что улучшить.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/common',
        __DIR__ . '/frontend',
        __DIR__ . '/backend',
        __DIR__ . '/console',
        __DIR__ . '/ws',
    ])
    ->withSkip([
        '*/runtime/*',
        '*/web/assets/*',
        '*/tests/_output/*',
        '*/tests/_support/_generated/*',
        // Конфликтует с phpstan-strict-rules (staticMethod.dynamicCall): assert* — статические методы,
        // поэтому вызываем их через self::, как требует phpstan и php-cs-fixer (php_unit_test_case_static_method_calls).
        PreferPHPUnitThisCallRector::class,
        // Codeception записывает актора в свойство $tester unit-теста из базового класса Unit:
        // private или readonly свойство он заполнить не может.
        PrivatizeFinalClassPropertyRector::class => ['*/tests/unit/*'],
        ReadOnlyPropertyRector::class => ['*/tests/unit/*'],
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
