<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/migrations'])
    ->append([__DIR__ . '/bin/console', __DIR__ . '/public/index.php', __DIR__ . '/public/router.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PhpCsFixer' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => false,
        'php_unit_test_class_requires_covers' => false,
        'php_unit_internal_class' => false,
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false, 'import_constants' => false],
        'yoda_style' => false,
        // Project conventions that differ from the @PhpCsFixer preset:
        'concat_space' => ['spacing' => 'one'],
        'php_unit_method_casing' => ['case' => 'snake_case'],
        'single_line_empty_body' => false,
        'empty_loop_condition' => ['style' => 'for'],
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
        'phpdoc_types_order' => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none'],
    ])
    ->setFinder($finder);
