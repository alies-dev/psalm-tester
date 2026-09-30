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
    /** --XFAIL--: failed to meet its expectation, as expected. */
    case XFailed;
    /** --XFAIL--: met its expectation although expected not to. */
    case XPassed;
    /** Psalm could not produce a result, e.g. its group timed out; $reason says why. */
    case Error;
}
