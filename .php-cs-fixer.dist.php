<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/bin', __DIR__ . '/public', __DIR__ . '/tests'])
    ->exclude('assets')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => true,
        'single_quote' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'ordered_imports' => true,
    ])
    ->setFinder($finder);
