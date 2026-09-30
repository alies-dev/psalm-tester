<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\PsalmTest;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\Constraint\StringMatchesFormatDescription;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Covers PsalmTester::test(), the single-file (non-batch) execution path.
 * Stub-based cases exercise pass/fail/cleanup cheaply; a couple of cases run
 * real Psalm end-to-end against src/psalm.xml's default config.
 */
final class PsalmTesterTestMethodTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/bin/psalm-stub';

    public function testTestPassesWhenOutputMatchesConstraint(): void
    {
        $tester = self::createStubTester();

        // No exception/failure means the assertion inside test() passed.
        $tester->test(new PsalmTest(
            code: '<?php // ok',
            constraint: new StringMatchesFormatDescription('StubError on line 1: stub error for code_%s'),
        ));

        self::assertTrue(true);
    }

    public function testTestFailsAssertionWhenOutputDoesNotMatchConstraint(): void
    {
        $tester = self::createStubTester();

        $this->expectException(ExpectationFailedException::class);

        $tester->test(new PsalmTest(code: '<?php // mismatch', constraint: new IsIdentical('')));
    }

    public function testTestCleansUpTemporaryCodeFileOnSuccess(): void
    {
        $tempDir = self::makeScratchDir();
        $tester = PsalmTester::create(psalmPath: self::STUB_PATH, temporaryDirectory: $tempDir, showProgress: false);

        $tester->test(new PsalmTest(
            code: '<?php // cleanup',
            constraint: new StringMatchesFormatDescription('StubError on line 1: stub error for code_%s'),
        ));

        self::assertSame([], \glob($tempDir . '/code_*'));
    }

    public function testTestCleansUpTemporaryCodeFileOnAssertionFailure(): void
    {
        $tempDir = self::makeScratchDir();
        $tester = PsalmTester::create(psalmPath: self::STUB_PATH, temporaryDirectory: $tempDir, showProgress: false);

        try {
            $tester->test(new PsalmTest(code: '<?php // mismatch', constraint: new IsIdentical('')));
            self::fail('Expected an assertion failure.');
        } catch (ExpectationFailedException) {
            // Expected: the constraint mismatch itself, not what's under test here.
        }

        self::assertSame([], \glob($tempDir . '/code_*'));
    }

    public function testTestRunsRealPsalmAndReportsNoErrorsForCleanCode(): void
    {
        $tester = PsalmTester::create(showProgress: false);

        $tester->test(new PsalmTest(code: "<?php\n\$x = 1;\nvar_export(\$x);\n", constraint: new IsIdentical('')));

        self::assertTrue(true);
    }

    public function testTestRunsRealPsalmAndFormatsOffsetErrorLine(): void
    {
        $tester = PsalmTester::create(showProgress: false);

        // codeFirstLine=1 here (raw code passed directly, not parsed from a .phpt file),
        // so the reported line matches the 1-indexed line inside $code verbatim.
        $tester->test(new PsalmTest(
            code: "<?php\n\$unused = 1;\n",
            constraint: new StringMatchesFormatDescription('UnusedVariable on line 2: %s'),
        ));

        self::assertTrue(true);
    }

    private static function createStubTester(): PsalmTester
    {
        return PsalmTester::create(psalmPath: self::STUB_PATH, showProgress: false);
    }

    private static function makeScratchDir(): string
    {
        $dir = \sys_get_temp_dir() . '/psalm_tester_test_method_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($dir, 0777, true));

        return $dir;
    }
}
