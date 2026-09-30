<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Inputs and Psalm runs that must not be mistaken for a clean analysis of the tested code.
 */
final class PsalmTesterGuardTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/bin/psalm-stub';

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTargetOverrides(): iterable
    {
        yield '-f <file>' => ['-f other.php'];
        yield '-f<file>' => ['-fother.php'];
        yield 'clustered -mf <file>' => ['-mf other.php'];
        yield 'an extra path' => ['--taint-analysis other.php'];
        yield 'stdin' => ['-'];
        yield 'a value after an option that takes none inline' => ['--threads 2'];
    }

    #[DataProvider('provideTargetOverrides')]
    public function testArgsThatChangeWhatPsalmAnalyzesGiveThatTestAnError(string $args): void
    {
        $results = self::tester()->run([
            'overriding' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: $args),
            'fine' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--stub-mode=empty'),
        ]);

        self::assertSame(Outcome::Error, $results['overriding']->outcome);
        self::assertStringContainsString('files to analyze', (string) $results['overriding']->reason);
        self::assertSame(Outcome::Passed, $results['fine']->outcome);
    }

    #[TestWith(['--config', 'psalm.xml', '--root', '/project', '-c', 'x.xml', '-r', '/r', '--threads=2', '--report=out.json', '--taint-analysis'])]
    public function testOptionsWithValuesAreNotMistakenForPaths(string ...$args): void
    {
        self::assertInstanceOf(PsalmTester::class, PsalmTester::create()->withArguments(...$args));
    }

    #[TestWith(['-f', 'other.php'])]
    #[TestWith(['other.php'])]
    public function testWithArgumentsRejectsArgsThatChangeWhatPsalmAnalyzes(string ...$args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('files to analyze');

        PsalmTester::create()->withArguments(...$args);
    }

    public function testAnUnterminatedQuoteInArgsGivesOnlyThatTestAnError(): void
    {
        $results = self::tester()->run([
            'broken' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--config="unclosed'),
            'fine' => new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--stub-mode=empty'),
        ]);

        self::assertSame(Outcome::Error, $results['broken']->outcome);
        self::assertStringContainsString('Unterminated', (string) $results['broken']->reason);
        self::assertSame(Outcome::Passed, $results['fine']->outcome);
    }

    #[TestWith(['1', 'exit code 1'])]
    #[TestWith(['255', 'exit code 255'])]
    #[TestWith(['signal', 'signal 9'])]
    public function testAnAbnormalExitGivesAnErrorEvenWithCleanOutput(string $exit, string $expected): void
    {
        $result = self::tester()->runOne(new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: "--stub-mode=empty --stub-exit={$exit}"));

        self::assertSame(Outcome::Error, $result->outcome);
        self::assertStringContainsString($expected, (string) $result->reason);
    }

    public function testExitCode2IsPsalmReportingIssues(): void
    {
        $result = self::tester()->runOne(new Phpt(code: '<?php', expectation: Expectation::format('StubError on line 1: %s'), arguments: '--stub-exit=2'));

        self::assertSame(Outcome::Passed, $result->outcome);
    }

    public function testAJsonObjectIsNotAnEmptyIssueList(): void
    {
        $result = self::tester()->runOne(new Phpt(code: '<?php', expectation: Expectation::exact(''), arguments: '--stub-mode=object_json'));

        self::assertSame(Outcome::Error, $result->outcome);
        self::assertStringContainsString('not a list of issues', (string) $result->reason);
    }

    public function testASkipifScriptIsJudgedByItsOutputOnlyLikeRunTests(): void
    {
        $result = self::tester()->runOne(new Phpt(code: '<?php', expectation: Expectation::exact(''), skipif: "<?php echo 'skip crashed after deciding'; exit(3);"));

        self::assertSame(Outcome::Skipped, $result->outcome);
        self::assertSame('crashed after deciding', $result->reason);
    }

    public function testNonPositiveConcurrencyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PsalmTester::create()->withConcurrency(0);
    }

    private static function tester(): PsalmTester
    {
        return PsalmTester::create()->withPsalm(self::STUB_PATH);
    }
}
