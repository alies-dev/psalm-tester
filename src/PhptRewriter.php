<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * Rewrites a .phpt file's --EXPECT-- section for update mode. Section boundaries come from
 * PhptParser::scan(), so only the body lines the parser reads as the expectation are replaced;
 * every other byte (headers with trailing text, other sections, line endings, whether the file
 * ends with a newline) is kept.
 *
 * @internal
 */
final class PhptRewriter
{
    /**
     * A symlink is followed: its target is rewritten and the link stays a link.
     *
     * @param ?string $sourceHash sha1 of the bytes the test was parsed from; a file that no longer
     *     matches it (edited during the run) is not rewritten
     * @throws \RuntimeException|\InvalidArgumentException|\LogicException when the file cannot be
     *     rewritten safely; it is then left untouched
     */
    public static function rewriteExpect(string $path, string $actualOutput, ?string $sourceHash): void
    {
        $target = \realpath($path);
        $raw = $target === false ? false : @\file_get_contents($target);

        if ($target === false || $raw === false) {
            throw new \RuntimeException(\sprintf('Failed to read file %s.', $path));
        }

        $sourceHash ??= \sha1($raw);

        ['lines' => $lines, 'sections' => $sections] = PhptParser::scan($raw, $path);

        if (!isset($sections['EXPECT'])) {
            throw new \RuntimeException(\sprintf('No --EXPECT-- section in %s.', $path));
        }

        $outputLines = $actualOutput === '' ? [] : \explode("\n", $actualOutput);

        foreach ($outputLines as $line) {
            if (\preg_match('/^--([_A-Z]+)--/', $line) === 1) {
                throw new \RuntimeException(\sprintf('The actual output has a line that would read as a section header: %s', $line));
            }
        }

        ['start' => $start, 'end' => $end] = $sections['EXPECT'];
        $header = $lines[$start - 1];
        $eol = self::lineEnding($header) ?? self::lineEnding($lines[0]) ?? "\n";
        $isLast = $end === \count($lines);
        // A body at the end of the file ends with a newline only if the file did.
        $terminateLast = !$isLast || self::lineEnding((string) \end($lines)) !== null;

        $body = '';
        foreach ($outputLines as $index => $line) {
            $body .= $line . ($index < \count($outputLines) - 1 || $terminateLast ? $eol : '');
        }

        if ($body !== '' && self::lineEnding($header) === null) {
            $header .= $eol;
        }

        $rewritten = \implode('', \array_slice($lines, 0, $start - 1)) . $header . $body . \implode('', \array_slice($lines, $end));
        self::writeAtomically($target, $rewritten, $sourceHash);
    }

    /**
     * @psalm-pure
     */
    private static function lineEnding(string $line): ?string
    {
        if (\str_ends_with($line, "\r\n")) {
            return "\r\n";
        }

        return \str_ends_with($line, "\n") ? "\n" : null;
    }

    /**
     * Writes a temp file next to $path and renames it over $path, so a failure never leaves a
     * half-written test behind; the original permissions are kept.
     */
    private static function writeAtomically(string $path, string $contents, string $sourceHash): void
    {
        $temp = \tempnam(\dirname($path), '.psalm-tester-');

        if ($temp === false) {
            throw new \RuntimeException(\sprintf('Failed to create a temporary file next to %s.', $path));
        }

        try {
            $permissions = \fileperms($path);

            if (\file_put_contents($temp, $contents) === false
                || ($permissions !== false && !\chmod($temp, $permissions & 0o7777))) {
                throw new \RuntimeException(\sprintf('Failed to write file %s.', $path));
            }

            // Checked right before the rename, which narrows the window for an editor save; a
            // mismatch also means the analyzed code is not what the file now holds.
            if (\sha1((string) @\file_get_contents($path)) !== $sourceHash) {
                throw new \RuntimeException('changed during the run');
            }

            if (!\rename($temp, $path)) {
                throw new \RuntimeException(\sprintf('Failed to write file %s.', $path));
            }
        } finally {
            if (\is_file($temp)) {
                @\unlink($temp);
            }
        }
    }
}
