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

    private function writePhpt(string $contents): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'psalm_test_skipif_');
        self::assertNotFalse($file);
        self::assertNotFalse(\file_put_contents($file, $contents));
        $this->tempFiles[] = $file;

        return $file;
    }
}
