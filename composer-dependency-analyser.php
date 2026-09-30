<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// command: composer dependency-analyser

$config = (new Configuration())
    // vimeo/psalm is required so this library can find and run ITS psalm binary via
    // InstalledVersions::getInstallPath(); it is never imported as a PHP class.
    ->ignoreErrorsOnPackage('vimeo/psalm', [ErrorType::UNUSED_DEPENDENCY]);

// #[\Override] (PHP 8.3+) is only read via reflection, so the missing class is harmless on PHP
// 8.2; ignoring it unconditionally would itself become an unused-ignore error once run on PHP
// 8.3+, where \Override is a real class.
if (\PHP_VERSION_ID < 80300) {
    $config = $config->ignoreUnknownClasses(['Override']);
}

return $config;
