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
        $concurrency ??= self::detectCpuCount();

        if ($concurrency < 1) {
            throw new \InvalidArgumentException('$concurrency must be at least 1.');
        }

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

        foreach (\array_chunk($scriptsByFile, $concurrency, preserve_keys: true) as $batch) {
            foreach (self::runSkipifBatch($batch) as $phptFile => $reason) {
                $results[$phptFile] = $reason;
            }
        }

        return $results;
    }

    /**
     * Launches one process per script, then reads each to completion in turn. Since every
     * process in the batch is already running by the time we start reading, wall time is
     * bounded by the slowest script in the batch, not their sum.
     *
     * @param array<string, string> $scriptsByFile
     * @return array<string, ?string>
     */
    private static function runSkipifBatch(array $scriptsByFile): array
    {
        /** @var array<string, array{tempFile: string, process: resource, stdout: ?resource}> */
        $running = [];

        try {
            foreach ($scriptsByFile as $phptFile => $script) {
                $running[$phptFile] = self::startSkipifProcess($script, $phptFile);
            }

            $results = [];

            foreach (array_keys($running) as $phptFile) {
                $stdout = $running[$phptFile]['stdout'];
                \assert($stdout !== null);
                $output = \trim((string) \stream_get_contents($stdout));
                \fclose($stdout);
                \proc_close($running[$phptFile]['process']);
                $running[$phptFile]['stdout'] = null; // mark closed so the finally below skips it
                $results[$phptFile] = \stripos($output, 'skip') === 0 ? \ltrim(\substr($output, 4)) : null;
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
    private static function startSkipifProcess(string $script, string $phptFile): array
    {
        $tempFile = \tempnam(\sys_get_temp_dir(), 'psalm_skipif_');

        if ($tempFile === false || \file_put_contents($tempFile, $script) === false) {
            throw new \RuntimeException(\sprintf('Failed to write temporary SKIPIF file for %s.', $phptFile));
        }

        // stderr inherits the parent's (like shell_exec did), not a pipe: a closed/unread pipe
        // means the script's first stderr write (a warning, or display_errors=stderr) raises
        // SIGPIPE and kills it before it ever reaches its skip echo.
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => \STDERR];
        $pipes = [];
        $process = \proc_open([\PHP_BINARY, $tempFile], $descriptors, $pipes);

        if (!\is_resource($process)) {
            @\unlink($tempFile);

            throw new \RuntimeException(\sprintf('Failed to run SKIPIF script for %s.', $phptFile));
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
