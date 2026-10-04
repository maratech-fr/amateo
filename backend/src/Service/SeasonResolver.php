<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use App\Repository\SeasonRepository;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves which of a club's seasons is the CURRENT one, derived from the
 * calendar — never from the `status` column (spec transition-de-saison §3:
 * "courante dérivée du calendrier", no cron flipping a flag, non-destructive
 * by construction). `Season.status` is display metadata only.
 *
 * The season year pivots on July 15 (TRANSITION_MONTH_DAY): from that date
 * the upcoming season (N+1) becomes current as soon as it exists. The rule
 * keys on startDate ONLY — endDate is untrusted (the register seed
 * historically wrote endDate < startDate) and seasons may gap or overlap.
 *
 * A club with exactly one season gets it as current unconditionally,
 * whatever its dates — the mono-season behaviour must be identical to the
 * pre-multi-season app.
 */
final class SeasonResolver
{
    /** Season switchover pivot (month-day): on/after this date we are in the season starting that year. */
    public const TRANSITION_MONTH_DAY = '07-15';

    public function __construct(
        private readonly SeasonRepository $seasonRepository,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * List-based variant of isReadonly (no query — the caller holds the
     * club's seasons, e.g. /api/me building the selector payload).
     *
     * @param list<Season> $seasons ordered by startDate ASC
     */
    public static function isReadonlyAmong(Season $season, array $seasons, ?DateTimeImmutable $today = null): bool
    {
        $current = self::currentAmong($seasons, $today);
        if (!$current instanceof Season || $current->getId() === $season->getId()) {
            return false;
        }

        return self::seasonYear($season->getStartDate()) < self::seasonYear($current->getStartDate());
    }

    /**
     * Same derivation from an already-loaded list (avoids a second query when
     * the caller holds the club's seasons, e.g. /api/me).
     *
     * Rule: seasons are binned by seasonYear(startDate). Inside TODAY'S bin,
     * a season becomes current on its actual startDate (an early-start next
     * season — e.g. 2026-07-01, same bin as the running one — must NOT win
     * before it begins); a prepared not-yet-started season wins only once its
     * bin is today's (the July-15 pivot). Empty bin → the latest
     * already-started season, else the earliest (all-future club).
     *
     * @param list<Season> $seasons ordered by startDate ASC
     */
    public static function currentAmong(array $seasons, ?DateTimeImmutable $today = null): ?Season
    {
        if ([] === $seasons) {
            return null;
        }
        if (1 === \count($seasons)) {
            // Mono-season club: current unconditionally (zero-behaviour-change guarantee).
            return $seasons[0];
        }

        $today ??= new DateTimeImmutable('today');
        $todayYear = self::seasonYear($today);
        $todayIso = $today->format('Y-m-d');

        $inBin = array_values(array_filter(
            $seasons,
            static fn (Season $s): bool => self::seasonYear($s->getStartDate()) === $todayYear,
        ));
        if ([] !== $inBin) {
            $started = null;
            foreach ($inBin as $season) {
                if ($season->getStartDate()->format('Y-m-d') <= $todayIso) {
                    // ASC list → last match = greatest started startDate.
                    $started = $season;
                }
            }

            return $started ?? $inBin[0];
        }

        // No season in today's bin: the latest already-started one (covers the
        // window between an early season start and the July-15 pivot).
        $candidate = null;
        foreach ($seasons as $season) {
            if ($season->getStartDate()->format('Y-m-d') <= $todayIso) {
                $candidate = $season;
            }
        }

        return $candidate ?? $seasons[0];
    }

    /**
     * BCK-34 — les BORNES d'une date d'horloge simulée pour un club démo : du DÉBUT
     * de la saison EN COURS à la FIN de la saison SUIVANTE (décision fondateur
     * 2026-10-02). Hors de cet intervalle une date simulée n'a pas de sens métier et
     * fausserait les écrans datés et les durées de sécurité.
     *
     * `$realToday` DOIT être l'horloge RÉELLE : la saison « en cours » se dérive du
     * calendrier réel, jamais de la date simulée elle-même (qui, non bornée, pourrait
     * se re-valider circulairement).
     *
     * La saison suivante = la plus proche dont le début dépasse celui de la courante.
     * À défaut (club sans saison préparée), la borne haute est PROJETÉE un an après la
     * fin de la saison en cours — une saison dure ~un an ; une endDate aberrante
     * (héritage : endDate < startDate) est rattrapée par la même projection. Null si
     * le club n'a AUCUNE saison (le foyer d'écriture laisse alors passer : rien à
     * borner — un club démo seedé a toujours sa saison).
     *
     * @param list<Season> $seasons ordered by startDate ASC
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null [min, max] inclusifs
     */
    public static function simulatedClockBoundsAmong(array $seasons, DateTimeImmutable $realToday): ?array
    {
        $current = self::currentAmong($seasons, $realToday);
        if (!$current instanceof Season) {
            return null;
        }
        $min = $current->getStartDate();

        $next = null;
        foreach ($seasons as $season) {
            if ($season->getStartDate() > $current->getStartDate()
                && (!$next instanceof Season || $season->getStartDate() < $next->getStartDate())) {
                $next = $season;
            }
        }

        $projected = $current->getEndDate()->modify('+1 year');
        $max = $next instanceof Season ? $next->getEndDate() : $projected;
        if ($max <= $min) {
            $max = $projected > $min ? $projected : $min->modify('+2 years');
        }

        return [$min, $max];
    }

    /**
     * The season-year a date belongs to: 2026-07-14 → 2025 (season 2025-26),
     * 2026-07-15 → 2026 (season 2026-27). Lexicographic 'm-d' compare is safe.
     */
    public static function seasonYear(DateTimeImmutable $date): int
    {
        $year = (int) $date->format('Y');

        return $date->format('m-d') >= self::TRANSITION_MONTH_DAY ? $year : $year - 1;
    }

    /**
     * Nom par défaut d'une saison : « 2026-2027 » (année de début–année de fin),
     * jamais « 2026 » seul — une saison est à cheval sur deux années civiles et un
     * nom mono-année est ambigu (décision fondateur 2026-07-24). Dérivé de la
     * fenêtre via seasonYear (pivot 15 juillet) : un start au 2026-08-01 comme au
     * 2026-07-15 donne « 2026-2027 ».
     */
    public static function defaultSeasonName(DateTimeImmutable $startDate): string
    {
        $year = self::seasonYear($startDate);

        return $year . '-' . ($year + 1);
    }

    /**
     * All seasons of the club, oldest first (startDate ASC).
     *
     * @return list<Season>
     */
    public function seasonsForClub(string $clubId): array
    {
        return $this->seasonRepository->findAllByClubId($clubId);
    }

    /**
     * The current season: the season with the greatest startDate among those
     * whose season-year is not in the future. All-future edge case → the
     * earliest season (a club prepared ahead of time still has a season).
     */
    public function currentSeason(string $clubId, ?DateTimeImmutable $today = null): ?Season
    {
        // Default "now" from the clock so the dev simulator shifts it too; the
        // static helpers keep taking $today explicitly (their callers pass it).
        return self::currentAmong($this->seasonsForClub($clubId), $today ?? $this->clock->now());
    }

    /**
     * The SELECTED season (validated `_season_id` request attribute set by
     * TenantFilterListener), else the calendar-derived current one. Single
     * home of the fallback shared by the custom controllers.
     */
    public function selectedOrCurrent(?Request $request, string $clubId): ?Season
    {
        $seasonId = $request?->attributes->get('_season_id');
        if (\is_string($seasonId) && '' !== $seasonId) {
            return $this->seasonRepository->find($seasonId);
        }

        return $this->currentSeason($clubId);
    }

    public function isCurrent(Season $season, ?DateTimeImmutable $today = null): bool
    {
        $current = $this->currentSeason($season->getClubId(), $today);

        return $current instanceof Season && $current->getId() === $season->getId();
    }

    /**
     * A season is read-only when it belongs to a season-year strictly before
     * the current one (N-1 and older). The current season and future drafts
     * are writable.
     */
    public function isReadonly(Season $season, ?DateTimeImmutable $today = null): bool
    {
        // Clock-aware like currentSeason()/isCurrent() — the dev simulator shifts
        // this too (and prod stays on real time via the native clock).
        return self::isReadonlyAmong($season, $this->seasonsForClub($season->getClubId()), $today ?? $this->clock->now());
    }
}
