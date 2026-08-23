<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\VenuePeriodOverride;
use App\Enum\VenueDayState;
use App\Enum\VenuePeriodMode;
use App\Repository\ConstraintRepository;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * P2-37/P2-38 — la MAISON UNIQUE de « ce que les fermetures s'appliquant à CE plan font ».
 *
 * Une indisponibilité de gymnase est une contrainte datée `venue_closed` portée par une entrée
 * de calendrier (ADR-0002 inv. 5 : la datée décrit le FAIT, elle reste au calendrier). Quatre
 * consommateurs en dérivent la même chose et doivent le faire de LA MÊME façon :
 *  - `ScheduleConstraintBuilder::buildForPeriodPlan` (le payload : le gymnase perd ses créneaux
 *    les jours fermés) ;
 *  - `OrphanPinGuard` (fermé-total = gymnase inerte, à SKIPPER ; jour fermé partiel = bloquant) ;
 *  - `VenuePeriodOverrideStateProcessor` (pas de mode sur un gymnase fermé-total) ;
 *  - `ReservationStateProcessor` (pas de réservation sur un gymnase fermé-total ou un jour fermé).
 *
 * P2-38 — TRANSVERSALITÉ (décision fondateur R4, bornée aux FERMETURES) : une fermeture
 * `venue_closed` s'applique à TOUT plan de période dont la fenêtre recoupe ses dates, quelle que
 * soit l'entrée qui la porte. Une fermeture « Matéo en travaux » déclarée sur la période racine
 * doit fermer le gymnase pendant une semaine de reprise qui la recoupe — sinon le solveur y place
 * des séances (planning faux produit en silence, le défaut que P2-38 ferme). On lit donc TOUTES
 * les fermetures du club+saison, chacune bornée à (les dates de son `config` si valides, sinon la
 * fenêtre de SA PROPRE entrée porteuse — le repli legacy ; jamais la fenêtre du plan consommateur)
 * ∩ la fenêtre du plan. Le plan SEASON n'a pas de fenêtre datée : {@see forPlan} y rend le vide
 * (R1 — une fermeture datée ne touche jamais le socle).
 *
 * Indispo INFORMATIVE (décision fondateur 2026-08-18) — l'indisponibilité déclarée ne FERME plus
 * d'autorité : elle PRÉ-REMPLIT le défaut, et le réglage du plan décide. {@see effectiveStateForPlan}
 * COMPOSE l'incident (le défaut vivant) avec les réglages de `VenuePeriodOverride` (mode + masque
 * jour) et rend l'ÉTAT EFFECTIF que tous les consommateurs partagent : le payload
 * (`ScheduleConstraintBuilder`), le gate (`PeriodConstraintSelector`), le garde (`OrphanPinGuard`),
 * la garde de réservation (`ReservationStateProcessor`) et le radar (`CalendarEntryConflictsController`).
 * La composition est la SEULE maison ; les consommateurs ne la redérivent jamais.
 */
