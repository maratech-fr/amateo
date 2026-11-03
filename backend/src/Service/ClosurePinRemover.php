<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Enum\AuditAction;
use App\Enum\LockLevel;
use App\Enum\VenuePeriodMode;
use App\Exception\ScheduleGenerationInProgressException;
use App\State\Processor\CalendarEntryStateProcessor;
use App\State\Processor\ConstraintStateProcessor;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * « Enregistrer une fermeture, après lecture de l'aperçu, vaut geste » (décision fondateur
 * 2026-11-02, chantier « Fermetures de gymnase » C3bis) — la MAISON UNIQUE du retrait des
 * épingles qu'une fermeture DÉCLARÉE met hors de portée.
 *
 * Pour les dates fermées NOUVELLES d'un gymnase (création : toutes ; re-datage / élargissement :
 * celles qui n'étaient pas déjà fermées), chaque plan de PÉRIODE qui gouverne ces dates
 * ({@see PeriodWindowUniquenessGuard::governingWindows}, `type <> SEASON` : le socle n'est JAMAIS
 * touché) perd, sur ce gymnase et aux jours ISO de ces dates :
 *  - ses verrous HARD (toute origine) de la version CHARGÉE ({@see SchedulePlanProvisioner::lockSourceScheduleIdOfPlan}),
 *    ramenés à NONE par {@see ManualEditService::applyLock} (origine effacée, trace `LOCK_CHANGED`
 *    avec la cause) ;
 *  - ses réservations, avec leur case bloc-complète et leurs verrous matérialisés
 *    ({@see ReservationRemoval}, même maison que le DELETE de l'API ; trace `ENTITY_DELETED`).
 * Jamais un gymnase DÉSACTIVÉ dans le plan (tout y est conservé, P3-20), jamais un jour que le plan
 * ROUVRE (masque OPEN, mode BLANK : la réouverture fait foi), jamais un autre gymnase ni un autre
 * jour. Les réservations s'annoncent dès qu'elles existent, avec ou sans version ; seuls les verrous
 * dépendent d'une version chargée (correction E3).
 *
 * `preview` LIT ce qu'`apply` retirerait — le même calcul, si bien que l'aperçu du formulaire et
 * le retrait disent le même chiffre par construction. `apply` est un geste SYSTÈME : il ne passe
 * pas par `VersionEditability` (qui ne tient que les guichets) et ne pose pas
 * `manuallyEditedSinceGeneration` ; le plan devient périmé par son empreinte.
 *
 * Génération en vol (E1) : le payload d'une période se bâtit au solve et l'import rematérialise
 * les verrous — un retrait concurrent serait annulé. `apply` PREND donc le verrou de génération du
 * club le temps du retrait (échec de prise → {@see ScheduleGenerationInProgressException} → 409,
 * rien d'écrit). Appelé dans la transaction de l'écriture qui fait naître les dates fermées
 * ({@see ConstraintStateProcessor}, {@see CalendarEntryStateProcessor}, rail `/window`), sous
 * `lockClubWindows` ; il prend lui-même les `lockPlanScope` des plans visés, triés (E6).
 */
final class ClosurePinRemover
{
    private const GENERATION_LOCK_TTL_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PeriodWindowUniquenessGuard $windowGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PlanVenueClosures $planVenueClosures,
        private readonly ReservationRemoval $reservationRemoval,
        private readonly ManualEditService $manualEditService,
        private readonly AuditTrail $auditTrail,
        private readonly Security $security,
        private readonly ClubGenerationLock $generationLock,
    ) {}

    /**
     * Ce que le retrait ferait, plan par plan (lecture pure).
     *
     * @param array<string, true> $newClosedDates dates fermées NOUVELLES (Y-m-d) du gymnase
     * @param string|null         $ignoredEntryId la fermeture interrogée : sa FAMILLE ne compte pas parmi les plans visés
     *
     * @return list<array{entryId: string, planId: string, title: string, startDate: string, endDate: string, chosenScheduleId: string|null, loadedScheduleId: string|null, locks: int, reservations: list<array{reservationId: string, teamId: string|null, teamName: string, dayOfWeek: int}>}>
     */
    public function preview(string $clubId, string $seasonId, string $venueId, array $newClosedDates, ?string $ignoredEntryId): array
    {
        $lines = [];
        foreach ($this->targets($clubId, $seasonId, $venueId, $newClosedDates, $ignoredEntryId) as $target) {
            $lines[] = [
                'entryId' => $target['entryId'],
                'planId' => $target['planId'],
                'title' => $target['title'],
                'startDate' => $target['startDate'],
                'endDate' => $target['endDate'],
                'chosenScheduleId' => $target['chosenScheduleId'],
                'loadedScheduleId' => $target['loadedScheduleId'],
                'locks' => \count($target['locks']),
                'reservations' => array_map(fn (Reservation $r): array => [
                    'reservationId' => $r->getId(),
                    'teamId' => $r->getTeamId(),
                    'teamName' => $this->subjectName($r),
                    'dayOfWeek' => $r->getDayOfWeek(),
                ], $target['reservations']),
            ];
        }

        return $lines;
    }

    /**
     * Retire ce que {@see preview} annonce. À appeler dans une transaction, sous `lockClubWindows`.
     *
     * @param array<string, true> $newClosedDates dates fermées NOUVELLES (Y-m-d) du gymnase
     * @param string|null         $constraintId   la fermeture qui cause le retrait (nommée dans la trace d'audit)
     *
     * @throws ScheduleGenerationInProgressException une génération tient le verrou du club
     *
     * @return array{locks: int, reservations: int}
     */
    public function apply(string $clubId, string $seasonId, string $venueId, array $newClosedDates, ?string $ignoredEntryId, ?string $constraintId = null): array
    {
        if ([] === $newClosedDates) {
            return ['locks' => 0, 'reservations' => 0];
        }
        $token = $this->generationLock->acquire($clubId, self::GENERATION_LOCK_TTL_SECONDS);
        if (null === $token) {
            throw new ScheduleGenerationInProgressException('Une génération est en cours pour ce club : réessayez quand elle est terminée.');
        }

        try {
            // E6 — les plans visés se verrouillent triés, AVANT la lecture qui décide.
            $windows = $this->governing($clubId, $seasonId, $newClosedDates, $ignoredEntryId);
            $entryIds = array_column($windows, 'entry_id');
            sort($entryIds);
            foreach ($entryIds as $entryId) {
                $this->schedulePlanProvisioner->lockPlanScope($entryId);
            }

            $details = ['cause' => 'venue_closed', 'constraintId' => $constraintId];
            $locks = 0;
            $removed = [];
            foreach ($this->targets($clubId, $seasonId, $venueId, $newClosedDates, $ignoredEntryId) as $target) {
                foreach ($target['locks'] as $slot) {
                    $this->manualEditService->applyLock($slot, LockLevel::NONE, $details, flush: false);
                    ++$locks;
                }
                foreach ($target['reservations'] as $reservation) {
                    if (isset($removed[$reservation->getId()])) {
                        continue;
                    }
                    foreach ($this->reservationRemoval->remove($reservation) as $gone) {
                        $removed[$gone->getId()] = $gone;
                    }
                }
            }
            $this->entityManager->flush();

            $actor = $this->security->getUser();
            foreach (array_keys($removed) as $reservationId) {
                $this->auditTrail->record(
                    AuditAction::ENTITY_DELETED,
                    $actor instanceof User ? $actor->getId() : null,
                    $clubId,
                    'Reservation',
                    $reservationId,
                    $details,
                );
            }

            return ['locks' => $locks, 'reservations' => \count($removed)];
        } finally {
            $this->generationLock->release($clubId, $token);
        }
    }

    /**
     * L'union des fermetures déclarées du club+saison, à photographier AVANT une écriture qui fait
     * naître des dates fermées ; {@see newClosedDatesSince} en tire ensuite les seules NOUVELLES.
     *
     * @return array<string, array<string, true>> venueId → dates fermées
     */
    public function closedDatesSnapshot(string $clubId, string $seasonId): array
    {
        return $this->planVenueClosures->closedDatesByVenue($clubId, $seasonId);
    }

    /**
     * Les dates fermées NOUVELLES depuis la photo `$before` (lue maintenant, l'écriture flushée).
     *
     * @param array<string, array<string, true>> $before
     *
     * @return array<string, array<string, true>> venueId → dates nouvellement fermées (gymnases sans nouveauté omis)
     */
    public function newClosedDatesSince(string $clubId, string $seasonId, array $before): array
    {
        $new = [];
        foreach ($this->planVenueClosures->closedDatesByVenue($clubId, $seasonId) as $venueId => $dates) {
            $fresh = array_diff_key($dates, $before[$venueId] ?? []);
            if ([] !== $fresh) {
                $new[$venueId] = $fresh;
            }
        }

        return $new;
    }

    /**
     * Le total que {@see applyAll} retirerait (lecture pure) — le chiffre de l'aperçu d'un re-datage.
     *
     * @param array<string, array<string, true>> $newByVenue
     *
     * @return array{locks: int, reservations: int}
     */
    public function previewTotals(string $clubId, string $seasonId, array $newByVenue): array
    {
        $totals = ['locks' => 0, 'reservations' => 0];
        foreach ($newByVenue as $venueId => $dates) {
            foreach ($this->preview($clubId, $seasonId, (string) $venueId, $dates, null) as $line) {
                $totals['locks'] += $line['locks'];
                $totals['reservations'] += \count($line['reservations']);
            }
        }

        return $totals;
    }

    /**
     * L'ANNONCE détaillée de ce que {@see applyAll} retirerait (lecture pure) — servie par le refus
     * d'un re-datage non confirmé et par l'aperçu de l'élargissement, rendue par l'écran avec les
     * mêmes textes que l'aperçu de création : le gymnase, et chaque plan touché avec ses nombres.
     *
     * @param array<string, array<string, true>> $newByVenue
     *
     * @return array{venueName: string, plans: list<array{entryId: string, title: string, locks: int, reservations: list<array{teamId: string|null, teamName: string, dayOfWeek: int}>}>}
     */
    public function announcement(string $clubId, string $seasonId, array $newByVenue): array
    {
        $venueName = '';
        $plans = [];
        foreach ($newByVenue as $venueId => $dates) {
            foreach ($this->preview($clubId, $seasonId, (string) $venueId, $dates, null) as $line) {
                if (0 === $line['locks'] && [] === $line['reservations']) {
                    continue;
                }
                if ('' === $venueName) {
                    $venue = $this->entityManager->getRepository(Venue::class)->find((string) $venueId);
                    $venueName = $venue instanceof Venue ? $venue->getName() : '';
                }
                $plans[$line['entryId']] ??= ['entryId' => $line['entryId'], 'title' => $line['title'], 'locks' => 0, 'reservations' => []];
                $plans[$line['entryId']]['locks'] += $line['locks'];
                foreach ($line['reservations'] as $reservation) {
                    $plans[$line['entryId']]['reservations'][] = ['teamId' => $reservation['teamId'], 'teamName' => $reservation['teamName'], 'dayOfWeek' => $reservation['dayOfWeek']];
                }
            }
        }

        return ['venueName' => $venueName, 'plans' => array_values($plans)];
    }

    /**
     * {@see apply} pour chaque gymnase qui gagne des dates fermées.
     *
     * @param array<string, array<string, true>> $newByVenue
     * @param array<string, string>              $constraintIdByVenue la fermeture qui cause le retrait, par gymnase (audit)
     *
     * @return array{locks: int, reservations: int}
     */
    public function applyAll(string $clubId, string $seasonId, array $newByVenue, array $constraintIdByVenue = []): array
    {
        $totals = ['locks' => 0, 'reservations' => 0];
        foreach ($newByVenue as $venueId => $dates) {
            $removed = $this->apply($clubId, $seasonId, (string) $venueId, $dates, null, $constraintIdByVenue[$venueId] ?? null);
            $totals['locks'] += $removed['locks'];
            $totals['reservations'] += $removed['reservations'];
        }

        return $totals;
    }

    /**
     * Les plans de période qui gouvernent au moins une des dates (jamais le socle).
     *
     * @param array<string, true> $dates
     *
     * @return list<array{entry_id: string, entry_title: string, start_date: string, end_date: string}>
     */
    private function governing(string $clubId, string $seasonId, array $dates, ?string $ignoredEntryId): array
    {
        if ([] === $dates) {
            return [];
        }
        $sorted = array_keys($dates);
        sort($sorted);
        $root = null !== $ignoredEntryId ? $this->planVenueClosures->rootEntryIdOf($ignoredEntryId) : null;

        return $this->windowGuard->governingWindows($clubId, $seasonId, $root, $sorted[0], $sorted[\count($sorted) - 1]);
    }

    /**
     * @param array<string, true> $newClosedDates
     *
     * @return list<array{entryId: string, planId: string, title: string, startDate: string, endDate: string, chosenScheduleId: string|null, loadedScheduleId: string|null, locks: list<ScheduleSlotTemplate>, reservations: list<Reservation>}>
     */
    private function targets(string $clubId, string $seasonId, string $venueId, array $newClosedDates, ?string $ignoredEntryId): array
    {
        $targets = [];
        foreach ($this->governing($clubId, $seasonId, $newClosedDates, $ignoredEntryId) as $window) {
            $planId = $this->schedulePlanProvisioner->periodPlanId($window['entry_id']);
            if (null === $planId) {
                continue;
            }
            $start = mb_substr($window['start_date'], 0, 10);
            $end = mb_substr($window['end_date'], 0, 10);

            $weekdays = [];
            foreach (array_keys($newClosedDates) as $date) {
                if ($date >= $start && $date <= $end) {
                    $weekdays[(int) new DateTimeImmutable($date)->format('N')] = true;
                }
            }
            $weekdays = $this->withoutReopenedWeekdays($planId, $venueId, $weekdays);

            $loaded = $this->schedulePlanProvisioner->lockSourceScheduleIdOfPlan($planId);
            $locks = [];
            $reservations = [];
            if ([] !== $weekdays) {
                if (null !== $loaded) {
                    foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
                        'clubId' => $clubId,
                        'scheduleId' => $loaded,
                        'venueId' => $venueId,
                        'lockLevel' => LockLevel::HARD,
                    ], ['dayOfWeek' => 'ASC', 'startTime' => 'ASC']) as $slot) {
                        if (isset($weekdays[$slot->getDayOfWeek()])) {
                            $locks[] = $slot;
                        }
                    }
                }
                foreach ($this->entityManager->getRepository(Reservation::class)->findBy([
                    'clubId' => $clubId,
                    'schedulePlanId' => $planId,
                    'venueId' => $venueId,
                ], ['dayOfWeek' => 'ASC', 'startTime' => 'ASC']) as $reservation) {
                    if (isset($weekdays[$reservation->getDayOfWeek()])) {
                        $reservations[] = $reservation;
                    }
                }
            }

            $targets[] = [
                'entryId' => $window['entry_id'],
                'planId' => $planId,
                'title' => $window['entry_title'],
                'startDate' => $start,
                'endDate' => $end,
                'chosenScheduleId' => $this->schedulePlanProvisioner->chosenScheduleIdOfPlan($planId),
                'loadedScheduleId' => $loaded,
                'locks' => $locks,
                'reservations' => $reservations,
            ];
        }

        return $targets;
    }

    /**
     * Un gymnase DÉSACTIVÉ dans le plan garde tout (P3-20) ; un jour que le plan ROUVRE (masque OPEN,
     * mode BLANK) n'est pas fermé pour lui.
     *
     * @param array<int, true> $weekdays
     *
     * @return array<int, true>
     */
    private function withoutReopenedWeekdays(string $planId, string $venueId, array $weekdays): array
    {
        $override = $this->entityManager->getRepository(VenuePeriodOverride::class)->findOneBy(['schedulePlanId' => $planId, 'venueId' => $venueId]);
        if ($override instanceof VenuePeriodOverride && VenuePeriodMode::DISABLED === $override->getMode()) {
            return [];
        }
        foreach (array_keys($this->planVenueClosures->reopenedWeekdaysByPlans([$planId])[$planId][$venueId] ?? []) as $day) {
            unset($weekdays[$day]);
        }

        return $weekdays;
    }

    private function subjectName(Reservation $reservation): string
    {
        $teamId = $reservation->getTeamId();
        if (null === $teamId) {
            return $reservation->getLabel() ?? '';
        }
        $team = $this->entityManager->getRepository(Team::class)->find($teamId);

        return $team instanceof Team ? $team->getName() : '';
    }
}
