<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$config = (new Configuration())
    ->addPathToScan(__DIR__.'/src', false)
    ->addPathToScan(__DIR__.'/tests', true)
    ->addPathToScan(__DIR__.'/scripts', true)
    ->addPathToScan(__DIR__.'/conformance', true)
    ->addPathToScan(__DIR__.'/examples', true)
    ->addPathToScan(__DIR__.'/smoke', true)
    // The runner's golden table is a script the golden test loads with require, as the runner does.
    ->ignoreUnknownClasses(['Hushscript\\Conformance\\Golden', 'Hushscript\\Conformance\\Goldens'])
    // The generator is a script that the tests load with require; its functions are not autoloadable.
    ->ignoreUnknownFunctions([
        'Hushscript\\Scripts\\emitCatalog',
        'Hushscript\\Scripts\\emitHints',
        'Hushscript\\Scripts\\emitScopes',
        'Hushscript\\Scripts\\generate',
    ])
    // The buy example is a script that its test loads with require; its functions are not autoloadable.
    ->ignoreUnknownFunctions(['mayHaveCharged'])
    // The sensitive-parameter guard tokenizes the sources; only the test suite needs the extension.
    ->ignoreErrorsOnExtensionAndPath('ext-tokenizer', __DIR__.'/tests', [ErrorType::SHADOW_DEPENDENCY])
    // The mutation baseline test reads PHPUnit's coverage and JUnit XML; only the test suite needs the extension.
    ->ignoreErrorsOnExtensionAndPath('ext-dom', __DIR__.'/tests', [ErrorType::SHADOW_DEPENDENCY]);

// The Override attribute is a PHP 8.3 builtin; on 8.2 it is an ignored unknown attribute class.
if (PHP_VERSION_ID < 80300) {
    $config->ignoreUnknownClasses(['Override']);
}

return $config;
