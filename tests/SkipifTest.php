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
        $log = \tempnam(\sys_get_temp_dir(), 'psalm_tester_skipif_peak_');
        self::assertNotFalse($log);
        // Each script logs +1 on start and -1 on exit; the running sum's maximum is the peak.
        $script = \sprintf(
            "<?php file_put_contents(%1\$s, \"+1\\n\", FILE_APPEND | LOCK_EX); usleep(200000); file_put_contents(%1\$s, \"-1\\n\", FILE_APPEND | LOCK_EX);",
            \var_export($log, true),
        );

        try {
            self::tester()->withConcurrency(2)->run(\array_fill(0, 6, self::phpt($script)));

            $running = 0;
            $peak = 0;
            foreach (\file($log, \FILE_IGNORE_NEW_LINES) ?: [] as $delta) {
                $running += (int) $delta;
                $peak = \max($peak, $running);
            }
        } finally {
            @\unlink($log);
        }

        self::assertSame(2, $peak);
    }

    public function testScriptsAreWrittenToTheConfiguredTemporaryDirectory(): void
    {
        $dir = \sys_get_temp_dir() . '/psalm_tester_skipif_tmp_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($dir));

        try {
            $result = self::tester()->withTemporaryDirectory($dir)->runOne(self::phpt("<?php echo 'skip ', __DIR__;"));
        } finally {
            $leftovers = \glob($dir . '/*') ?: [];
            @\rmdir($dir);
        }

        self::assertSame(\realpath($dir), \realpath((string) $result->reason));
        self::assertSame([], $leftovers);
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
        return PsalmTester::create()->withPsalm(__DIR__ . '/bin/psalm-stub');
    }
}
