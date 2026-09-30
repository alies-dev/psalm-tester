<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * @api
 */
enum ExpectationKind
{
    /** --EXPECT--: output must be identical. */
    case Exact;

    /** --EXPECTF--: output must match a format description (%s, %d, ...). */
    case Format;
}
