<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Tests\Fixtures\PsalmPhptTestCase\FixturePhptCase;
use PHPUnit\Framework\TestCase;

final class PsalmPhptTestCaseTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/PsalmPhptTestCase/FixturePhptCase.php';
    private const ASSERTING_FIXTURE = __DIR__ . '/Fixtures/PsalmPhptTestCase/AssertingFixturePhptCase.php';
    private const BROKEN_FIXTURE = __DIR__ . '/Fixtures/PsalmPhptTestCase/BrokenFixturePhptCase.php';

    private string $logDir = '';

    protected function setUp(): void
    {
        $this->logDir = \sys_get_temp_dir() . '/psalm_tester_phpt_case_' . \bin2hex(\random_bytes(4));
        \mkdir($this->logDir);
    }

    protected function tearDown(): void
    {
        \putenv('STUB_MODE');
        \putenv('STUB_CONTENTS_LOG_DIR');
        FixturePhptCase::tearDownAfterClass();

        foreach (\glob($this->logDir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->logDir);
    }

    public function testDataSetsAreSortedRelativePaths(): void
    {
        self::assertSame(
            ['alpha.phpt', 'beta.phpt', 'skipped.phpt', 'sub/gamma.phpt'],
            \array_keys(\iterator_to_array(FixturePhptCase::phptFiles())),
        );
    }

    public function testUnfilteredRunAnalyzesEveryNonSkippedFileInOneBatch(): void
    {
        [$exitCode, $output] = $this->runFixture([]);

        self::assertSame([['<?php // alpha', '<?php // beta', '<?php // gamma']], $this->analyzedContents());
        self::assertSame(0, $exitCode, $output);
        self::assertMatchesRegularExpression(self::summary(tests: 4, assertions: 3, suffix: 'Skipped: 1'), $output);
        self::assertStringContainsString('fixture is always skipped', $output);
    }

    public function testAProcessIsolatedRunPasses(): void
    {
        // PHPUnit fails an isolated test whose child process wrote to stderr, so the default
        // tester must stay quiet there.
        [$exitCode, $output] = $this->runFixture(['--process-isolation']);

        self::assertSame(0, $exitCode, $output);
        self::assertMatchesRegularExpression(self::summary(tests: 4, assertions: 3, suffix: 'Skipped: 1'), $output);
    }

    public function testPrintsOneStartLineWithFileSkipAndGroupCounts(): void
    {
        // The fixture: 4 files, 1 SKIPIF-skipped, the other 3 sharing the same (default) --ARGS--.
        [, $output] = $this->runFixture([]);
        self::assertStringContainsString('psalm-tester: 4 phpt files (1 skipped), 1 Psalm run', $output);
    }

    public function testPrintsTheStartLineExactlyOnceUnderProcessIsolationToo(): void
    {
        // setUpBeforeClass() runs once in PHPUnit's coordinating process even with
        // --process-isolation (the batching this class exists for depends on that), so the start
        // line is safe there and the run must still pass.
        [$exitCode, $output] = $this->runFixture(['--process-isolation']);

        self::assertSame(0, $exitCode, $output);
        self::assertSame(1, \substr_count($output, 'psalm-tester: 4 phpt files (1 skipped), 1 Psalm run'), $output);
    }

    public function testDataSetFilterSelectsANestedFileByItsRelativePath(): void
    {
        [$exitCode, $output] = $this->runFixture(['--filter', 'testPhpt@sub/gamma.phpt']);

        self::assertSame([['<?php // gamma']], $this->analyzedContents());
        self::assertSame(0, $exitCode, $output);
        self::assertMatchesRegularExpression(self::summary(tests: 1, assertions: 1), $output);
    }

    public function testAMalformedFileFailsOnlyItsOwnTest(): void
    {
        [$exitCode, $output] = $this->runFixture([], self::BROKEN_FIXTURE);

        self::assertSame([['<?php // ok']], $this->analyzedContents());
        self::assertSame(2, $exitCode, $output);
        self::assertStringContainsString('must have a FILE section', $output);
        self::assertMatchesRegularExpression(self::summary(tests: 2, assertions: 1, suffix: 'Errors: 1'), $output);
    }

    public function testEachFileIsAssertedAgainstItsOwnAnalysisOutput(): void
    {
        [$exitCode, $output] = $this->runFixture([], self::ASSERTING_FIXTURE);

        self::assertSame(1, $exitCode, $output);
        self::assertMatchesRegularExpression(self::summary(tests: 2, assertions: 2, suffix: 'Failures: 1'), $output);
        // Data set names print as "testPhpt@name" (PHPUnit 13) or 'with data set "name"' (11, 12).
        self::assertMatchesRegularExpression('/(@|data set ")mismatch\.phpt/', $output);
        self::assertDoesNotMatchRegularExpression('/(@|data set ")match\.phpt/', $output);
        self::assertStringContainsString("-'StubError on line 4: // expected'", $output);
        self::assertStringContainsString("+'StubError on line 4: // actual'", $output);
    }

    public function testWithoutAnOwningTestSuiteEachTestAnalyzesOnlyItself(): void
    {
        \putenv('STUB_MODE=record_contents');
        \putenv('STUB_CONTENTS_LOG_DIR=' . $this->logDir);

        // Called from this test, not from FixturePhptCase's own suite, so no selection is known.
        FixturePhptCase::setUpBeforeClass();
        self::assertSame([], $this->analyzedContents());

        (new FixturePhptCase('testPhpt'))->testPhpt('beta.phpt');
        self::assertSame([['<?php // beta']], $this->analyzedContents());
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runFixture(array $arguments, string $fixture = self::FIXTURE): array
    {
        $root = \dirname(__DIR__);
        $command = [
            \PHP_BINARY,
            $root . '/vendor/bin/phpunit',
            '--no-configuration',
            '--bootstrap',
            $root . '/vendor/autoload.php',
            '--no-progress',
            '--colors=never',
            '--display-skipped',
            ...$arguments,
            $fixture,
        ];

        $env = \getenv();
        $env['STUB_MODE'] = 'record_contents';
        $env['STUB_CONTENTS_LOG_DIR'] = $this->logDir;

        $pipes = [];
        $process = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root, $env);
        self::assertIsResource($process);
        $output = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);

        return [\proc_close($process), $output];
    }

    /**
     * Matches PHPUnit's result summary while tolerating extra counts (e.g. deprecations PHP 8.4+
     * raises inside older PHPUnit releases themselves).
     */
    private static function summary(int $tests, int $assertions, string $suffix = ''): string
    {
        if ($suffix === '') {
            return \sprintf('/OK \(%d tests?, %d assertions?\)|Tests: %1$d, Assertions: %2$d, Deprecations: \d+\./', $tests, $assertions);
        }

        return \sprintf('/Tests: %d, Assertions: %d, .*%s\./', $tests, $assertions, $suffix);
    }

    /**
     * @return list<list<string>> contents of the files each stub Psalm invocation received
     */
    private function analyzedContents(): array
    {
        $invocations = [];

        foreach (\glob($this->logDir . '/*.json') ?: [] as $log) {
            /** @var list<string> $contents */
            $contents = \json_decode((string) \file_get_contents($log), true);
            \sort($contents);
            $invocations[] = $contents;
        }

        return $invocations;
    }
}
