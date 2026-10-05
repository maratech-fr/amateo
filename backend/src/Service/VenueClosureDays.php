<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Throwable;

/**
 * P2-5 5b — les JOURS/DATES où un gymnase est réellement fermé, dans une fenêtre.
 *
 * Une contrainte datée `venue_closed` (FACILITY, `scopeTargetId` = le gymnase)
 * porte `config[startDate,endDate]` : l'incident. Les DATES fermées = celles de
 * l'incident ∩ fenêtre du plan (source précise, {@see closedDatesByVenue}).
 *
 * L'engine planifie en semaine-type (un créneau = un `dayOfWeek` récurrent), donc
 * le BUILDER dérive un ensemble de jours ISO ({@see closedWeekdaysByVenue}) : sur
 * une fenêtre d'UNE semaine (plan de semaine, cas nominal 5a) la réduction est
 * EXACTE (chaque jour apparaît une fois) ; sur une fenêtre multi-semaines (« bloc »),
 * un même jour se répète — la semaine-type ne peut pas être date-précise, donc le
 * jour fermé une semaine ferme le gymnase ce jour-là sur TOUT le bloc (sur-ferme,
 * jamais sous-ferme). Pour la précision, découper en semaines (5a). Le RADAR, lui,
 * liste des DATES concrètes et reste exact via {@see closedDatesByVenue}.
 *
 * Fallback SANS dates valides dans le config (donnée legacy, config nu) : le
 * gymnase est fermé sur TOUTE la fenêtre — jamais sous-contraint (comportement 5a).
 */
