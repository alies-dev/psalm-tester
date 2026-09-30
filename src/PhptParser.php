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
    private const SUPPORTED = ['TEST', 'SKIPIF', 'XFAIL', 'FILE', 'ARGS', 'EXPECT', 'EXPECTF', 'CONFLICTS'];

    /** Real run-tests.php sections whose semantics psalm-tester does not implement. */
    private const NOT_SUPPORTED = ['CLEAN', 'ENV', 'INI', 'EXPECT_EXTERNAL', 'EXPECTF_EXTERNAL'];

    /**
     * Section name => [content, line number of the content's first line].
     *
     * @return PhptSections
     */
    public static function parseSource(string $raw, string $phptFile): array
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
            $end = $ends[$section] ?? $start;
            $body = \implode('', \array_slice($lines, $start, $end - $start));
            $sections[$section] = [(string) \preg_replace(['/\r\n/', '/\n\z/'], ["\n", ''], $body), $start + 1];
        }

        /** @var PhptSections */
        return $sections;
    }
}
