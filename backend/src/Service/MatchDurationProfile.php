<?php

declare(strict_types=1);

namespace App\Service;

/**
 * How long a match occupies a person's timeline: the match itself plus the
 * warm-up before kickoff (both in minutes). It is the per-category duration
 * setting (P2-54 RMM-9) consumed by {@see MatchFootprint}; a category either
 * carries its own values or inherits its FAMILY default (see
 * {@see MatchDurationResolver}).
 *
 * ⚠ The shower/buffer that used to live in the footprint are GONE (founder
 * decision, P2-54): the footprint no longer models the after-match changing
 * room. Travel is still a separate leg carried by MatchFootprint's
 * roundTripTravelMinutes (0 until PR-3), NOT part of this profile.
 */
final readonly class MatchDurationProfile
{
    public function __construct(
        public int $matchMinutes,
        public int $warmupMinutes,
    ) {}

    /**
     * The documented last-resort profile (105 min match / 30 min warm-up) — a
     * category that maps to no age family lands here, and it is DELIBERATELY the
     * same value as the Seniors family default. Also the default the pure
     * conflict detector uses for a team absent from the resolved profile map.
     */
    public static function fallback(): self
    {
        return new self(105, 30);
    }
}
