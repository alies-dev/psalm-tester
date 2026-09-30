<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * @internal
 */
final class SkipifEvaluator
{
    /**
     * Runs each --SKIPIF-- script in its own PHP process (so die()/exit() cannot end this run) and
     * returns, per id, the skip reason: the script's output with its leading "skip" stripped, or
     * null when the output does not start with "skip".
     *
     * @template TKey of array-key
     * @param array<TKey, string> $scriptsById
     * @param positive-int $concurrency
     * @param array<string, string> $env
     * @return array<TKey, ?string>
     */
    public static function evaluate(array $scriptsById, int $concurrency, string $temporaryDirectory, ?string $workingDirectory, array $env): array
    {
        /** @var array<TKey, ?string> */
        $results = [];
        /** @var array<TKey, array{command: non-empty-list<string>, env: array<string, string>, cwd: ?string}> */
        $jobs = [];
        /** @var list<string> */
        $scriptFiles = [];

        try {
            foreach ($scriptsById as $id => $script) {
                $scriptFile = self::writeScript($temporaryDirectory, $script, $id);
                $scriptFiles[] = $scriptFile;
                $jobs[$id] = ['command' => [\PHP_BINARY, $scriptFile], 'cwd' => $workingDirectory, 'env' => $env];
            }

            ProcessRunner::run(
                $jobs,
                $concurrency,
                $temporaryDirectory,
                // As in php-src's run-tests.php only the output decides: a script that crashes after
                // echoing "skip ..." still skips, and one that crashes silently lets the test run.
                static function (int|string $id, ?string $output) use (&$results): void {
                    $output = \trim((string) $output);
                    /** @var TKey $id */
                    $results[$id] = \stripos($output, 'skip') === 0 ? \ltrim(\substr($output, 4)) : null;
                },
            );
        } finally {
            foreach ($scriptFiles as $scriptFile) {
                @\unlink($scriptFile);
            }
        }

        return $results;
    }

    private static function writeScript(string $temporaryDirectory, string $script, int|string $id): string
    {
        $scriptFile = \tempnam($temporaryDirectory, 'psalm_skipif_');

        if ($scriptFile === false) {
            throw new \RuntimeException(\sprintf('Failed to create temporary SKIPIF file for %s.', $id));
        }

        if (\file_put_contents($scriptFile, $script) === false) {
            @\unlink($scriptFile);

            throw new \RuntimeException(\sprintf('Failed to write temporary SKIPIF file for %s.', $id));
        }

        return $scriptFile;
    }
}
