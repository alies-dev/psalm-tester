<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\Result;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testFromAnalysisPassesWhenTheOutputMeetsTheExpectation(): void
    {
        $result = Result::fromAnalysis(self::phpt(Expectation::format('Trace on line %d: int')), 'Trace on line 3: int', []);

        self::assertSame(Outcome::Passed, $result->outcome);
        $result->assert();
    }

    public function testAFailedResultAssertsWithADiff(): void
    {
        $result = Result::fromAnalysis(self::phpt(Expectation::exact('expected')), 'actual', []);

        self::assertSame(Outcome::Failed, $result->outcome);
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('two strings are identical');

        $result->assert();
    }

    public function testASkippedResultMarksTheTestSkippedWithItsReason(): void
    {
        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('requires PHP 9');

        (new Result(self::phpt(Expectation::exact('')), Outcome::Skipped, reason: 'requires PHP 9'))->assert();
    }

    public function testAnErrorResultFailsWithItsReason(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('PsalmTimeout: group');

        (new Result(self::phpt(Expectation::exact('')), Outcome::Error, reason: 'PsalmTimeout: group [x]'))->assert();
    }

    public function testAnXFailedResultIsIncomplete(): void
    {
        $this->expectException(IncompleteTestError::class);

        (new Result(self::phpt(Expectation::exact('')), Outcome::XFailed, reason: 'known limitation'))->assert();
    }

    private static function phpt(Expectation $expectation): Phpt
    {
        return new Phpt(code: '<?php', expectation: $expectation);
    }
}
