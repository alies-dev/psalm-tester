<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

/**
 * @api
 */
enum ExpectationKind
{
    /** --EXPECT-- / --EXPECT_EXTERNAL--: output must be identical. */
    case Exact;

    /** --EXPECTF-- / --EXPECTF_EXTERNAL--: output must match a format description (%s, %d, ...). */
    case Format;
}
