<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\Fixture;

/**
 * Why a home fixture that WAS placed lost its venue and went back to « à placer »
 * (P2-52 / RMM-10). Persisted on `Fixture.unplacedReason`, distinct from the
 * VOLATILE auto-placement reason (`no_access_window`…) that lives only in the UI.
 *
 * `VENUE_LOST` = its venue is no longer affiliated to the club: the venue was
 * deleted, or the schedule validation found the match pointing at a venue that no
 * longer exists (exploration on « Charger cette version » may leave a dangling
 * pointer — transient, assumed — and VALIDATION is the trigger that resolves it).
 * The match keeps its kickoff time as an editable hint and stays recoverable.
 *
 * Cleared the moment a non-null venue is set again ({@see Fixture::setVenueId}).
 *
 * `NEUTRAL_GROUND` = a HOME fixture played on a NEUTRAL court (« pas chez nous » —
 * a cup/playoff round hosted in another club's gym). It is a home with no club
 * venue BY CHOICE, not a venue to attach: it never goes to the solver as a match
 * to place, it keeps its `fbiVenueLabel` (so attaching the label later is the way
 * out — the backfill clears this reason via {@see Fixture::setVenueId}), and it is
 * never « validé ligue ». Declared at import from the venue selector; no migration
 * (the column already stores an open enum value).
 */
enum FixtureUnplacedReason: string
{
    use HasValues;

    case VENUE_LOST = 'venue_lost';

    case NEUTRAL_GROUND = 'neutral_ground';
}
