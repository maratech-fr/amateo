<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\LeagueEnvelopeResolver;
use App\Service\MatchConflictDetector;
use DateTimeImmutable;

/**
 * The business shape of a league match-kickoff window, shared by the GLOBAL
 * federation catalog ({@see LeagueMatchWindow}, couche 1, seed/suggestion) and
 * the per-club tenant COPY ({@see ClubLeagueWindow}, the maison unique read at
 * placement / radar since P4-272 ①).
 *
 * The tolerant team↔window join ({@see LeagueEnvelopeResolver}) and
 * the membership predicate
 * ({@see MatchConflictDetector::kickoffInsideLeagueWindow}) read
 * ONLY these getters, so they stay agnostic of which side owns the window — the
 * predicate does not move a single byte between the two.
 */
interface LeagueWindowInterface
{
    public function getId(): string;

    public function getCategory(): string;

    /** DEPARTEMENTAL | REGIONAL (federation tier). */
    public function getLevel(): string;

    /** Null = applies to all genders; else a Gender enum value. */
    public function getGender(): ?string;

    /** ISO 1 (Monday) .. 7 (Sunday). */
    public function getDayOfWeek(): int;

    public function getKickoffMin(): DateTimeImmutable;

    public function getKickoffMax(): DateTimeImmutable;
}
