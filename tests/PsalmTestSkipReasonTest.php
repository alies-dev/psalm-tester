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
        $files = [];
        for ($i = 0; $i < 4; $i++) {
            $files[] = $this->writePhpt(<<<'PHPT'
                    --SKIPIF--
                    <?php usleep(300000);
                    --FILE--
                    <?php
                    --EXPECT--
                    PHPT);
        }

        $start = \microtime(true);
        $reasons = PsalmTest::getSkipReasons($files, concurrency: 2);
        $elapsed = \microtime(true) - $start;

        self::assertSame([null, null, null, null], \array_values($reasons));
        // 4 files at concurrency=2 means 2 sequential batches of ~0.3s each;
        // serial execution would take ~1.2s, so this bounds it well below that.
        self::assertLessThan(0.9, $elapsed, \sprintf('Expected roughly 2 concurrent batches (~0.6s), got %.2fs.', $elapsed));
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
