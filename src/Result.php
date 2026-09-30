<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

use PHPUnit\Framework\Assert;

/**
 * @api
 */
final readonly class Result
{
    /**
     * @internal build Results with fromAnalysis(), skipped() or error(), which keep outcome and output consistent
     * @param list<Issue> $issues
     * @psalm-mutation-free
     */
    public function __construct(
        public Phpt $phpt,
        public Outcome $outcome,
        public string $output = '',
        public array $issues = [],
        public ?string $reason = null,
    ) {}

    /**
     * @internal
     * @param list<Issue> $issues
     */
    public static function fromAnalysis(Phpt $phpt, string $output, array $issues): self
    {
        $passed = $phpt->expectation->constraint()->evaluate($output, '', true) === true;

        if ($phpt->xfail !== null) {
            return new self($phpt, $passed ? Outcome::XPassed : Outcome::XFailed, $output, $issues, $phpt->xfail);
        }

        return new self($phpt, $passed ? Outcome::Passed : Outcome::Failed, $output, $issues);
    }

    /**
     * @internal
     * @psalm-pure
     */
    public static function skipped(Phpt $phpt, string $reason): self
    {
        return new self($phpt, Outcome::Skipped, reason: $reason);
    }

    /**
     * @internal
     * @psalm-pure
     */
    public static function error(Phpt $phpt, string $reason): self
    {
        return new self($phpt, Outcome::Error, reason: $reason);
    }

    /**
     * Reports this result to PHPUnit: an assertion on the output (failing with a diff), a
     * skipped or incomplete test, or a plain failure.
     */
    public function assert(): void
    {
        match ($this->outcome) {
            Outcome::Passed, Outcome::Failed => Assert::assertThat($this->output, $this->phpt->expectation->constraint()),
            Outcome::Skipped => Assert::markTestSkipped((string) $this->reason),
            Outcome::XFailed => Assert::markTestIncomplete((string) $this->reason),
            Outcome::XPassed => Assert::fail(\sprintf('XPASS: %s now matches its expectation; remove --XFAIL-- (%s)', $this->phpt->path, (string) $this->reason)),
            Outcome::Updated => Assert::assertTrue(true),
            Outcome::Error => Assert::fail((string) $this->reason),
        };
    }
}
