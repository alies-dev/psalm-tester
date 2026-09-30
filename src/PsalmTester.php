<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

use Composer\InstalledVersions;

/**
 * Runs .phpt tests through Psalm. Configure with the with*() methods, each returning a copy.
 *
 * @api
 * @psalm-type Options = array{psalm: ?string, config: string, arguments: list<string>, timeout: ?float, concurrency: ?positive-int, workingDirectory: ?string, env: array<string, string>, progress: bool, temporaryDirectory: ?string}
 * @psalm-type GroupEntries = array<array-key, array{file: string, phpt: Phpt}>
 * @psalm-type Group = array{argv: list<string>, entries: GroupEntries}
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
     * --no-progress --no-diff, no timeout, one process per CPU core, no progress output.
     *
     * @psalm-pure
     */
    public static function create(): self
    {
        return new self([
            'psalm' => null,
            'config' => __DIR__ . '/psalm.xml',
            'arguments' => ['--no-progress', '--no-diff'],
            'timeout' => null,
            'concurrency' => null,
            'workingDirectory' => null,
            'env' => [],
            // Off by default: under --process-isolation PHPUnit treats any child stderr as an error.
            'progress' => false,
            'temporaryDirectory' => null,
        ]);
    }

    /** @psalm-mutation-free */
    public function withPsalm(string $binary): self
    {
        return new self(['psalm' => $binary] + $this->options);
    }

    /**
     * Passed as --config=, unless withArguments() or a test's --ARGS-- has a --config or -c.
     *
     * @psalm-mutation-free
     */
    public function withConfig(string $psalmXml): self
    {
        return new self(['config' => $psalmXml] + $this->options);
    }

    /**
     * Arguments for every Psalm run, one per parameter, passed as is (no shell); default
     * --no-progress --no-diff. A test's --ARGS-- are appended to them.
     *
     * @psalm-mutation-free
     */
    public function withArguments(string ...$args): self
    {
        return new self(['arguments' => \array_values($args)] + $this->options);
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
     * XDG_CACHE_HOME, TMPDIR, TMP and TEMP cannot be set for Psalm: each run gets its own.
     *
     * @param array<string, string> $env
     * @psalm-mutation-free
     */
    public function withEnv(array $env): self
    {
        return new self(['env' => $env] + $this->options);
    }

    /**
     * Whether to print one "<arguments>: <n> tests" line per Psalm run on STDERR (default off).
     *
     * @psalm-mutation-free
     */
    public function withProgress(bool $on): self
    {
        return new self(['progress' => $on] + $this->options);
    }

    /**
     * Where code files, SKIPIF scripts and per-run cache directories are created (default:
     * <system temp>/psalm_test). A relative path is resolved against the current directory now,
     * not against withWorkingDirectory().
     */
    public function withTemporaryDirectory(string $dir): self
    {
        $isAbsolute = \str_starts_with($dir, '/') || \str_starts_with($dir, '\\') || \preg_match('/^[A-Za-z]:[\/\\\\]/', $dir) === 1;

        return new self(['temporaryDirectory' => $isAbsolute ? $dir : (\getcwd() ?: '.') . \DIRECTORY_SEPARATOR . $dir] + $this->options);
    }

    /**
     * Runs the tests: evaluates SKIPIF scripts concurrently, then analyzes the rest with one Psalm
     * run per distinct argument set (concurrently, bounded by withConcurrency()). Returns exactly
     * one Result per input key; a Psalm run that times out or whose output cannot be attributed
     * gives Outcome::Error to each of its tests. Throws only for infrastructure failures (and then
     * kills the Psalm runs still going first).
     *
     * @template TKey of array-key
     * @param iterable<TKey, Phpt> $phpts keys must be unique
     * @return array<TKey, Result> in the order of $phpts
     */
    public function run(iterable $phpts): array
    {
        $unique = [];
        foreach ($phpts as $id => $phpt) {
            if (\array_key_exists($id, $unique)) {
                throw new \InvalidArgumentException(\sprintf('Duplicate test key "%s".', $id));
            }
            $unique[$id] = $phpt;
        }
        $phpts = $unique;
        $temporaryDirectory = self::resolveTemporaryDirectory($this->options['temporaryDirectory']);
        $concurrency = $this->options['concurrency'] ?? ProcessRunner::cpuCount();
        $env = $this->options['env'] + (\getenv() ?: []);

        $scripts = [];
        foreach ($phpts as $id => $phpt) {
            if ($phpt->skipif !== null) {
                $scripts[$id] = $phpt->skipif;
            }
        }

        $skipReasons = SkipifEvaluator::evaluate($scripts, $concurrency, $temporaryDirectory, $this->options['workingDirectory'], $env);
        $toAnalyze = [];
        foreach ($phpts as $id => $phpt) {
            if (($skipReasons[$id] ?? null) === null) {
                $toAnalyze[$id] = $phpt;
            }
        }

        $analyzed = $toAnalyze === [] ? [] : $this->analyze($toAnalyze, $concurrency, $env, $temporaryDirectory);

        $results = [];
        foreach ($phpts as $id => $phpt) {
            $results[$id] = $analyzed[$id] ?? Result::skipped($phpt, $skipReasons[$id] ?? '');
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
    private function analyze(array $phpts, int $concurrency, array $env, string $temporaryDirectory): array
    {
        $psalm = $this->options['psalm'] ?? self::findPsalm();
        /** @var array<string, Group> */
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
                $argv = $this->effectiveArguments($phpt);
                $key = \implode("\0", $argv);
                $groups[$key]['argv'] = $argv;
                $groups[$key]['entries'][$id] = ['file' => $file, 'phpt' => $phpt];
            }

            /** @var array<string, array{command: non-empty-list<string>, env: array<string, string>, cwd: ?string}> */
            $jobs = [];

            foreach ($groups as $key => $group) {
                // Point Psalm and any plugins at a per-group scratch dir so concurrent groups
                // don't race on a shared cache location. XDG_CACHE_HOME is what Psalm itself
                // reads; TMPDIR/TMP/TEMP cover plugins that derive their cache from
                // sys_get_temp_dir() (e.g. psalm-plugin-laravel's Plugin::getCacheLocation()).
                $cacheDir = self::createGroupCacheDir($temporaryDirectory);
                $cacheDirs[] = $cacheDir;
                $jobs[$key] = [
                    'command' => self::buildCommand($psalm, $group),
                    'env' => ['XDG_CACHE_HOME' => $cacheDir, 'TMPDIR' => $cacheDir, 'TMP' => $cacheDir, 'TEMP' => $cacheDir] + $env,
                    'cwd' => $this->options['workingDirectory'],
                ];
            }

            ProcessRunner::run(
                $jobs,
                $concurrency,
                $temporaryDirectory,
                function (string $key, ?string $output) use ($groups, &$results): void {
                    $group = $groups[$key];
                    $args = \implode(' ', $group['argv']);

                    foreach ($this->groupResults($group, $args, $output) as $id => $result) {
                        /** @var TKey $id */
                        $results[$id] = $result;
                    }

                    if ($this->options['progress']) {
                        $count = \count($group['entries']);
                        \fwrite(\STDERR, \sprintf("%s: %d %s\n", $args, $count, $count === 1 ? 'test' : 'tests'));
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
     * @param Group $group
     * @param ?string $output null when the run timed out
     * @return array<array-key, Result>
     */
    private function groupResults(array $group, string $args, ?string $output): array
    {
        $entries = $group['entries'];

        try {
            if ($output === null) {
                throw new \UnexpectedValueException(\sprintf('PsalmTimeout: group [%s] did not finish within %.1fs and was terminated.', $args, (float) $this->options['timeout']));
            }

            $errorsByFile = IssueFormatter::decodeByFile($output, $args);
            $testedFiles = [];

            foreach ($entries as $entry) {
                $testedFiles[IssueFormatter::fileKey($entry['file'])] = true;
            }

            $unmatched = \array_diff_key($errorsByFile, $testedFiles);

            if ($unmatched !== []) {
                // Issues in some other file (an included one, a -f target) must not vanish and
                // let an empty expectation pass; nothing says which test caused them.
                throw new \UnexpectedValueException(\sprintf(
                    "Psalm reported issues outside the tested code for group [%s]:\n%s",
                    $args,
                    \implode("\n", \array_map(
                        static fn(array $error): string => \sprintf('%s:%d %s: %s', $error['file_path'], $error['line_from'], $error['type'], $error['message']),
                        \array_merge(...\array_values($unmatched)),
                    )),
                ));
            }
        } catch (\UnexpectedValueException $e) {
            return \array_map(static fn(array $entry): Result => Result::error($entry['phpt'], $e->getMessage()), $entries);
        }

        return \array_map(static function (array $entry) use ($errorsByFile): Result {
            $issues = IssueFormatter::toIssues($errorsByFile[IssueFormatter::fileKey($entry['file'])] ?? [], $entry['phpt']->codeFirstLine);

            return Result::fromAnalysis($entry['phpt'], IssueFormatter::format($issues), $issues);
        }, $entries);
    }

    /**
     * The group key and the arguments Psalm gets: the configured arguments, then --config (unless
     * those or the test's --ARGS-- have one), then the test's --ARGS-- tokens.
     *
     * @return list<string>
     * @psalm-mutation-free
     */
    private function effectiveArguments(Phpt $phpt): array
    {
        $testArgs = ArgumentTokenizer::tokenize($phpt->arguments);
        $args = $this->options['arguments'];

        if (!self::hasConfigOption($args) && !self::hasConfigOption($testArgs)) {
            $args[] = '--config=' . $this->options['config'];
        }

        return [...$args, ...$testArgs];
    }

    /**
     * @param list<string> $args
     * @psalm-pure
     */
    private static function hasConfigOption(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--config' || \str_starts_with($arg, '--config=') || \str_starts_with($arg, '-c')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Group $group
     * @return non-empty-list<string>
     */
    private static function buildCommand(string $psalm, array $group): array
    {
        $argv = $group['argv'];

        // The per-group cache dir starts empty and is deleted afterwards, so writing a cache only
        // costs time (2x on a 700-file suite); --no-cache also keeps an explicitly configured
        // cacheDirectory, which XDG_CACHE_HOME cannot redirect, from being shared by concurrent groups.
        if (!\in_array('--no-cache', $argv, true)) {
            $argv[] = '--no-cache';
        }

        // Psalm's entry points are PHP scripts: run them with this PHP binary rather than relying
        // on their shebang and executable bit, which Windows does not have.
        $prefix = self::isPhpScript($psalm) ? [\PHP_BINARY, $psalm] : [$psalm];

        return [...$prefix, '--output-format=json', ...$argv, ...\array_values(\array_map(static fn(array $entry): string => $entry['file'], $group['entries']))];
    }

    private static function isPhpScript(string $path): bool
    {
        if (\preg_match('/\.(php|phar)$/i', $path) === 1) {
            return true;
        }

        $handle = @\fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) \fread($handle, 128);
        \fclose($handle);

        return \str_starts_with($head, '<?php') || (\str_starts_with($head, '#!') && \str_contains(\explode("\n", $head)[0], 'php'));
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

        // Best-effort cleanup: this runs from analyze()'s finally, so an iterator
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
