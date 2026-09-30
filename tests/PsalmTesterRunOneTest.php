<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Covers PsalmTester::runOne() and Result::assert(), the single-test path.
 * Stub-based cases exercise pass/fail/cleanup cheaply; a couple of cases run
 * real Psalm end-to-end against src/psalm.xml's default config.
 */
final class PsalmTesterRunOneTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/bin/psalm-stub';

    /** @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            foreach (\glob($dir . '/*') ?: [] as $leftover) {
                @\unlink($leftover);
            }
            @\rmdir($dir);
        }
        $this->scratchDirs = [];
    }

    public function testRunOnePassesWhenOutputMatchesConstraint(): void
    {
        $tester = self::createStubTester();

        // No exception/failure means the assertion inside assert() passed.
        $tester->runOne(new Phpt(
            code: '<?php // ok',
            expectation: Expectation::format('StubError on line 1: stub error for code_%s'),
        ))->assert();

        self::assertTrue(true);
    }

    public function testRunOneFailsAssertionWhenOutputDoesNotMatchConstraint(): void
    {
        $tester = self::createStubTester();

        $this->expectException(ExpectationFailedException::class);

        $tester->runOne(new Phpt(code: '<?php // mismatch', expectation: Expectation::exact('')))->assert();
    }

    public function testRunOneCleansUpTemporaryCodeFileOnSuccess(): void
    {
        $tempDir = $this->makeScratchDir();
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withTemporaryDirectory($tempDir);

        $tester->runOne(new Phpt(
            code: '<?php // cleanup',
            expectation: Expectation::format('StubError on line 1: stub error for code_%s'),
        ))->assert();

        self::assertSame([], \glob($tempDir . '/code_*'));
    }

    public function testRunOneCleansUpTemporaryCodeFileOnAssertionFailure(): void
    {
        $tempDir = $this->makeScratchDir();
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withTemporaryDirectory($tempDir);

        try {
            $tester->runOne(new Phpt(code: '<?php // mismatch', expectation: Expectation::exact('')))->assert();
            self::fail('Expected an assertion failure.');
        } catch (ExpectationFailedException) {
            // Expected: the constraint mismatch itself, not what's under test here.
        }

        self::assertSame([], \glob($tempDir . '/code_*'));
    }

    public function testRunOneRunsRealPsalmAndReportsNoErrorsForCleanCode(): void
    {
        $tester = PsalmTester::create();

        $tester->runOne(new Phpt(code: "<?php\n\$x = 1;\nvar_export(\$x);\n", expectation: Expectation::exact('')))->assert();

        self::assertTrue(true);
    }

    public function testRunOneIsolatesCacheAndEnvLikeRun(): void
    {
        $logDir = $this->makeScratchDir();

        try {
            \putenv('STUB_MODE=env_record');
            \putenv('STUB_ENV_LOG_DIR=' . $logDir);

            self::createStubTester()->runOne(new Phpt(code: '<?php // env', expectation: Expectation::exact('')))->assert();

            $files = \glob($logDir . '/*.json') ?: [];
            self::assertCount(1, $files, 'The stub should have been invoked exactly once.');

            /** @var array{XDG_CACHE_HOME: string} $record */
            $record = \json_decode((string) \file_get_contents($files[0]), true, flags: \JSON_THROW_ON_ERROR);

            // Same per-call cache-dir isolation run() gives its groups (see
            // PsalmTesterRunTest::testRunGivesEachGroupIsolatedCacheDirAndCleansUp).
            self::assertStringStartsWith(\sys_get_temp_dir() . '/psalm_test/cache_', $record['XDG_CACHE_HOME']);
        } finally {
            \putenv('STUB_MODE');
            \putenv('STUB_ENV_LOG_DIR');
        }
    }

    public function testRunOneRunsRealPsalmAndFormatsOffsetErrorLine(): void
    {
        $tester = PsalmTester::create();

        // codeFirstLine=1 here (raw code passed directly, not parsed from a .phpt file),
        // so the reported line matches the 1-indexed line inside $code verbatim.
        $tester->runOne(new Phpt(
            code: "<?php\n\$unused = 1;\n",
            expectation: Expectation::format('UnusedVariable on line 2: %s'),
        ))->assert();

        self::assertTrue(true);
    }

    public function testRunOneRunsRealPsalmAndShiftsErrorLineByCodeFirstLine(): void
    {
        $tester = PsalmTester::create();

        // As if $code started on line 10 of a .phpt file: line 2 of $code is reported as line 11.
        $tester->runOne(new Phpt(
            code: "<?php\n\$unused = 1;\n",
            expectation: Expectation::format('UnusedVariable on line 11: %s'),
            codeFirstLine: 10,
        ))->assert();

        self::assertTrue(true);
    }

    public function testRunOneWithRealPsalmToleratesArgsRepeatingTheConfiguredDefaults(): void
    {
        // Older suites repeat the full default arguments in --ARGS--; they are now appended to
        // the configured ones, so Psalm sees --no-progress and --no-diff twice.
        $result = PsalmTester::create()->runOne(new Phpt(
            code: "<?php\n\$unused = 1;\n",
            expectation: Expectation::format('UnusedVariable on line 2: %s'),
            arguments: '--no-progress --no-diff --config=' . \dirname(__DIR__) . '/src/psalm.xml',
        ));

        self::assertSame(Outcome::Passed, $result->outcome, $result->output);
    }

    public function testRunOneWithRealPsalmAcceptsAConfigPassedThroughWithArguments(): void
    {
        // 0.3's defaultArguments carried the config; ported to withArguments() as is, the bundled
        // default config must not be added as a second one ("Too many config files provided").
        $result = PsalmTester::create()
            ->withArguments('--no-progress', '--no-diff', '--config=' . \dirname(__DIR__) . '/src/psalm.xml')
            ->runOne(new Phpt(code: "<?php\n\$unused = 1;\n", expectation: Expectation::format('UnusedVariable on line 2: %s')));

        self::assertSame(Outcome::Passed, $result->outcome, (string) $result->reason . $result->output);
    }

    private static function createStubTester(): PsalmTester
    {
        return PsalmTester::create()->withPsalm(self::STUB_PATH);
    }

    private function makeScratchDir(): string
    {
        $dir = \sys_get_temp_dir() . '/psalm_tester_test_method_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($dir, 0777, true));
        $this->scratchDirs[] = $dir;

        return $dir;
    }
}
