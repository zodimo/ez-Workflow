<?php

declare(strict_types=1);

/**
 * @contact  zodimo@gmail.com
 */
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in(__DIR__.'/src')
;

return (new Config())
    ->setRules([
        '@PhpCsFixer' => true,
        '@PHP74Migration' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
        'phpdoc_to_comment' => false,
    ])
    ->setFinder($finder)
;
