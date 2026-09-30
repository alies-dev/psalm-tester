<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\PsalmTest;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\TestCase;

final class PsalmTesterTimeoutTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/bin/psalm-stub';

    public function testRunBatchFailsEveryTestInATimedOutGroupWithAClearMessage(): void
    {
        $tester = PsalmTester::create(psalmPath: self::STUB_PATH, showProgress: false, timeoutSeconds: 0.3);

        $start = \microtime(true);
        $results = $tester->runBatch([
            'a' => new PsalmTest(code: '<?php // a', constraint: new IsIdentical(''), arguments: '--config=slow --stub-sleep=5'),
            'b' => new PsalmTest(code: '<?php // b', constraint: new IsIdentical(''), arguments: '--config=slow --stub-sleep=5'),
        ]);
        $elapsed = \microtime(true) - $start;

        // Both entries share one group (same arguments), so both get the timeout message.
        self::assertStringContainsString('--config=slow --stub-sleep=5', $results['a']);
        self::assertStringContainsString('0.3', $results['a']);
        self::assertStringContainsStringIgnoringCase('timeout', $results['a']);
        self::assertSame($results['a'], $results['b']);

        // The stub sleeps 5s; a killed-at-0.3s group proves termination actually happened.
        self::assertLessThan(4.0, $elapsed, \sprintf('Expected the group to be killed well before its 5s sleep, took %.2fs.', $elapsed));
    }

    public function testRunBatchLeavesOtherGroupsUnaffectedByATimeoutInOneGroup(): void
    {
        $tester = PsalmTester::create(psalmPath: self::STUB_PATH, showProgress: false, timeoutSeconds: 0.3);

        $results = $tester->runBatch([
            'slow' => new PsalmTest(code: '<?php // slow', constraint: new IsIdentical(''), arguments: '--config=slow --stub-sleep=5'),
            'fast' => new PsalmTest(code: '<?php // fast', constraint: new IsIdentical(''), arguments: '--config=fast'),
        ]);

        self::assertStringContainsStringIgnoringCase('timeout', $results['slow']);
        self::assertMatchesRegularExpression('/^StubError on line 1: stub error for code_\w+$/', $results['fast']);
    }

    public function testRunBatchDoesNotTimeOutWhenTimeoutSecondsIsNull(): void
    {
        $tester = PsalmTester::create(psalmPath: self::STUB_PATH, showProgress: false);

        $results = $tester->runBatch([
            'a' => new PsalmTest(code: '<?php // a', constraint: new IsIdentical('')),
        ]);

        self::assertMatchesRegularExpression('/^StubError on line 1: stub error for code_\w+$/', $results['a']);
    }
}
