<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    private string $scratch = '';

    protected function setUp(): void
    {
        $this->scratch = \sys_get_temp_dir() . '/psalm_tester_runner_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($this->scratch . '/tmp', 0777, true));
    }

    protected function tearDown(): void
    {
        foreach ([...(\glob($this->scratch . '/tmp/*') ?: []), ...(\glob($this->scratch . '/*') ?: [])] as $file) {
            \is_dir($file) ? @\rmdir($file) : @\unlink($file);
        }
        @\rmdir($this->scratch);
    }

    public function testAChildClosingStdoutEarlyNeitherEndsItNorStallsOthers(): void
    {
        $start = \microtime(true);
        $completedAt = [];
        $outputs = [];

        ProcessRunner::run(
            [
                'early' => ['command' => [\PHP_BINARY, '-r', 'echo "early"; fclose(STDOUT); usleep(1500000);']],
                'large' => ['command' => [\PHP_BINARY, '-r', 'usleep(200000); echo str_repeat("x", 1 << 20);']],
            ],
            2,
            $this->scratch . '/tmp',
            static function (string $id, string $output) use ($start, &$completedAt, &$outputs): void {
                $completedAt[$id] = \microtime(true) - $start;
                $outputs[$id] = $output;
            },
        );

        self::assertSame(['large', 'early'], \array_keys($completedAt));
        self::assertLessThan(1.2, $completedAt['large'], 'The sibling must not wait for the early-closing child.');
        self::assertGreaterThanOrEqual(1.5, $completedAt['early'], 'Closing stdout is not exiting.');
        self::assertSame('early', $outputs['early']);
        self::assertSame(1 << 20, \strlen($outputs['large']));
        self::assertSame([], \glob($this->scratch . '/tmp/*'));
    }

    public function testAnExceptionFromTheCompletionCallbackKillsTheOtherChildren(): void
    {
        $pidFile = $this->scratch . '/sleeper.pid';
        $jobs = [
            'sleeper' => ['command' => [\PHP_BINARY, '-r', \sprintf('file_put_contents(%s, getmypid()); sleep(30);', \var_export($pidFile, true))]],
            'quick' => ['command' => [\PHP_BINARY, '-r', \sprintf('while (!is_file(%s)) usleep(10000);', \var_export($pidFile, true))]],
        ];

        $start = \microtime(true);

        try {
            ProcessRunner::run($jobs, 2, $this->scratch . '/tmp', static function (string $id): void {
                throw new \RuntimeException('callback failed for ' . $id);
            });
            self::fail('Expected the callback exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('callback failed for quick', $e->getMessage());
        }

        self::assertLessThan(10.0, \microtime(true) - $start);
        /** @psalm-suppress ForbiddenCode */
        self::assertSame('', \trim((string) \shell_exec('ps -p ' . (int) \file_get_contents($pidFile) . ' -o pid= 2>/dev/null')), 'The sleeper survived.');
        self::assertSame([], \glob($this->scratch . '/tmp/*'));
    }

    public function testAFailedStartKillsRunningChildrenAndRemovesEveryTemporaryFile(): void
    {
        $pidFile = $this->scratch . '/sleeper.pid';
        $jobs = [
            'sleeper' => ['command' => [\PHP_BINARY, '-r', \sprintf('file_put_contents(%s, getmypid()); sleep(30);', \var_export($pidFile, true))]],
            // Holds the second slot until the sleeper is known to run, so the failing start below
            // happens while the sleeper is live.
            'gate' => ['command' => [\PHP_BINARY, '-r', \sprintf('while (!is_file(%s)) usleep(10000);', \var_export($pidFile, true))]],
            'broken' => ['command' => []],
        ];

        $start = \microtime(true);

        try {
            /** @psalm-suppress InvalidArgument an empty command is the start failure under test */
            ProcessRunner::run($jobs, 2, $this->scratch . '/tmp', static function (): void {});
            self::fail('Expected the empty command to fail to start.');
        } catch (\ValueError) {
            // proc_open() rejects an empty command array.
        }

        self::assertLessThan(10.0, \microtime(true) - $start);
        $pid = (int) \file_get_contents($pidFile);
        self::assertGreaterThan(0, $pid);
        /** @psalm-suppress ForbiddenCode */
        self::assertSame('', \trim((string) \shell_exec('ps -p ' . $pid . ' -o pid= 2>/dev/null')), 'The sleeper survived.');
        self::assertSame([], \glob($this->scratch . '/tmp/*'), 'Stdout files, including the failed start\'s, must be removed.');
    }
}
