<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests\Fixtures\PhptTestCase;

use AliesDev\PsalmTester\PhptTestCase;
use AliesDev\PsalmTester\PsalmTester;

/**
 * Like FixturePhptCase, over a directory holding one malformed file.
 */
final class BrokenFixturePhptCase extends PhptTestCase
{
    #[\Override]
    protected static function baseDir(): string
    {
        return __DIR__ . '/broken';
    }

    #[\Override]
    protected static function createTester(): PsalmTester
    {
        return PsalmTester::create(psalmPath: \dirname(__DIR__, 2) . '/bin/psalm-stub', showProgress: false);
    }
}
