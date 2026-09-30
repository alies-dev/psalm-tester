<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * @api
 */
enum Outcome
{
    case Passed;
    case Failed;
    case Skipped;
    /** Reserved for --XFAIL-- support: failed as expected. */
    case XFailed;
    /** Reserved for --XFAIL-- support: passed although expected to fail. */
    case XPassed;
    /** Reserved for update mode: the expectation was rewritten to the actual output. */
    case Updated;
    /** Psalm could not produce a result, e.g. its group timed out; $reason says why. */
    case Error;
}
