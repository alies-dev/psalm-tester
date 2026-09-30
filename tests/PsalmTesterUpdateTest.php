<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\TestCase;

/**
 * Covers PsalmTester::withUpdate() and the PSALM_TESTER_UPDATE rewrite path: run() rewrites a
 * Failed test's --EXPECT-- section in place with the actual output, leaving everything else
 * (other sections, line endings, trailing bytes) untouched.
 */
final class PsalmTesterUpdateTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/bin/psalm-stub';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        \putenv('STUB_MODE=echo_code');
    }

    protected function tearDown(): void
    {
        \putenv('STUB_MODE');
        \putenv('PSALM_TESTER_UPDATE');

        foreach ($this->tempFiles as $file) {
            @\unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testUpdateRewritesTheExpectSectionInPlace(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // rewrite-me\n--EXPECT--\nstale text\n");

        $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)
            ->runOne(Phpt::fromFile($file));

        self::assertSame(Outcome::Updated, $result->outcome);
        self::assertSame(
            "--FILE--\n<?php // rewrite-me\n--EXPECT--\nStubError on line 2: // rewrite-me",
            \file_get_contents($file),
        );
    }

    public function testUpdateIsIdempotent(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // idempotent\n--EXPECT--\nstale text\n");
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true);

        $tester->runOne(Phpt::fromFile($file));
        $second = $tester->runOne(Phpt::fromFile($file));

        self::assertSame(Outcome::Passed, $second->outcome);
    }

    public function testMatchingExpectfIsLeftAlone(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // fmt-ok\n--EXPECTF--\nStubError on line %d: %s\n");
        $before = \file_get_contents($file);

        $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)
            ->runOne(Phpt::fromFile($file));

        self::assertSame(Outcome::Passed, $result->outcome);
        self::assertSame($before, \file_get_contents($file));
    }

    public function testAMismatchedExpectfIsNeverRewritten(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // fmt-bad\n--EXPECTF--\nWrongType on line %d: %s\n");
        $before = \file_get_contents($file);

        $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)
            ->runOne(Phpt::fromFile($file));

        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertSame($before, \file_get_contents($file));
    }

    public function testNotUpdatedFilesAreReportedOnStderr(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // report\n--EXPECTF--\nWrongType on line %d: %s\n");
        $stderr = $this->runInSubprocess($file, true);

        self::assertStringContainsString(
            \sprintf('not updated: %s (EXPECTF cannot be rewritten)', $file),
            $stderr,
        );
    }

    public function testUpdatedFilesAreReportedOnStderr(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // report-updated\n--EXPECT--\nstale\n");
        $stderr = $this->runInSubprocess($file, true);

        self::assertStringContainsString(\sprintf('updated: %s', $file), $stderr);
    }

    public function testXfailTestsAreNeverRewrittenUnderUpdateMode(): void
    {
        $file = $this->writePhpt("--XFAIL--\nknown limitation\n--FILE--\n<?php // xfail-report\n--EXPECT--\nwrong\n");
        $stderr = $this->runInSubprocess($file, true);

        self::assertStringContainsString(\sprintf('not updated: %s (has --XFAIL--)', $file), $stderr);
    }

    public function testUpdatePreservesCrlfLineEndings(): void
    {
        $file = $this->writePhpt("--FILE--\r\n<?php // crlf\r\n--EXPECT--\r\nstale\r\n");

        PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)
            ->runOne(Phpt::fromFile($file));

        self::assertSame(
            "--FILE--\r\n<?php // crlf\r\n--EXPECT--\r\nStubError on line 2: // crlf",
            \file_get_contents($file),
        );
    }

    public function testEnvVariableSetsTheDefault(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // env\n--EXPECT--\nstale\n");
        \putenv('PSALM_TESTER_UPDATE=1');

        $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->runOne(Phpt::fromFile($file));

        self::assertSame(Outcome::Updated, $result->outcome);
    }

    public function testWithUpdateFalseOverridesTheEnvDefault(): void
    {
        $contents = "--FILE--\n<?php // env-off\n--EXPECT--\nstale\n";
        $file = $this->writePhpt($contents);
        \putenv('PSALM_TESTER_UPDATE=1');

        $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(false)
            ->runOne(Phpt::fromFile($file));

        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertSame($contents, \file_get_contents($file));
    }

    private function writePhpt(string $contents): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'psalm_test_update_');
        self::assertNotFalse($file);
        self::assertNotFalse(\file_put_contents($file, $contents));
        $this->tempFiles[] = $file;

        return $file;
    }

    /**
     * Runs update mode on $file in a subprocess so STDERR (written directly to the STDERR
     * constant, which cannot be intercepted in-process) can be captured.
     */
    private function runInSubprocess(string $file, bool $update): string
    {
        $script = <<<'PHP'
            require $argv[1];
            $tester = AliesDev\PsalmTester\PsalmTester::create()->withPsalm($argv[2])->withUpdate((bool) $argv[3]);
            $tester->runOne(AliesDev\PsalmTester\Phpt::fromFile($argv[4]));
            PHP;
        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, '-r', $script, \dirname(__DIR__) . '/vendor/autoload.php', self::STUB_PATH, $update ? '1' : '', $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            \getenv() ?: null,
        );
        self::assertIsResource($process);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        \proc_close($process);

        return $stderr;
    }
}