final class PlanVenueClosures
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConstraintRepository $constraintRepository,
    ) {}

    /**
     * Un libellé HUMAIN des fermetures d'un gymnase (titre + bornes j/n), ou null si aucune.
     * Aucun identifiant interne — que des données saisies par le gestionnaire
     * (`PublicTextIsFreeOfInternalIdentifiersTest`).
     *
     * @param list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}> $summaries
     */
    public static function describeForVenue(array $summaries, string $venueId): ?string
    {
        $labels = [];
        foreach ($summaries as $summary) {
            if ($summary['venueId'] !== $venueId) {
                continue;
            }
            $labels[] = \sprintf(
                '« %s » (du %s au %s)',
                $summary['title'],
                self::humanDate($summary['startDate']),
                self::humanDate($summary['endDate']),
            );
        }

        return [] === $labels ? null : implode(' ; ', $labels);
    }

    /** Date `Y-m-d` en `j/n` humain (« 20/10 »), sans zéro de tête. */
    private static function humanDate(string $isoDate): string
    {
        return new DateTimeImmutable($isoDate)->format('j/n');
    }

    /**
     * On ne réserve pas un gymnase que la période rend indisponible CE jour-là. L'indisponibilité
     * est INFORMATIVE (décision fondateur 2026-08-18) : on suit l'ÉTAT EFFECTIF de la MAISON UNIQUE
     * — l'incident déclaré COMPOSÉ avec le masque manuel du plan. Un jour rouvert OPEN redevient
     * réservable ; un jour décoché à la main devient refusé. Le message distingue la cause
     * (indisponibilité déclarée vs décochage manuel). On refuse à la SOURCE (422).
     *
     * MAISON UNIQUE partagée : le rail de réservation unitaire ({@see ReservationStateProcessor})
     * ET le rail batch de mutualisation ({@see GroupReservationController}) passent tous deux ici —
     * la copie du message et la logique de composition ne vivent qu'à un endroit.
     */
    public function assertVenueOpenForPlan(?string $schedulePlanId, ?string $venueId, ?int $dayOfWeek): void
    {
        // Trois retours anticipés séparés (et non un `||` — que Rector réécrirait en `in_array`,
        // perdant le narrowing non-null dont a besoin `effectiveStateForPlan(string)`).
        if (null === $schedulePlanId) {
            return;
        }
        if (null === $venueId || null === $dayOfWeek) {
            return;
        }
        $state = $this->effectiveStateForPlan($schedulePlanId);
        $fullyClosed = isset($state['fullyClosedVenueIds'][$venueId]);
        $dayClosed = isset($state['effectiveClosedWeekdaysByVenue'][$venueId][$dayOfWeek]);
        if (!$fullyClosed && !$dayClosed) {
            return;
        }

        // Un jour fermé À LA MAIN (masque CLOSED sans indisponibilité déclarée dessous) : la
        // cause est le décochage, pas une fermeture — rien à nommer, on invite à le rouvrir.
        if (!$fullyClosed && isset($state['manualClosedWeekdaysByVenue'][$venueId][$dayOfWeek])) {
            throw new UnprocessableEntityHttpException('Ce gymnase est fermé ce jour-là pour cette période (jour décoché) : la séance ne peut pas y être réservée. Rouvrez ce jour, ou choisissez un autre créneau.');
        }

        $label = self::describeForVenue($state['summaries'], $venueId);
        $cause = $fullyClosed ? 'est indisponible sur toute la période' : 'est fermé ce jour-là';

        // Même patron surfaçant le message que `assertSchedulePlanExists` (le
        // `ValidationException(string)` d'API Platform ne remonte pas son message).
        throw new UnprocessableEntityHttpException(\sprintf('Ce gymnase %s%s : la séance ne peut pas y être réservée. Choisissez un autre créneau, ou ajustez la fermeture.', $cause, null !== $label ? ' — ' . $label : ''));
    }

    /**
     * @return array{
     *   fullyClosedVenueIds: array<string, true>,
     *   closedWeekdaysByVenue: array<string, array<int, true>>,
     *   closedDatesByVenue: array<string, array<string, true>>,
     *   summaries: list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}>
     * }
     */
    public function forPlan(string $schedulePlanId): array
    {
        $entry = $this->periodEntry($schedulePlanId);
        if (!$entry instanceof CalendarEntry) {
            // un plan SEASON (ou introuvable) n'a pas de fenêtre datée → aucune fermeture (R1)
            return ['fullyClosedVenueIds' => [], 'closedWeekdaysByVenue' => [], 'closedDatesByVenue' => [], 'summaries' => []];
        }

        return $this->forEntry($entry);
    }

    /**
     * Les fermetures qui s'appliquent à la FENÊTRE d'une entrée de période — transversales
     * (toutes entrées confondues, bornées à leur propre entrée porteuse) ∩ la fenêtre de
     * `$planEntry`. Le radar (`CalendarEntryConflictsController`) l'utilise directement pour
     * une entrée qui porte un plan ; {@see forPlan} y délègue.
     *
     * @return array{
     *   fullyClosedVenueIds: array<string, true>,
     *   closedWeekdaysByVenue: array<string, array<int, true>>,
     *   closedDatesByVenue: array<string, array<string, true>>,
     *   summaries: list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}>
     * }
     */
    public function forEntry(CalendarEntry $planEntry): array
    {
        $planStart = $planEntry->getStartDate();
        $planEnd = $planEntry->getEndDate();

        $closedDates = [];
        $summaries = [];
        foreach ($this->closuresByCarrier($planEntry->getClubId(), $planEntry->getSeasonId()) as [$constraints, $carrierStart, $carrierEnd]) {
            // Clip = fenêtre du plan ; repli legacy = fenêtre de l'entrée PORTEUSE (jamais celle
            // du plan : sinon un config sans dates fermerait TOUT le plan — défaut fermé par P2-38).
            foreach (VenueClosureDays::closedDatesByVenue($constraints, $planStart, $planEnd, $carrierStart, $carrierEnd) as $venueId => $dates) {
                foreach (array_keys($dates) as $day) {
                    $closedDates[$venueId][$day] = true;
                }
            }
            foreach (VenueClosureDays::closureSummaries($constraints, $planStart, $planEnd, $carrierStart, $carrierEnd) as $summary) {
                $summaries[] = $summary;
            }
        }

        // Fermé-total = calculé sur l'UNION des dates fermées de TOUTES les entrées porteuses
        // (deux fermetures qui se relaient couvrent la fenêtre à elles deux — P2-37 D1).
        $fully = [];
        foreach (VenueClosureDays::fullyClosedFromDates($closedDates, $planStart, $planEnd) as $venueId) {
            $fully[$venueId] = true;
        }

        return [
            'fullyClosedVenueIds' => $fully,
            'closedWeekdaysByVenue' => VenueClosureDays::closedWeekdaysFromDates($closedDates),
            'closedDatesByVenue' => $closedDates,
            'summaries' => $summaries,
        ];
    }

    /**
     * L'ÉTAT EFFECTIF d'un plan de période : l'incident (le défaut vivant) COMPOSÉ avec les
     * réglages de `VenuePeriodOverride` (mode + masque jour). C'est la maison unique que tous
     * les consommateurs partagent — aucun ne redérive la composition.
     *
     * Le plan SEASON (ou introuvable) n'a pas de fenêtre datée → tout est vide (R1).
     *
     * @return array{
     *   disabledVenueIds: array<string, true>,
     *   effectiveClosedWeekdaysByVenue: array<string, array<int, true>>,
     *   defaultClosedWeekdaysByVenue: array<string, array<int, true>>,
     *   manualClosedWeekdaysByVenue: array<string, array<int, true>>,
     *   fullyClosedVenueIds: array<string, true>,
     *   summaries: list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}>
     * }
     */
    public function effectiveStateForPlan(string $schedulePlanId): array
    {
        $entry = $this->periodEntry($schedulePlanId);
        if (!$entry instanceof CalendarEntry) {
            return $this->emptyEffectiveState();
        }

        return $this->effectiveStateForEntry($entry, $schedulePlanId);
    }

    /**
     * Même composition que {@see effectiveStateForPlan}, mais quand l'appelant tient déjà
     * l'entrée du plan (le sélecteur de période) — évite de la relire.
     *
     * Règle de composition (décision fondateur 2026-08-18), jour par jour :
     *   mode = DISABLED           → gymnase hors service (masque DORMANT, conservé sans effet) ;
     *   sinon base(jour) =
     *     mode = BLANK            → ouvert (grille ressaisie, incident informatif) ;
     *     sinon (défaut vivant)   → fermé ssi jour ∈ jours dérivés de l'incident ;
     *   masque[jour] = OPEN       → ouvert (remplace la base pour CE jour) ;
     *   masque[jour] = CLOSED     → fermé ;
     *   masque[jour] absent       → base(jour).
     *
     * @return array{
     *   disabledVenueIds: array<string, true>,
     *   effectiveClosedWeekdaysByVenue: array<string, array<int, true>>,
     *   defaultClosedWeekdaysByVenue: array<string, array<int, true>>,
     *   manualClosedWeekdaysByVenue: array<string, array<int, true>>,
     *   fullyClosedVenueIds: array<string, true>,
     *   summaries: list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}>
     * }
     */
    public function effectiveStateForEntry(CalendarEntry $planEntry, string $schedulePlanId): array
    {
        $incident = $this->forEntry($planEntry);
        $defaultClosedWeekdaysByVenue = $incident['closedWeekdaysByVenue'];

        $disabledVenueIds = [];
        $modeByVenue = [];
        $maskByVenue = [];
        foreach ($this->entityManager->getRepository(VenuePeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
            $venueId = $override->getVenueId();
            $modeByVenue[$venueId] = $override->getMode();
            $maskByVenue[$venueId] = $override->getDayOverrides() ?? [];
            if (VenuePeriodMode::DISABLED === $override->getMode()) {
                $disabledVenueIds[$venueId] = true;
            }
        }

        $windowWeekdays = $this->windowWeekdays($planEntry);

        $effectiveClosedWeekdaysByVenue = [];
        $manualClosedWeekdaysByVenue = [];
        $fullyClosedVenueIds = [];
        $venueIds = array_unique([...array_keys($defaultClosedWeekdaysByVenue), ...array_keys($modeByVenue)]);
        foreach ($venueIds as $venueId) {
            // Un gymnase DÉSACTIVÉ est hors service tout entier : le masque reste DORMANT
            // (stocké, rendu tel quel à la réactivation), sans effet sur l'état effectif.
            if (isset($disabledVenueIds[$venueId])) {
                continue;
            }
            $mode = $modeByVenue[$venueId] ?? null;
            // BLANK ressaisit la grille : l'incident redevient purement informatif (base vide).
            $closed = VenuePeriodMode::BLANK === $mode ? [] : ($defaultClosedWeekdaysByVenue[$venueId] ?? []);
            foreach ($maskByVenue[$venueId] ?? [] as $day => $state) {
                $day = (int) $day;
                if (VenueDayState::OPEN->value === $state) {
                    unset($closed[$day]);
                } elseif (VenueDayState::CLOSED->value === $state) {
                    $closed[$day] = true;
                    if (!isset($defaultClosedWeekdaysByVenue[$venueId][$day])) {
                        $manualClosedWeekdaysByVenue[$venueId][$day] = true;
                    }
                }
            }
            if ([] !== $closed) {
                $effectiveClosedWeekdaysByVenue[$venueId] = $closed;
            }
            // Effectivement fermé-total = plus AUCUN jour de la fenêtre n'est servi. Un
            // épinglage y est inerte (rien où le déplacer en silence) — même doctrine que le
            // gymnase désactivé (OrphanPinGuard, P2-37 D4 / P3-20).
            if ([] !== $windowWeekdays && [] === array_diff($windowWeekdays, array_keys($closed))) {
                $fullyClosedVenueIds[$venueId] = true;
            }
        }

        return [
            'disabledVenueIds' => $disabledVenueIds,
            'effectiveClosedWeekdaysByVenue' => $effectiveClosedWeekdaysByVenue,
            'defaultClosedWeekdaysByVenue' => $defaultClosedWeekdaysByVenue,
            'manualClosedWeekdaysByVenue' => $manualClosedWeekdaysByVenue,
            'fullyClosedVenueIds' => $fullyClosedVenueIds,
            'summaries' => $incident['summaries'],
        ];
    }

    /**
     * @return array{
     *   disabledVenueIds: array<string, true>,
     *   effectiveClosedWeekdaysByVenue: array<string, array<int, true>>,
     *   defaultClosedWeekdaysByVenue: array<string, array<int, true>>,
     *   manualClosedWeekdaysByVenue: array<string, array<int, true>>,
     *   fullyClosedVenueIds: array<string, true>,
     *   summaries: list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}>
     * }
     */
    private function emptyEffectiveState(): array
    {
        return [
            'disabledVenueIds' => [],
            'effectiveClosedWeekdaysByVenue' => [],
            'defaultClosedWeekdaysByVenue' => [],
            'manualClosedWeekdaysByVenue' => [],
            'fullyClosedVenueIds' => [],
            'summaries' => [],
        ];
    }

    /**
     * Les jours ISO (1..7) qui APPARAISSENT dans la fenêtre du plan — la base du « fermé-total »
     * effectif. Une semaine pleine les couvre tous ; une fenêtre courte en couvre un sous-ensemble.
     *
     * @return list<int>
     */
    private function windowWeekdays(CalendarEntry $planEntry): array
    {
        $weekdays = [];
        $end = $planEntry->getEndDate()->modify('+1 day'); // DatePeriod end exclusif
        foreach (new DatePeriod($planEntry->getStartDate(), new DateInterval('P1D'), $end) as $day) {
            $weekdays[(int) $day->format('N')] = true;
        }

        return array_keys($weekdays);
    }

    /**
     * Les fermetures datées du club+saison, groupées par entrée PORTEUSE, chacune assortie de
     * la fenêtre de cette entrée (pour le repli legacy). Ordre stable : le repo trie par
     * (createdAt, id) — d'où des résumés déterministes quand plusieurs fermetures se recoupent.
     *
     * @return list<array{0: list<Constraint>, 1: DateTimeImmutable, 2: DateTimeImmutable}>
     */
    private function closuresByCarrier(string $clubId, string $seasonId): array
    {
        $closures = $this->constraintRepository->findDatedFacilityByClubSeason($clubId, $seasonId);
        if ([] === $closures) {
            return [];
        }

        $byCarrier = [];
        foreach ($closures as $closure) {
            $carrierId = $closure->getCalendarEntryId();
            if (null === $carrierId) {
                continue; // déjà filtré en SQL — garde de typage
            }
            $byCarrier[$carrierId][] = $closure;
        }

        // La fenêtre de CHAQUE entrée porteuse : elle borne le repli legacy à sa propre entrée.
        $windowById = [];
        foreach ($this->entityManager->getRepository(CalendarEntry::class)->findBy(['id' => array_keys($byCarrier)]) as $carrier) {
            $windowById[$carrier->getId()] = [$carrier->getStartDate(), $carrier->getEndDate()];
        }

        $groups = [];
        foreach ($byCarrier as $carrierId => $constraints) {
            if (!isset($windowById[$carrierId])) {
                continue; // entrée porteuse disparue : aucune fenêtre pour la borner, on ignore
            }
            [$carrierStart, $carrierEnd] = $windowById[$carrierId];
            $groups[] = [$constraints, $carrierStart, $carrierEnd];
        }

        return $groups;
    }

    private function periodEntry(string $schedulePlanId): ?CalendarEntry
    {
        $entryId = $this->entityManager->getConnection()->fetchOne(
            'SELECT calendar_entry_id FROM schedule_plan WHERE id = :pid',
            ['pid' => $schedulePlanId],
        );
        if (!\is_string($entryId) || '' === $entryId) {
            return null;
        }

        return $this->entityManager->getRepository(CalendarEntry::class)->find($entryId);
    }
}
