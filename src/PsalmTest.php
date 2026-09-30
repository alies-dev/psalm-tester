<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\Constraint\StringMatchesFormatDescription;

/**
 * @api
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
     * @psalm-mutation-free
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
     * Evaluate the --SKIPIF-- section of a single .phpt file. Wraps getSkipReasons();
     * prefer that when checking many files, since it evaluates them concurrently.
     *
     * @see getSkipReasons()
     */
    public static function getSkipReason(string $phptFile): ?string
    {
        return self::getSkipReasons([$phptFile])[$phptFile] ?? null;
    }

    /**
     * Evaluate the --SKIPIF-- section of each .phpt file and return the skip reason per file,
     * or null per file if that test should not be skipped.
     *
     * The SKIPIF section contains a PHP script (starting with <?php) that echoes
     * a message beginning with "skip" when the test should be skipped, e.g.:
     *
     *   --SKIPIF--
     *   <?php if (PHP_VERSION_ID < 80200) { echo 'skip requires PHP 8.2+'; }
     *
     * Each script runs in its own PHP process (so die()/exit() calls cannot terminate the
     * current run), up to $concurrency processes at a time (default: one per CPU core).
     * Returns the reason string with the leading "skip" token stripped (e.g. "requires PHP 8.2+"),
     * or null when a file has no SKIPIF section or its output does not start with "skip".
     * Keys of the returned array match $phptFiles, in the same order.
     *
     * @param list<string> $phptFiles
     * @return array<string, ?string>
     */
    public static function getSkipReasons(array $phptFiles, ?int $concurrency = null): array
    {
        $concurrency ??= ProcessRunner::cpuCount();

        if ($concurrency < 1) {
            throw new \InvalidArgumentException('$concurrency must be at least 1.');
        }

        /** @var array<string, ?string> $results */
        $results = [];
        /** @var array<string, array{command: non-empty-list<string>}> $jobs */
        $jobs = [];
        /** @var list<string> $scriptFiles */
        $scriptFiles = [];

        try {
            foreach ($phptFiles as $phptFile) {
                $sections = self::parsePhpt($phptFile);
                $results[$phptFile] = null;

                if (isset($sections[self::SKIPIF])) {
                    $scriptFile = self::writeSkipifScript($sections[self::SKIPIF][0], $phptFile);
                    $scriptFiles[] = $scriptFile;
                    // Its own PHP process, so die()/exit() in the script cannot end this run.
                    $jobs[$phptFile] = ['command' => [\PHP_BINARY, $scriptFile]];
                }
            }

            ProcessRunner::run(
                $jobs,
                $concurrency,
                \sys_get_temp_dir(),
                static function (string $phptFile, ?string $output) use (&$results): void {
                    $output = \trim((string) $output);
                    $results[$phptFile] = \stripos($output, 'skip') === 0 ? \ltrim(\substr($output, 4)) : null;
                },
            );
        } finally {
            foreach ($scriptFiles as $scriptFile) {
                @\unlink($scriptFile);
            }
        }

        return $results;
    }

    private static function writeSkipifScript(string $script, string $phptFile): string
    {
        $scriptFile = \tempnam(\sys_get_temp_dir(), 'psalm_skipif_');

        if ($scriptFile === false) {
            throw new \RuntimeException(\sprintf('Failed to create temporary SKIPIF file for %s.', $phptFile));
        }

        if (\file_put_contents($scriptFile, $script) === false) {
            @\unlink($scriptFile);

            throw new \RuntimeException(\sprintf('Failed to write temporary SKIPIF file for %s.', $phptFile));
        }

        return $scriptFile;
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
     * @psalm-pure This reads the filesystem via file(), so it is not truly pure; the
     *     annotation is required only because Psalm's impure-function list omits file()
     *     (unlike e.g. file_get_contents()), so Psalm would otherwise report MissingPureAnnotation.
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
