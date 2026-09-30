<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\TestCase;

/**
 * Covers --XFAIL-- end to end, through PsalmTester::run() rather than Result::fromAnalysis()
 * directly (see ResultTest for the unit-level mapping).
 */
final class PsalmTesterXfailTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/bin/psalm-stub';

    protected function setUp(): void
    {
        \putenv('STUB_MODE=echo_code');
    }

    protected function tearDown(): void
    {
        \putenv('STUB_MODE');
    }

    public function testAMismatchedXfailTestIsIncomplete(): void
    {
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH);

        $result = $tester->runOne(new Phpt(code: '<?php // x', expectation: Expectation::exact('wrong'), xfail: 'known limitation'));

        self::assertSame(Outcome::XFailed, $result->outcome);
        self::assertSame('known limitation', $result->reason);
        $this->expectException(IncompleteTestError::class);
        $result->assert();
    }

    public function testAMatchingXfailTestFailsAsXpass(): void
    {
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH);

        $result = $tester->runOne(new Phpt(code: '<?php // x', expectation: Expectation::exact('StubError on line 1: // x'), xfail: 'known limitation'));

        self::assertSame(Outcome::XPassed, $result->outcome);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('XPASS: (in-code test) now matches its expectation; remove --XFAIL-- (known limitation)');
        $result->assert();
    }

    /** The reason's trailing whitespace and blank lines are trimmed. */
    public function testFromFileParsedXfailWiresThroughToTheResult(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'psalm_test_xfail_');
        self::assertNotFalse($file);
        Assert::assertNotFalse(\file_put_contents($file, "--XFAIL--\nfilebased reason  \n\n--FILE--\n<?php // filebased\n--EXPECT--\nwrong\n"));

        try {
            $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->runOne(Phpt::fromFile($file));

            self::assertSame(Outcome::XFailed, $result->outcome);
            self::assertSame('filebased reason', $result->reason);
        } finally {
            @\unlink($file);
        }
    }
}
