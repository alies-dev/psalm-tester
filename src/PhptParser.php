<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * @internal
 * @psalm-immutable
 * @psalm-type PhptSections = array<non-empty-string, array{string, positive-int}>
 */
final class PhptParser
{
    private const SUPPORTED = ['SKIPIF', 'FILE', 'ARGS', 'EXPECT', 'EXPECTF', 'EXPECT_EXTERNAL', 'EXPECTF_EXTERNAL'];

    /** Real run-tests.php sections whose semantics psalm-tester does not implement. */
    private const NOT_SUPPORTED = ['CLEAN', 'ENV', 'INI'];

    /**
     * Section name => [content, line number of the content's first line].
     *
     * @return PhptSections
     * @psalm-pure This reads the filesystem via file(), so it is not truly pure; the
     *     annotation is required only because Psalm's impure-function list omits file()
     *     (unlike e.g. file_get_contents()), so Psalm would otherwise report MissingPureAnnotation.
     */
    public static function parse(string $phptFile): array
    {
        $name = null;
        /** @var array<string, array{string, positive-int}> $sections */
        $sections = [];
        $lineNumber = 0;

        $lines = file($phptFile, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new \RuntimeException(\sprintf('Failed to read file %s.', $phptFile));
        }

        foreach ($lines as $line) {
            ++$lineNumber;

            if (preg_match('/^--([_A-Z]+)--/', $line, $matches)) {
                $section = $matches[1];

                if (\in_array($section, self::NOT_SUPPORTED, true)) {
                    throw new \InvalidArgumentException(\sprintf('Section --%s-- in %s is not supported by psalm-tester.', $section, $phptFile));
                }

                if (!\in_array($section, self::SUPPORTED, true)) {
                    throw new \InvalidArgumentException(\sprintf('Unknown section --%s-- in %s.', $section, $phptFile));
                }

                /** @var non-empty-string widened back: $sections is keyed by string */
                $name = $section;

                $sections[$name] = ['', $lineNumber + 1];

                continue;
            }

            if ($name === null) {
                throw new \LogicException(\sprintf('%s must start with a section delimiter, e.g. --FILE--.', $phptFile));
            }

            $sections[$name][0] .= ($sections[$name][0] ? "\n" : '') . $line;
        }

        /** @var PhptSections */
        return $sections;
    }
}
