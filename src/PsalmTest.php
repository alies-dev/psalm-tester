<?php

declare(strict_types=1);

namespace PHPyh\PsalmTester;

use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\Constraint\StringMatchesFormatDescription;

/**
 * @api
 * @psalm-immutable
 * @psalm-type PhptSections = array<non-empty-string, array{string, positive-int}>
 */
final readonly class PsalmTest
{
    private const SKIPIF = 'SKIPIF';
    private const FILE = 'FILE';
    private const ARGS = 'ARGS';
    private const EXPECT = 'EXPECT';
    private const EXPECTF = 'EXPECTF';
    private const EXPECT_EXTERNAL = 'EXPECT_EXTERNAL';
    private const EXPECTF_EXTERNAL = 'EXPECTF_EXTERNAL';

    /**
     * @param positive-int $codeFirstLine
     */
    public function __construct(
        public string $code,
        public Constraint $constraint,
        public string $arguments = '',
        public int $codeFirstLine = 1,
    ) {}

    /**
     * @see https://qa.php.net/phpt_details.php
     */
    public static function fromPhptFile(string $phptFile): self
    {
        $sections = self::parsePhpt($phptFile);

        if (!isset($sections[self::FILE])) {
            throw new \LogicException(\sprintf('File %s must have a FILE section.', $phptFile));
        }

        return new self(
            code: $sections[self::FILE][0],
            constraint: self::resolvePhptConstraint($phptFile, $sections),
            arguments: $sections[self::ARGS][0] ?? '',
            codeFirstLine: $sections[self::FILE][1],
        );
    }

    /**
     * Evaluate the --SKIPIF-- section of a .phpt file and return the skip reason,
     * or null if the test should not be skipped.
     *
     * The SKIPIF section contains a PHP script (starting with <?php) that echoes
     * a message beginning with "skip" when the test should be skipped, e.g.:
     *
     *   --SKIPIF--
     *   <?php if (PHP_VERSION_ID < 80200) { echo 'skip requires PHP 8.2+'; }
     *
     * Returns the reason string with the leading "skip" token stripped (e.g. "requires PHP 8.2+"),
     * or null when no SKIPIF section is present or the output does not start with "skip".
     */
    public static function getSkipReason(string $phptFile): ?string
    {
        $sections = self::parsePhpt($phptFile);

        if (!isset($sections[self::SKIPIF])) {
            return null;
        }

        // Execute the SKIPIF script in a separate PHP process so that die()/exit() calls
        // in the script do not terminate the current test run.
        $tempFile = \tempnam(\sys_get_temp_dir(), 'psalm_skipif_');

        if ($tempFile === false) {
            throw new \RuntimeException(\sprintf('Failed to create temporary file for SKIPIF evaluation of %s.', $phptFile));
        }

        if (\file_put_contents($tempFile, $sections[self::SKIPIF][0]) === false) {
            \unlink($tempFile);

            throw new \RuntimeException(\sprintf('Failed to write temporary SKIPIF file for %s.', $phptFile));
        }

        try {
            /** @psalm-suppress ForbiddenCode */
            $output = \trim((string) \shell_exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($tempFile)));
        } finally {
            \unlink($tempFile);
        }

        if (\stripos($output, 'skip') === 0) {
            return \ltrim(\substr($output, 4));
        }

        return null;
    }

    /**
     * @param PhptSections $sections
     */
    private static function resolvePhptConstraint(string $file, array $sections): Constraint
    {
        if (isset($sections[self::EXPECT])) {
            return new IsIdentical($sections[self::EXPECT][0]);
        }

        if (isset($sections[self::EXPECTF])) {
            return new StringMatchesFormatDescription($sections[self::EXPECTF][0]);
        }

        if (isset($sections[self::EXPECT_EXTERNAL])) {
            $contents = file_get_contents($sections[self::EXPECT_EXTERNAL][0]);

            if ($contents === false) {
                throw new \RuntimeException(\sprintf('Failed to read file %s.', $sections[self::EXPECT_EXTERNAL][0]));
            }

            return new IsIdentical($contents);
        }

        if (isset($sections[self::EXPECTF_EXTERNAL])) {
            $contents = file_get_contents($sections[self::EXPECTF_EXTERNAL][0]);

            if ($contents === false) {
                throw new \RuntimeException(\sprintf('Failed to read file %s.', $sections[self::EXPECTF_EXTERNAL][0]));
            }

            return new StringMatchesFormatDescription($contents);
        }

        throw new \LogicException(\sprintf('File %s must have an EXPECT* section.', $file));
    }

    /**
     * @return PhptSections
     */
    private static function parsePhpt(string $phptFile): array
    {
        $name = null;
        $sections = [];
        $lineNumber = 0;

        $lines = file($phptFile, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new \RuntimeException(\sprintf('Failed to read file %s.', $phptFile));
        }

        foreach ($lines as $line) {
            ++$lineNumber;

            if (preg_match('/^--([_A-Z]+)--/', $line, $matches)) {
                /** @var non-empty-string */
                $name = $matches[1];

                if (!\defined(\sprintf('%s::%s', self::class, $name))) {
                    throw new \InvalidArgumentException(\sprintf('Section %s is not supported.', $name));
                }

                $sections[$name] = ['', $lineNumber + 1];

                continue;
            }

            if ($name === null) {
                throw new \LogicException('.phpt file must start with a section delimiter, f.e. --TEST--.');
            }

            $sections[$name][0] .= ($sections[$name][0] ? "\n" : '') . $line;
        }

        /** @var PhptSections */
        return $sections;
    }
}
