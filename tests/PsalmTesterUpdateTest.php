<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
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

    /** @var list<string> */
    private array $tempDirs = [];

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

        foreach ($this->tempDirs as $dir) {
            foreach (\glob($dir . '/{,.}*', \GLOB_BRACE) ?: [] as $entry) {
                if (\is_file($entry)) {
                    @\unlink($entry);
                }
            }
            @\rmdir($dir);
        }
        $this->tempDirs = [];
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

    public function testNotUpdatedFilesAreReportedOnStderrWithProgress(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // report\n--EXPECTF--\nWrongType on line %d: %s\n");
        $stderr = $this->runInSubprocess($file, true, true);

        self::assertStringContainsString(
            \sprintf('not updated: %s (EXPECTF cannot be rewritten)', $file),
            $stderr,
        );
    }

    public function testUpdatedFilesAreReportedOnStderrWithProgress(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // report-updated\n--EXPECT--\nstale\n");
        $stderr = $this->runInSubprocess($file, true, true);

        self::assertStringContainsString(\sprintf('updated: %s', $file), $stderr);
    }

    public function testAnXfailTestKeepsItsOutcomeAndReasonUnderUpdateMode(): void
    {
        $contents = "--XFAIL--\nknown limitation\n--FILE--\n<?php // xfail-kept\n--EXPECT--\nwrong\n";
        $file = $this->writePhpt($contents);

        $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)->runOne(Phpt::fromFile($file));

        self::assertSame(Outcome::XFailed, $result->outcome);
        self::assertSame('known limitation', $result->reason);
        self::assertSame($contents, \file_get_contents($file));
    }

    public function testXfailTestsAreNeverRewrittenUnderUpdateMode(): void
    {
        $file = $this->writePhpt("--XFAIL--\nknown limitation\n--FILE--\n<?php // xfail-report\n--EXPECT--\nwrong\n");
        $stderr = $this->runInSubprocess($file, true, true);

        self::assertStringContainsString(\sprintf('not updated: %s (has --XFAIL--)', $file), $stderr);
    }

    public function testUpdateModeWritesNothingToStderrWithoutProgress(): void
    {
        // Any stderr fails a --process-isolation test, so the report stays in Result::$reason.
        $updated = $this->writePhpt("--FILE--\n<?php // quiet\n--EXPECT--\nstale\n");
        $ineligible = $this->writePhpt("--FILE--\n<?php // quiet-fmt\n--EXPECTF--\nWrongType on line %d: %s\n");

        self::assertSame('', $this->runInSubprocess($updated, true, false));
        self::assertSame('', $this->runInSubprocess($ineligible, true, false));
        self::assertStringContainsString('StubError on line 2: // quiet', (string) \file_get_contents($updated));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRewrites(): iterable
    {
        $out = 'StubError on line 2: // a';
        yield 'EXPECT in the middle' => ["--FILE--\n<?php // a\n--EXPECT--\nstale\n--ARGS--\n--no-cache\n", "--FILE--\n<?php // a\n--EXPECT--\n{$out}\n--ARGS--\n--no-cache\n"];
        // One body line before and after, so the FILE section (and its reported line) stays put.
        yield 'EXPECT before FILE' => ["--EXPECT--\nstale\n--FILE--\n<?php // a\n", "--EXPECT--\nStubError on line 4: // a\n--FILE--\n<?php // a\n"];
        yield 'EXPECT last, final newline' => ["--FILE--\n<?php // a\n--EXPECT--\nstale\n", "--FILE--\n<?php // a\n--EXPECT--\n{$out}\n"];
        yield 'EXPECT last, no final newline' => ["--FILE--\n<?php // a\n--EXPECT--\nstale", "--FILE--\n<?php // a\n--EXPECT--\n{$out}"];
        yield 'EXPECT header at EOF' => ["--FILE--\n<?php // a\n--EXPECT--", "--FILE--\n<?php // a\n--EXPECT--\n{$out}"];
        yield 'padded next header' => ["--FILE--\n<?php // a\n--EXPECT--\nstale\n--ARGS-- \n--no-cache\n", "--FILE--\n<?php // a\n--EXPECT--\n{$out}\n--ARGS-- \n--no-cache\n"];
        yield 'padded EXPECT header' => ["--FILE--\n<?php // a\n--EXPECT--  \nstale\n", "--FILE--\n<?php // a\n--EXPECT--  \n{$out}\n"];
        yield 'CRLF' => ["--FILE--\r\n<?php // a\r\n--EXPECT--\r\nstale\r\n--ARGS--\r\n--no-cache\r\n", "--FILE--\r\n<?php // a\r\n--EXPECT--\r\n{$out}\r\n--ARGS--\r\n--no-cache\r\n"];
        yield 'empty output' => ["--ARGS--\n--stub-mode=empty\n--FILE--\n<?php // a\n--EXPECT--\nstale\n--SKIPIF--\n<?php\n", "--ARGS--\n--stub-mode=empty\n--FILE--\n<?php // a\n--EXPECT--\n--SKIPIF--\n<?php\n"];
        yield 'replacement-looking text' => ["--FILE--\n<?php // $1 \\0 \\\\ \${1}\n--EXPECT--\nstale\n", "--FILE--\n<?php // $1 \\0 \\\\ \${1}\n--EXPECT--\nStubError on line 2: // $1 \\0 \\\\ \${1}\n"];
        yield 'multi-line output' => ["--ARGS--\n--stub-mode=two_lines\n--FILE--\n<?php // a\n--EXPECT--\nstale\n", "--ARGS--\n--stub-mode=two_lines\n--FILE--\n<?php // a\n--EXPECT--\nStubError on line 4: first\nStubError on line 5: second\n"];
    }

    #[DataProvider('provideRewrites')]
    public function testUpdateReplacesExactlyTheExpectBodyAndIsIdempotent(string $before, string $after): void
    {
        $dir = $this->makeDir();
        $file = $dir . '/test.phpt';
        self::assertNotFalse(\file_put_contents($file, $before));
        \chmod($file, 0640);
        $tester = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true);

        $result = $tester->runOne(Phpt::fromFile($file));

        self::assertSame(Outcome::Updated, $result->outcome, (string) $result->reason);
        self::assertSame($after, \file_get_contents($file));
        \clearstatcache();
        self::assertSame(0640, \fileperms($file) & 0777);
        self::assertSame(['test.phpt'], \array_values(\array_diff(\scandir($dir) ?: [], ['.', '..'])), 'The atomic write must not leave temp files.');
        self::assertSame(Outcome::Passed, $tester->runOne(Phpt::fromFile($file))->outcome);
    }

    public function testOutputWithASectionHeaderLineIsNotWritten(): void
    {
        $contents = "--ARGS--\n--stub-mode=header_message\n--FILE--\n<?php // a\n--EXPECT--\nstale\n";
        $file = $this->writePhpt($contents);

        $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)->runOne(Phpt::fromFile($file));

        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertStringContainsString('not updated: ', (string) $result->reason);
        self::assertStringContainsString('section header', (string) $result->reason);
        self::assertSame($contents, \file_get_contents($file));
    }

    public function testARewriteFailureLeavesThatFileAloneAndTheRestOfTheBatchContinues(): void
    {
        $broken = $this->writePhpt("--FILE--\n<?php // broken\n--EXPECT--\nstale\n");
        $fine = $this->writePhpt("--FILE--\n<?php // fine\n--EXPECT--\nstale\n");
        $parsed = Phpt::fromFile($broken);
        // Built without a source hash, so the rewriter's own re-read (not the change check)
        // meets the duplicate section and must refuse to guess.
        $phpts = [
            'broken' => new Phpt($parsed->code, $parsed->expectation, codeFirstLine: $parsed->codeFirstLine, path: $broken),
            'fine' => Phpt::fromFile($fine),
        ];
        $duplicated = "--FILE--\n<?php // broken\n--EXPECT--\nstale\n--EXPECT--\nstale\n";
        self::assertNotFalse(\file_put_contents($broken, $duplicated));

        $results = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)->run($phpts);

        self::assertSame(Outcome::Failed, $results['broken']->outcome);
        self::assertStringContainsString(\sprintf('not updated: %s (', $broken), (string) $results['broken']->reason);
        self::assertStringContainsString('Duplicate section --EXPECT--', (string) $results['broken']->reason);
        self::assertSame($duplicated, \file_get_contents($broken));
        self::assertSame(Outcome::Updated, $results['fine']->outcome);
    }

    public function testAFileChangedAfterParsingIsNotOverwritten(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // snapshot\n--EXPECT--\nstale\n");
        $phpt = Phpt::fromFile($file);
        // An editor save between parsing and the rewrite: both the edit and the (now stale)
        // analysis of the old code must not be written over it.
        $edited = "--FILE--\n<?php // edited meanwhile\n--EXPECT--\nstale\n";
        self::assertNotFalse(\file_put_contents($file, $edited));

        $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)->runOne($phpt);

        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertSame(\sprintf('not updated: %s (changed during the run)', $file), $result->reason);
        self::assertSame($edited, \file_get_contents($file));
    }

    public function testTheSameFileTwiceInOneRunIsRewrittenOnce(): void
    {
        $file = $this->writePhpt("--FILE--\n<?php // twice\n--EXPECT--\nstale\n");

        $results = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)
            ->run(['first' => Phpt::fromFile($file), 'second' => Phpt::fromFile($file)]);

        self::assertSame(Outcome::Updated, $results['first']->outcome);
        self::assertSame(Outcome::Updated, $results['second']->outcome, (string) $results['second']->reason);
        self::assertSame("--FILE--\n<?php // twice\n--EXPECT--\nStubError on line 2: // twice\n", \file_get_contents($file));
    }

    public function testASymlinkedTestUpdatesItsTargetAndStaysALink(): void
    {
        $dir = $this->makeDir();
        \mkdir($dir . '/real');
        $target = $dir . '/real/test.phpt';
        self::assertNotFalse(\file_put_contents($target, "--FILE--\n<?php // linked\n--EXPECT--\nstale\n"));
        self::assertTrue(\symlink($target, $dir . '/link.phpt'));

        $result = PsalmTester::create()->withPsalm(self::STUB_PATH)->withUpdate(true)->runOne(Phpt::fromFile($dir . '/link.phpt'));

        self::assertSame(Outcome::Updated, $result->outcome, (string) $result->reason);
        self::assertTrue(\is_link($dir . '/link.phpt'));
        self::assertSame("--FILE--\n<?php // linked\n--EXPECT--\nStubError on line 2: // linked\n", \file_get_contents($target));
        self::assertSame(['test.phpt'], \array_values(\array_diff(\scandir($dir . '/real') ?: [], ['.', '..'])));
        @\unlink($dir . '/link.phpt');
        @\unlink($target);
        @\rmdir($dir . '/real');
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

    private function makeDir(): string
    {
        $dir = \sys_get_temp_dir() . '/psalm_test_update_dir_' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($dir));
        $this->tempDirs[] = $dir;

        return $dir;
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
    private function runInSubprocess(string $file, bool $update, bool $progress): string
    {
        $script = <<<'PHP'
            require $argv[1];
            $tester = AliesDev\PsalmTester\PsalmTester::create()->withPsalm($argv[2])->withUpdate((bool) $argv[3])->withProgress((bool) $argv[5]);
            $tester->runOne(AliesDev\PsalmTester\Phpt::fromFile($argv[4]));
            PHP;
        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, '-r', $script, \dirname(__DIR__) . '/vendor/autoload.php', self::STUB_PATH, $update ? '1' : '', $file, $progress ? '1' : ''],
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
