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
     * @throws \UnexpectedValueException when $output is not Psalm's JSON issue list
     */
    public static function decodeByFile(string $output, string $args): array
    {
        // Decoding to arrays would turn {} into the same [] as an empty issue list.
        if (!\str_starts_with(\ltrim($output), '[')) {
            throw self::invalidOutput($args, 'not a list of issues', $output);
        }

        try {
            $errors = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalidOutput($args, $e->getMessage(), $output);
        }

        if (!\is_array($errors) || !\array_is_list($errors)) {
            throw self::invalidOutput($args, 'not a list of issues', $output);
        }

        $errorsByFile = [];

        foreach ($errors as $error) {
            if (!\is_array($error) || !\is_string($error['type'] ?? null) || !\is_int($error['line_from'] ?? null)
                || !\is_int($error['column_from'] ?? null) || !\is_string($error['message'] ?? null) || !\is_string($error['file_path'] ?? null)) {
                throw self::invalidOutput($args, 'an issue lacks type, line_from, column_from, message or file_path', $output);
            }

            /** @var PsalmError $error */
            $errorsByFile[self::fileKey($error['file_path'])][] = $error;
        }

        return $errorsByFile;
    }

    /**
     * @psalm-pure
     */
    private static function invalidOutput(string $args, string $problem, string $output): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf(
            "Failed to decode Psalm JSON output for args [%s]: %s\nOutput: %s",
            $args,
            $problem,
            $output === '' ? '(empty)' : \substr($output, 0, 2000),
        ));
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
