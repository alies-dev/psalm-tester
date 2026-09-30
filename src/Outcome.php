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
    /** Update mode rewrote the --EXPECT-- section to the actual output; $reason names the file. */
    case Updated;
    /** Psalm could not produce a result, e.g. its group timed out; $reason says why. */
    case Error;
}
