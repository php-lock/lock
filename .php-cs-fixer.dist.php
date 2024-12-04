<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__])
    ->exclude(['vendor']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP74Migration' => true,
        '@PHP74Migration:risky' => true,

        // required by PSR-12
        'concat_space' => [
            'spacing' => 'one',
        ],

        // disable some too strict rules
        'phpdoc_types_order' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'none',
        ],
        'single_line_throw' => false,
        'yoda_style' => [
            'equal' => false,
            'identical' => false,
        ],
        'native_constant_invocation' => [
            'include' => [
                // https://github.com/php/php-src/blob/php-8.4.0/ext/pcntl/pcntl.stub.php#L201
                'SIGALRM',
            ],
        ],
        'native_function_invocation' => false,
        'void_return' => false,
        'blank_line_before_statement' => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'exit'],
        ],
        'final_internal_class' => false,
        'combine_consecutive_issets' => false,
        'combine_consecutive_unsets' => false,
        'multiline_whitespace_before_semicolons' => false,
        'no_superfluous_elseif' => false,
        'ordered_class_elements' => false,
        'php_unit_internal_class' => false,
        'php_unit_test_class_requires_covers' => false,
        'phpdoc_add_missing_param_annotation' => false,
        'return_assignment' => false,
        'comment_to_phpdoc' => false,
        'general_phpdoc_annotation_remove' => [
            'annotations' => ['author', 'copyright', 'throws'],
        ],

        // fn => without curly brackets is less readable,
        // also prevent bounding of unwanted variables for GC
        'use_arrow_functions' => false,

        // TODO disable too strict rules for now
        'declare_strict_types' => false,
        'general_phpdoc_annotation_remove' => false,
        'php_unit_data_provider_static' => false,
        'php_unit_strict' => false,
        'phpdoc_to_comment' => false,
        'strict_comparison' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(sys_get_temp_dir() . '/php-cs-fixer.' . md5(__DIR__) . '.cache');
