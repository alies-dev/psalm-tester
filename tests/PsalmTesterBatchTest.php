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

    private static function createTester(string $defaultArguments = ''): PsalmTester
    {
        return PsalmTester::create(
            psalmPath: self::STUB_PATH,
            defaultArguments: $defaultArguments,
            showProgress: false,
        );
    }
}
