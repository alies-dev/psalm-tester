<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * @internal
 * @psalm-type PhptSections = array<non-empty-string, array{string, positive-int}>
 */
final class PhptParser
{
    /** TEST is php-src's description section: accepted, not used. */
    private const SUPPORTED = ['TEST', 'SKIPIF', 'FILE', 'ARGS', 'EXPECT', 'EXPECTF', 'EXPECT_EXTERNAL', 'EXPECTF_EXTERNAL'];

    /** Real run-tests.php sections whose semantics psalm-tester does not implement. */
    private const NOT_SUPPORTED = ['CLEAN', 'ENV', 'INI'];

    /**
     * Section name => [content, line number of the content's first line].
     *
     * @return PhptSections
     */
    public static function parse(string $phptFile): array
    {
        $raw = @\file_get_contents($phptFile);

        if ($raw === false) {
            throw new \RuntimeException(\sprintf('Failed to read file %s.', $phptFile));
        }

        ['lines' => $lines, 'sections' => $bounds] = self::scan($raw, $phptFile);
        $sections = [];

        foreach ($bounds as $name => ['start' => $start, 'end' => $end]) {
            $body = \array_map(self::stripEol(...), \array_slice($lines, $start, $end - $start));
            $sections[$name] = [\implode("\n", $body), $start + 1];
        }

        /** @var PhptSections */
        return $sections;
    }

    /**
     * The one place that decides where sections are, shared by parse() and PhptRewriter so they
     * cannot disagree: $lines keep their line ending, and a section's body is
     * $lines[start] .. $lines[end - 1], right after its header line $lines[start - 1].
     *
     * @return array{lines: list<string>, sections: array<non-empty-string, array{start: int, end: int}>}
     * @psalm-pure
     */
    public static function scan(string $raw, string $phptFile): array
    {
        $split = \preg_split('/(?<=\n)/', $raw, -1, \PREG_SPLIT_NO_EMPTY);
        $lines = $split === false ? [] : $split;
        /** @var array<non-empty-string, int> $starts */
        $starts = [];
        /** @var array<non-empty-string, int> $ends */
        $ends = [];
        $current = null;

        foreach ($lines as $index => $line) {
            if (\preg_match('/^--([_A-Z]+)--/', $line, $matches) === 1) {
                $section = $matches[1];

                if (\in_array($section, self::NOT_SUPPORTED, true)) {
                    throw new \InvalidArgumentException(\sprintf('Section --%s-- in %s is not supported by psalm-tester.', $section, $phptFile));
                }

                if (!\in_array($section, self::SUPPORTED, true)) {
                    throw new \InvalidArgumentException(\sprintf('Unknown section --%s-- in %s.', $section, $phptFile));
                }

                if (isset($starts[$section])) {
                    throw new \InvalidArgumentException(\sprintf('Duplicate section --%s-- in %s.', $section, $phptFile));
                }

                if ($current !== null) {
                    $ends[$current] = $index;
                }

                $starts[$section] = $index + 1;
                $current = $section;

                continue;
            }

            if ($current === null) {
                throw new \LogicException(\sprintf('%s must start with a section delimiter, e.g. --FILE--.', $phptFile));
            }
        }

        if ($current !== null) {
            $ends[$current] = \count($lines);
        }

        $sections = [];
        foreach ($starts as $section => $start) {
            $sections[$section] = ['start' => $start, 'end' => $ends[$section] ?? $start];
        }

        return ['lines' => $lines, 'sections' => $sections];
    }

    /**
     * Drops a line's "\n" or "\r\n", as file(..., FILE_IGNORE_NEW_LINES) did.
     *
     * @psalm-pure
     */
    public static function stripEol(string $line): string
    {
        if (\str_ends_with($line, "\n")) {
            $line = \substr($line, 0, -1);

            if (\str_ends_with($line, "\r")) {
                $line = \substr($line, 0, -1);
            }
        }

        return $line;
    }
}
