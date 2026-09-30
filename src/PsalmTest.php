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
        public ?string $skipifScript = null,
    ) {}

    /**
     * Populates $skipifScript (if the file has a --SKIPIF-- section) from the same parse as
     * everything else, so a caller that also needs the skip decision can get it via
     * getSkipReasonsForTests() without this file being read from disk a second time.
     *
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
            skipifScript: $sections[self::SKIPIF][0] ?? null,
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
        $concurrency = self::resolveConcurrency($concurrency);

        /** @var array<string, ?string> $results */
        $results = [];
        /** @var array<string, string> $scriptsByFile */
        $scriptsByFile = [];

        foreach ($phptFiles as $phptFile) {
            $sections = self::parsePhpt($phptFile);
            $results[$phptFile] = null;

            if (isset($sections[self::SKIPIF])) {
                $scriptsByFile[$phptFile] = $sections[self::SKIPIF][0];
            }
        }

        foreach (self::evaluateSkipifScripts($scriptsByFile, $concurrency) as $phptFile => $reason) {
            // evaluateSkipifScripts() is shared with getSkipReasonsForTests() and typed
            // array-key-generically for that; every key here really is one of $scriptsByFile's
            // own string keys, seeded from $phptFiles above.
            /** @var string $phptFile */
            $results[$phptFile] = $reason;
        }

        return $results;
    }

    /**
     * Evaluate the --SKIPIF-- script each PsalmTest already carries (populated by
     * fromPhptFile() from its one parse of the file). Prefer this over getSkipReasons()
     * when you already built PsalmTest instances for the same files — e.g. to also
     * runBatch() them — since it never touches the filesystem again to get the decision.
     *
     * @param array<array-key, PsalmTest> $tests keyed by identifier
     * @return array<array-key, ?string> skip reason per identifier, same keys as $tests
     */
    public static function getSkipReasonsForTests(array $tests, ?int $concurrency = null): array
    {
        $concurrency = self::resolveConcurrency($concurrency);

        /** @var array<array-key, ?string> $results */
        $results = [];
        /** @var array<array-key, string> $scriptsById */
        $scriptsById = [];

        foreach ($tests as $id => $test) {
            $results[$id] = null;

            if ($test->skipifScript !== null) {
                $scriptsById[$id] = $test->skipifScript;
            }
        }

        foreach (self::evaluateSkipifScripts($scriptsById, $concurrency) as $id => $reason) {
            $results[$id] = $reason;
        }

        return $results;
    }

    /**
     * @return positive-int
     */
    private static function resolveConcurrency(?int $concurrency): int
    {
        $concurrency ??= ProcessRunner::cpuCount();

        if ($concurrency < 1) {
            throw new \InvalidArgumentException('$concurrency must be at least 1.');
        }

        return $concurrency;
    }

    /**
     * @param array<array-key, string> $scriptsById
     * @param positive-int $concurrency
     * @return array<array-key, ?string>
     */
    private static function evaluateSkipifScripts(array $scriptsById, int $concurrency): array
    {
        $results = [];
        /** @var array<array-key, array{command: non-empty-list<string>}> $jobs */
        $jobs = [];
        /** @var list<string> $scriptFiles */
        $scriptFiles = [];

        try {
            foreach ($scriptsById as $id => $script) {
                $scriptFile = self::writeSkipifScript($script, $id);
                $scriptFiles[] = $scriptFile;
                // Its own PHP process, so die()/exit() in the script cannot end this run.
                $jobs[$id] = ['command' => [\PHP_BINARY, $scriptFile]];
            }

            ProcessRunner::run(
                $jobs,
                $concurrency,
                \sys_get_temp_dir(),
                static function (int|string $id, ?string $output) use (&$results): void {
                    $output = \trim((string) $output);
                    $results[$id] = \stripos($output, 'skip') === 0 ? \ltrim(\substr($output, 4)) : null;
                },
            );
        } finally {
            foreach ($scriptFiles as $scriptFile) {
                @\unlink($scriptFile);
            }
        }

        return $results;
    }

    private static function writeSkipifScript(string $script, int|string $id): string
    {
        $scriptFile = \tempnam(\sys_get_temp_dir(), 'psalm_skipif_');

        if ($scriptFile === false) {
            throw new \RuntimeException(\sprintf('Failed to create temporary SKIPIF file for %s.', $id));
        }

        if (\file_put_contents($scriptFile, $script) === false) {
            @\unlink($scriptFile);

            throw new \RuntimeException(\sprintf('Failed to write temporary SKIPIF file for %s.', $id));
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
