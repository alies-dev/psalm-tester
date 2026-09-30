<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\FailureReport;
use AliesDev\PsalmTester\Issue;
use PHPUnit\Framework\TestCase;

final class FailureReportTest extends TestCase
{
    public function testMissingAndUnexpectedIssuesAreReportedWithASnippet(): void
    {
        $report = FailureReport::build(
            Expectation::exact('TaintedHtml on line 5: Detected tainted HTML'),
            [new Issue('TaintedSSRF', 4, 1, 'Detected tainted network request')],
            "<?php\n\$in = \$_GET['x'];\n\$client = new Client();\n\$client->get(\$in);\n",
            1,
        );

        self::assertSame(
            "Psalm reported what the expectation did not list, or missed what it did:\n"
            . "  missing     TaintedHtml: Detected tainted HTML\n"
            . "  unexpected  line 4  TaintedSSRF: Detected tainted network request\n"
            . '              | $client->get($in);',
            $report,
        );
    }

    public function testACheckTypeIssueIsReportedAsExpectedVersusActualInsteadOfARawMessage(): void
    {
        $report = FailureReport::build(
            Expectation::exact(''),
            [new Issue('CheckType', 14, 1, 'Checked variable $user = App\Models\User does not match $user = App\Models\User|null')],
            "<?php\n",
            1,
        );

        self::assertSame(
            "Psalm reported what the expectation did not list, or missed what it did:\n"
            . '  type        line 14 $user: expected App\Models\User, actual App\Models\User|null',
            $report,
        );
    }

    public function testAPossiblyUndefinedCheckTypeVariableIsStillParsed(): void
    {
        $report = FailureReport::build(
            Expectation::exact(''),
            [new Issue('CheckType', 3, 1, 'Checked variable $x = int does not match $x? = int|null')],
            "<?php\n",
            1,
        );

        self::assertSame(
            "Psalm reported what the expectation did not list, or missed what it did:\n"
            . '  type        line 3  $x: expected int, actual int|null',
            $report,
        );
    }

    public function testAFormatPlaceholderOtherThanOnLinePercentDFallsBackToNull(): void
    {
        $report = FailureReport::build(
            Expectation::format('TaintedHtml on line %d: %A'),
            [new Issue('TaintedHtml', 1, 1, 'anything')],
            "<?php\n",
            1,
        );

        self::assertNull($report);
    }

    public function testTextThatIsNotShapedLikeAnIssueListFallsBackToNull(): void
    {
        $report = FailureReport::build(
            Expectation::exact("free-form text\nnot describing Psalm issues at all"),
            [new Issue('TaintedHtml', 1, 1, 'anything')],
            "<?php\n",
            1,
        );

        self::assertNull($report);
    }

    public function testFullyMatchingIssuesReportNothing(): void
    {
        $report = FailureReport::build(
            Expectation::exact('TaintedHtml on line 1: Detected tainted HTML'),
            [new Issue('TaintedHtml', 1, 1, 'Detected tainted HTML')],
            "<?php\n",
            1,
        );

        self::assertNull($report);
    }

    public function testNoIssuesExpectedAndNoneReportedIsNull(): void
    {
        self::assertNull(FailureReport::build(Expectation::exact(''), [], "<?php\n", 1));
    }

    public function testALiteralExpectedLineOnlyMatchesThatExactLine(): void
    {
        $report = FailureReport::build(
            Expectation::exact('TaintedHtml on line 1: Detected tainted HTML'),
            [new Issue('TaintedHtml', 2, 1, 'Detected tainted HTML')],
            "<?php\n\n",
            1,
        );

        self::assertSame(
            "Psalm reported what the expectation did not list, or missed what it did:\n"
            . "  missing     TaintedHtml: Detected tainted HTML\n"
            . '  unexpected  line 2  TaintedHtml: Detected tainted HTML',
            $report,
        );
    }

    public function testAnOnLinePercentDExpectationMatchesAnyLine(): void
    {
        $report = FailureReport::build(
            Expectation::format('TaintedHtml on line %d: Detected tainted HTML'),
            [new Issue('TaintedHtml', 7, 1, 'Detected tainted HTML')],
            "<?php\n",
            1,
        );

        self::assertNull($report);
    }
}
