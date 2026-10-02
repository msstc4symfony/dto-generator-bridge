<?php declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->append([__FILE__, __DIR__ . '/rector.php'])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@PHP74Migration' => true,
        '@PHP74Migration:risky' => true,
        'declare_strict_types' => true,
        'phpdoc_align' => ['align' => 'left'],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'phpdoc_to_comment' => false,
        // PHP 7.4 has no native mixed, so a `@param mixed` is the only type a decoder boundary can declare.
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'linebreak_after_opening_tag' => false,
        'blank_line_after_opening_tag' => false,
        'concat_space' => ['spacing' => 'one'],
        'increment_style' => ['style' => 'post'],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
        'class_attributes_separation' => true,
        // Trailing commas in parameter lists are PHP 8.0 syntax; the package runs on 7.4.
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays']],
        'multiline_whitespace_before_semicolons' => ['strategy' => 'new_line_for_chained_calls'],
        'global_namespace_import' => ['import_classes' => true],
        'blank_line_before_statement' => ['statements' => ['declare', 'return']],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
;
