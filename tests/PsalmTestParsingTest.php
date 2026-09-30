<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\PsalmTest;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\Constraint\StringMatchesFormatDescription;
use PHPUnit\Framework\TestCase;

final class PsalmTestParsingTest extends TestCase
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

    public function testFromPhptFileParsesFileAndExpectSections(): void
    {
        $test = PsalmTest::fromPhptFile($this->writePhpt(<<<'PHPT'
                --FILE--
                <?php
                $x = 1;
                --EXPECT--
                no errors
                PHPT));

        self::assertSame("<?php\n\$x = 1;", $test->code);
        self::assertSame('', $test->arguments);
        self::assertEquals(new IsIdentical('no errors'), $test->constraint);
    }

    public function testFromPhptFileCapturesArgsSection(): void
    {
        $test = PsalmTest::fromPhptFile($this->writePhpt(<<<'PHPT'
                --ARGS--
                --no-cache
                --FILE--
                <?php
                --EXPECT--
                PHPT));

        self::assertSame('--no-cache', $test->arguments);
    }

    public function testFromPhptFileCodeFirstLineTracksFileSectionOffset(): void
    {
        $test = PsalmTest::fromPhptFile($this->writePhpt(<<<'PHPT'
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

    public function testFromPhptFileWithExpectfUsesFormatDescriptionConstraint(): void
    {
        $test = PsalmTest::fromPhptFile($this->writePhpt(<<<'PHPT'
                --FILE--
                <?php
                --EXPECTF--
                Trace on line %d: %s
                PHPT));

        self::assertEquals(new StringMatchesFormatDescription("Trace on line %d: %s"), $test->constraint);
    }

    public function testFromPhptFileWithExpectExternalReadsReferencedFile(): void
    {
        $externalFile = $this->writeTempFile('expected external output');
        $test = PsalmTest::fromPhptFile($this->writePhpt(<<<PHPT
                --FILE--
                <?php
                --EXPECT_EXTERNAL--
                {$externalFile}
                PHPT));

        self::assertEquals(new IsIdentical('expected external output'), $test->constraint);
    }

    public function testFromPhptFileWithExpectfExternalReadsReferencedFile(): void
    {
        $externalFile = $this->writeTempFile('Trace on line %d: %s');
        $test = PsalmTest::fromPhptFile($this->writePhpt(<<<PHPT
                --FILE--
                <?php
                --EXPECTF_EXTERNAL--
                {$externalFile}
                PHPT));

        self::assertEquals(new StringMatchesFormatDescription('Trace on line %d: %s'), $test->constraint);
    }

    public function testFromPhptFileRejectsUnsupportedSection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/BOGUS/');

        PsalmTest::fromPhptFile($this->writePhpt(<<<'PHPT'
                --BOGUS--
                whatever
                --FILE--
                <?php
                --EXPECT--
                PHPT));
    }

    public function testFromPhptFileRequiresFileSection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/FILE section/');

        PsalmTest::fromPhptFile($this->writePhpt(<<<'PHPT'
                --EXPECT--
                no errors
                PHPT));
    }

    public function testFromPhptFileRequiresAnExpectSection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/EXPECT\* section/');

        PsalmTest::fromPhptFile($this->writePhpt(<<<'PHPT'
                --FILE--
                <?php
                PHPT));
    }

    public function testFromPhptFileRequiresSectionDelimiterFirst(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/section delimiter/');

        PsalmTest::fromPhptFile($this->writePhpt(<<<'PHPT'
                not a section header
                --FILE--
                <?php
                --EXPECT--
                PHPT));
    }

    public function testFileIsParsedOnceAcrossGetSkipReasonAndFromPhptFile(): void
    {
        $file = $this->writePhpt(<<<'PHPT'
                --SKIPIF--
                <?php // never skips
                --FILE--
                <?php
                $x = 1;
                --EXPECT--
                PHPT);

        // Prime the parse cache via getSkipReason() first.
        self::assertNull(PsalmTest::getSkipReason($file));

        // If fromPhptFile() re-read the file from disk instead of reusing the cached
        // parse, this would throw (file() fails once the path no longer exists).
        \unlink($file);
        $test = PsalmTest::fromPhptFile($file);

        self::assertSame("<?php\n\$x = 1;", $test->code);
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
