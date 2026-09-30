<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests\Fixtures\PsalmPhptTestCase;

use AliesDev\PsalmTester\PsalmPhptTestCase;
use AliesDev\PsalmTester\PsalmTester;

/**
 * Run only in a PHPUnit subprocess by PsalmPhptTestCaseTest; the file name lacks the "Test.php"
 * suffix so the main suite's directory scan does not pick it up.
 */
final class FixturePhptCase extends PsalmPhptTestCase
{
    #[\Override]
    protected static function phptDirectory(): string
    {
        return __DIR__ . '/phpt';
    }

    #[\Override]
    protected static function tester(): PsalmTester
    {
        return PsalmTester::create()->withPsalm(\dirname(__DIR__, 2) . '/bin/psalm-stub');
    }
}
