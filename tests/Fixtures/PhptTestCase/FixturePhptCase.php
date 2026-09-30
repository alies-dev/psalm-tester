<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests\Fixtures\PhptTestCase;

use AliesDev\PsalmTester\PhptTestCase;
use AliesDev\PsalmTester\PsalmTester;

/**
 * Run only in a PHPUnit subprocess by PhptTestCaseTest; the file name lacks the "Test.php"
 * suffix so the main suite's directory scan does not pick it up.
 */
final class FixturePhptCase extends PhptTestCase
{
    #[\Override]
    protected static function baseDir(): string
    {
        return __DIR__ . '/phpt';
    }

    #[\Override]
    protected static function createTester(): PsalmTester
    {
        return PsalmTester::create(psalmPath: \dirname(__DIR__, 2) . '/bin/psalm-stub', showProgress: false);
    }
}
