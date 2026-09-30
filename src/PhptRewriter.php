<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * Rewrites a .phpt file's --EXPECT-- section in place for update mode, leaving every other byte
 * (other sections, line endings, trailing newline) untouched.
 *
 * @internal
 */
final class PhptRewriter
{
    public static function rewriteExpect(string $path, string $actualOutput): void
    {
        $raw = \file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException(\sprintf('Failed to read file %s.', $path));
        }

        // The rest of the file is copied verbatim, so its own line ending style (not $actualOutput's
        // "\n", built by IssueFormatter::format()) decides how the replacement body is joined.
        $eol = \str_contains($raw, "\r\n") ? "\r\n" : "\n";
        $body = \str_replace("\n", $eol, $actualOutput);

        $rewritten = \preg_replace_callback(
            '/^(--EXPECT--\R)(?:.*?)(?=^--[_A-Z]+--\R|\z)/ms',
            static fn(array $matches): string => $matches[1] . $body,
            $raw,
            1,
            $count,
        );

        if ($rewritten === null || $count !== 1) {
            throw new \RuntimeException(\sprintf('Failed to locate an --EXPECT-- section to rewrite in %s.', $path));
        }

        if (\file_put_contents($path, $rewritten) === false) {
            throw new \RuntimeException(\sprintf('Failed to write file %s.', $path));
        }
    }
}
