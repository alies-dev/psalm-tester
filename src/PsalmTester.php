<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

use Composer\InstalledVersions;
use PHPUnit\Framework\Assert;

/**
 * @api
 * @psalm-type GroupEntries = array<array-key, array{file: string, test: PsalmTest}>
 * @psalm-type StartedGroup = array{args: string, entries: GroupEntries, command: string, cacheDir: string, process: resource, stdout: resource, stderr: resource, stdoutBuffer: string, startedAt: float}
 * @psalm-type RunningGroup = array{args: string, entries: GroupEntries, command: string, cacheDir: string, process: resource, stdout: ?resource, stderr: ?resource, stdoutBuffer: string, startedAt: float}
 */
final readonly class PsalmTester
{
    /**
     * @psalm-mutation-free
     */
    private function __construct(
        private string $psalmPath,
        private string $defaultArguments,
        private string $temporaryDirectory,
        private bool $showProgress,
        private ?float $timeoutSeconds,
    ) {}

    public static function create(
        ?string $psalmPath = null,
        string $defaultArguments = '--no-progress --no-diff --config=' . __DIR__ . '/psalm.xml',
        ?string $temporaryDirectory = null,
        bool $showProgress = true,
        ?float $timeoutSeconds = null,
    ): self {
        return new self(
            psalmPath: $psalmPath ?? self::findPsalm(),
            defaultArguments: $defaultArguments,
            temporaryDirectory: self::resolveTemporaryDirectory($temporaryDirectory),
            showProgress: $showProgress,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    private static function findPsalm(): string
    {
        if (!method_exists(InstalledVersions::class, 'getInstallPath')) {
            throw new \RuntimeException('Cannot find Psalm installation path. Please, explicitly specify path to Psalm binary.');
        }

        $installPath = InstalledVersions::getInstallPath('vimeo/psalm');

        if ($installPath === null) {
            throw new \RuntimeException('Cannot find Psalm installation path. Please, explicitly specify path to Psalm binary.');
        }

        return $installPath . '/psalm';
    }

    private static function resolveTemporaryDirectory(?string $temporaryDirectory): string
    {
        $temporaryDirectory ??= sys_get_temp_dir() . '/psalm_test';

        if (!is_dir($temporaryDirectory) && !mkdir($temporaryDirectory, recursive: true)) {
            throw new \RuntimeException(\sprintf('Failed to create temporary directory %s.', $temporaryDirectory));
        }

        return $temporaryDirectory;
    }

    /**
     * Run multiple tests in batched Psalm invocations (one per unique argument set).
     * Groups are launched concurrently via proc_open; wall time is bounded by the slowest group.
     * Returns formatted output per test — callers are responsible for assertions.
     * @api
     * @param array<array-key, PsalmTest> $tests keyed by identifier
     * @return array<array-key, string> formatted output keyed by identifier
     */
    public function runBatch(array $tests): array
    {
        /** @var array<string, array<array-key, array{file: string, test: PsalmTest}>> */
        $groups = [];
        /** @var list<string> */
        $allTempFiles = [];

        try {
            foreach ($tests as $id => $test) {
                $args = (string) \preg_replace('/\s+/', ' ', \trim($test->arguments ?: $this->defaultArguments));
                $file = $this->createTemporaryCodeFile($test->code);
                $allTempFiles[] = $file;
                $groups[$args][$id] = [
                    'file' => $file,
                    'test' => $test,
                ];
            }

            // Pre-seed results keyed by input id so the returned dict preserves $tests input order.
            /** @var array<array-key, string> */
            $results = [];
            foreach ($tests as $id => $_) {
                $results[$id] = '';
            }

            /** @var array<int, RunningGroup> */
            $running = [];
            /** @var list<string> */
            $allCacheDirs = [];

            try {
                foreach ($groups as $args => $entries) {
                    $proc = $this->startGroup($args, $entries);
                    $allCacheDirs[] = $proc['cacheDir'];
                    $running[] = $proc;
                }

                $this->drainAndFinalize($running, $results);

                return $results;
            } finally {
                foreach ($running as $proc) {
                    if ($proc['stdout'] !== null) {
                        @\fclose($proc['stdout']);
                    }
                    if ($proc['stderr'] !== null) {
                        @\fclose($proc['stderr']);
                    }
                    @\proc_close($proc['process']);
                }
                foreach ($allCacheDirs as $dir) {
                    self::removeDirectoryRecursive($dir);
                }
            }
        } finally {
            foreach ($allTempFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * @param array<array-key, array{file: string, test: PsalmTest}> $entries
     * @return StartedGroup
     */
    private function startGroup(string $args, array $entries): array
    {
        $filePaths = array_map(
            static fn(array $entry): string => $entry['file'],
            $entries,
        );

        $command = \sprintf(
            '%s --output-format=json %s %s',
            \escapeshellarg($this->psalmPath),
            $args,
            \implode(' ', array_map(\escapeshellarg(...), array_values($filePaths))),
        );

        // Point Psalm and any plugins at a per-group scratch dir so concurrent groups
        // don't race on a shared cache location. XDG_CACHE_HOME is what Psalm itself
        // reads; TMPDIR/TMP/TEMP cover plugins that derive their cache from
        // sys_get_temp_dir() (e.g. psalm-plugin-laravel's Plugin::getCacheLocation()).
        $cacheDir = $this->createGroupCacheDir();
        $env = self::buildChildEnv($cacheDir);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = \proc_open($command, $descriptors, $pipes, null, $env);

        if (!\is_resource($process)) {
            self::removeDirectoryRecursive($cacheDir);
            throw new \RuntimeException(\sprintf('Failed to run command %s.', $command));
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        return [
            'args' => $args,
            'entries' => $entries,
            'command' => $command,
            'cacheDir' => $cacheDir,
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'stdoutBuffer' => '',
            'startedAt' => \microtime(true),
        ];
    }

    private function createGroupCacheDir(): string
    {
        $dir = $this->temporaryDirectory . '/cache_' . \bin2hex(\random_bytes(8));

        if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create per-group cache directory %s.', $dir));
        }

        return $dir;
    }

    /**
     * @return array<string, string>
     */
    private static function buildChildEnv(string $cacheDir): array
    {
        $env = \getenv() ?: [];
        $env['XDG_CACHE_HOME'] = $cacheDir;
        $env['TMPDIR'] = $cacheDir;
        $env['TMP'] = $cacheDir;
        $env['TEMP'] = $cacheDir;

        return $env;
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

    /**
     * @param array<int, RunningGroup> $running
     * @param array<array-key, string> $results
     * @param-out array<array-key, string> $results
     */
    private function drainAndFinalize(array &$running, array &$results): void
    {
        // Progress lines are printed in completion order (non-deterministic across parallel groups).
        while ($running !== []) {
            if ($this->timeoutSeconds !== null) {
                $this->finalizeTimedOutGroups($running, $results, $this->timeoutSeconds);

                if ($running === []) {
                    break;
                }
            }

            $readable = [];
            foreach ($running as $proc) {
                if ($proc['stdout'] !== null) {
                    $readable[] = $proc['stdout'];
                }
                if ($proc['stderr'] !== null) {
                    $readable[] = $proc['stderr'];
                }
            }

            if ($readable === []) {
                break;
            }

            $write = null;
            $except = null;
            $ready = @\stream_select($readable, $write, $except, 1);

            if ($ready === false) {
                continue;
            }

            foreach (array_keys($running) as $key) {
                $stdout = $running[$key]['stdout'];
                if ($stdout !== null && \in_array($stdout, $readable, true)) {
                    $chunk = \fread($stdout, 65536);
                    if ($chunk === false || ($chunk === '' && \feof($stdout))) {
                        \fclose($stdout);
                        $running[$key]['stdout'] = null;
                    } elseif ($chunk !== '') {
                        $running[$key]['stdoutBuffer'] .= $chunk;
                    }
                }

                $stderr = $running[$key]['stderr'];
                if ($stderr !== null && \in_array($stderr, $readable, true)) {
                    $chunk = \fread($stderr, 65536);
                    if ($chunk === false || ($chunk === '' && \feof($stderr))) {
                        \fclose($stderr);
                        $running[$key]['stderr'] = null;
                    } elseif ($chunk !== '') {
                        // Mirror shell_exec behavior: stderr falls through to the parent terminal.
                        \fwrite(\STDERR, $chunk);
                    }
                }

                if ($running[$key]['stdout'] === null && $running[$key]['stderr'] === null) {
                    \proc_close($running[$key]['process']);
                    $finalized = $running[$key];
                    unset($running[$key]);
                    $this->collectGroupResults($finalized, $results);
                }
            }
        }
    }

    /**
     * Terminates and finalizes any group that has been running longer than $timeoutSeconds,
     * writing a failure output naming its args and the timeout to every test in that group.
     * Groups still within budget are left untouched, so other groups run to completion normally.
     *
     * @param array<int, RunningGroup> $running
     * @param array<array-key, string> $results
     * @param-out array<array-key, string> $results
     */
    private function finalizeTimedOutGroups(array &$running, array &$results, float $timeoutSeconds): void
    {
        $now = \microtime(true);

        foreach (array_keys($running) as $key) {
            $proc = $running[$key];

            if ($now - $proc['startedAt'] < $timeoutSeconds) {
                continue;
            }

            // Psalm re-execs itself into a child PHP process when its own restarter needs
            // different ini/opcache/JIT settings (PsalmRestarter). That child is spawned via a
            // plain fork+exec, not pcntl_exec(), so killing only the process proc_open() gave us
            // leaves it running, orphaned under init and still burning CPU (and racing our cache
            // dir cleanup). Discover descendants first, while the parent/ppid chain is still
            // intact, then kill leaves before the root.
            self::killDescendants(\proc_get_status($proc['process'])['pid']);

            // Signal 9 = SIGKILL, passed as a literal so this doesn't need ext-pcntl for the
            // constant.
            @\proc_terminate($proc['process'], 9);

            if ($proc['stdout'] !== null) {
                @\fclose($proc['stdout']);
            }
            if ($proc['stderr'] !== null) {
                @\fclose($proc['stderr']);
            }
            @\proc_close($proc['process']);
            unset($running[$key]);

            $message = \sprintf(
                'PsalmTimeout: group [%s] did not finish within %.1fs and was terminated.',
                $proc['args'],
                $timeoutSeconds,
            );
            foreach (array_keys($proc['entries']) as $id) {
                $results[$id] = $message;
            }
        }
    }

    /**
     * Best-effort: kills every process descended from $rootPid (not $rootPid itself), deepest
     * generation first, so a still-alive parent never gets the chance to reparent a child we
     * already accounted for. Shells out to `ps`/`kill` rather than posix_kill()/pcntl, since
     * neither extension is required by this package.
     */
    private static function killDescendants(int $rootPid): void
    {
        foreach (array_reverse(self::collectDescendantGenerations($rootPid)) as $generation) {
            foreach ($generation as $pid) {
                /** @psalm-suppress ForbiddenCode */
                @\shell_exec('kill -9 ' . $pid . ' 2>/dev/null');
            }
        }
    }

    /**
     * @return list<list<int>> descendant pids grouped by generation, direct children first
     */
    private static function collectDescendantGenerations(int $rootPid): array
    {
        /** @psalm-suppress ForbiddenCode */
        $output = (string) @\shell_exec('ps -A -o pid=,ppid= 2>/dev/null');

        /** @var array<int, list<int>> */
        $childrenByParent = [];
        foreach (\explode("\n", \trim($output)) as $line) {
            if (\preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $matches) !== 1) {
                continue;
            }
            $childrenByParent[(int) $matches[2]][] = (int) $matches[1];
        }

        $generations = [];
        $frontier = $childrenByParent[$rootPid] ?? [];

        while ($frontier !== []) {
            $generations[] = $frontier;
            $next = [];
            foreach ($frontier as $pid) {
                foreach ($childrenByParent[$pid] ?? [] as $childPid) {
                    $next[] = $childPid;
                }
            }
            $frontier = $next;
        }

        return $generations;
    }

    /**
     * @param RunningGroup $proc
     * @param array<array-key, string> $results
     * @param-out array<array-key, string> $results
     */
    private function collectGroupResults(array $proc, array &$results): void
    {
        $args = $proc['args'];
        $entries = $proc['entries'];
        $output = $proc['stdoutBuffer'];

        $decoded = $this->decodeOutput($output, $args);

        /** @var array<string, list<array{type: string, column_from: int, line_from: int, message: string, file_path: string, ...}>> */
        $errorsByFile = [];
        foreach ($decoded as $error) {
            $resolved = \realpath($error['file_path']);
            $key = $resolved !== false ? $resolved : $error['file_path'];
            $errorsByFile[$key][] = $error;
        }

        $this->writeProgressStart($args);
        $groupCount = 0;
        foreach ($entries as $id => $entry) {
            $resolved = \realpath($entry['file']);
            $key = $resolved !== false ? $resolved : $entry['file'];
            $results[$id] = $this->formatErrors(
                $errorsByFile[$key] ?? [],
                $entry['test']->codeFirstLine,
            );
            $groupCount++;
        }
        $this->writeProgressEnd($groupCount);
    }

    public function test(PsalmTest $test): void
    {
        $codeFile = $this->createTemporaryCodeFile($test->code);

        try {
            $args = (string) \preg_replace('/\s+/', ' ', \trim($test->arguments ?: $this->defaultArguments));
            $this->writeProgressStart($args);
            $output = $this->runPsalm($args, $codeFile);
            $decoded = $this->decodeOutput($output, $args);
            $formattedOutput = $this->formatErrors($decoded, $test->codeFirstLine);

            $this->writeProgressEnd(1);
            Assert::assertThat($formattedOutput, $test->constraint);
        } finally {
            @unlink($codeFile);
        }
    }

    /**
     * @param string $args Pre-built argument string — trusted input from $this->defaultArguments or PsalmTest::$arguments (parsed from .phpt files).
     *                     Not escaped, as it contains multiple shell-level arguments.
     */
    private function runPsalm(string $args, string ...$files): string
    {
        // Collapse any whitespace (including newlines from --ARGS-- sections) to single spaces
        // to prevent newlines from being interpreted as shell command separators.
        $args = (string) \preg_replace('/\s+/', ' ', \trim($args));

        $command = \sprintf(
            '%s --output-format=json %s %s',
            escapeshellarg($this->psalmPath),
            $args,
            implode(' ', array_map(escapeshellarg(...), $files)),
        );

        /** @psalm-suppress ForbiddenCode */
        $output = shell_exec($command);

        if (!\is_string($output)) {
            throw new \RuntimeException(\sprintf('Failed to run command %s.', $command));
        }

        return $output;
    }

    /**
     * @return list<array{type: string, column_from: int, line_from: int, message: string, file_path: string, ...}>
     * @psalm-mutation-free
     */
    private function decodeOutput(string $output, string $args): array
    {
        try {
            /** @var list<array{type: string, column_from: int, line_from: int, message: string, file_path: string, ...}> */
            return json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf(
                "Failed to decode Psalm JSON output for args [%s]: %s\nOutput: %s",
                $args,
                $e->getMessage(),
                $output,
            ), previous: $e);
        }
    }

    /**
     * @param list<array{type: string, column_from: int, line_from: int, message: string, file_path: string, ...}> $errors
     * @param positive-int $codeFirstLine
     */
    private function formatErrors(array $errors, int $codeFirstLine): string
    {
        /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
        usort($errors, static fn(array $a, array $b): int => ($a['line_from'] <=> $b['line_from'])
            ?: ($a['column_from'] <=> $b['column_from'])
            ?: ($a['type'] <=> $b['type'])
            ?: ($a['message'] <=> $b['message']));

        return implode("\n", array_map(
            static fn(array $error): string => \sprintf(
                '%s on line %d: %s',
                $error['type'],
                $error['line_from'] + $codeFirstLine - 1,
                $error['message'],
            ),
            $errors,
        ));
    }

    private function writeProgressStart(string $args): void
    {
        if ($this->showProgress) {
            $displayArgs = \preg_replace('/\s+/', ' ', \trim($args)) ?? $args;
            fwrite(\STDERR, $displayArgs);
        }
    }

    private function writeProgressEnd(int $count): void
    {
        if ($this->showProgress) {
            fwrite(\STDERR, \sprintf(": %d %s\n", $count, $count === 1 ? 'test' : 'tests'));
        }
    }

    private function createTemporaryCodeFile(string $contents): string
    {
        $file = tempnam($this->temporaryDirectory, 'code_');

        if ($file === false) {
            throw new \LogicException(\sprintf('Failed to create temporary code file in %s.', $this->temporaryDirectory));
        }

        if (file_put_contents($file, $contents) === false) {
            throw new \RuntimeException(\sprintf('Failed to write temporary code file: %s.', $file));
        }

        return $file;
    }
}
