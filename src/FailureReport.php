<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * Explains a failed expectation as issues Psalm reported that the expectation did not list
 * (unexpected, with the code line they were reported on), issues the expectation listed that
 * Psalm did not report (missing), and Psalm's own CheckType mismatches shown as an expected and
 * an actual type instead of a raw message. Presentation only: PHPUnit's own diff, built from the
 * same expectation and output, still decides pass and fail; this is an extra message alongside it.
 *
 * @internal
 */
final class FailureReport
{
    /**
     * Null when the expectation does not parse line-wise (a placeholder other than "on line %d",
     * or text that is not "<Type> on line <N or %d>: <message>" at all): building a report by
     * guessing at such a line would risk showing something that is not actually true.
     *
     * @param list<Issue> $issues the actual issues, already phpt-line-adjusted like Issue::$line
     */
    public static function build(Expectation $expectation, array $issues, string $code, int $codeFirstLine): ?string
    {
        $expected = self::parseExpectation($expectation->text);

        if ($expected === null) {
            return null;
        }

        $remaining = $issues;
        $missing = [];

        foreach ($expected as $line) {
            $index = self::findMatch($line, $remaining);

            if ($index === null) {
                $missing[] = $line;
            } else {
                unset($remaining[$index]);
            }
        }

        if ($missing === [] && $remaining === []) {
            return null;
        }

        $codeLines = \explode("\n", $code);
        $report = ['Psalm reported what the expectation did not list, or missed what it did:'];

        foreach ($missing as $line) {
            $report[] = \sprintf('  %-12s%s: %s', 'missing', $line['type'], $line['message']);
        }

        foreach ($remaining as $issue) {
            $checkType = $issue->type === 'CheckType' ? self::parseCheckType($issue->message) : null;

            if ($checkType !== null) {
                $report[] = \sprintf('  %-12sline %-2d %s: expected %s, actual %s', 'type', $issue->line, $checkType['var'], $checkType['expected'], $checkType['actual']);

                continue;
            }

            $report[] = \sprintf('  %-12sline %-2d %s: %s', 'unexpected', $issue->line, $issue->type, $issue->message);
            $snippet = \trim($codeLines[$issue->line - $codeFirstLine] ?? '');

            if ($snippet !== '') {
                $report[] = \str_repeat(' ', 14) . '| ' . $snippet;
            }
        }

        return \implode("\n", $report);
    }

    /**
     * @return list<array{type: string, line: ?int, message: string}>|null one entry per
     *     expectation line ('' means none expected), null when any line does not parse
     */
    private static function parseExpectation(string $text): ?array
    {
        if ($text === '') {
            return [];
        }

        $lines = [];

        foreach (\explode("\n", $text) as $raw) {
            if (\preg_match('/^(\w+) on line (%d|\d+): (.*)$/', $raw, $m) !== 1 || \preg_match('/%[a-zA-Z]/', $m[3]) === 1) {
                return null;
            }

            $lines[] = ['type' => $m[1], 'line' => $m[2] === '%d' ? null : (int) $m[2], 'message' => $m[3]];
        }

        return $lines;
    }

    /**
     * The first not-yet-matched issue with the same type and message (and, when the expectation
     * gave a literal line, the same line): greedy, in the expectation's own order.
     *
     * @param array{type: string, line: ?int, message: string} $expected
     * @param array<int, Issue> $actual
     */
    private static function findMatch(array $expected, array $actual): ?int
    {
        foreach ($actual as $index => $issue) {
            if ($issue->type === $expected['type'] && $issue->message === $expected['message']
                && ($expected['line'] === null || $expected['line'] === $issue->line)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array{var: string, expected: string, actual: string}|null
     */
    private static function parseCheckType(string $message): ?array
    {
        if (\preg_match('/^Checked variable \$(\w+)(\??) = (.+) does not match \$\1(\??) = (.+)$/', $message, $m) !== 1) {
            return null;
        }

        return [
            'var' => '$' . $m[1],
            'expected' => $m[3] . ($m[2] === '?' ? ' (possibly undefined)' : ''),
            'actual' => $m[5] . ($m[4] === '?' ? ' (possibly undefined)' : ''),
        ];
    }
}
