<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhptParsingTest extends TestCase
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

    public function testFromFileParsesFileAndExpectSections(): void
    {
        $test = Phpt::fromFile($this->writeTempFile(<<<'PHPT'
                --FILE--
                <?php
                $x = 1;
                --EXPECT--
                no errors
                PHPT));

        self::assertSame("<?php\n\$x = 1;", $test->code);
        self::assertSame('', $test->arguments);
        self::assertEquals(Expectation::exact('no errors'), $test->expectation);
    }

    public function testFromFileCapturesArgsSection(): void
    {
        $test = Phpt::fromFile($this->writeTempFile(<<<'PHPT'
                --ARGS--
                --no-cache
                --FILE--
                <?php
                --EXPECT--
                PHPT));

        self::assertSame('--no-cache', $test->arguments);
    }

    public function testFromFileCodeFirstLineTracksFileSectionOffset(): void
    {
        $test = Phpt::fromFile($this->writeTempFile(<<<'PHPT'
                --SKIPIF--
                <?php
                --ARGS--
                --no-cache
                --FILE--
                <?php
                $y = 2;
                --EXPECT--
                PHPT));

        // Line 1 --SKIPIF--, 2 body, 3 --ARGS--, 4 body, 5 --FILE--, 6 is the first code line.
        self::assertSame(6, $test->codeFirstLine);
    }

    public function testFromFileWithExpectfUsesFormatDescriptionConstraint(): void
    {
        $test = Phpt::fromFile($this->writeTempFile(<<<'PHPT'
                --FILE--
                <?php
                --EXPECTF--
                Trace on line %d: %s
                PHPT));

        self::assertEquals(Expectation::format("Trace on line %d: %s"), $test->expectation);
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>, string}>
     */
    public static function provideMalformedInput(): iterable
    {
        yield 'unsupported section' => ["--BOGUS--\nwhatever\n--FILE--\n<?php\n--EXPECT--\n", \InvalidArgumentException::class, '/BOGUS/'];
        yield 'missing FILE section' => ["--EXPECT--\nno errors\n", \LogicException::class, '/FILE section/'];
        yield 'missing EXPECT section' => ["--FILE--\n<?php\n", \LogicException::class, '/EXPECT\* section/'];
        yield 'no section delimiter first' => ["not a section header\n--FILE--\n<?php\n--EXPECT--\n", \LogicException::class, '/section delimiter/'];
        yield 'unimplemented CLEAN section' => ["--FILE--\n<?php\n--CLEAN--\nx\n--EXPECT--\n", \InvalidArgumentException::class, '/Section --CLEAN-- in .* is not supported by psalm-tester/'];
        yield 'unimplemented ENV section' => ["--FILE--\n<?php\n--ENV--\nx\n--EXPECT--\n", \InvalidArgumentException::class, '/Section --ENV-- in .* is not supported by psalm-tester/'];
        yield 'unimplemented INI section' => ["--FILE--\n<?php\n--INI--\nx\n--EXPECT--\n", \InvalidArgumentException::class, '/Section --INI-- in .* is not supported by psalm-tester/'];
        yield 'unimplemented EXPECT_EXTERNAL section' => ["--FILE--\n<?php\n--EXPECT_EXTERNAL--\nx\n", \InvalidArgumentException::class, '/Section --EXPECT_EXTERNAL-- in .* is not supported by psalm-tester/'];
        yield 'unimplemented EXPECTF_EXTERNAL section' => ["--FILE--\n<?php\n--EXPECTF_EXTERNAL--\nx\n", \InvalidArgumentException::class, '/Section --EXPECTF_EXTERNAL-- in .* is not supported by psalm-tester/'];
    }

    /**
     * @param class-string<\Throwable> $expectedException
     */
    #[DataProvider('provideMalformedInput')]
    public function testFromFileRejectsMalformedInput(string $contents, string $expectedException, string $messagePattern): void
    {
        $this->expectException($expectedException);
        $this->expectExceptionMessageMatches($messagePattern);

        Phpt::fromFile($this->writeTempFile($contents));
    }

    public function testFromFileCapturesTheSkipifScriptFromTheSameParse(): void
    {
        $withSkipif = Phpt::fromFile($this->writeTempFile(<<<'PHPT'
                --SKIPIF--
                <?php echo 'skip not today';
                --FILE--
                <?php
                --EXPECT--
                PHPT));
        $withoutSkipif = Phpt::fromFile($this->writeTempFile(<<<'PHPT'
                --FILE--
                <?php
                --EXPECT--
                PHPT));

        self::assertSame("<?php echo 'skip not today';", $withSkipif->skipif);
        self::assertNull($withoutSkipif->skipif);
    }

    public function testFromFileRecordsThePath(): void
    {
        $file = $this->writeTempFile("--FILE--\n<?php\n--EXPECT--\n");

        self::assertSame($file, Phpt::fromFile($file)->path);
        self::assertNull(Phpt::fromFile($file)->xfail);
    }

    public function testFromFileCapturesTheXfailReason(): void
    {
        $test = Phpt::fromFile($this->writeTempFile("--XFAIL--\nknown limitation: see #123  \n\n--FILE--\n<?php\n--EXPECT--\n"));

        self::assertSame('known limitation: see #123', $test->xfail);
    }

    public function testRunEvaluatesTheCarriedSkipifScriptWithoutRereadingTheFile(): void
    {
        $file = $this->writeTempFile(<<<'PHPT'
                --SKIPIF--
                <?php echo 'skip stale by now';
                --FILE--
                <?php
                --EXPECT--
                PHPT);
        $test = Phpt::fromFile($file);

        // If run() re-parsed the file instead of reusing $test->skipif (captured above, from
        // fromFile()'s one parse), this would have nothing to read.
        \unlink($file);

        $result = PsalmTester::create()->withPsalm(__DIR__ . '/bin/psalm-stub')->runOne($test);

        self::assertSame(Outcome::Skipped, $result->outcome);
        self::assertSame('stale by now', $result->reason);
    }

    public function testFromFileAcceptsATestDescriptionSection(): void
    {
        $test = Phpt::fromFile($this->writeTempFile("--TEST--\nnarrows array_values\n--FILE--\n<?php\n--EXPECT--\nok"));

        self::assertSame('<?php', $test->code);
        self::assertSame(4, $test->codeFirstLine);
    }

    public function testFromFileKeepsBlankAndZeroLinesVerbatim(): void
    {
        $test = Phpt::fromFile($this->writeTempFile("--FILE--\n<?php\n--EXPECT--\n\n0\nlast"));

        self::assertSame("\n0\nlast", $test->expectation->text);
    }

    private function writeTempFile(string $contents): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'psalm_test_parsing_');
        self::assertNotFalse($file);
        self::assertNotFalse(\file_put_contents($file, $contents));
        $this->tempFiles[] = $file;

        return $file;
    }
}
