<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

// command: composer dependency-analyser

return (new Configuration())
    // #[\Override] (PHP 8.3+) is only read via reflection, so on PHP 8.2 the missing class is harmless.
    ->ignoreUnknownClasses(['Override']);
