<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;
use AliesDev\PsalmTester\Result;
use PHPUnit\Framework\TestCase;

/**
 * --SKIPIF-- evaluation inside PsalmTester::run(). Skipped tests never reach Psalm, so the stub
 * binary only answers for the ones that run.
 */
final class SkipifTest extends TestCase
{
    public function testATestWithoutSkipifRuns(): void
    {
        self::assertNotSame(Outcome::Skipped, self::runWithSkipif(null)->outcome);
    }

    public function testATestRunsWhenItsScriptDoesNotEchoSkip(): void
    {
        self::assertNotSame(Outcome::Skipped, self::runWithSkipif('<?php // no output, test should run')->outcome);
    }

    public function testTheLeadingSkipTokenIsStrippedFromTheReason(): void
    {
        $result = self::runWithSkipif("<?php echo 'skip requires PHP 8.2+';");

        self::assertSame(Outcome::Skipped, $result->outcome);
        self::assertSame('requires PHP 8.2+', $result->reason);
    }

    public function testTheSkipTokenIsCaseInsensitive(): void
    {
        self::assertSame('because', self::runWithSkipif("<?php echo 'SKIP because';")->reason);
    }

    public function testTheScriptRunsInASeparateProcessSoDieDoesNotKillTheSuite(): void
    {
        // If die() ran in-process, this assertion (and the rest of the suite) would never execute.
        self::assertSame('died early', self::runWithSkipif("<?php echo 'skip died early'; die();")->reason);
    }

    public function testATestRunsWhenItsScriptDiesWithoutEchoingSkip(): void
    {
        self::assertNotSame(Outcome::Skipped, self::runWithSkipif('<?php die();')->outcome);
    }

    public function testTheScriptMayWriteToStderrFirst(): void
    {
        // A closed stderr pipe means the child's first stderr write raises SIGPIPE and kills
        // it before the skip echo ever runs. Real SKIPIF scripts commonly warn (or run under
        // display_errors=stderr) before deciding to skip, so stderr must stay usable.
        self::assertSame('after stderr', self::runWithSkipif("<?php fwrite(STDERR, 'warn'); echo 'skip after stderr';")->reason);
    }

    public function testRunReturnsOneResultPerTestInInputOrder(): void
    {
        $results = self::tester()->run([
            'skipped' => self::phpt("<?php echo 'skip nope';"),
            'plain' => self::phpt(null),
            'kept' => self::phpt('<?php // runs'),
        ]);

        self::assertSame(['skipped', 'plain', 'kept'], \array_keys($results));
        self::assertSame(Outcome::Skipped, $results['skipped']->outcome);
        self::assertSame('nope', $results['skipped']->reason);
        self::assertSame(Outcome::Passed, $results['plain']->outcome);
        self::assertSame(Outcome::Passed, $results['kept']->outcome);
    }

    public function testScriptsRunWithinTheConfiguredConcurrency(): void
    {
        $phpts = \array_fill(0, 4, self::phpt("<?php usleep(300000); echo 'skip slept';"));

        $start = \microtime(true);
        $results = self::tester()->withConcurrency(2)->run($phpts);
        $elapsed = \microtime(true) - $start;

        self::assertSame(['slept', 'slept', 'slept', 'slept'], \array_map(static fn(Result $r): ?string => $r->reason, $results));
        // 4 scripts at concurrency 2 take ~0.6s; serially they would take ~1.2s.
        self::assertLessThan(0.9, $elapsed, \sprintf('Expected roughly 2 concurrent rounds (~0.6s), got %.2fs.', $elapsed));
    }

    public function testNonPositiveConcurrencyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PsalmTester::create()->withConcurrency(0);
    }

    public function testScriptsRunInTheConfiguredWorkingDirectoryAndEnvironment(): void
    {
        $result = self::tester()
            ->withWorkingDirectory(__DIR__)
            ->withEnv(['PSALM_TESTER_SKIPIF_PROBE' => 'probed'])
            ->runOne(self::phpt("<?php echo 'skip ', basename(getcwd()), ' ', getenv('PSALM_TESTER_SKIPIF_PROBE');"));

        self::assertSame('tests probed', $result->reason);
    }

    private static function runWithSkipif(?string $skipif): Result
    {
        return self::tester()->runOne(self::phpt($skipif));
    }

    private static function phpt(?string $skipif): Phpt
    {
        // The stub reports nothing in "empty" mode, so a test that runs passes.
        return new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--stub-mode=empty', skipif: $skipif);
    }

    private static function tester(): PsalmTester
    {
        return PsalmTester::create()->withPsalm(__DIR__ . '/bin/psalm-stub')->withProgress(false);
    }
}
