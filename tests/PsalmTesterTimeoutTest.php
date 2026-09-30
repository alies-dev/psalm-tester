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

    public function testRunBatchKillsGrandchildProcessesOfATimedOutGroup(): void
    {
        $pidFile = \tempnam(\sys_get_temp_dir(), 'psalm_tester_grandchild_pid_');
        self::assertNotFalse($pidFile);
        @\unlink($pidFile); // the stub creates it; start from "doesn't exist yet"

        $tester = PsalmTester::create(psalmPath: self::STUB_PATH, showProgress: false, timeoutSeconds: 0.3);

        try {
            \putenv('STUB_MODE=spawn_grandchild');
            \putenv('STUB_GRANDCHILD_PID_FILE=' . $pidFile);

            $results = $tester->runBatch([
                'a' => new PsalmTest(code: '<?php // a', constraint: new IsIdentical('')),
            ]);

            self::assertStringContainsStringIgnoringCase('timeout', $results['a']);

            // The grandchild writes its pid file immediately, before its own 30s sleep.
            $deadline = \microtime(true) + 2.0;
            while (!\is_file($pidFile) && \microtime(true) < $deadline) {
                \usleep(10_000);
            }
            self::assertFileExists($pidFile, 'The stub should have spawned a grandchild and recorded its pid.');

            $grandchildPid = (int) \trim((string) \file_get_contents($pidFile));
            self::assertGreaterThan(0, $grandchildPid);

            // Give the kill a moment to land, then confirm the grandchild is actually dead —
            // not just its immediate parent (the process proc_open() returned to us).
            \usleep(300_000);
            /** @psalm-suppress ForbiddenCode */
            $alive = \trim((string) \shell_exec(\sprintf('ps -p %d -o pid= 2>/dev/null', $grandchildPid)));
            self::assertSame('', $alive, \sprintf('Expected grandchild pid %d to be dead after the group timeout.', $grandchildPid));
        } finally {
            \putenv('STUB_MODE');
            \putenv('STUB_GRANDCHILD_PID_FILE');
            @\unlink($pidFile);
        }
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
