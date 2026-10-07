<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToScan(__DIR__.'/src', false)
    ->addPathToScan(__DIR__.'/tests', true)
    ->addPathToScan(__DIR__.'/scripts', true)
    ->addPathToScan(__DIR__.'/conformance', true)
    ->addPathToScan(__DIR__.'/smoke', true)
    // The Override attribute is a PHP 8.3 builtin; on 8.2 it is an ignored unknown attribute class.
    ->ignoreUnknownClasses(['Override'])
    // The generator is a script that the tests load with require; its functions are not autoloadable.
    ->ignoreUnknownFunctions([
        'Hushscript\\Scripts\\emitCatalog',
        'Hushscript\\Scripts\\emitHints',
        'Hushscript\\Scripts\\emitScopes',
        'Hushscript\\Scripts\\generate',
    ])
    // The coverage gate in scripts/ parses clover.xml; it is a dev script, not part of the package.
    ->ignoreErrorsOnExtensionAndPath('ext-simplexml', __DIR__.'/scripts', [ErrorType::SHADOW_DEPENDENCY])
    // Declared for the transcribe pipeline's duration probe (spec 8.2); nothing imports it yet.
    ->ignoreErrorsOnPackage('james-heinrich/getid3', [ErrorType::UNUSED_DEPENDENCY]);
