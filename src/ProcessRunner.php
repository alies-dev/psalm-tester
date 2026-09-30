<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * Runs child processes with bounded concurrency. Each child's stdout goes to a temporary file and
 * its stderr is inherited, so there are no pipes to drain: no stream_select() (unreliable for
 * process pipes on Windows, limited by FD_SETSIZE elsewhere), and a child that closes its stdout
 * early is not mistaken for a finished one. Completion is detected by polling proc_get_status().
 *
 * @internal
 * @psalm-type Job = array{command: string|non-empty-list<string>, env?: array<string, string>, cwd?: ?string}
 * @psalm-type LiveProcess = array{process: resource, stdoutFile: string, startedAt: float}
 */
final class ProcessRunner
{
    private const POLL_INTERVAL_MICROSECONDS = 5_000;

    /**
     * Calls $onComplete(id, stdout) as each job exits, in completion order; stdout is null for a
     * job killed (with its whole process tree) after running $timeoutSeconds. If anything throws
     * (a failed start, or $onComplete itself), every still-running child is killed and its
     * temporary file removed before the exception propagates.
     *
     * @template TKey of array-key
     * @param array<TKey, Job> $jobs
     * @param positive-int $concurrency
     * @param callable(TKey, ?string): void $onComplete
     */
    public static function run(array $jobs, int $concurrency, string $temporaryDirectory, callable $onComplete, ?float $timeoutSeconds = null): void
    {
        $queue = $jobs;
        /** @var array<TKey, LiveProcess> */
        $live = [];

        try {
            while ($queue !== [] || $live !== []) {
                while ($queue !== [] && \count($live) < $concurrency) {
                    $id = \array_key_first($queue);
                    $job = $queue[$id];
                    unset($queue[$id]);
                    $live[$id] = self::start($job, $temporaryDirectory);
                }

                foreach ($live as $id => $proc) {
                    if (\proc_get_status($proc['process'])['running']) {
                        if ($timeoutSeconds !== null && \microtime(true) - $proc['startedAt'] >= $timeoutSeconds) {
                            self::kill($proc['process']);
                            \proc_close($proc['process']);
                            unset($live[$id]);
                            @\unlink($proc['stdoutFile']);
                            $onComplete($id, null);
                        }

                        continue;
                    }

                    // Only reaped once exit is confirmed, so proc_close() never blocks here.
                    \proc_close($proc['process']);
                    unset($live[$id]);
                    $onComplete($id, self::takeOutput($proc['stdoutFile']));
                }

                if ($live !== []) {
                    /** @psalm-suppress ForbiddenCode polling interval, not a debugging leftover */
                    \usleep(self::POLL_INTERVAL_MICROSECONDS);
                }
            }
        } finally {
            foreach ($live as $proc) {
                self::kill($proc['process']);
                @\proc_close($proc['process']);
                @\unlink($proc['stdoutFile']);
            }
        }
    }

    /**
     * @return positive-int
     */
    public static function cpuCount(): int
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
     * @param Job $job
     * @return LiveProcess
     */
    private static function start(array $job, string $temporaryDirectory): array
    {
        $stdoutFile = \tempnam($temporaryDirectory, 'stdout_');

        if ($stdoutFile === false) {
            throw new \RuntimeException(\sprintf('Failed to create a temporary stdout file in %s.', $temporaryDirectory));
        }

        $nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = false;

        try {
            $process = \proc_open(
                $job['command'],
                [0 => ['file', $nullDevice, 'r'], 1 => ['file', $stdoutFile, 'w']],
                $pipes,
                $job['cwd'] ?? null,
                $job['env'] ?? null,
            );
        } finally {
            // Also when proc_open() throws (e.g. ValueError): the caller only owns what start() returns.
            if (!\is_resource($process)) {
                @\unlink($stdoutFile);
            }
        }

        if (!\is_resource($process)) {
            throw new \RuntimeException(\sprintf('Failed to start %s.', \is_array($job['command']) ? \implode(' ', $job['command']) : $job['command']));
        }

        return ['process' => $process, 'stdoutFile' => $stdoutFile, 'startedAt' => \microtime(true)];
    }

    private static function takeOutput(string $stdoutFile): string
    {
        $output = @\file_get_contents($stdoutFile);
        @\unlink($stdoutFile);

        if ($output === false) {
            throw new \RuntimeException(\sprintf('Failed to read child output from %s.', $stdoutFile));
        }

        return $output;
    }

    /**
     * @param resource $process
     */
    private static function kill($process): void
    {
        // Psalm re-execs itself into a child PHP process when its own restarter needs
        // different ini/opcache/JIT settings (PsalmRestarter). That child is spawned via a
        // plain fork+exec, not pcntl_exec(), so killing only the process proc_open() gave us
        // leaves it running, orphaned under init and still burning CPU (and racing our cache
        // dir cleanup). Discover descendants first, while the parent/ppid chain is still
        // intact, then kill leaves before the root.
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::killDescendants(\proc_get_status($process)['pid']);
        }

        // Signal 9 = SIGKILL, as a literal so this does not need ext-pcntl; ignored on Windows.
        @\proc_terminate($process, 9);
    }

    /**
     * Best-effort: kills every process descended from $rootPid (not $rootPid itself), deepest
     * generation first, so a still-alive parent never gets the chance to reparent a child we
     * already accounted for. Shells out to `ps`/`kill` rather than posix_kill()/pcntl, since
     * neither extension is required by this package.
     */
    private static function killDescendants(int $rootPid): void
    {
        foreach (\array_reverse(self::collectDescendantGenerations($rootPid)) as $generation) {
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
}
