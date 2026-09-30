<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

use Composer\InstalledVersions;

/**
 * Runs .phpt tests through Psalm. Configure with the with*() methods, each returning a copy.
 *
 * @api
 * @psalm-type Options = array{psalm: ?string, config: string, arguments: string, timeout: ?float, concurrency: ?positive-int, workingDirectory: ?string, env: array<string, string>, progress: bool, temporaryDirectory: ?string}
 * @psalm-type GroupEntries = array<array-key, array{file: string, phpt: Phpt}>
 */
final readonly class PsalmTester
{
    /**
     * @param Options $options
     * @psalm-mutation-free
     */
    private function __construct(private array $options) {}

    /**
     * Defaults: the vimeo/psalm binary installed via Composer, the bundled psalm.xml,
     * "--no-progress --no-diff", no timeout, one process per CPU core, progress on STDERR.
     *
     * @psalm-pure
     */
    public static function create(): self
    {
        return new self([
            'psalm' => null,
            'config' => __DIR__ . '/psalm.xml',
            'arguments' => '--no-progress --no-diff',
            'timeout' => null,
            'concurrency' => null,
            'workingDirectory' => null,
            'env' => [],
            'progress' => true,
            'temporaryDirectory' => null,
        ]);
    }

    /** @psalm-mutation-free */
    public function withPsalm(string $binary): self
    {
        return new self(['psalm' => $binary] + $this->options);
    }

    /**
     * Passed as --config=, unless a test's --ARGS-- has its own --config.
     *
     * @psalm-mutation-free
     */
    public function withConfig(string $psalmXml): self
    {
        return new self(['config' => $psalmXml] + $this->options);
    }

    /**
     * Arguments for every Psalm run (default "--no-progress --no-diff"); a test's --ARGS-- are
     * appended to them.
     *
     * @psalm-mutation-free
     */
    public function withArguments(string $args): self
    {
        return new self(['arguments' => $args] + $this->options);
    }

    /**
     * Kills a Psalm run (with its process tree) still running $seconds after it started; its
     * tests get Outcome::Error. Null means no timeout.
     *
     * @psalm-mutation-free
     */
    public function withTimeout(?float $seconds): self
    {
        return new self(['timeout' => $seconds] + $this->options);
    }

    /**
     * How many SKIPIF scripts, and separately how many Psalm runs, may run at once.
     *
     * @psalm-mutation-free
     */
    public function withConcurrency(int $n): self
    {
        if ($n < 1) {
            throw new \InvalidArgumentException('Concurrency must be at least 1.');
        }

        return new self(['concurrency' => $n] + $this->options);
    }

    /**
     * Working directory of Psalm and SKIPIF processes; relative --config paths resolve against it.
     *
     * @psalm-mutation-free
     */
    public function withWorkingDirectory(string $dir): self
    {
        return new self(['workingDirectory' => $dir] + $this->options);
    }

    /**
     * Extra environment variables for Psalm and SKIPIF processes, on top of the inherited ones.
     *
     * @param array<string, string> $env
     * @psalm-mutation-free
     */
    public function withEnv(array $env): self
    {
        return new self(['env' => $env] + $this->options);
    }

    /**
     * Whether to print one "<arguments>: <n> tests" line per Psalm run on STDERR.
     *
     * @psalm-mutation-free
     */
    public function withProgress(bool $on): self
    {
        return new self(['progress' => $on] + $this->options);
    }

    /**
     * Where code files and per-run cache directories are created (default: <system temp>/psalm_test).
     *
     * @psalm-mutation-free
     */
    public function withTemporaryDirectory(string $dir): self
    {
        return new self(['temporaryDirectory' => $dir] + $this->options);
    }

    /**
     * Runs the tests: evaluates SKIPIF scripts concurrently, then analyzes the rest with one Psalm
     * run per distinct argument set (concurrently, bounded by withConcurrency()). Throws if Psalm
     * output cannot be decoded; still-running Psalm processes are killed first.
     *
     * @template TKey of array-key
     * @param iterable<TKey, Phpt> $phpts
     * @return array<TKey, Result> in the order of $phpts
     */
    public function run(iterable $phpts): array
    {
        $phpts = \is_array($phpts) ? $phpts : \iterator_to_array($phpts);
        $concurrency = $this->options['concurrency'] ?? ProcessRunner::cpuCount();
        $env = $this->options['env'] + (\getenv() ?: []);

        $scripts = [];
        foreach ($phpts as $id => $phpt) {
            if ($phpt->skipif !== null) {
                $scripts[$id] = $phpt->skipif;
            }
        }

        $skipReasons = SkipifEvaluator::evaluate($scripts, $concurrency, $this->options['workingDirectory'], $env);
        $toAnalyze = [];
        foreach ($phpts as $id => $phpt) {
            if (($skipReasons[$id] ?? null) === null) {
                $toAnalyze[$id] = $phpt;
            }
        }

        $analyzed = $toAnalyze === [] ? [] : $this->analyze($toAnalyze, $concurrency, $env);

        $results = [];
        foreach ($phpts as $id => $phpt) {
            $results[$id] = $analyzed[$id] ?? new Result($phpt, Outcome::Skipped, reason: $skipReasons[$id] ?? null);
        }

        return $results;
    }

    public function runOne(Phpt $phpt): Result
    {
        return $this->run([$phpt])[0];
    }

    /**
     * @template TKey of array-key
     * @param non-empty-array<TKey, Phpt> $phpts
     * @param positive-int $concurrency
     * @param array<string, string> $env
     * @return array<TKey, Result>
     */
    private function analyze(array $phpts, int $concurrency, array $env): array
    {
        $temporaryDirectory = self::resolveTemporaryDirectory($this->options['temporaryDirectory']);
        $psalm = $this->options['psalm'] ?? self::findPsalm();
        /** @var array<string, GroupEntries> */
        $groups = [];
        /** @var list<string> */
        $tempFiles = [];
        /** @var list<string> */
        $cacheDirs = [];
        /** @var array<TKey, Result> */
        $results = [];

        try {
            foreach ($phpts as $id => $phpt) {
                $file = self::createTemporaryCodeFile($temporaryDirectory, $phpt->code);
                $tempFiles[] = $file;
                $groups[$this->effectiveArguments($phpt)][$id] = ['file' => $file, 'phpt' => $phpt];
            }

            /** @var array<string, array{command: string, env: array<string, string>, cwd: ?string}> */
            $jobs = [];

            foreach ($groups as $args => $entries) {
                // Point Psalm and any plugins at a per-group scratch dir so concurrent groups
                // don't race on a shared cache location. XDG_CACHE_HOME is what Psalm itself
                // reads; TMPDIR/TMP/TEMP cover plugins that derive their cache from
                // sys_get_temp_dir() (e.g. psalm-plugin-laravel's Plugin::getCacheLocation()).
                $cacheDir = self::createGroupCacheDir($temporaryDirectory);
                $cacheDirs[] = $cacheDir;
                $jobs[$args] = [
                    'command' => self::buildCommand($psalm, $args, $entries),
                    'env' => ['XDG_CACHE_HOME' => $cacheDir, 'TMPDIR' => $cacheDir, 'TMP' => $cacheDir, 'TEMP' => $cacheDir] + $env,
                    'cwd' => $this->options['workingDirectory'],
                ];
            }

            ProcessRunner::run(
                $jobs,
                $concurrency,
                $temporaryDirectory,
                function (string $args, ?string $output) use ($groups, &$results): void {
                    $entries = $groups[$args];

                    if ($output === null) {
                        $reason = \sprintf(
                            'PsalmTimeout: group [%s] did not finish within %.1fs and was terminated.',
                            $args,
                            (float) $this->options['timeout'],
                        );
                        foreach ($entries as $id => $entry) {
                            /** @var TKey $id */
                            $results[$id] = new Result($entry['phpt'], Outcome::Error, reason: $reason);
                        }

                        return;
                    }

                    $errorsByFile = IssueFormatter::decodeByFile($output, $args);

                    foreach ($entries as $id => $entry) {
                        $issues = IssueFormatter::toIssues($errorsByFile[IssueFormatter::fileKey($entry['file'])] ?? [], $entry['phpt']->codeFirstLine);
                        /** @var TKey $id */
                        $results[$id] = Result::fromAnalysis($entry['phpt'], IssueFormatter::format($issues), $issues);
                    }

                    if ($this->options['progress']) {
                        \fwrite(\STDERR, \sprintf("%s: %d %s\n", $args, \count($entries), \count($entries) === 1 ? 'test' : 'tests'));
                    }
                },
                $this->options['timeout'],
            );

            return $results;
        } finally {
            foreach ($tempFiles as $file) {
                @\unlink($file);
            }
            foreach ($cacheDirs as $dir) {
                self::removeDirectoryRecursive($dir);
            }
        }
    }

    /**
     * The group key and the arguments Psalm gets: the configured arguments, then --config (unless
     * the test has its own), then the test's --ARGS--, whitespace-collapsed because a newline would
     * end the shell command.
     *
     * @psalm-mutation-free
     */
    private function effectiveArguments(Phpt $phpt): string
    {
        $args = $this->options['arguments'];

        if (\preg_match('/(?:^|\s)(?:--config\b|-c\b)/', $phpt->arguments) !== 1) {
            $args .= ' --config=' . \escapeshellarg($this->options['config']);
        }

        return (string) \preg_replace('/\s+/', ' ', \trim($args . ' ' . $phpt->arguments));
    }

    /**
     * @param string $args Trusted input (tester configuration and .phpt --ARGS--), not escaped:
     *     it holds several arguments.
     * @param GroupEntries $entries
     * @psalm-pure
     */
    private static function buildCommand(string $psalm, string $args, array $entries): string
    {
        // The per-group cache dir starts empty and is deleted afterwards, so writing a cache only
        // costs time (2x on a 700-file suite); --no-cache also keeps an explicitly configured
        // cacheDirectory, which XDG_CACHE_HOME cannot redirect, from being shared by concurrent groups.
        if (\preg_match('/(^| )--no-cache( |$)/', $args) !== 1) {
            $args .= ' --no-cache';
        }

        return \sprintf(
            // exec replaces the shell, so killing the process proc_open() returns kills Psalm itself.
            '%s%s --output-format=json %s %s',
            \PHP_OS_FAMILY === 'Windows' ? '' : 'exec ',
            \escapeshellarg($psalm),
            $args,
            \implode(' ', array_map(static fn(array $entry): string => \escapeshellarg($entry['file']), $entries)),
        );
    }

    private static function findPsalm(): string
    {
        if (!method_exists(InstalledVersions::class, 'getInstallPath')) {
            throw new \RuntimeException('Cannot find Psalm installation path. Pass it to withPsalm().');
        }

        $installPath = InstalledVersions::getInstallPath('vimeo/psalm');

        if ($installPath === null) {
            throw new \RuntimeException('Cannot find Psalm installation path. Pass it to withPsalm().');
        }

        return $installPath . '/psalm';
    }

    private static function resolveTemporaryDirectory(?string $temporaryDirectory): string
    {
        $temporaryDirectory ??= \sys_get_temp_dir() . '/psalm_test';

        if (!\is_dir($temporaryDirectory) && !@\mkdir($temporaryDirectory, recursive: true) && !\is_dir($temporaryDirectory)) {
            throw new \RuntimeException(\sprintf('Failed to create temporary directory %s.', $temporaryDirectory));
        }

        return $temporaryDirectory;
    }

    private static function createGroupCacheDir(string $temporaryDirectory): string
    {
        $dir = $temporaryDirectory . '/cache_' . \bin2hex(\random_bytes(8));

        if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create per-group cache directory %s.', $dir));
        }

        return $dir;
    }

    private static function removeDirectoryRecursive(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        // Best-effort cleanup: this runs from runBatch's finally, so an iterator
        // failure here must not mask the original exception.
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->isDir() && !$entry->isLink()) {
                    @\rmdir($entry->getPathname());
                } else {
                    @\unlink($entry->getPathname());
                }
            }
        } catch (\UnexpectedValueException) {
            return;
        }

        @\rmdir($dir);
    }

    private static function createTemporaryCodeFile(string $temporaryDirectory, string $contents): string
    {
        $file = \tempnam($temporaryDirectory, 'code_');

        if ($file === false) {
            throw new \RuntimeException(\sprintf('Failed to create temporary code file in %s.', $temporaryDirectory));
        }

        if (\file_put_contents($file, $contents) === false) {
            @\unlink($file);

            throw new \RuntimeException(\sprintf('Failed to write temporary code file: %s.', $file));
        }

        return $file;
    }
}
