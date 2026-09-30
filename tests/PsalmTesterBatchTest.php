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
                'c' => new PsalmTest(code: '<?php // c', constraint: new IsIdentical(''), arguments: '--config=c'),
            ]);

            /** @var list<array{XDG_CACHE_HOME: string, TMPDIR: string, TMP: string, TEMP: string, sys_get_temp_dir: string}> $records */
            $records = [];
            foreach (\glob($logDir . '/*.json') ?: [] as $file) {
                /** @var array{XDG_CACHE_HOME: string, TMPDIR: string, TMP: string, TEMP: string, sys_get_temp_dir: string} $decoded */
                $decoded = \json_decode((string) \file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
                $records[] = $decoded;
            }

            self::assertCount(3, $records, 'Each group should invoke the stub exactly once.');

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
