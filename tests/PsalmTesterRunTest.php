<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Issue;
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\Result;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PsalmTesterRunTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/bin/psalm-stub';

    protected function tearDown(): void
    {
        \putenv('STUB_SLEEP');
        \putenv('STUB_MODE');
        \putenv('STUB_ENV_LOG_DIR');
        \putenv('STUB_PID_DIR');
        \putenv('STUB_POPULATE_CACHE');
        \putenv('STUB_ENV_LOG_DIR');

        foreach ($this->scratchDirs as $dir) {
            self::removeTree($dir);
        }
        $this->scratchDirs = [];
    }

    /** @var list<string> */
    private array $scratchDirs = [];

    public function testAnUndecodableGroupGetsErrorResultsWhileOtherGroupsComplete(): void
    {
        $temporaryDirectory = $this->makeScratchDir();
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withTemporaryDirectory($temporaryDirectory);

        $results = $tester->run([
            'slow' => new Phpt(code: '<?php', expectation: Expectation::format('StubError on line 1: %s'), arguments: '--stub-sleep=0.5'),
            'bad' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--stub-mode=invalid_json'),
            'bad-too' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--stub-mode=invalid_json'),
            'empty' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--stub-mode=no_output'),
        ]);

        self::assertSame(['slow', 'bad', 'bad-too', 'empty'], \array_keys($results));
        self::assertSame(Outcome::Passed, $results['slow']->outcome);
        self::assertSame(Outcome::Error, $results['bad']->outcome);
        self::assertStringContainsString('Failed to decode Psalm JSON output for args [--no-progress --no-diff --config=', (string) $results['bad']->reason);
        self::assertStringContainsString('--stub-mode=invalid_json]', (string) $results['bad']->reason);
        self::assertStringContainsString("Output: NOT JSON", (string) $results['bad']->reason);
        self::assertSame($results['bad']->reason, $results['bad-too']->reason);
        self::assertSame(Outcome::Error, $results['empty']->outcome);
        self::assertStringContainsString('Output: (empty)', (string) $results['empty']->reason);
        self::assertSame([], \glob($temporaryDirectory . '/*'), 'Code files, stdout files and cache dirs must be removed.');
    }

    public function testIssuesOutsideTheTestedCodeGiveTheirGroupErrorResults(): void
    {
        $results = self::createTester()->run([
            'a' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--stub-mode=foreign_file'),
            'b' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--stub-mode=foreign_file'),
            'other' => new Phpt(code: '<?php', expectation: Expectation::format('StubError on line 1: %s')),
        ]);

        foreach (['a', 'b'] as $id) {
            self::assertSame(Outcome::Error, $results[$id]->outcome);
            self::assertStringContainsString('Psalm reported issues outside the tested code', (string) $results[$id]->reason);
            self::assertStringContainsString('/elsewhere/Included.php:3 ForeignError: from an included file', (string) $results[$id]->reason);
        }
        self::assertSame(Outcome::Passed, $results['other']->outcome);
    }

    public function testDuplicateKeysFromAnIterableAreRejected(): void
    {
        $phpt = new Phpt(code: '<?php', expectation: Expectation::exact(''));
        $phpts = (static function () use ($phpt): \Generator {
            yield 'same' => $phpt;
            yield 'same' => $phpt;
        })();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate test key "same"');

        self::createTester()->run($phpts);
    }

    public function testARelativeTemporaryDirectoryIsResolvedWhenConfiguredNotAgainstTheWorkingDirectory(): void
    {
        $logDir = $this->makeScratchDir();
        \putenv('STUB_MODE=env_record');
        \putenv('STUB_ENV_LOG_DIR=' . $logDir);
        $relative = 'var/psalm_tester_relative_' . \bin2hex(\random_bytes(4));
        $this->scratchDirs[] = \getcwd() . '/' . $relative;

        self::createTester()->withTemporaryDirectory($relative)->withWorkingDirectory(__DIR__ . '/bin')
            ->runOne(new Phpt(code: '<?php', expectation: Expectation::exact('')));

        $logs = \glob($logDir . '/*.json') ?: [];
        self::assertCount(1, $logs);
        /** @var array{XDG_CACHE_HOME: string} $record */
        $record = \json_decode((string) \file_get_contents($logs[0]), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringStartsWith(\getcwd() . '/' . $relative . '/cache_', $record['XDG_CACHE_HOME']);
        self::assertSame([], \glob(\getcwd() . '/' . $relative . '/*'));
    }

    public function testRunUsesTheWorkingDirectoryForPsalm(): void
    {
        $logDir = $this->makeScratchDir();
        \putenv('STUB_MODE=env_record');
        \putenv('STUB_ENV_LOG_DIR=' . $logDir);

        self::createTester()->withWorkingDirectory(__DIR__ . '/bin')->runOne(new Phpt(code: '<?php', expectation: Expectation::exact('')));

        $logs = \glob($logDir . '/*.json') ?: [];
        self::assertCount(1, $logs);
        /** @var array{cwd: string} $record */
        $record = \json_decode((string) \file_get_contents($logs[0]), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(\realpath(__DIR__ . '/bin'), \realpath($record['cwd']));
    }

    public function testRunRemovesNestedCacheContents(): void
    {
        $temporaryDirectory = $this->makeScratchDir();
        \putenv('STUB_POPULATE_CACHE=1');
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withTemporaryDirectory($temporaryDirectory);

        $tester->run([
            'a' => new Phpt(code: '<?php', expectation: Expectation::exact('')),
            'b' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--config=b'),
        ]);

        self::assertSame([], \glob($temporaryDirectory . '/*'));
    }

    public function testRunHonorsConcurrency(): void
    {
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withArguments()->withConcurrency(1);
        \putenv('STUB_SLEEP=0.4');

        $start = \microtime(true);
        $tester->run([
            'a' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--config=a'),
            'b' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--config=b'),
            'c' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--config=c'),
        ]);

        self::assertGreaterThanOrEqual(1.2, \microtime(true) - $start, 'Three 0.4s groups must run one at a time.');
    }

    public function testConcurrentGroupsWritingLargeStdoutAndStderrComplete(): void
    {
        $scratch = $this->makeScratchDir();
        $script = <<<'PHP'
            require $argv[1];
            $tester = AliesDev\PsalmTester\PsalmTester::create()->withPsalm($argv[2])->withArguments();
            $tests = [];
            foreach (['a', 'b', 'c'] as $id) {
                $tests[$id] = new AliesDev\PsalmTester\Phpt(code: '<?php', expectation: AliesDev\PsalmTester\Expectation::exact(''), arguments: '--config=' . $id);
            }
            foreach ($tester->run($tests) as $id => $result) {
                echo $id, '=', substr_count($result->output, "\n") + 1, "\n";
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

        self::assertFalse($timedOut, 'run() did not finish within 30s.');
        self::assertSame("a=3000\nb=3000\nc=3000\n", \file_get_contents($scratch . '/stdout'));
        self::assertSame(3 << 20, \filesize($scratch . '/stderr'));
    }

    /**
     * @return iterable<string, array{list<string>, string, list<string>}>
     */
    public static function provideArgumentCompositions(): iterable
    {
        $configured = '--config=/configured  dir/psalm.xml';
        yield 'ARGS appended after the configured config' => [['--base'], '--extra', ['--base', $configured, '--extra']];
        yield 'ARGS --config= replaces it' => [['--base'], "--config=own.xml\n--extra", ['--base', '--config=own.xml', '--extra']];
        yield 'ARGS --config <file> replaces it' => [[], '--config own.xml', ['--config', 'own.xml']];
        yield 'ARGS -c <file> replaces it' => [[], '-c own.xml', ['-c', 'own.xml']];
        yield 'quoted ARGS config options count' => [[], '"-c" \'strict  config.xml\'', ['-c', 'strict  config.xml']];
        yield 'a --config inside another value does not' => [[], '--report="prefix --config=x.json"', [$configured, '--report=prefix --config=x.json']];
        yield 'a config in withArguments() replaces it' => [['--config=/from-arguments.xml'], '--extra', ['--config=/from-arguments.xml', '--extra']];
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $expected
     */
    #[DataProvider('provideArgumentCompositions')]
    public function testPsalmGetsTheConfiguredArgumentsThenTheConfigThenTheArgsTokens(array $arguments, string $args, array $expected): void
    {
        $logDir = $this->makeScratchDir();
        \putenv('STUB_MODE=env_record');
        \putenv('STUB_ENV_LOG_DIR=' . $logDir);
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)
            ->withArguments(...$arguments)->withConfig('/configured  dir/psalm.xml');

        $result = $tester->runOne(new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: $args));

        self::assertSame(Outcome::Passed, $result->outcome);
        $logs = \glob($logDir . '/*.json') ?: [];
        self::assertCount(1, $logs);
        /** @var array{argv: list<string>} $record */
        $record = \json_decode((string) \file_get_contents($logs[0]), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['--output-format=json', ...$expected, '--no-cache'], \array_slice($record['argv'], 0, -1));
        self::assertStringContainsString('/code_', (string) \end($record['argv']));
    }

    public function testRunRoutesEachFilesErrorsToItsOwnId(): void
    {
        $tester = self::createTester();
        \putenv('STUB_MODE=echo_code');

        // Interleaved across two argument groups, so group order differs from input order.
        $results = $tester->run([
            'a' => new Phpt(code: '<?php // alpha', expectation: Expectation::exact('')),
            'b' => new Phpt(code: '<?php // beta', expectation: Expectation::exact(''), arguments: '--config=other'),
            'c' => new Phpt(code: '<?php // gamma', expectation: Expectation::exact('')),
            'd' => new Phpt(code: '<?php // delta', expectation: Expectation::exact(''), arguments: '--config=other'),
        ]);

        self::assertSame([
            'a' => 'StubError on line 1: // alpha',
            'b' => 'StubError on line 1: // beta',
            'c' => 'StubError on line 1: // gamma',
            'd' => 'StubError on line 1: // delta',
        ], self::outputs($results));
    }

    public function testRunShiftsReportedLinesByCodeFirstLine(): void
    {
        $tester = self::createTester();
        \putenv('STUB_MODE=echo_code');

        $results = $tester->run([
            'shifted' => new Phpt(code: '<?php // shifted', expectation: Expectation::exact(''), codeFirstLine: 7),
            'plain' => new Phpt(code: '<?php // plain', expectation: Expectation::exact('')),
        ]);

        self::assertSame(['shifted' => 'StubError on line 7: // shifted', 'plain' => 'StubError on line 1: // plain'], self::outputs($results));
        self::assertEquals([new Issue('StubError', 7, 1, '// shifted')], $results['shifted']->issues);
    }

    public function testRunAndRunOnePassPsalmStderrThrough(): void
    {
        $script = <<<'PHP'
            require $argv[1];
            $tester = AliesDev\PsalmTester\PsalmTester::create()->withPsalm($argv[2]);
            $test = new AliesDev\PsalmTester\Phpt(
                code: '<?php',
                expectation: AliesDev\PsalmTester\Expectation::format('%A'),
            );
            $tester->run(['x' => $test]);
            $tester->runOne($test)->assert();
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

    public function testRunPreservesInputOrderAndDistributesErrorsAcrossGroups(): void
    {
        $tester = self::createTester();

        $tests = [
            'z_first' => new Phpt(code: '<?php // z', expectation: Expectation::exact('')),
            'a_other' => new Phpt(code: '<?php // a', expectation: Expectation::exact(''), arguments: '--config=other'),
            'm_last' => new Phpt(code: '<?php // m', expectation: Expectation::exact('')),
        ];

        $results = $tester->run($tests);

        // Output dict key order matches $tests input order, not internal group order.
        self::assertSame(['z_first', 'a_other', 'm_last'], \array_keys($results));

        // Parity: each test's output has the exact format a single-test invocation produces.
        // Tempfile basenames vary between runs, so we normalize them before comparison.
        foreach ($tests as $id => $test) {
            $alone = $tester->run([$id => $test]);
            self::assertSame(
                self::normalize($alone[$id]->output),
                self::normalize($results[$id]->output),
                \sprintf('Output for "%s" differs from running it alone.', $id),
            );
        }

        foreach (\array_keys($tests) as $id) {
            self::assertMatchesRegularExpression(
                '/^StubError on line 1: stub error for code_\w+$/',
                $results[$id]->output,
            );
        }
    }

    /**
     * @param array<array-key, Result> $results
     * @return array<array-key, string>
     */
    private static function outputs(array $results): array
    {
        return \array_map(static fn(Result $result): string => $result->output, $results);
    }

    private static function normalize(string $output): string
    {
        return (string) \preg_replace('/code_\w+/', 'code_HASH', $output);
    }

    public function testRunRunsGroupsInParallel(): void
    {
        $tester = self::createTester();

        // Three distinct argument sets => three groups, each sleeping 1s in the stub.
        $tests = [
            'a' => new Phpt(code: '<?php // a', expectation: Expectation::exact(''), arguments: '--config=a'),
            'b' => new Phpt(code: '<?php // b', expectation: Expectation::exact(''), arguments: '--config=b'),
            'c' => new Phpt(code: '<?php // c', expectation: Expectation::exact(''), arguments: '--config=c'),
        ];

        \putenv('STUB_SLEEP=1');

        $start = \microtime(true);
        $tester->run($tests);
        $elapsed = \microtime(true) - $start;

        self::assertLessThan(
            2.5,
            $elapsed,
            \sprintf('Expected parallel wall time < 2.5s for 3x1s groups, got %.2fs.', $elapsed),
        );
    }

    #[Group('slow')]
    public function testRunParallelSpeedup(): void
    {
        $tester = self::createTester();

        $tests = [
            'a' => new Phpt(code: '<?php // a', expectation: Expectation::exact(''), arguments: '--config=a'),
            'b' => new Phpt(code: '<?php // b', expectation: Expectation::exact(''), arguments: '--config=b'),
            'c' => new Phpt(code: '<?php // c', expectation: Expectation::exact(''), arguments: '--config=c'),
        ];

        \putenv('STUB_SLEEP=1');

        $singleStart = \microtime(true);
        $tester->run(['a' => $tests['a']]);
        $singleElapsed = \microtime(true) - $singleStart;

        $batchStart = \microtime(true);
        $tester->run($tests);
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



    public function testRunHandlesLargeJsonOutputWithoutTruncation(): void
    {
        $tester = self::createTester();

        \putenv('STUB_MODE=large');

        $results = $tester->run([
            'big' => new Phpt(code: '<?php', expectation: Expectation::exact('')),
        ]);

        self::assertArrayHasKey('big', $results);

        // The stub emits 3000 errors per file (~600KB of JSON) which exceeds the
        // typical OS pipe buffer (16-64KB). Each error maps to one output line.
        $lineCount = \substr_count($results['big']->output, "\n") + 1;
        self::assertSame(3000, $lineCount);
    }

    public function testRunGivesEachGroupIsolatedCacheDirAndCleansUp(): void
    {
        $tester = self::createTester();

        $logDir = \sys_get_temp_dir() . '/psalm_tester_env_log_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($logDir, 0777, true));

        $scratchRootBefore = self::listScratchCacheDirs();

        try {
            \putenv('STUB_MODE=env_record');
            \putenv('STUB_ENV_LOG_DIR=' . $logDir);

            $tester->run([
                'a' => new Phpt(code: '<?php // a', expectation: Expectation::exact(''), arguments: '--config=a'),
                'b' => new Phpt(code: '<?php // b', expectation: Expectation::exact(''), arguments: '--config=b'),
                'c' => new Phpt(code: '<?php // c', expectation: Expectation::exact(''), arguments: '--config=c --no-cache'),
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
                'Per-group cache dirs must be cleaned up after run() returns.',
            );
        } finally {
            foreach (\glob($logDir . '/*.json') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($logDir);
        }
    }

    public function testRunCleansUpTemporaryCodeFilesAfterReturning(): void
    {
        $tempDir = \sys_get_temp_dir() . '/psalm_tester_code_cleanup_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($tempDir, 0777, true));

        try {
            $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withTemporaryDirectory($tempDir);

            $tester->run([
                'a' => new Phpt(code: '<?php // a', expectation: Expectation::exact('')),
                'b' => new Phpt(code: '<?php // b', expectation: Expectation::exact(''), arguments: '--config=b'),
            ]);

            self::assertSame([], \glob($tempDir . '/code_*'), 'Per-test temporary code files must be removed once run() returns.');
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

    private static function createTester(string ...$arguments): PsalmTester
    {
        return PsalmTester::create()->withPsalm(self::STUB_PATH)->withArguments(...$arguments);
    }
}