final class VenueClosureDays
{
    /**
     * DATES fermées par gymnase = incident ∩ fenêtre. La source PRÉCISE (radar).
     *
     * P2-38 — la fenêtre de CLIP (`$windowStart`/`$windowEnd`) et la fenêtre de REPLI
     * legacy (`$fallbackStart`/`$fallbackEnd`, null ⇒ = la fenêtre de clip) sont désormais
     * distinctes. Une fermeture TRANSVERSALE portée par une AUTRE entrée (`PlanVenueClosures`)
     * est bornée à SA propre entrée pour le repli legacy — jamais à la fenêtre du plan
     * consommateur, sinon un `config` sans dates fermerait TOUT le plan (le défaut que P2-38
     * ferme). Les appelants mono-fenêtre historiques laissent le repli à null : rien ne bouge.
     *
     * @param iterable<Constraint> $datedConstraints
     *
     * @return array<string, array<string, true>> venueId => set de dates Y-m-d fermées
     */
    public static function closedDatesByVenue(iterable $datedConstraints, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, ?DateTimeImmutable $fallbackStart = null, ?DateTimeImmutable $fallbackEnd = null): array
    {
        $windowStartDay = $windowStart->format('Y-m-d');
        $windowEndDay = $windowEnd->format('Y-m-d');
        $fallbackStartDay = ($fallbackStart ?? $windowStart)->format('Y-m-d');
        $fallbackEndDay = ($fallbackEnd ?? $windowEnd)->format('Y-m-d');

        $closed = [];
        foreach ($datedConstraints as $constraint) {
            if (!self::isVenueClosure($constraint)) {
                continue;
            }
            $venueId = (string) $constraint->getScopeTargetId();
            $config = $constraint->getConfig();
            $incidentStart = self::isoDate($config['startDate'] ?? null);
            $incidentEnd = self::isoDate($config['endDate'] ?? null);
            // Fallback si UNE des bornes manque/est invalide : un config partiel est malformé,
            // on ferme toute la fenêtre de REPLI (par défaut la fenêtre de clip ; pour une
            // fermeture transversale, la fenêtre de SON entrée porteuse) — jamais sous-contraint.
            if (null === $incidentStart || null === $incidentEnd) {
                $incidentStart = $fallbackStartDay;
                $incidentEnd = $fallbackEndDay;
            }
            $from = max($incidentStart, $windowStartDay);
            $to = min($incidentEnd, $windowEndDay);
            if ($from > $to) {
                continue; // incident disjoint de la fenêtre : rien de fermé ici
            }
            // DatePeriod end exclusif → +1 jour. (from/to sont des dates VALIDES : isoDate le garantit.)
            $end = new DateTimeImmutable($to)->modify('+1 day');
            foreach (new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), $end) as $day) {
                $closed[$venueId][$day->format('Y-m-d')] = true;
            }
        }

        return $closed;
    }

    /**
     * P2-37 D1 — les gymnases dont TOUTES les dates de la fenêtre sont fermées : le gymnase
     * ne sert AUCUN jour de la période, il est TOTALEMENT indisponible. DÉRIVÉ, jamais stocké
     * (une fermeture éditée après coup se répercute alors toute seule).
     *
     * Calculé sur {@see closedDatesByVenue}, qui UNIT les dates fermées de TOUTES les
     * fermetures du gymnase : deux fermetures partielles qui se RELAIENT (lun→jeu + ven→dim)
     * couvrent la fenêtre à elles deux et rendent le gymnase entièrement fermé — un test naïf
     * « une fermeture ⊇ la fenêtre » raterait ce cas. Un gymnase est entièrement fermé ssi son
     * ensemble de dates fermées (borné à la fenêtre, dédupliqué) a autant d'éléments que la
     * fenêtre a de jours.
     *
     * @param iterable<Constraint> $datedConstraints
     *
     * @return list<string> venueIds entièrement fermés sur la fenêtre
     */
    public static function fullyClosedVenueIds(iterable $datedConstraints, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        return self::fullyClosedFromDates(
            self::closedDatesByVenue($datedConstraints, $windowStart, $windowEnd),
            $windowStart,
            $windowEnd,
        );
    }

    /**
     * P2-38 — même dérivation « fenêtre entièrement couverte » que {@see fullyClosedVenueIds},
     * mais à partir d'un ensemble de dates DÉJÀ MERGÉ (l'union de plusieurs entrées porteuses,
     * calculée par {@see PlanVenueClosures}). La seule maison du calcul « fermé-total ».
     *
     * @param array<string, array<string, true>> $closedDatesByVenue venueId => set de dates Y-m-d
     *
     * @return list<string> venueIds entièrement fermés sur la fenêtre
     */
    public static function fullyClosedFromDates(array $closedDatesByVenue, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        if ([] === $closedDatesByVenue) {
            return [];
        }
        $windowDayCount = self::windowDayCount($windowStart, $windowEnd);
        if (0 === $windowDayCount) {
            return [];
        }
        $fully = [];
        foreach ($closedDatesByVenue as $venueId => $dates) {
            // Les dates sont bornées à la fenêtre et dédupliquées (clé de set),
            // donc |dates| ≤ windowDayCount : l'égalité vaut couverture totale.
            if (\count($dates) >= $windowDayCount) {
                $fully[] = $venueId;
            }
        }

        return $fully;
    }

    /**
     * JOURS ISO fermés par gymnase (dérivés des dates) — pour le BUILDER (semaine-type).
     *
     * @param iterable<Constraint> $datedConstraints
     *
     * @return array<string, array<int, true>> venueId => set de jours ISO fermés (1..7)
     */
    public static function closedWeekdaysByVenue(iterable $datedConstraints, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        return self::closedWeekdaysFromDates(self::closedDatesByVenue($datedConstraints, $windowStart, $windowEnd));
    }

    /**
     * P2-38 — jours ISO fermés à partir d'un ensemble de dates DÉJÀ MERGÉ (union des
     * entrées porteuses, {@see PlanVenueClosures}). Même dérivation que
     * {@see closedWeekdaysByVenue}, une seule maison.
     *
     * @param array<string, array<string, true>> $closedDatesByVenue venueId => set de dates Y-m-d
     *
     * @return array<string, array<int, true>> venueId => set de jours ISO fermés (1..7)
     */
    public static function closedWeekdaysFromDates(array $closedDatesByVenue): array
    {
        $weekdays = [];
        foreach ($closedDatesByVenue as $venueId => $dates) {
            foreach (array_keys($dates) as $date) {
                $weekdays[$venueId][(int) new DateTimeImmutable($date)->format('N')] = true;
            }
        }

        return $weekdays;
    }

    /**
     * RÉSUMÉ LISIBLE de chaque fermeture recoupant la fenêtre — pour l'affichage (radar,
     * message de garde) : le gymnase, le titre, les bornes de l'incident brut et les jours
     * ISO fermés ∩ fenêtre. Une fermeture entièrement hors fenêtre est absente.
     *
     * Les `weekdays` se dérivent EXACTEMENT comme {@see closedWeekdaysByVenue} (même
     * sur-fermeture assumée sur un bloc multi-semaines) ; `startDate`/`endDate` restent les
     * bornes de l'incident (config), et retombent sur la fenêtre de l'entrée en legacy (config
     * sans dates valides), comme {@see closedDatesByVenue}. Une entrée par contrainte : deux
     * fermetures d'un même gymnase donnent deux résumés.
     *
     * @param iterable<Constraint> $datedConstraints
     *
     * @return list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}>
     */
    public static function closureSummaries(iterable $datedConstraints, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, ?DateTimeImmutable $fallbackStart = null, ?DateTimeImmutable $fallbackEnd = null): array
    {
        $windowStartDay = $windowStart->format('Y-m-d');
        $windowEndDay = $windowEnd->format('Y-m-d');
        $fallbackStartDay = ($fallbackStart ?? $windowStart)->format('Y-m-d');
        $fallbackEndDay = ($fallbackEnd ?? $windowEnd)->format('Y-m-d');

        $summaries = [];
        foreach ($datedConstraints as $constraint) {
            if (!self::isVenueClosure($constraint)) {
                continue;
            }
            $config = $constraint->getConfig();
            $incidentStart = self::isoDate($config['startDate'] ?? null);
            $incidentEnd = self::isoDate($config['endDate'] ?? null);
            // Legacy / config nu : bornes = fenêtre de REPLI (l'entrée porteuse ;
            // par défaut la fenêtre de clip, voir closedDatesByVenue).
            if (null === $incidentStart || null === $incidentEnd) {
                $incidentStart = $fallbackStartDay;
                $incidentEnd = $fallbackEndDay;
            }
            $from = max($incidentStart, $windowStartDay);
            $to = min($incidentEnd, $windowEndDay);
            if ($from > $to) {
                continue; // fermeture entièrement hors fenêtre → absente
            }
            // Jours ISO fermés ∩ fenêtre — même dérivation que closedWeekdaysByVenue.
            $weekdaySet = [];
            $end = new DateTimeImmutable($to)->modify('+1 day'); // DatePeriod end exclusif
            foreach (new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), $end) as $day) {
                $weekdaySet[(int) $day->format('N')] = true;
            }
            ksort($weekdaySet);
            $summaries[] = [
                'constraintId' => $constraint->getId(),
                'venueId' => (string) $constraint->getScopeTargetId(),
                'title' => $constraint->getName(),
                'startDate' => $incidentStart,
                'endDate' => $incidentEnd,
                'weekdays' => array_keys($weekdaySet),
            ];
        }

        return $summaries;
    }

    /**
     * P4-300 — l'INTERVALLE BRUT de chaque fermeture `venue_closed`, SANS clip : les dates du
     * `config` si elles sont valides, sinon la fenêtre de REPLI legacy (l'entrée porteuse). C'est
     * le FAIT brut que les MATCHS consomment (décision fondateur D1) — aucune intersection avec
     * une fenêtre de plan, aucune composition `VenuePeriodOverride` (un jour rouvert dans un plan
     * d'entraînement NE rouvre PAS la salle aux matchs). Une fermeture entièrement hors de toute
     * saison reste émise telle quelle : c'est l'appelant (match à une date) qui tranche l'inclusion.
     *
     * Contrairement à {@see closureSummaries} (qui CLIPE à la fenêtre et laisse tomber une
     * fermeture disjointe), ici rien n'est clipé ni filtré : une entrée par contrainte.
     *
     * @param iterable<Constraint> $datedConstraints
     *
     * @return list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string}>
     */
    public static function rawIntervals(iterable $datedConstraints, DateTimeImmutable $fallbackStart, DateTimeImmutable $fallbackEnd): array
    {
        $fallbackStartDay = $fallbackStart->format('Y-m-d');
        $fallbackEndDay = $fallbackEnd->format('Y-m-d');

        $intervals = [];
        foreach ($datedConstraints as $constraint) {
            if (!self::isVenueClosure($constraint)) {
                continue;
            }
            $config = $constraint->getConfig();
            $start = self::isoDate($config['startDate'] ?? null);
            $end = self::isoDate($config['endDate'] ?? null);
            // Config partiel/legacy : repli sur la fenêtre de l'entrée porteuse (jamais clipé).
            if (null === $start || null === $end) {
                $start = $fallbackStartDay;
                $end = $fallbackEndDay;
            }
            $intervals[] = [
                'constraintId' => $constraint->getId(),
                'venueId' => (string) $constraint->getScopeTargetId(),
                'title' => $constraint->getName(),
                'startDate' => $start,
                'endDate' => $end,
            ];
        }

        return $intervals;
    }

    /** Nombre de jours calendaires de la fenêtre, bornes incluses (0 si fenêtre inversée). */
    private static function windowDayCount(DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): int
    {
        $start = new DateTimeImmutable($windowStart->format('Y-m-d'));
        $end = new DateTimeImmutable($windowEnd->format('Y-m-d'));
        if ($end < $start) {
            return 0;
        }

        return (int) $start->diff($end)->days + 1;
    }

    /**
     * Cette contrainte est-elle une fermeture de gymnase ? FACILITY active à
     * scopeTargetId, ET config.type = venue_closed OU config sans `type` (legacy :
     * jusqu'ici toute datée FACILITY était une fermeture). Exclut un futur
     * forcedVenueId/preferredVenueId daté qui ne ferme rien.
     */
    private static function isVenueClosure(Constraint $constraint): bool
    {
        if (ConstraintFamily::FACILITY !== $constraint->getFamily() || !$constraint->getIsActive() || null === $constraint->getScopeTargetId()) {
            return false;
        }
        $type = $constraint->getConfig()['type'] ?? null;

        return null === $type || 'venue_closed' === $type;
    }

    /** Y-m-d syntaxiquement ET calendairement valide (checkdate), sinon null. */
    private static function isoDate(mixed $value): ?string
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null; // format valide mais date impossible (2026-13-45) → pas de crash
        }
        // Défense en profondeur : toute anomalie de parse retombe sur « pas de date ».
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }

        return $value;
    }
}
