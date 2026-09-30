<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester\Tests;

use AliesDev\PsalmTester\ArgumentTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArgumentTokenizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideArguments(): iterable
    {
        yield 'empty' => ['', []];
        yield 'whitespace and newlines separate' => ["  --a\t--b\n--c  ", ['--a', '--b', '--c']];
        yield 'single quotes are literal' => ["'--config=/tmp/two  spaces/p.xml' '\\n'", ['--config=/tmp/two  spaces/p.xml', '\\n']];
        yield 'double quotes keep spaces' => ['"-c" "strict  config.xml"', ['-c', 'strict  config.xml']];
        yield 'double quote escapes' => ['"a\\"b\\\\c\\$d\\n"', ['a"b\\c$d\\n']];
        yield 'quotes join adjacent text' => ['--report="prefix --config=x.json"', ['--report=prefix --config=x.json']];
        yield 'empty quoted token' => ["'' \"\"", ['', '']];
        yield 'backslash escapes outside quotes' => ['two\\ words \\"q', ['two words', '"q']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideArguments')]
    public function testTokenize(string $arguments, array $expected): void
    {
        self::assertSame($expected, ArgumentTokenizer::tokenize($arguments));
    }

    public function testAnUnterminatedQuoteThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ArgumentTokenizer::tokenize('--config="oops');
    }
}
