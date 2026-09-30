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
     * @psalm-mutation-free
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
        $sections = PhptParser::parse($path);

        if (!isset($sections['FILE'])) {
            throw new \LogicException(\sprintf('File %s must have a FILE section.', $path));
        }

        return new self(
            code: $sections['FILE'][0],
            expectation: self::resolveExpectation($path, $sections),
            arguments: $sections['ARGS'][0] ?? '',
            codeFirstLine: $sections['FILE'][1],
            skipif: $sections['SKIPIF'][0] ?? null,
            xfail: $sections['XFAIL'][0] ?? null,
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

        foreach (['EXPECT_EXTERNAL' => ExpectationKind::Exact, 'EXPECTF_EXTERNAL' => ExpectationKind::Format] as $section => $kind) {
            if (isset($sections[$section])) {
                $externalPath = $sections[$section][0];

                if (!self::isAbsolutePath($externalPath)) {
                    $externalPath = \dirname($path) . \DIRECTORY_SEPARATOR . $externalPath;
                }

                $contents = file_get_contents($externalPath);

                if ($contents === false) {
                    throw new \RuntimeException(\sprintf('Failed to read file %s.', $externalPath));
                }

                return new Expectation($kind, $contents, $externalPath);
            }
        }

        throw new \LogicException(\sprintf('File %s must have an EXPECT* section.', $path));
    }

    /**
     * @psalm-pure
     */
    private static function isAbsolutePath(string $path): bool
    {
        return \str_starts_with($path, '/') || \str_starts_with($path, '\\') || \preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
