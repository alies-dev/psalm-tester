<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * One issue Psalm reported, with $line counted in the .phpt file (codeFirstLine applied).
 *
 * @api
 * @psalm-immutable
 */
final readonly class Issue
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public string $type,
        public int $line,
        public int $column,
        public string $message,
    ) {}
}
