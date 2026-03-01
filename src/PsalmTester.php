<?php

declare(strict_types=1);

namespace PHPyh\PsalmTester;

use Composer\InstalledVersions;
use PHPUnit\Framework\Assert;

/**
 * @api
 */
final readonly class PsalmTester
{
    private function __construct(
        private string $psalmPath,
        private string $defaultArguments,
        private string $temporaryDirectory,
    ) {}

    public static function create(
        ?string $psalmPath = null,
        string $defaultArguments = '--no-progress --no-diff --config=' . __DIR__ . '/psalm.xml',
        ?string $temporaryDirectory = null,
    ): self {
        return new self(
            psalmPath: $psalmPath ?? self::findPsalm(),
            defaultArguments: $defaultArguments,
            temporaryDirectory: self::resolveTemporaryDirectory($temporaryDirectory),
        );
    }

    private static function findPsalm(): string
    {
        if (!method_exists(InstalledVersions::class, 'getInstallPath')) {
            throw new \RuntimeException('Cannot find Psalm installation path. Please, explicitly specify path to Psalm binary.');
        }

        $installPath = InstalledVersions::getInstallPath('vimeo/psalm');

        if ($installPath === null) {
            throw new \RuntimeException('Cannot find Psalm installation path. Please, explicitly specify path to Psalm binary.');
        }

        return $installPath . '/psalm';
    }

    private static function resolveTemporaryDirectory(?string $temporaryDirectory): string
    {
        $temporaryDirectory ??= sys_get_temp_dir() . '/psalm_test';

        if (!is_dir($temporaryDirectory) && !mkdir($temporaryDirectory, recursive: true)) {
            throw new \RuntimeException(\sprintf('Failed to create temporary directory %s.', $temporaryDirectory));
        }

        return $temporaryDirectory;
    }

    /**
     * Run multiple tests in batched Psalm invocations (one per unique argument set).
     * Returns formatted output per test — callers are responsible for assertions.
     * @api
     * @param array<string, PsalmTest> $tests keyed by identifier
     * @return array<string, string> formatted output keyed by identifier
     */
    public function runBatch(array $tests): array
    {
        /** @var array<string, array<string, array{file: string, test: PsalmTest}>> */
        $groups = [];

        foreach ($tests as $id => $test) {
            $args = $test->arguments ?: $this->defaultArguments;
            $groups[$args][$id] = [
                'file' => $this->createTemporaryCodeFile($test->code),
                'test' => $test,
            ];
        }

        $results = [];

        foreach ($groups as $args => $entries) {
            $results += $this->runGroup($args, $entries);
        }

        return $results;
    }

    /**
     * @param array<string, array{file: string, test: PsalmTest}> $entries
     * @return array<string, string>
     */
    private function runGroup(string $args, array $entries): array
    {
        try {
            $filePaths = array_map(
                static fn(array $entry): string => $entry['file'],
                $entries,
            );

            $output = $this->runPsalm($args, ...array_values($filePaths));
            $decoded = $this->decodeOutput($output, $args);

            /** @var array<string, list<array{type: string, column_from: int, line_from: int, message: string, file_path: string, ...}>> $errorsByFile */
            $errorsByFile = [];
            foreach ($decoded as $error) {
                $errorsByFile[$error['file_path']][] = $error;
            }

            return array_map(function ($entry) use ($errorsByFile) {
                return $this->formatErrors(
                    $errorsByFile[$entry['file']] ?? [],
                    $entry['test']->codeFirstLine,
                );
            }, $entries);
        } finally {
            foreach ($entries as $entry) {
                @unlink($entry['file']);
            }
        }
    }

    public function test(PsalmTest $test): void
    {
        $codeFile = $this->createTemporaryCodeFile($test->code);

        try {
            $args = $test->arguments ?: $this->defaultArguments;
            $output = $this->runPsalm($args, $codeFile);
            $decoded = $this->decodeOutput($output, $args);
            $formattedOutput = $this->formatErrors($decoded, $test->codeFirstLine);

            Assert::assertThat($formattedOutput, $test->constraint);
        } finally {
            @unlink($codeFile);
        }
    }

    /**
     * @param string $args Pre-built argument string — trusted input from $this->defaultArguments or PsalmTest::$arguments (parsed from .phpt files).
     *                     Not escaped, as it contains multiple shell-level arguments.
     */
    private function runPsalm(string $args, string ...$files): string
    {
        $command = \sprintf(
            '%s --output-format=json %s %s',
            escapeshellarg($this->psalmPath),
            $args,
            implode(' ', array_map(escapeshellarg(...), $files)),
        );

        /** @psalm-suppress ForbiddenCode */
        $output = shell_exec($command);

        if (!\is_string($output)) {
            throw new \RuntimeException(\sprintf('Failed to run command %s.', $command));
        }

        return $output;
    }

    /**
     * @return list<array{type: string, column_from: int, line_from: int, message: string, file_path: string, ...}>
     */
    private function decodeOutput(string $output, string $args): array
    {
        try {
            /** @var list<array{type: string, column_from: int, line_from: int, message: string, file_path: string, ...}> */
            return json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf(
                "Failed to decode Psalm JSON output for args [%s]: %s\nOutput: %s",
                $args,
                $e->getMessage(),
                $output,
            ), previous: $e);
        }
    }

    /**
     * @param list<array{type: string, column_from: int, line_from: int, message: string, file_path: string, ...}> $errors
     * @param positive-int $codeFirstLine
     */
    private function formatErrors(array $errors, int $codeFirstLine): string
    {
        /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
        usort($errors, static fn(array $a, array $b): int => $a['line_from'] <=> $b['line_from']);

        return implode("\n", array_map(
            static fn(array $error): string => \sprintf(
                '%s on line %d: %s',
                $error['type'],
                $error['line_from'] + $codeFirstLine - 1,
                $error['message'],
            ),
            $errors,
        ));
    }

    private function createTemporaryCodeFile(string $contents): string
    {
        $file = tempnam($this->temporaryDirectory, 'code_');

        if ($file === false) {
            throw new \LogicException(\sprintf('Failed to create temporary code file in %s.', $this->temporaryDirectory));
        }

        file_put_contents($file, $contents);

        return $file;
    }
}
