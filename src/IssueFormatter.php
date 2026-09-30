<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * Turns Psalm's --output-format=json into Issues and the "Type on line N: message" text that
 * expectations are written against.
 *
 * @internal
 * @psalm-type PsalmError = array{type: string, column_from: int, line_from: int, message: string, file_path: string, ...}
 */
final class IssueFormatter
{
    /**
     * @return array<string, list<PsalmError>> keyed by the reported file's real path
     */
    public static function decodeByFile(string $output, string $args): array
    {
        try {
            /** @var list<PsalmError> $errors */
            $errors = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf(
                "Failed to decode Psalm JSON output for args [%s]: %s\nOutput: %s",
                $args,
                $e->getMessage(),
                $output,
            ), previous: $e);
        }

        $errorsByFile = [];

        foreach ($errors as $error) {
            $errorsByFile[self::fileKey($error['file_path'])][] = $error;
        }

        return $errorsByFile;
    }

    public static function fileKey(string $path): string
    {
        $resolved = \realpath($path);

        return $resolved !== false ? $resolved : $path;
    }

    /**
     * @param list<PsalmError> $errors
     * @param positive-int $codeFirstLine
     * @return list<Issue> sorted by line, column, type and message
     */
    public static function toIssues(array $errors, int $codeFirstLine): array
    {
        $issues = array_map(
            static fn(array $error): Issue => new Issue(
                $error['type'],
                $error['line_from'] + $codeFirstLine - 1,
                $error['column_from'],
                $error['message'],
            ),
            $errors,
        );

        usort($issues, static fn(Issue $a, Issue $b): int => [$a->line, $a->column, $a->type, $a->message] <=> [$b->line, $b->column, $b->type, $b->message]);

        return $issues;
    }

    /**
     * @param list<Issue> $issues
     */
    public static function format(array $issues): string
    {
        return implode("\n", array_map(
            static fn(Issue $issue): string => \sprintf('%s on line %d: %s', $issue->type, $issue->line, $issue->message),
            $issues,
        ));
    }
}
