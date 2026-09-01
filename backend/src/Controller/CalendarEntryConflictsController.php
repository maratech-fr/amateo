<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Service\PlanVenueClosures;
use App\Service\SchedulePlanProvisioner;
use App\Service\VenueClosureDays;
use DateInterval;
use DatePeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Palier A: sessions the base plan wants to place in a venue that a period marks
 * as closed — "séances à replacer" for the cockpit calendar/radar. Nothing is
 * moved (overlay generation is palier B); this only surfaces the conflict.
 *
 * For a period entry, the closed venues are read from its dated FACILITY
 * constraints (Constraint.calendarEntryId), then the baseline schedule's slot
 * templates on those venues whose weekday falls inside the period window are
 * returned with the concrete conflicting dates.
 * See accueil-cockpit-temporel.md §6 (état intermédiaire, palier A).
 */
final class CalendarEntryConflictsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PlanVenueClosures $planVenueClosures,
    ) {}

    #[Route('/api/calendar-entries/{id}/conflicts', name: 'api_calendar_entry_conflicts', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $currentClubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($id);
        if (!$entry instanceof CalendarEntry) {
            return $this->json(['error' => 'Calendar entry not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($entry->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // Le pointeur est lu UNE fois, avant toute sortie : `seasonPlanChosen` ne doit
        // jamais être affirmé sans l'avoir consulté. Codé en dur à `true` sur les
        // sorties courtes, il affirmait un fait non vérifié — et le radar, qui masque
        // désormais les fermetures sans impact, faisait disparaître une période sur ce
        // mensonge.
        $seasonScheduleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($entry->getSeasonId());
        $planChosen = null !== $seasonScheduleId;

        // An IGNORED entry was explicitly dismissed by the manager: it must not
        // keep raising conflicts (the radar would resurrect it as a to-do).
        if (CalendarEntryKind::PERIOD !== $entry->getKind() || CalendarEntryStatus::IGNORED === $entry->getStatus()) {
            return $this->json(['entryId' => $entry->getId(), 'venueIds' => [], 'conflicts' => [], 'closures' => [], 'fullyClosedVenueIds' => [], 'effectiveClosedWeekdays' => [], 'disabledVenueIds' => [], 'seasonPlanChosen' => $planChosen]);
        }

        // Indispo INFORMATIVE (décision fondateur 2026-08-18) — l'ÉTAT EFFECTIF jour par jour
        // (incident × masque manuel du plan), AVEC provenance, servi tel quel : le front ne
        // redérive JAMAIS la composition (elle vit dans la MAISON UNIQUE `PlanVenueClosures`).
        // Une entrée sans plan de période n'a pas de masque — l'état effectif y est vide.
        $effectiveClosedWeekdays = [];
        $disabledVenueIds = [];
        $periodPlanId = $this->schedulePlanProvisioner->periodPlanId($entry->getId());
        if (null !== $periodPlanId) {
            $state = $this->planVenueClosures->effectiveStateForPlan($periodPlanId);
            $disabledVenueIds = array_keys($state['disabledVenueIds']);
            foreach ($state['effectiveClosedWeekdaysByVenue'] as $venueId => $weekdays) {
                foreach (array_keys($weekdays) as $weekday) {
                    // Provenance : décoché À LA MAIN (masque CLOSED) ou hérité de l'indisponibilité déclarée.
                    $effectiveClosedWeekdays[$venueId][(string) $weekday] = isset($state['manualClosedWeekdaysByVenue'][$venueId][$weekday]) ? 'manual' : 'default-incident';
                }
            }
        }

        // Les gymnases fermés dans cette fenêtre + leurs dates + leurs résumés.
        //
        // P2-38 — une entrée qui PORTE un plan voit les fermetures TRANSVERSALEMENT (toutes
        // entrées confondues, chacune bornée à sa propre entrée porteuse ∩ la fenêtre), comme
        // le payload et OrphanPinGuard : sinon les jours barrés à l'écran ignoreraient la
        // fermeture déclarée sur une autre entrée qui recoupe la période. Une entrée SANS plan
        // (jamais adaptée) garde le périmètre par-entrée historique, désormais l'UNION du modèle
        // FAIT/GENÈSE (P2-59 : une entrée-enfant lit SES genèses ∪ les faits de SA mère —
        // CalendarEntry::datedConstraintSourceIds).
        //
        // Ces trois sorties (`venueIds`, `closures`, `fullyClosedVenueIds`) sont servies sur
        // TOUTES les sorties, y compris sans plan choisi — une fermeture est un fait déclaré,
        // indépendant de l'existence d'un calendrier à comparer. Seuls les `conflicts` (séances à
        // replacer) dépendent du plan choisi.
        if ($this->schedulePlanProvisioner->periodPlanExists($entry->getId())) {
            $bundle = $this->planVenueClosures->forEntry($entry);
            $closedDatesByVenue = $bundle['closedDatesByVenue'];
            $closures = $bundle['summaries'];
            $fullyClosedVenueIds = array_keys($bundle['fullyClosedVenueIds']);
        } else {
            /** @var list<Constraint> $facilityConstraints */
            $facilityConstraints = $this->entityManager->getRepository(Constraint::class)->findBy([
                'calendarEntryId' => $entry->datedConstraintSourceIds(),
                'family' => ConstraintFamily::FACILITY,
                'isActive' => true,
            ]);
            $closedDatesByVenue = VenueClosureDays::closedDatesByVenue($facilityConstraints, $entry->getStartDate(), $entry->getEndDate());
            $closures = VenueClosureDays::closureSummaries($facilityConstraints, $entry->getStartDate(), $entry->getEndDate());
            $fullyClosedVenueIds = VenueClosureDays::fullyClosedVenueIds($facilityConstraints, $entry->getStartDate(), $entry->getEndDate());
        }
        // `venueIds` = les gymnases réellement fermés DANS cette fenêtre — un gymnase dont
        // l'incident tombe hors de la période n'est pas listé (sinon le chip « gymnase fermé »
        // mentirait).
        $venueIds = array_keys($closedDatesByVenue);

        if ([] === $venueIds) {
            return $this->json(['entryId' => $entry->getId(), 'venueIds' => [], 'conflicts' => [], 'closures' => $closures, 'fullyClosedVenueIds' => $fullyClosedVenueIds, 'effectiveClosedWeekdays' => $effectiveClosedWeekdays, 'disabledVenueIds' => $disabledVenueIds, 'seasonPlanChosen' => $planChosen]);
        }

        // The entry's OWN season baseline (not the active season) — an entry may
        // belong to a past/other season; scoring it against a different season's
        // plan would be wrong.
        // ADR-0002 : le calendrier de base = la version CHOISIE du plan SEASON (lue plus
        // haut). Rien ne la pose automatiquement — tant que le gestionnaire n'a pas
        // validé, la saison n'a PAS de calendrier et le radar n'a rien à comparer.
        //
        // `seasonPlanChosen: false` dit exactement ça. Rendre `conflicts: []` sans le
        // dire se lisait « aucun impact » : le gestionnaire déclarait une fermeture de
        // gymnase, lisait que tout allait bien, et n'adaptait rien — alors que le radar
        // n'avait simplement rien regardé. Un silence qui ment est pire qu'un blanc.
        if (null === $seasonScheduleId) {
            return $this->json(['entryId' => $entry->getId(), 'venueIds' => $venueIds, 'conflicts' => [], 'closures' => $closures, 'fullyClosedVenueIds' => $fullyClosedVenueIds, 'effectiveClosedWeekdays' => $effectiveClosedWeekdays, 'disabledVenueIds' => $disabledVenueIds, 'seasonPlanChosen' => false]);
        }

        /** @var list<ScheduleSlotTemplate> $slots */
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $seasonScheduleId,
            'venueId' => $venueIds,
        ]);

        // Concrete dates of the window, indexed by ISO weekday (1=Mon..7=Sun) —
        // ScheduleSlotTemplate.dayOfWeek uses the same ISO convention.
        // P2-5 5b : une séance récurrente ne compte QUE ses occurrences fermées
        // ($closedDatesByVenue, calculé plus haut) : gym fermé le seul jeudi 05-07, la
        // séance du jeudi ne liste que 05-07 (pas 05-14/05-21 des semaines ouvertes) —
        // plus de conflit fantôme.
        $datesByWeekday = $this->windowDatesByWeekday($entry);

        $conflicts = [];
        foreach ($slots as $slot) {
            $closedDates = $closedDatesByVenue[$slot->getVenueId()] ?? [];
            // Occurrences du créneau (jour ISO → dates de la fenêtre) ∩ dates fermées.
            $dates = array_values(array_filter(
                $datesByWeekday[$slot->getDayOfWeek()] ?? [],
                static fn (string $date): bool => isset($closedDates[$date]),
            ));
            if ([] === $dates) {
                continue; // le gymnase est ouvert toutes les occurrences de ce créneau
            }
            $conflicts[] = [
                'slotTemplateId' => $slot->getId(),
                'teamId' => $slot->getTeamId(),
                'venueId' => $slot->getVenueId(),
                'dayOfWeek' => $slot->getDayOfWeek(),
                'startTime' => $slot->getStartTime()->format('H:i'),
                'endTime' => $slot->getStartTime()->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'))->format('H:i'),
                'dates' => $dates,
            ];
        }

        return $this->json([
            'entryId' => $entry->getId(),
            'venueIds' => $venueIds,
            'conflicts' => $conflicts,
            'closures' => $closures,
            'fullyClosedVenueIds' => $fullyClosedVenueIds,
            'effectiveClosedWeekdays' => $effectiveClosedWeekdays,
            'disabledVenueIds' => $disabledVenueIds,
            'seasonPlanChosen' => $planChosen,
        ]);
    }

    /**
     * @return array<int, list<string>> ISO weekday (1..7) → list of Y-m-d dates in the window
     */
    private function windowDatesByWeekday(CalendarEntry $entry): array
    {
        $end = $entry->getEndDate()->modify('+1 day'); // DatePeriod end is exclusive
        $byWeekday = [];
        foreach (new DatePeriod($entry->getStartDate(), new DateInterval('P1D'), $end) as $day) {
            $byWeekday[(int) $day->format('N')][] = $day->format('Y-m-d');
        }

        return $byWeekday;
    }
}
