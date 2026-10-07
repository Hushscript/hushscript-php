<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration)
    ->addPathToScan(__DIR__.'/src', false)
    ->addPathToScan(__DIR__.'/tests', true)
    ->addPathToScan(__DIR__.'/scripts', true)
    ->addPathToScan(__DIR__.'/conformance', true)
    ->addPathToScan(__DIR__.'/smoke', true)
    // Declared for the transcribe pipeline's duration probe (spec 8.2); nothing imports it yet.
    ->ignoreErrorsOnPackage('james-heinrich/getid3', [ErrorType::UNUSED_DEPENDENCY])
    // Spec 2 lists Guzzle as the only HTTP dependency; the PSR packages and Guzzle's PSR-7 arrive with it.
    ->ignoreErrorsOnPackage('psr/http-client', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackage('psr/http-message', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackage('guzzlehttp/psr7', [ErrorType::SHADOW_DEPENDENCY]);
