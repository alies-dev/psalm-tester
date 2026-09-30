<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * Splits an --ARGS-- section into argv tokens the way a POSIX shell would for plain words:
 * whitespace separates, single quotes are literal, double quotes allow \" \\ \$ \` escapes,
 * and a backslash outside quotes escapes the next character. No expansion of any kind.
 *
 * @internal
 * @psalm-immutable
 */
final class ArgumentTokenizer
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    public static function tokenize(string $arguments): array
    {
        $tokens = [];
        $token = '';
        $inToken = false;
        $quote = null;
        $length = \strlen($arguments);

        for ($i = 0; $i < $length; ++$i) {
            $char = $arguments[$i];

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                } else {
                    $token .= $char;
                }
            } elseif ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                } elseif ($char === '\\' && $i + 1 < $length && \in_array($arguments[$i + 1], ['"', '\\', '$', '`'], true)) {
                    $token .= $arguments[++$i];
                } else {
                    $token .= $char;
                }
            } elseif ($char === "'" || $char === '"') {
                $quote = $char;
                $inToken = true;
            } elseif ($char === '\\' && $i + 1 < $length) {
                $token .= $arguments[++$i];
                $inToken = true;
            } elseif (\in_array($char, [" ", "\t", "\n", "\r", "\v", "\f"], true)) {
                if ($inToken) {
                    $tokens[] = $token;
                    $token = '';
                    $inToken = false;
                }
            } else {
                $token .= $char;
                $inToken = true;
            }
        }

        if ($quote !== null) {
            throw new \InvalidArgumentException(\sprintf('Unterminated %s quote in arguments: %s', $quote, $arguments));
        }

        if ($inToken) {
            $tokens[] = $token;
        }

        return $tokens;
    }
}
