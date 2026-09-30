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

    private static function resolveConcurrency(?int $concurrency): int
    {
        $concurrency ??= self::detectCpuCount();

        if ($concurrency < 1) {
            throw new \InvalidArgumentException('$concurrency must be at least 1.');
        }

        return $concurrency;
    }

    /**
     * @param array<array-key, string> $scriptsById
     * @return array<array-key, ?string>
     */
    private static function evaluateSkipifScripts(array $scriptsById, int $concurrency): array
    {
        $results = [];

        foreach (\array_chunk($scriptsById, $concurrency, preserve_keys: true) as $batch) {
            foreach (self::runSkipifBatch($batch) as $id => $reason) {
                $results[$id] = $reason;
            }
        }

        return $results;
    }

    /**
     * Launches one process per script, then reads each to completion in turn. Since every
     * process in the batch is already running by the time we start reading, wall time is
     * bounded by the slowest script in the batch, not their sum.
     *
     * @param array<array-key, string> $scriptsById
     * @return array<array-key, ?string>
     */
    private static function runSkipifBatch(array $scriptsById): array
    {
        /** @var array<array-key, array{tempFile: string, process: resource, stdout: ?resource}> */
        $running = [];

        try {
            foreach ($scriptsById as $id => $script) {
                $running[$id] = self::startSkipifProcess($script, $id);
            }

            $results = [];

            foreach (array_keys($running) as $id) {
                $stdout = $running[$id]['stdout'];
                \assert($stdout !== null);
                $output = \trim((string) \stream_get_contents($stdout));
                \fclose($stdout);
                \proc_close($running[$id]['process']);
                $running[$id]['stdout'] = null; // mark closed so the finally below skips it
                $results[$id] = \stripos($output, 'skip') === 0 ? \ltrim(\substr($output, 4)) : null;
            }

            return $results;
        } finally {
            // If startSkipifProcess() throws partway through the first loop above, or the
            // second loop throws before finishing, any process still open here (stdout !== null)
            // was started but never drained/closed — close it too, not just its temp file.
            foreach ($running as $proc) {
                if ($proc['stdout'] !== null) {
                    @\fclose($proc['stdout']);
                    @\proc_close($proc['process']);
                }
                @\unlink($proc['tempFile']);
            }
        }
    }

    /**
     * @return array{tempFile: string, process: resource, stdout: resource}
     */
    private static function startSkipifProcess(string $script, int|string $id): array
    {
        $tempFile = \tempnam(\sys_get_temp_dir(), 'psalm_skipif_');

        if ($tempFile === false || \file_put_contents($tempFile, $script) === false) {
            throw new \RuntimeException(\sprintf('Failed to write temporary SKIPIF file for %s.', $id));
        }

        // stderr inherits the parent's (like shell_exec did), not a pipe: a closed/unread pipe
        // means the script's first stderr write (a warning, or display_errors=stderr) raises
        // SIGPIPE and kills it before it ever reaches its skip echo.
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => \STDERR];
        $pipes = [];
        $process = \proc_open([\PHP_BINARY, $tempFile], $descriptors, $pipes);

        if (!\is_resource($process)) {
            @\unlink($tempFile);

            throw new \RuntimeException(\sprintf('Failed to run SKIPIF script for %s.', $id));
        }

        \fclose($pipes[0]);

        return ['tempFile' => $tempFile, 'process' => $process, 'stdout' => $pipes[1]];
    }

    private static function detectCpuCount(): int
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $count = (int) \getenv('NUMBER_OF_PROCESSORS');

            return $count > 0 ? $count : 1;
        }

        $probe = \PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n hw.ncpu' : 'nproc';
        /** @psalm-suppress ForbiddenCode */
        $count = (int) \trim((string) @\shell_exec($probe));

        return $count > 0 ? $count : 1;
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
