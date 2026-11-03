<?php declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // The libical oracle tests call libical through FFI; they skip when ext-ffi is missing.
    ->ignoreErrorsOnExtensionAndPath('ext-ffi', __DIR__ . '/tests/Oracle', [ErrorType::SHADOW_DEPENDENCY])
    // PHPStan type-inference fixtures; PHPStan itself is a phar, so its functions are unknown to the analyser.
    ->addPathToExclude(__DIR__ . '/tests/Type/data');
