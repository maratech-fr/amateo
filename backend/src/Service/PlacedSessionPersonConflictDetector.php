<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\ValidateConstraintsController;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P4-269 — « cette personne est-elle à deux endroits en même temps dans le planning
 * d'entraînement EN VIGUEUR ? ».
 *
 * Quand le gestionnaire complète son modèle sans régénérer (P4-268 : ajouter/lier un
 * coach ne rend plus un planning « à régénérer »), un simple lien peut mettre une
 * personne à deux séances DÉJÀ placées qui se chevauchent — sans que rien ne le
 * signale : {@see CoachDoubleBookingDetector} n'est consommé que PRÉ-solve
 * ({@see PreSolvePreventionWarnings}, {@see ValidateConstraintsController}).
 * Ce service ferme l'angle mort APRÈS génération, en LECTURE seule (feed recalculé,
 * rien de persisté — patron {@see ConflictRadarLoader}).
 *
 * Périmètre V1 (fondateur 2026-09-28) :
 *  - chevauchement d'intervalles réels de séances PLACÉES dans des gymnases DIFFÉRENTS
 *    (même gymnase = mutualisation voulue, jamais un conflit) ;
 *  - personnes = coach MAIN, ASSISTANT, et joueur (`CoachPlayerMembership` actif :
 *    « joue aussi dans ») — élargissement ASSUMÉ vs la règle pré-solve (MAIN seul) ;
 *  - planning scanné : la version POINTÉE du plan SEASON seulement (plans de période
 *    hors V1) ;
 *  - indisponibilités déclarées et trajets entre gymnases : hors V1.
 *
 * La RÈGLE PURE de collision n'est PAS réécrite : on réutilise
 * {@see CoachDoubleBookingDetector::bookingsCollide} (même personne, équipes
 * DIFFÉRENTES, gymnases DIFFÉRENTS, même jour, intervalles réels demi-ouverts). Son
 * comportement pré-solve (MAIN seul) est INCHANGÉ — l'élargissement aux ASSISTANT et
 * aux joueurs se fait ICI, en amont, dans la construction des « personnes présentes »,
 * jamais dans la règle partagée.
 *
 * Tenant : tout est chargé via les repositories mappés — les filtres Doctrine
 * club+saison s'appliquent (+ RLS) ; un club ne voit jamais que ses propres conflits.
 */
