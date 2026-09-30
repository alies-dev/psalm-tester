<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\PsalmTest;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\TestCase;

final class PsalmTesterBatchTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/bin/psalm-stub';

    protected function tearDown(): void
    {
        \putenv('STUB_SLEEP');
        \putenv('STUB_MODE');
        \putenv('STUB_ENV_LOG_DIR');
        \putenv('STUB_PID_DIR');
        \putenv('STUB_POPULATE_CACHE');

        foreach ($this->scratchDirs as $dir) {
            self::removeTree($dir);
        }
        $this->scratchDirs = [];
    }

    /** @var list<string> */
    private array $scratchDirs = [];

    public function testRunBatchFailsFastOnInvalidOutputAndKillsTheStillRunningSibling(): void
    {
        $temporaryDirectory = $this->makeScratchDir();
        $pidDir = $this->makeScratchDir();
        \putenv('STUB_PID_DIR=' . $pidDir);
        $tester = PsalmTester::create(psalmPath: self::STUB_PATH, temporaryDirectory: $temporaryDirectory, showProgress: false);

        $start = \microtime(true);

        try {
            $tester->runBatch([
                'slow' => new PsalmTest(code: '<?php', constraint: new IsIdentical(''), arguments: '--stub-sleep=30'),
                // Waits until both stubs are running, so the sibling is live when this one fails.
                'bad' => new PsalmTest(code: '<?php', constraint: new IsIdentical(''), arguments: '--stub-mode=invalid_json --stub-await-pids=2'),
            ]);
            self::fail('Expected invalid JSON to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to decode Psalm JSON output', $e->getMessage());
        }

        self::assertLessThan(10.0, \microtime(true) - $start, 'The failure must not wait for the 30s sibling.');
        $pids = \array_map(\basename(...), \glob($pidDir . '/*') ?: []);
        self::assertCount(2, $pids);
        foreach ($pids as $pid) {
            self::assertFalse(self::isAlive((int) $pid), \sprintf('Stub pid %s survived.', $pid));
        }
        self::assertSame([], \glob($temporaryDirectory . '/*'), 'Code files, stdout files and cache dirs must be removed.');
    }

    public function testRunBatchRemovesNestedCacheContents(): void
    {
        $temporaryDirectory = $this->makeScratchDir();
        \putenv('STUB_POPULATE_CACHE=1');
        $tester = PsalmTester::create(psalmPath: self::STUB_PATH, temporaryDirectory: $temporaryDirectory, showProgress: false);

        $tester->runBatch([
            'a' => new PsalmTest(code: '<?php', constraint: new IsIdentical('')),
            'b' => new PsalmTest(code: '<?php', constraint: new IsIdentical(''), arguments: '--config=b'),
        ]);

        self::assertSame([], \glob($temporaryDirectory . '/*'));
    }

    public function testRunBatchHonorsConcurrency(): void
    {
        $tester = PsalmTester::create(psalmPath: self::STUB_PATH, defaultArguments: '', showProgress: false, concurrency: 1);
        \putenv('STUB_SLEEP=0.4');

        $start = \microtime(true);
        $tester->runBatch([
            'a' => new PsalmTest(code: '<?php', constraint: new IsIdentical(''), arguments: '--config=a'),
            'b' => new PsalmTest(code: '<?php', constraint: new IsIdentical(''), arguments: '--config=b'),
            'c' => new PsalmTest(code: '<?php', constraint: new IsIdentical(''), arguments: '--config=c'),
        ]);

        self::assertGreaterThanOrEqual(1.2, \microtime(true) - $start, 'Three 0.4s groups must run one at a time.');
    }

    public function testConcurrentGroupsWritingLargeStdoutAndStderrComplete(): void
    {
        $scratch = $this->makeScratchDir();
        $script = <<<'PHP'
            require $argv[1];
            $tester = AliesDev\PsalmTester\PsalmTester::create(psalmPath: $argv[2], defaultArguments: '', showProgress: false);
            $tests = [];
            foreach (['a', 'b', 'c'] as $id) {
                $tests[$id] = new AliesDev\PsalmTester\PsalmTest(code: '<?php', constraint: new PHPUnit\Framework\Constraint\IsIdentical(''), arguments: '--config=' . $id);
            }
            foreach ($tester->runBatch($tests) as $id => $output) {
                echo $id, '=', substr_count($output, "\n") + 1, "\n";
            }
            PHP;
        $env = \getenv();
        $env['STUB_MODE'] = 'large';
        $env['STUB_STDERR_BYTES'] = (string) (1 << 20);
        $process = \proc_open(
            [\PHP_BINARY, '-r', $script, \dirname(__DIR__) . '/vendor/autoload.php', self::STUB_PATH],
            [1 => ['file', $scratch . '/stdout', 'w'], 2 => ['file', $scratch . '/stderr', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($process);

        // An external deadline, so a pipe deadlock fails this test instead of hanging the suite.
        $deadline = \microtime(true) + 30;
        while (\proc_get_status($process)['running'] && \microtime(true) < $deadline) {
            \usleep(20_000);
        }
        $timedOut = \proc_get_status($process)['running'];
        if ($timedOut) {
            \proc_terminate($process, 9);
        }
        \proc_close($process);

        self::assertFalse($timedOut, 'runBatch did not finish within 30s.');
        self::assertSame("a=3000\nb=3000\nc=3000\n", \file_get_contents($scratch . '/stdout'));
        self::assertSame(3 << 20, \filesize($scratch . '/stderr'));
    }

    public function testRunBatchRoutesEachFilesErrorsToItsOwnId(): void
    {
        $tester = self::createTester();
        \putenv('STUB_MODE=echo_code');

        // Interleaved across two argument groups, so group order differs from input order.
        $results = $tester->runBatch([
            'a' => new PsalmTest(code: '<?php // alpha', constraint: new IsIdentical('')),
            'b' => new PsalmTest(code: '<?php // beta', constraint: new IsIdentical(''), arguments: '--config=other'),
            'c' => new PsalmTest(code: '<?php // gamma', constraint: new IsIdentical('')),
            'd' => new PsalmTest(code: '<?php // delta', constraint: new IsIdentical(''), arguments: '--config=other'),
        ]);

        self::assertSame([
            'a' => 'StubError on line 1: // alpha',
            'b' => 'StubError on line 1: // beta',
            'c' => 'StubError on line 1: // gamma',
            'd' => 'StubError on line 1: // delta',
        ], $results);
    }

    public function testRunBatchShiftsReportedLinesByCodeFirstLine(): void
    {
        $tester = self::createTester();
        \putenv('STUB_MODE=echo_code');

        $results = $tester->runBatch([
            'shifted' => new PsalmTest(code: '<?php // shifted', constraint: new IsIdentical(''), codeFirstLine: 7),
            'plain' => new PsalmTest(code: '<?php // plain', constraint: new IsIdentical('')),
        ]);

        self::assertSame(['shifted' => 'StubError on line 7: // shifted', 'plain' => 'StubError on line 1: // plain'], $results);
    }

    public function testRunBatchAndTestPassPsalmStderrThrough(): void
    {
        $script = <<<'PHP'
            require $argv[1];
            $tester = AliesDev\PsalmTester\PsalmTester::create(psalmPath: $argv[2], showProgress: false);
            $test = new AliesDev\PsalmTester\PsalmTest(
                code: '<?php',
                constraint: new PHPUnit\Framework\Constraint\StringMatchesFormatDescription('%A'),
            );
            $tester->runBatch(['x' => $test]);
            $tester->test($test);
            PHP;
        $env = \getenv();
        $env['STUB_STDERR'] = '[stub-stderr-marker]';
        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, '-r', $script, \dirname(__DIR__) . '/vendor/autoload.php', self::STUB_PATH],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($process);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        self::assertSame(0, \proc_close($process), $stderr);
        self::assertSame(2, \substr_count($stderr, '[stub-stderr-marker]'), $stderr);
    }

    public function testRunBatchPreservesInputOrderAndDistributesErrorsAcrossGroups(): void
    {
        $tester = self::createTester();

        $tests = [
            'z_first' => new PsalmTest(code: '<?php // z', constraint: new IsIdentical('')),
            'a_other' => new PsalmTest(code: '<?php // a', constraint: new IsIdentical(''), arguments: '--config=other'),
            'm_last' => new PsalmTest(code: '<?php // m', constraint: new IsIdentical('')),
        ];

        $results = $tester->runBatch($tests);

        // Output dict key order matches $tests input order, not internal group order.
        self::assertSame(['z_first', 'a_other', 'm_last'], \array_keys($results));

        // Parity: each test's output has the exact format a single-test invocation produces.
        // Tempfile basenames vary between runs, so we normalize them before comparison.
        foreach ($tests as $id => $test) {
            $alone = $tester->runBatch([$id => $test]);
            self::assertSame(
                self::normalize($alone[$id]),
                self::normalize($results[$id]),
                \sprintf('Batch output for "%s" differs from single-test output.', $id),
            );
        }

        foreach (\array_keys($tests) as $id) {
            self::assertMatchesRegularExpression(
                '/^StubError on line 1: stub error for code_\w+$/',
                $results[$id],
            );
        }
    }

    private static function normalize(string $output): string
    {
        return (string) \preg_replace('/code_\w+/', 'code_HASH', $output);
    }

    public function testRunBatchRunsGroupsInParallel(): void
    {
        $tester = self::createTester();

        // Three distinct argument sets => three groups, each sleeping 1s in the stub.
        $tests = [
            'a' => new PsalmTest(code: '<?php // a', constraint: new IsIdentical(''), arguments: '--config=a'),
            'b' => new PsalmTest(code: '<?php // b', constraint: new IsIdentical(''), arguments: '--config=b'),
            'c' => new PsalmTest(code: '<?php // c', constraint: new IsIdentical(''), arguments: '--config=c'),
        ];

        \putenv('STUB_SLEEP=1');

        $start = \microtime(true);
        $tester->runBatch($tests);
        $elapsed = \microtime(true) - $start;

        self::assertLessThan(
            2.5,
            $elapsed,
            \sprintf('Expected parallel wall time < 2.5s for 3x1s groups, got %.2fs.', $elapsed),
        );
    }

    #[Group('slow')]
    public function testRunBatchParallelSpeedup(): void
    {
        $tester = self::createTester();

        $tests = [
            'a' => new PsalmTest(code: '<?php // a', constraint: new IsIdentical(''), arguments: '--config=a'),
            'b' => new PsalmTest(code: '<?php // b', constraint: new IsIdentical(''), arguments: '--config=b'),
            'c' => new PsalmTest(code: '<?php // c', constraint: new IsIdentical(''), arguments: '--config=c'),
        ];

        \putenv('STUB_SLEEP=1');

        $singleStart = \microtime(true);
        $tester->runBatch(['a' => $tests['a']]);
        $singleElapsed = \microtime(true) - $singleStart;

        $batchStart = \microtime(true);
        $tester->runBatch($tests);
        $batchElapsed = \microtime(true) - $batchStart;

        self::assertLessThan(
            $singleElapsed * 2.5,
            $batchElapsed,
            \sprintf(
                'Expected 3-group batch to stay under 2.5x single-group time (%.2fs), got %.2fs.',
                $singleElapsed,
                $batchElapsed,
            ),
        );
    }

    public function testRunBatchThrowsRuntimeExceptionIncludingArgsOnInvalidJson(): void
    {
        $tester = self::createTester('--unique-marker-xyz');

        \putenv('STUB_MODE=invalid_json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/--unique-marker-xyz/');

        $tester->runBatch([
            'x' => new PsalmTest(code: '<?php', constraint: new IsIdentical('')),
        ]);
    }

    public function testRunBatchHandlesLargeJsonOutputWithoutTruncation(): void
    {
        $tester = self::createTester();

        \putenv('STUB_MODE=large');

        $results = $tester->runBatch([
            'big' => new PsalmTest(code: '<?php', constraint: new IsIdentical('')),
        ]);

        self::assertArrayHasKey('big', $results);

        // The stub emits 3000 errors per file (~600KB of JSON) which exceeds the
        // typical OS pipe buffer (16-64KB). Each error maps to one output line.
        $lineCount = \substr_count($results['big'], "\n") + 1;
        self::assertSame(3000, $lineCount);
    }

    public function testRunBatchGivesEachGroupIsolatedCacheDirAndCleansUp(): void
    {
        $tester = self::createTester();

        $logDir = \sys_get_temp_dir() . '/psalm_tester_env_log_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($logDir, 0777, true));

        $scratchRootBefore = self::listScratchCacheDirs();

        try {
            \putenv('STUB_MODE=env_record');
            \putenv('STUB_ENV_LOG_DIR=' . $logDir);

            $tester->runBatch([
                'a' => new PsalmTest(code: '<?php // a', constraint: new IsIdentical(''), arguments: '--config=a'),
                'b' => new PsalmTest(code: '<?php // b', constraint: new IsIdentical(''), arguments: '--config=b'),
                'c' => new PsalmTest(code: '<?php // c', constraint: new IsIdentical(''), arguments: '--config=c --no-cache'),
            ]);

            /** @var list<array{XDG_CACHE_HOME: string, TMPDIR: string, TMP: string, TEMP: string, sys_get_temp_dir: string, argv: list<string>}> $records */
            $records = [];
            foreach (\glob($logDir . '/*.json') ?: [] as $file) {
                /** @var array{XDG_CACHE_HOME: string, TMPDIR: string, TMP: string, TEMP: string, sys_get_temp_dir: string, argv: list<string>} $decoded */
                $decoded = \json_decode((string) \file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
                $records[] = $decoded;
            }

            self::assertCount(3, $records, 'Each group should invoke the stub exactly once.');

            foreach ($records as $record) {
                self::assertSame(1, \count(\array_keys($record['argv'], '--no-cache', true)), 'Each group runs with exactly one --no-cache.');
            }

            $xdg = \array_column($records, 'XDG_CACHE_HOME');
            $tmpdir = \array_column($records, 'TMPDIR');
            self::assertCount(3, \array_unique($xdg), 'XDG_CACHE_HOME must differ across groups.');
            self::assertCount(3, \array_unique($tmpdir), 'TMPDIR must differ across groups.');

            // sys_get_temp_dir() honors TMPDIR unless php.ini's sys_temp_dir is set,
            // in which case it ignores the env var entirely. Only assert the
            // end-to-end override when sys_temp_dir is unset.
            $sysTempDirIniSet = (string) \ini_get('sys_temp_dir') !== '';

            foreach ($records as $record) {
                self::assertNotSame('', $record['XDG_CACHE_HOME']);
                self::assertSame($record['XDG_CACHE_HOME'], $record['TMPDIR']);
                self::assertSame($record['XDG_CACHE_HOME'], $record['TMP']);
                self::assertSame($record['XDG_CACHE_HOME'], $record['TEMP']);
                self::assertStringStartsWith(\sys_get_temp_dir() . '/psalm_test/cache_', $record['XDG_CACHE_HOME']);

                if (!$sysTempDirIniSet) {
                    self::assertSame($record['XDG_CACHE_HOME'], $record['sys_get_temp_dir']);
                }
            }

            self::assertSame(
                $scratchRootBefore,
                self::listScratchCacheDirs(),
                'Per-group cache dirs must be cleaned up after runBatch returns.',
            );
        } finally {
            foreach (\glob($logDir . '/*.json') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($logDir);
        }
    }

    public function testRunBatchCleansUpTemporaryCodeFilesAfterReturning(): void
    {
        $tempDir = \sys_get_temp_dir() . '/psalm_tester_code_cleanup_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($tempDir, 0777, true));

        try {
            $tester = PsalmTester::create(psalmPath: self::STUB_PATH, temporaryDirectory: $tempDir, showProgress: false);

            $tester->runBatch([
                'a' => new PsalmTest(code: '<?php // a', constraint: new IsIdentical('')),
                'b' => new PsalmTest(code: '<?php // b', constraint: new IsIdentical(''), arguments: '--config=b'),
            ]);

            self::assertSame([], \glob($tempDir . '/code_*'), 'Per-test temporary code files must be removed once runBatch returns.');
        } finally {
            self::assertSame([], \glob($tempDir . '/*'));
            @\rmdir($tempDir);
        }
    }

    private function makeScratchDir(): string
    {
        $dir = \sys_get_temp_dir() . '/psalm_tester_batch_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($dir, 0777, true));
        $this->scratchDirs[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        foreach (\glob($dir . '/*') ?: [] as $entry) {
            \is_dir($entry) ? self::removeTree($entry) : @\unlink($entry);
        }
        @\rmdir($dir);
    }

    private static function isAlive(int $pid): bool
    {
        /** @psalm-suppress ForbiddenCode */
        return \trim((string) \shell_exec('ps -p ' . $pid . ' -o pid= 2>/dev/null')) !== '';
    }

    /**
     * @return list<string>
     */
    private static function listScratchCacheDirs(): array
    {
        /** @var list<string> $dirs */
        $dirs = \glob(\sys_get_temp_dir() . '/psalm_test/cache_*', \GLOB_ONLYDIR) ?: [];
        \sort($dirs);

        return $dirs;
    }

    private static function createTester(string $defaultArguments = ''): PsalmTester
    {
        return PsalmTester::create(
            psalmPath: self::STUB_PATH,
            defaultArguments: $defaultArguments,
            showProgress: false,
        );
    }
}
