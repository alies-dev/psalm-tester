<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\ExpectationKind;
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\Attributes\TestWith;
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
        $test = Phpt::fromFile($this->writePhpt(<<<'PHPT'
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
        $test = Phpt::fromFile($this->writePhpt(<<<'PHPT'
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
        $test = Phpt::fromFile($this->writePhpt(<<<'PHPT'
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
        $test = Phpt::fromFile($this->writePhpt(<<<'PHPT'
                --FILE--
                <?php
                --EXPECTF--
                Trace on line %d: %s
                PHPT));

        self::assertEquals(Expectation::format("Trace on line %d: %s"), $test->expectation);
    }

    public function testFromFileWithExpectExternalReadsReferencedFile(): void
    {
        $externalFile = $this->writeTempFile('expected external output');
        $test = Phpt::fromFile($this->writePhpt(<<<PHPT
                --FILE--
                <?php
                --EXPECT_EXTERNAL--
                {$externalFile}
                PHPT));

        self::assertEquals(new Expectation(ExpectationKind::Exact, 'expected external output', $externalFile), $test->expectation);
    }

    public function testFromFileWithExpectfExternalReadsReferencedFile(): void
    {
        $externalFile = $this->writeTempFile('Trace on line %d: %s');
        $test = Phpt::fromFile($this->writePhpt(<<<PHPT
                --FILE--
                <?php
                --EXPECTF_EXTERNAL--
                {$externalFile}
                PHPT));

        self::assertEquals(new Expectation(ExpectationKind::Format, 'Trace on line %d: %s', $externalFile), $test->expectation);
    }

    public function testFromFileRejectsUnsupportedSection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/BOGUS/');

        Phpt::fromFile($this->writePhpt(<<<'PHPT'
                --BOGUS--
                whatever
                --FILE--
                <?php
                --EXPECT--
                PHPT));
    }

    public function testFromFileRequiresFileSection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/FILE section/');

        Phpt::fromFile($this->writePhpt(<<<'PHPT'
                --EXPECT--
                no errors
                PHPT));
    }

    public function testFromFileRequiresAnExpectSection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/EXPECT\* section/');

        Phpt::fromFile($this->writePhpt(<<<'PHPT'
                --FILE--
                <?php
                PHPT));
    }

    public function testFromFileRequiresSectionDelimiterFirst(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/section delimiter/');

        Phpt::fromFile($this->writePhpt(<<<'PHPT'
                not a section header
                --FILE--
                <?php
                --EXPECT--
                PHPT));
    }

    public function testFromFileCapturesTheSkipifScriptFromTheSameParse(): void
    {
        $withSkipif = Phpt::fromFile($this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php echo 'skip not today';
                --FILE--
                <?php
                --EXPECT--
                PHPT));
        $withoutSkipif = Phpt::fromFile($this->writePhpt(<<<'PHPT'
                --FILE--
                <?php
                --EXPECT--
                PHPT));

        self::assertSame("<?php echo 'skip not today';", $withSkipif->skipif);
        self::assertNull($withoutSkipif->skipif);
    }

    #[TestWith(['CLEAN'])]
    #[TestWith(['ENV'])]
    #[TestWith(['INI'])]
    public function testFromFileRejectsRunTestsSectionsItDoesNotImplement(string $section): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(\sprintf('/Section --%s-- in .* is not supported by psalm-tester/', $section));

        Phpt::fromFile($this->writePhpt("--FILE--\n<?php\n--{$section}--\nx\n--EXPECT--\n"));
    }

    public function testFromFileRecordsThePath(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php\n--EXPECT--\n");

        self::assertSame($file, Phpt::fromFile($file)->path);
        self::assertNull(Phpt::fromFile($file)->xfail);
    }

    public function testRunEvaluatesTheCarriedSkipifScriptWithoutRereadingTheFile(): void
    {
        $file = $this->writePhpt(<<<'PHPT'
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

        $result = PsalmTester::create()->withPsalm(__DIR__ . '/bin/psalm-stub')->withProgress(false)->runOne($test);

        self::assertSame(Outcome::Skipped, $result->outcome);
        self::assertSame('stale by now', $result->reason);
    }

    private function writePhpt(string $contents): string
    {
        // Fixture bodies above are indented to match the calling heredoc; strip that
        // shared indentation the way PHP's flexible heredoc does for <<<'PHPT'.
        return $this->writeTempFile($contents);
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