final class PlacedSessionPersonConflictDetector
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Les conflits COURANTS du club+saison sur le planning d'entraînement en vigueur.
     * `seasonPlanChosen` = false quand aucune version du plan SEASON n'est pointée
     * (espace de travail) : il n'y a alors PAS de planning en vigueur à scanner, et
     * `conflicts: []` ne veut pas dire « tout va bien » — le consommateur en tient compte.
     *
     * @return array{seasonPlanChosen: bool, conflicts: list<array{
     *     personId: string,
     *     personName: string,
     *     dayOfWeek: int,
     *     first: array{teamId: string, teamName: string, venueId: string, venueName: string, startTime: string},
     *     second: array{teamId: string, teamName: string, venueId: string, venueName: string, startTime: string},
     * }>}
     */
    public function detect(string $clubId, ?string $seasonId): array
    {
        $seasonScheduleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId);
        if (null === $seasonScheduleId || null === $seasonId) {
            return ['seasonPlanChosen' => false, 'conflicts' => []];
        }

        /** @var list<ScheduleSlotTemplate> $slots */
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $seasonScheduleId]);
        if (\count($slots) < 2) {
            return ['seasonPlanChosen' => true, 'conflicts' => []];
        }

        // teamId → coachs de séance (MAIN + ASSISTANT) et joueurs (memberships ACTIFS).
        // Deux cartes distinctes : le repli « coach de séance » n'utilise que la première
        // (slot.coachId ?? coachs de l'équipe), les joueurs s'ajoutent toujours. Club+saison
        // explicites (comme {@see CoachDoubleBookingDetector::mainCoachByTeam}) : la scope ne
        // dépend pas de l'état des filtres Doctrine, seulement de la frontière tenant.
        $scope = ['clubId' => $clubId, 'seasonId' => $seasonId];
        $sessionCoachesByTeam = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy($scope) as $link) {
            $sessionCoachesByTeam[$link->getTeamId()][] = $link->getCoachId();
        }
        $playersByTeam = [];
        foreach ($this->entityManager->getRepository(CoachPlayerMembership::class)->findBy($scope) as $membership) {
            if ($membership->getIsActive()) {
                $playersByTeam[$membership->getTeamId()][] = $membership->getCoachId();
            }
        }

        $pairs = $this->collidingPairs($slots, $sessionCoachesByTeam, $playersByTeam);
        if ([] === $pairs) {
            return ['seasonPlanChosen' => true, 'conflicts' => []];
        }

        $names = $this->displayNames($pairs, $slots);
        $conflicts = [];
        foreach ($pairs as $pair) {
            $conflicts[] = [
                'personId' => $pair['personId'],
                'personName' => $names['coach'][$pair['personId']] ?? 'Cette personne',
                'dayOfWeek' => $pair['dayOfWeek'],
                'first' => $this->describe($pair['first'], $names),
                'second' => $this->describe($pair['second'], $names),
            ];
        }

        return ['seasonPlanChosen' => true, 'conflicts' => $conflicts];
    }

    /**
     * Le cœur PUR : croise chaque séance placée avec les personnes qui y sont présentes,
     * puis applique la règle partagée {@see CoachDoubleBookingDetector::bookingsCollide}
     * paire à paire, par personne. Rend les IDENTIFIANTS (les noms sont résolus en aval).
     *
     * Personne présente à une séance de l'équipe T :
     *  - coach de séance : `slot.coachId` s'il est posé, sinon les coachs de l'équipe
     *    (MAIN + ASSISTANT) — même repli que le planning ;
     *  - joueur : toute personne d'un `CoachPlayerMembership` ACTIF de T (elle joue là).
     * Une personne présente pour deux raisons (coach ET joueur du même créneau) ne compte
     * qu'UNE fois par créneau (dédup par slotId), sinon la paire se détecterait en double.
     *
     * @param list<ScheduleSlotTemplate>  $slots
     * @param array<string, list<string>> $sessionCoachesByTeam teamId => coachIds (MAIN + ASSISTANT)
     * @param array<string, list<string>> $playersByTeam        teamId => coachIds (joueurs actifs)
     *
     * @return list<array{personId: string, dayOfWeek: int, first: ScheduleSlotTemplate, second: ScheduleSlotTemplate}>
     */
    private function collidingPairs(array $slots, array $sessionCoachesByTeam, array $playersByTeam): array
    {
        // personId → [slotId => booking] : une entrée par (personne, créneau) où elle est présente.
        /** @var array<string, array<string, array{slot: ScheduleSlotTemplate, booking: array{coachId: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}}>> $byPerson */
        $byPerson = [];
        foreach ($slots as $slot) {
            $teamId = $slot->getTeamId();
            $coachId = $slot->getCoachId();
            $sessionCoaches = null !== $coachId ? [$coachId] : ($sessionCoachesByTeam[$teamId] ?? []);
            $persons = array_values(array_unique(array_merge($sessionCoaches, $playersByTeam[$teamId] ?? [])));
            $booking = $this->bookingOf($slot);
            foreach ($persons as $personId) {
                $byPerson[$personId][$slot->getId()] ??= ['slot' => $slot, 'booking' => ['coachId' => $personId] + $booking];
            }
        }

        $pairs = [];
        foreach ($byPerson as $personId => $entriesById) {
            $entries = array_values($entriesById);
            $count = \count($entries);
            for ($i = 0; $i < $count; ++$i) {
                for ($j = $i + 1; $j < $count; ++$j) {
                    if (!CoachDoubleBookingDetector::bookingsCollide($entries[$i]['booking'], $entries[$j]['booking'])) {
                        continue;
                    }
                    // « first » = la séance qui commence le plus tôt (départage stable par id) —
                    // pour un rendu déterministe, indépendant de l'ordre de chargement.
                    [$first, $second] = $this->orderByStart($entries[$i]['slot'], $entries[$j]['slot']);
                    $pairs[] = [
                        'personId' => $personId,
                        'dayOfWeek' => $first->getDayOfWeek(),
                        'first' => $first,
                        'second' => $second,
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * @return array{0: ScheduleSlotTemplate, 1: ScheduleSlotTemplate}
     */
    private function orderByStart(ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): array
    {
        $sa = $this->startMinutes($a);
        $sb = $this->startMinutes($b);
        if ($sa === $sb) {
            return $a->getId() <= $b->getId() ? [$a, $b] : [$b, $a];
        }

        return $sa < $sb ? [$a, $b] : [$b, $a];
    }

    /**
     * @return array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}
     */
    private function bookingOf(ScheduleSlotTemplate $slot): array
    {
        return [
            'teamId' => $slot->getTeamId(),
            'venueId' => $slot->getVenueId(),
            'dayOfWeek' => $slot->getDayOfWeek(),
            'startMinutes' => $this->startMinutes($slot),
            'durationMinutes' => $slot->getDurationMinutes(),
        ];
    }

    private function startMinutes(ScheduleSlotTemplate $slot): int
    {
        return (int) $slot->getStartTime()->format('H') * 60 + (int) $slot->getStartTime()->format('i');
    }

    /**
     * @param list<array{personId: string, dayOfWeek: int, first: ScheduleSlotTemplate, second: ScheduleSlotTemplate}> $pairs
     * @param list<ScheduleSlotTemplate>                                                                               $slots
     *
     * @return array{coach: array<string, string>, team: array<string, string>, venue: array<string, string>}
     */
    private function displayNames(array $pairs, array $slots): array
    {
        $personIds = [];
        $teamIds = [];
        $venueIds = [];
        foreach ($pairs as $pair) {
            $personIds[] = $pair['personId'];
            foreach ([$pair['first'], $pair['second']] as $slot) {
                $teamIds[] = $slot->getTeamId();
                $venueIds[] = $slot->getVenueId();
            }
        }
        $personIds = array_values(array_unique($personIds));
        $teamIds = array_values(array_unique($teamIds));
        $venueIds = array_values(array_unique($venueIds));
        unset($slots);

        $coach = [];
        foreach ([] === $personIds ? [] : $this->entityManager->getRepository(Coach::class)->findBy(['id' => $personIds]) as $entity) {
            $coach[$entity->getId()] = trim($entity->getFirstName() . ' ' . $entity->getLastName());
        }
        $team = [];
        foreach ([] === $teamIds ? [] : $this->entityManager->getRepository(Team::class)->findBy(['id' => $teamIds]) as $entity) {
            $team[$entity->getId()] = $entity->getName();
        }
        $venue = [];
        foreach ([] === $venueIds ? [] : $this->entityManager->getRepository(Venue::class)->findBy(['id' => $venueIds]) as $entity) {
            $venue[$entity->getId()] = $entity->getName();
        }

        return ['coach' => $coach, 'team' => $team, 'venue' => $venue];
    }

    /**
     * @param array{coach: array<string, string>, team: array<string, string>, venue: array<string, string>} $names
     *
     * @return array{teamId: string, teamName: string, venueId: string, venueName: string, startTime: string}
     */
    private function describe(ScheduleSlotTemplate $slot, array $names): array
    {
        return [
            'teamId' => $slot->getTeamId(),
            'teamName' => $names['team'][$slot->getTeamId()] ?? 'une équipe',
            'venueId' => $slot->getVenueId(),
            'venueName' => $names['venue'][$slot->getVenueId()] ?? 'un gymnase',
            'startTime' => $slot->getStartTime()->format('H\hi'),
        ];
    }
}
