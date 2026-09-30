<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\TestCase;

final class PsalmTesterTimeoutTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/bin/psalm-stub';

    public function testRunFailsEveryTestInATimedOutGroupWithAClearMessage(): void
    {
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withTimeout(0.3);

        $start = \microtime(true);
        $results = $tester->run([
            'a' => new Phpt(code: '<?php // a', expectation: Expectation::exact(''), arguments: '--config=slow --stub-sleep=5'),
            'b' => new Phpt(code: '<?php // b', expectation: Expectation::exact(''), arguments: '--config=slow --stub-sleep=5'),
        ]);
        $elapsed = \microtime(true) - $start;

        // Both entries share one group (same arguments), so both get the timeout error.
        self::assertSame(Outcome::Error, $results['a']->outcome);
        self::assertStringContainsString('--config=slow --stub-sleep=5', (string) $results['a']->reason);
        self::assertStringContainsString('0.3', (string) $results['a']->reason);
        self::assertStringContainsStringIgnoringCase('timeout', (string) $results['a']->reason);
        self::assertSame($results['a']->reason, $results['b']->reason);

        // The stub sleeps 5s; a killed-at-0.3s group proves termination actually happened.
        self::assertLessThan(4.0, $elapsed, \sprintf('Expected the group to be killed well before its 5s sleep, took %.2fs.', $elapsed));
    }

    public function testRunLeavesOtherGroupsUnaffectedByATimeoutInOneGroup(): void
    {
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withTimeout(0.3);

        $results = $tester->run([
            'slow' => new Phpt(code: '<?php // slow', expectation: Expectation::exact(''), arguments: '--config=slow --stub-sleep=5'),
            'fast' => new Phpt(code: '<?php // fast', expectation: Expectation::exact(''), arguments: '--config=fast'),
        ]);

        self::assertSame(Outcome::Error, $results['slow']->outcome);
        self::assertStringContainsStringIgnoringCase('timeout', (string) $results['slow']->reason);
        self::assertMatchesRegularExpression('/^StubError on line 1: stub error for code_\w+$/', $results['fast']->output);
    }

    public function testRunKillsGrandchildProcessesOfATimedOutGroup(): void
    {
        $pidFile = \tempnam(\sys_get_temp_dir(), 'psalm_tester_grandchild_pid_');
        self::assertNotFalse($pidFile);
        @\unlink($pidFile); // the stub creates it; start from "doesn't exist yet"

        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withTimeout(0.3);

        try {
            \putenv('STUB_MODE=spawn_grandchild');
            \putenv('STUB_GRANDCHILD_PID_FILE=' . $pidFile);

            $results = $tester->run([
                'a' => new Phpt(code: '<?php // a', expectation: Expectation::exact('')),
            ]);

            self::assertStringContainsStringIgnoringCase('timeout', (string) $results['a']->reason);

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

    public function testRunDoesNotTimeOutWhenNoTimeoutIsSet(): void
    {
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH);

        $results = $tester->run([
            'a' => new Phpt(code: '<?php // a', expectation: Expectation::exact('')),
        ]);

        self::assertMatchesRegularExpression('/^StubError on line 1: stub error for code_\w+$/', $results['a']->output);
    }
}
