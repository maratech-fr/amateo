<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\ValidateConstraintsController;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTravelTime;
use App\Enum\TeamCoachRole;
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
 *  - personnes = coach MAIN et joueur (`CoachPlayerMembership` actif : « joue aussi dans ») ;
 *  - amendé le 2026-10-30 (validé fondateur) : conflit SEULEMENT si une séance n'a personne pour la
 *    tenir ({@see CoachDoubleBookingDetector::holdersFor}). Un adjoint ne tient pas et n'est pas
 *    attendu ; un second coach principal tient ; le rôle de joueur occupe. Deux équipes où la
 *    personne ne fait que jouer restent un conflit : aucun coach ne la remplace sur le terrain ;
 *  - planning scanné : la version POINTÉE du plan SEASON seulement (plans de période
 *    hors V1) ;
 *  - indisponibilités déclarées et trajets entre gymnases : hors V1.
 *
 * La RÈGLE PURE de collision n'est PAS réécrite : on réutilise
 * {@see CoachDoubleBookingDetector::bookingsCollide} (même personne, équipes
 * DIFFÉRENTES, gymnases DIFFÉRENTS, même jour, intervalles réels demi-ouverts). Son
 * comportement pré-solve (MAIN seul) est INCHANGÉ — l'élargissement aux joueurs se fait ICI, en
 * amont, dans la construction des « personnes présentes », jamais dans la règle partagée.
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
     * }>, relays: list<array{
     *     personId: string,
     *     personName: string,
     *     dayOfWeek: int,
     *     lateMinutes: int,
     *     adjointName: string,
     *     first: array{teamId: string, teamName: string, venueId: string, venueName: string, startTime: string},
     *     second: array{teamId: string, teamName: string, venueId: string, venueName: string, startTime: string},
     * }>}
     */
    public function detect(string $clubId, ?string $seasonId): array
    {
        $seasonScheduleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId);
        if (null === $seasonScheduleId || null === $seasonId) {
            return ['seasonPlanChosen' => false, 'conflicts' => [], 'relays' => []];
        }

        /** @var list<ScheduleSlotTemplate> $slots */
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $seasonScheduleId]);
        if (\count($slots) < 2) {
            return ['seasonPlanChosen' => true, 'conflicts' => [], 'relays' => []];
        }

        // teamId → coachs principaux et joueurs (memberships ACTIFS).
        // Deux cartes distinctes : le repli « coach de séance » n'utilise que la première
        // (slot.coachId ?? coachs de l'équipe), les joueurs s'ajoutent toujours. Club+saison
        // explicites (comme {@see CoachDoubleBookingDetector::mainCoachByTeam}) : la scope ne
        // dépend pas de l'état des filtres Doctrine, seulement de la frontière tenant.
        $scope = ['clubId' => $clubId, 'seasonId' => $seasonId];
        $sessionCoachesByTeam = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy($scope) as $link) {
            if (TeamCoachRole::MAIN === $link->getRole()) {
                $sessionCoachesByTeam[$link->getTeamId()][] = $link->getCoachId();
            }
        }
        $playersByTeam = [];
        foreach ($this->entityManager->getRepository(CoachPlayerMembership::class)->findBy($scope) as $membership) {
            if ($membership->getIsActive()) {
                $playersByTeam[$membership->getTeamId()][] = $membership->getCoachId();
            }
        }

        $pairs = $this->collidingPairs($slots, $sessionCoachesByTeam, $playersByTeam);
        if ([] === $pairs) {
            return ['seasonPlanChosen' => true, 'conflicts' => [], 'relays' => []];
        }

        // Chantier H — décor du relais (mêmes maisons partagées que le récap) : adjoints par équipe,
        // barème de trajet, statut véhiculé + nom des coachs, indispos déclarées, séances-coach.
        $assistantsByTeam = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy($scope) as $link) {
            if (TeamCoachRole::ASSISTANT === $link->getRole()) {
                $assistantsByTeam[$link->getTeamId()][] = $link->getCoachId();
            }
        }
        $matrix = $this->travelMatrix($scope);
        [$coachNames, $coachVehicled] = $this->coachIndex($scope);
        $blockedByCoach = CoachDoubleBookingDetector::blockedIntervalsByCoach(
            $this->entityManager->getRepository(Constraint::class)->findBy($scope),
        );
        $coachBookings = $this->coachBookings($slots, $sessionCoachesByTeam);
        // Les groupes déclarés du socle : seul le plan SEASON est scanné ici.
        $groups = [];
        foreach ($this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy($scope + ['schedulePlanId' => null]) as $member) {
            $groups[$member->getBlockId()][] = $member->getTeamId();
        }
        $unheld = $this->unheldSlots($slots, $sessionCoachesByTeam, $playersByTeam, $blockedByCoach, array_values($groups));

        $names = $this->displayNames($pairs, $slots);
        $conflicts = [];
        $relays = [];
        foreach ($pairs as $pair) {
            $onlyPlays = $this->onlyPlaysIn($pair['personId'], $pair['first'], $sessionCoachesByTeam, $playersByTeam)
                && $this->onlyPlaysIn($pair['personId'], $pair['second'], $sessionCoachesByTeam, $playersByTeam);
            if (!$onlyPlays && !isset($unheld[$pair['first']->getId()]) && !isset($unheld[$pair['second']->getId()])) {
                continue; // chaque séance a quelqu'un pour la tenir.
            }
            $relay = $this->relayFor($pair, $sessionCoachesByTeam, $assistantsByTeam, $matrix, $coachVehicled, $coachNames, $blockedByCoach, $coachBookings);
            if (null !== $relay) {
                $relays[] = [
                    'personId' => $pair['personId'],
                    'personName' => $names['coach'][$pair['personId']] ?? 'Cette personne',
                    'dayOfWeek' => $pair['dayOfWeek'],
                    'lateMinutes' => $relay['lateMinutes'],
                    'adjointName' => $relay['adjointName'],
                    'first' => $this->describe($pair['first'], $names),
                    'second' => $this->describe($pair['second'], $names),
                ];

                continue;
            }
            $conflicts[] = [
                'personId' => $pair['personId'],
                'personName' => $names['coach'][$pair['personId']] ?? 'Cette personne',
                'dayOfWeek' => $pair['dayOfWeek'],
                'first' => $this->describe($pair['first'], $names),
                'second' => $this->describe($pair['second'], $names),
            ];
        }

        return ['seasonPlanChosen' => true, 'conflicts' => $conflicts, 'relays' => $relays];
    }

    /**
     * Les séances placées qui n'ont personne pour les tenir. Le coach nommé par une séance en reste
     * candidat, derrière ceux de l'équipe : un planning déjà en base nomme le premier coach.
     *
     * @param list<ScheduleSlotTemplate>                         $slots
     * @param array<string, list<string>>                        $sessionCoachesByTeam
     * @param array<string, list<string>>                        $playersByTeam
     * @param array<string, list<array{0: int, 1: int, 2: int}>> $blockedByCoach
     * @param list<list<string>>                                 $groups
     *
     * @return array<string, true>
     */
    private function unheldSlots(array $slots, array $sessionCoachesByTeam, array $playersByTeam, array $blockedByCoach, array $groups): array
    {
        $coaches = $sessionCoachesByTeam;
        $playing = [];
        $sessions = [];
        foreach ($slots as $slot) {
            $booking = $this->bookingOf($slot);
            $sessions[] = ['id' => $slot->getId()] + $booking;
            $named = $slot->getCoachId();
            if (null !== $named && !\in_array($named, $coaches[$slot->getTeamId()] ?? [], true)) {
                $coaches[$slot->getTeamId()][] = $named;
            }
            foreach ($playersByTeam[$slot->getTeamId()] ?? [] as $playerId) {
                $playing[$playerId][] = $booking;
            }
        }

        return CoachDoubleBookingDetector::holdsByCluster($sessions, $coaches, $blockedByCoach, $playing, $groups)['unheld'];
    }

    /**
     * @param array<string, list<string>> $sessionCoachesByTeam
     * @param array<string, list<string>> $playersByTeam
     */
    private function onlyPlaysIn(string $personId, ScheduleSlotTemplate $slot, array $sessionCoachesByTeam, array $playersByTeam): bool
    {
        return \in_array($personId, $playersByTeam[$slot->getTeamId()] ?? [], true)
            && !\in_array($personId, $sessionCoachesByTeam[$slot->getTeamId()] ?? [], true)
            && $personId !== $slot->getCoachId();
    }

    /**
     * Chantier H — cette paire dédoublante est-elle un RELAIS tolérable ? Oui ssi la personne est un
     * coach de séance de l'équipe REJOINTE (la plus tardive — un joueur n'est jamais « ouvert » par
     * un adjoint), que cette séance tolère un retard couvrant le retard géométrique (fin de la 1ʳᵉ +
     * barème de trajet − début de la 2ᵉ), et qu'un adjoint DISPONIBLE l'ouvre (maison partagée
     * {@see CoachDoubleBookingDetector::adjointOpening}). Rend `{lateMinutes, adjointName}` ou null.
     *
     * @param array{personId: string, dayOfWeek: int, first: ScheduleSlotTemplate, second: ScheduleSlotTemplate}   $pair
     * @param array<string, list<string>>                                                                          $sessionCoachesByTeam
     * @param array<string, list<string>>                                                                          $assistantsByTeam
     * @param array<string, array{driving: ?int, walking: ?int}>                                                   $matrix
     * @param array<string, bool>                                                                                  $coachVehicled
     * @param array<string, string>                                                                                $coachNames
     * @param array<string, list<array{0: int, 1: int, 2: int}>>                                                   $blockedByCoach
     * @param array<string, list<array{venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>> $coachBookings
     *
     * @return array{lateMinutes: int, adjointName: string}|null
     */
    private function relayFor(array $pair, array $sessionCoachesByTeam, array $assistantsByTeam, array $matrix, array $coachVehicled, array $coachNames, array $blockedByCoach, array $coachBookings): ?array
    {
        $personId = $pair['personId'];
        $early = $pair['first'];
        $late = $pair['second'];
        // Côté JOUEUR jamais : la personne doit être un coach de séance de l'équipe rejointe.
        $lateCoach = $late->getCoachId();
        $lateCoaches = null !== $lateCoach ? [$lateCoach] : ($sessionCoachesByTeam[$late->getTeamId()] ?? []);
        if (!\in_array($personId, $lateCoaches, true)) {
            return null;
        }

        $bareme = CoachDoubleBookingDetector::travelBaremeMinutes($matrix, $early->getVenueId(), $late->getVenueId(), $coachVehicled[$personId] ?? false);
        $earlyBooking = ['startMinutes' => $this->startMinutes($early), 'durationMinutes' => $early->getDurationMinutes()];
        $lateBooking = ['startMinutes' => $this->startMinutes($late), 'durationMinutes' => $late->getDurationMinutes()];
        if (!CoachDoubleBookingDetector::lateArrivalTolerated($earlyBooking, $lateBooking, $late->getCoachLateToleranceMinutes(), $bareme)) {
            return null;
        }

        $lateMinutes = max(0, $this->startMinutes($early) + $early->getDurationMinutes() + $bareme - $this->startMinutes($late));
        $assistants = array_values(array_filter($assistantsByTeam[$late->getTeamId()] ?? [], static fn (string $id): bool => $id !== $personId));
        $adjointId = CoachDoubleBookingDetector::adjointOpening($assistants, $blockedByCoach, $coachBookings, $late->getDayOfWeek(), $this->startMinutes($late), $this->startMinutes($late) + $lateMinutes, $late->getVenueId());
        if (null === $adjointId) {
            return null;
        }

        return ['lateMinutes' => $lateMinutes, 'adjointName' => $coachNames[$adjointId] ?? 'Un adjoint'];
    }

    /**
     * @param array{clubId: string, seasonId: string} $scope
     *
     * @return array<string, array{driving: ?int, walking: ?int}>
     */
    private function travelMatrix(array $scope): array
    {
        $matrix = [];
        foreach ($this->entityManager->getRepository(VenueTravelTime::class)->findBy($scope) as $row) {
            [$a, $b] = $row->getVenueAId() < $row->getVenueBId() ? [$row->getVenueAId(), $row->getVenueBId()] : [$row->getVenueBId(), $row->getVenueAId()];
            $matrix[$a . '|' . $b] = ['driving' => $row->getDrivingMinutes(), 'walking' => $row->getWalkingMinutes()];
        }

        return $matrix;
    }

    /**
     * @param array{clubId: string, seasonId: string} $scope
     *
     * @return array{0: array<string, string>, 1: array<string, bool>}
     */
    private function coachIndex(array $scope): array
    {
        $names = [];
        $vehicled = [];
        foreach ($this->entityManager->getRepository(Coach::class)->findBy($scope) as $coach) {
            $names[$coach->getId()] = trim($coach->getFirstName() . ' ' . $coach->getLastName());
            $vehicled[$coach->getId()] = $coach->isVehicled();
        }

        return [$names, $vehicled];
    }

    /**
     * Les séances placées où chaque coach de séance est présent — pour vérifier qu'un adjoint n'est
     * pas occupé ailleurs (chantier H). Coach de séance = `slot.coachId` posé, sinon les coachs de
     * l'équipe (même repli que la présence).
     *
     * @param list<ScheduleSlotTemplate>  $slots
     * @param array<string, list<string>> $sessionCoachesByTeam
     *
     * @return array<string, list<array{venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>>
     */
    private function coachBookings(array $slots, array $sessionCoachesByTeam): array
    {
        $byCoach = [];
        foreach ($slots as $slot) {
            $coachId = $slot->getCoachId();
            $coaches = null !== $coachId ? [$coachId] : ($sessionCoachesByTeam[$slot->getTeamId()] ?? []);
            $booking = [
                'venueId' => $slot->getVenueId(),
                'dayOfWeek' => $slot->getDayOfWeek(),
                'startMinutes' => $this->startMinutes($slot),
                'durationMinutes' => $slot->getDurationMinutes(),
            ];
            foreach ($coaches as $coach) {
                $byCoach[$coach][] = $booking;
            }
        }

        return $byCoach;
    }

    /**
     * Le cœur PUR : croise chaque séance placée avec les personnes qui y sont présentes,
     * puis applique la règle partagée {@see CoachDoubleBookingDetector::bookingsCollide}
     * paire à paire, par personne. Rend les IDENTIFIANTS (les noms sont résolus en aval).
     *
     * Personne présente à une séance de l'équipe T :
     *  - coach de séance : `slot.coachId` s'il est posé, sinon les coachs principaux de l'équipe ;
     *  - joueur : toute personne d'un `CoachPlayerMembership` ACTIF de T (elle joue là).
     * Une personne présente pour deux raisons (coach ET joueur du même créneau) ne compte
     * qu'UNE fois par créneau (dédup par slotId), sinon la paire se détecterait en double.
     *
     * @param list<ScheduleSlotTemplate>  $slots
     * @param array<string, list<string>> $sessionCoachesByTeam teamId => coachIds (MAIN)
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
            'startTime' => $slot->getStartTime()->format('H:i'),
        ];
    }
}
