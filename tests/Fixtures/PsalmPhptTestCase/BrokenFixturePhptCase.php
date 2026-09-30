<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests\Fixtures\PsalmPhptTestCase;

use AliesDev\PsalmTester\PsalmPhptTestCase;
use AliesDev\PsalmTester\PsalmTester;

/**
 * Like FixturePhptCase, over a directory holding one malformed file.
 */
final class BrokenFixturePhptCase extends PsalmPhptTestCase
{
    #[\Override]
    protected static function phptDirectory(): string
    {
        return __DIR__ . '/broken';
    }

    #[\Override]
    protected static function tester(): PsalmTester
    {
        return PsalmTester::create()->withPsalm(\dirname(__DIR__, 2) . '/bin/psalm-stub');
    }
}
