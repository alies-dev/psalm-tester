<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsIdentical;
use PHPUnit\Framework\Constraint\StringMatchesFormatDescription;

/**
 * @api
 */
final readonly class Expectation
{
    /**
     * @param ?string $externalPath the file $text was read from (*_EXTERNAL sections), else null
     * @psalm-mutation-free
     */
    public function __construct(
        public ExpectationKind $kind,
        public string $text,
        public ?string $externalPath = null,
    ) {}

    /**
     * @psalm-pure
     */
    public static function exact(string $text): self
    {
        return new self(ExpectationKind::Exact, $text);
    }

    /**
     * @psalm-pure
     */
    public static function format(string $text): self
    {
        return new self(ExpectationKind::Format, $text);
    }

    public function constraint(): Constraint
    {
        return match ($this->kind) {
            ExpectationKind::Exact => new IsIdentical($this->text),
            ExpectationKind::Format => new StringMatchesFormatDescription($this->text),
        };
    }
}
