<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * One parsed .phpt file.
 *
 * @api
 * @psalm-import-type PhptSections from PhptParser
 */
final readonly class Phpt
{
    /**
     * @param string $arguments Psalm arguments from --ARGS--, appended to the tester's own; a
     *     --config in them replaces the tester's configured config
     * @param positive-int $codeFirstLine line of the .phpt file that $code starts on
     * @param ?string $skipif the --SKIPIF-- script, if any
     * @param ?string $xfail the --XFAIL-- reason, if any: the test is expected to fail its expectation
     * @param string $path the .phpt file this was parsed from ('' when built in code)
     */
    public function __construct(
        public string $code,
        public Expectation $expectation,
        public string $arguments = '',
        public int $codeFirstLine = 1,
        public ?string $skipif = null,
        public ?string $xfail = null,
        public string $path = '',
    ) {}

    /**
     * @see https://qa.php.net/phpt_details.php
     */
    public static function fromFile(string $path): self
    {
        $raw = @\file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException(\sprintf('Failed to read file %s.', $path));
        }

        $sections = PhptParser::parseSource($raw, $path);

        if (!isset($sections['FILE'])) {
            throw new \LogicException(\sprintf('File %s must have a FILE section.', $path));
        }

        return new self(
            code: $sections['FILE'][0],
            expectation: self::resolveExpectation($path, $sections),
            arguments: $sections['ARGS'][0] ?? '',
            codeFirstLine: $sections['FILE'][1],
            skipif: $sections['SKIPIF'][0] ?? null,
            xfail: isset($sections['XFAIL']) ? \rtrim($sections['XFAIL'][0]) : null,
            path: $path,
        );
    }

    /**
     * @param PhptSections $sections
     */
    private static function resolveExpectation(string $path, array $sections): Expectation
    {
        if (isset($sections['EXPECT'])) {
            return Expectation::exact($sections['EXPECT'][0]);
        }

        if (isset($sections['EXPECTF'])) {
            return Expectation::format($sections['EXPECTF'][0]);
        }

        throw new \LogicException(\sprintf('File %s must have an EXPECT* section.', $path));
    }
}
