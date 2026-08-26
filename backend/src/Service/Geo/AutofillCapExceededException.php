<?php

declare(strict_types=1);

namespace App\Service\Geo;

use RuntimeException;

/**
 * Raised by {@see VenueTravelTimeAutofillService} when the number of geolocated
 * venue pairs exceeds the hard cap — the controller maps it to a 422 with a
 * message naming the cap. A hard bound (never a silent truncation) keeps a club
 * with an implausible number of venues from firing thousands of routing calls.
 */
final class AutofillCapExceededException extends RuntimeException
{
    public function __construct(public readonly int $pairs, public readonly int $cap)
    {
        parent::__construct(\sprintf('Too many geolocated venue pairs (%d > %d).', $pairs, $cap));
    }
}
