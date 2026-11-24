<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in(__DIR__)
    ->exclude(['vendor', 'node_modules', 'e2e', 'tools', 'tests/_output'])
    ->notPath(['#/runtime/#', '#/web/assets/#', '#/tests/_output/#', '#/tests/_support/_generated/#'])
    ->append([__DIR__ . '/yii']);

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        '@PER-CS3x0:risky' => true,
        '@PHP8x3Migration' => true,
        '@PHP8x2Migration:risky' => true,
        '@PHPUnit10x0Migration:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        // ПР-4: assertEquals → assertSame и т. п.
        'php_unit_strict' => true,
        'php_unit_construct' => true,
        'php_unit_dedicate_assert' => true,
        'php_unit_method_casing' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'final_class' => true,
        'void_return' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters', 'match']],
    ])
    ->setFinder($finder);
