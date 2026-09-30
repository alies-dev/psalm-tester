<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\PsalmTest;
use PHPUnit\Framework\TestCase;

final class PsalmTestSkipReasonTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @\unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testGetSkipReasonReturnsNullWhenNoSkipifSection(): void
    {
        self::assertNull(PsalmTest::getSkipReason($this->writePhpt(<<<'PHPT'
                --FILE--
                <?php
                --EXPECT--
                PHPT)));
    }

    public function testGetSkipReasonReturnsNullWhenScriptDoesNotEchoSkip(): void
    {
        self::assertNull(PsalmTest::getSkipReason($this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php // no output, test should run
                --FILE--
                <?php
                --EXPECT--
                PHPT)));
    }

    public function testGetSkipReasonStripsLeadingSkipToken(): void
    {
        self::assertSame('requires PHP 8.2+', PsalmTest::getSkipReason($this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php echo 'skip requires PHP 8.2+';
                --FILE--
                <?php
                --EXPECT--
                PHPT)));
    }

    public function testGetSkipReasonIsCaseInsensitiveOnTheSkipToken(): void
    {
        self::assertSame('because', PsalmTest::getSkipReason($this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php echo 'SKIP because';
                --FILE--
                <?php
                --EXPECT--
                PHPT)));
    }

    public function testGetSkipReasonRunsScriptInASeparateProcessSoDieDoesNotKillTheSuite(): void
    {
        $reason = PsalmTest::getSkipReason($this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php echo 'skip died early'; die();
                --FILE--
                <?php
                --EXPECT--
                PHPT));

        // If die() ran in-process, this assertion (and the rest of the suite) would never execute.
        self::assertSame('died early', $reason);
    }

    public function testGetSkipReasonReturnsNullWhenScriptDiesWithoutEchoingSkip(): void
    {
        self::assertNull(PsalmTest::getSkipReason($this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php die();
                --FILE--
                <?php
                --EXPECT--
                PHPT)));
    }

    public function testGetSkipReasonsReturnsOneEntryPerFileInInputOrder(): void
    {
        $noSkip = $this->writePhpt(<<<'PHPT'
                --FILE--
                <?php
                --EXPECT--
                PHPT);
        $skipped = $this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php echo 'skip nope';
                --FILE--
                <?php
                --EXPECT--
                PHPT);
        $notSkipped = $this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php // runs
                --FILE--
                <?php
                --EXPECT--
                PHPT);

        $reasons = PsalmTest::getSkipReasons([$skipped, $noSkip, $notSkipped]);

        self::assertSame([$skipped, $noSkip, $notSkipped], \array_keys($reasons));
        self::assertSame('nope', $reasons[$skipped]);
        self::assertNull($reasons[$noSkip]);
        self::assertNull($reasons[$notSkipped]);
    }

    public function testGetSkipReasonIsAWrapperAroundGetSkipReasons(): void
    {
        $file = $this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php echo 'skip via wrapper';
                --FILE--
                <?php
                --EXPECT--
                PHPT);

        self::assertSame(PsalmTest::getSkipReasons([$file])[$file], PsalmTest::getSkipReason($file));
    }

    public function testGetSkipReasonsRunsWithinBoundedConcurrency(): void
    {
        $log = \tempnam(\sys_get_temp_dir(), 'psalm_tester_skipif_peak_');
        self::assertNotFalse($log);
        $this->tempFiles[] = $log;
        // Each script logs +1 on start and -1 on exit; the running sum's maximum is the peak.
        // Structural rather than a wall-time bound, which flakes on a loaded CI runner.
        $script = \sprintf(
            '<?php file_put_contents(%1$s, "+1\n", FILE_APPEND | LOCK_EX); usleep(200000); file_put_contents(%1$s, "-1\n", FILE_APPEND | LOCK_EX);',
            \var_export($log, true),
        );
        $files = [];
        for ($i = 0; $i < 6; $i++) {
            $files[] = $this->writePhpt("--SKIPIF--\n{$script}\n--FILE--\n<?php\n--EXPECT--\n");
        }

        $reasons = PsalmTest::getSkipReasons($files, concurrency: 2);

        self::assertSame(\array_fill(0, 6, null), \array_values($reasons));
        $running = 0;
        $peak = 0;
        foreach (\file($log, \FILE_IGNORE_NEW_LINES) ?: [] as $delta) {
            $running += (int) $delta;
            $peak = \max($peak, $running);
        }
        self::assertSame(2, $peak);
    }

    public function testGetSkipReasonsRejectsNonPositiveConcurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PsalmTest::getSkipReasons([], concurrency: 0);
    }

    public function testGetSkipReasonSurvivesTheScriptWritingToStderrFirst(): void
    {
        // A closed stderr pipe means the child's first stderr write raises SIGPIPE and kills
        // it before the skip echo ever runs. Real SKIPIF scripts commonly warn (or run under
        // display_errors=stderr) before deciding to skip, so stderr must stay usable.
        $reason = PsalmTest::getSkipReason($this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php fwrite(STDERR, 'warn'); echo 'skip after stderr';
                --FILE--
                <?php
                --EXPECT--
                PHPT));

        self::assertSame('after stderr', $reason);
    }

    private function writePhpt(string $contents): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'psalm_test_skipif_');
        self::assertNotFalse($file);
        self::assertNotFalse(\file_put_contents($file, $contents));
        $this->tempFiles[] = $file;

        return $file;
    }
}
