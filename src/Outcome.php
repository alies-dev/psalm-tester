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
    /** Update mode rewrote the --EXPECT-- section to the actual output; $reason names the file. */
    case Updated;
    /** Psalm could not produce a result, e.g. its group timed out; $reason says why. */
    case Error;
}
