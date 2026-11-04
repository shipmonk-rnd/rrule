<?php declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // The differential tests call libical through FFI; they skip when ext-ffi is missing.
    ->ignoreErrorsOnExtensionAndPath('ext-ffi', __DIR__ . '/tests/Differential', [ErrorType::SHADOW_DEPENDENCY]);
