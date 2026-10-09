<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Enum\ConstraintFamily;
use App\Enum\TeamCoachRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-9 PR B — « ce verrou met-il un coach à deux endroits en même temps ? ».
 *
 * Un verrou HARD (une {@see Reservation}) est pré-placé HORS du solveur : sa variable
 * n'est jamais créée, donc aucune contrainte ne peut l'atteindre (ALIGN-07 — le verrou
 * est SOUVERAIN, décision fondateur). Le solveur ne peut donc pas refuser deux verrous
 * qui dédoublent un coach : il ne les voit même pas. Cette impossibilité PHYSIQUE
 * n'est pas une préférence bafouée — le gestionnaire n'a jamais choisi que son coach se
 * dédouble — d'où la décision fondateur : **empêcher la génération**.
 *
 * SOURCE UNIQUE de la règle. Le récap la consomme pour BLOQUER (PR B) et la modale de
 * réservation la consommera pour PRÉVENIR au clic (PR C). Une règle recopiée à la main
 * diverge : c'est ce motif exact qui a coûté trois rounds de revue au volet capacité.
 * D'où la signature ci-dessous, qui prend une LISTE DE RÉSERVATIONS et non un périmètre
 * à résoudre : la modale pourra lui passer les réservations existantes PLUS celle qu'on
 * est en train de poser, sans rien réécrire.
 *
 * Les trois règles (tranchées par le fondateur, 2026-07-31) :
 *  1. **Intervalles réels** `[début, début + durée[` — pas l'égalité des heures de début :
 *     17h00-18h30 et 17h30-19h00 dédoublent bien le coach.
 *  2. **Gymnases DIFFÉRENTS uniquement.** Deux équipes sur le même créneau du même
 *     gymnase divisible, c'est la MUTUALISATION voulue (`Venue.canSplit` + capacité) —
 *     bloquer dessus casserait une fonctionnalité.
 *  3. **Coachs `MAIN` uniquement.** Le solveur ne traite qu'eux en ressource exclusive
 *     (`add_coach_at_most_one`) ; un `ASSISTANT` est optionnel, bloquer dessus
 *     refuserait des réservations légitimes.
 *
 * Rend des données STRUCTURÉES, jamais des phrases : le récap et la modale formulent
 * différemment (« Maxime se trouve à deux créneaux en même temps » vs « Emerick coache
 * déjà les U15F à 17h à Debarros »). Une seule règle, deux rendus.
 */
final class CoachDoubleBookingDetector
{
    /** ISO-8601 : 1 = lundi. */
    private const DAY_NAMES = [
        1 => 'lundi',
        2 => 'mardi',
        3 => 'mercredi',
        4 => 'jeudi',
        5 => 'vendredi',
        6 => 'samedi',
        7 => 'dimanche',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * LA règle pure de dédoublement de coach — le seul foyer, extrait pour être testable
     * en parité MÉCANIQUE avec le front (`wizard/lib/coachDoubleBooking.ts::bookingsCollide`,
     * cas partagés `coachDoubleBooking.parity.json`, gardé par `CoachDoubleBookingMirrorParityTest`).
     * Les trois règles (fondateur 2026-07-31) :
     *  3. même coach MAIN des deux côtés, équipes DIFFÉRENTES (la même équipe deux fois est
     *     une double-réservation d'équipe, autre sujet) ;
     *  2. gymnases DIFFÉRENTS (même gymnase = mutualisation `canSplit` voulue) ;
     *  1. intervalles réels, demi-ouverts `[début, début+durée[` — deux créneaux qui se
     *     touchent ne se chevauchent PAS.
     *
     * @param array{coachId: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int} $a
     * @param array{coachId: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int} $b
     */
    public static function bookingsCollide(array $a, array $b): bool
    {
        return $a['coachId'] === $b['coachId']
            && $a['teamId'] !== $b['teamId']
            && $a['venueId'] !== $b['venueId']
            && $a['dayOfWeek'] === $b['dayOfWeek']
            && $a['startMinutes'] < $b['startMinutes'] + $b['durationMinutes']
            && $b['startMinutes'] < $a['startMinutes'] + $a['durationMinutes'];
    }

    /**
     * @param list<Reservation> $reservations le périmètre EXACT qui partira au solveur
     *                                        (socle : `schedulePlanId IS NULL` ; période :
     *                                        son plan, gymnases désactivés déjà retirés)
     *
     * @return list<array{coachId: string, coachName: string, dayOfWeek: int, first: array{teamName: string, venueName: string, startTime: string}, second: array{teamName: string, venueName: string, startTime: string}}>
     */
    public function detect(array $reservations, string $clubId, string $seasonId): array
    {
        if (\count($reservations) < 2) {
            return []; // il faut deux verrous pour se dédoubler
        }

        $mainCoachByTeam = $this->mainCoachByTeam($clubId, $seasonId);
        // Une équipe sans coach MAIN ne peut pas dédoubler qui que ce soit : on la sort
        // AVANT le balayage plutôt que de la tester à chaque paire.
        $relevant = array_values(array_filter(
            $reservations,
            static fn (Reservation $r): bool => isset($mainCoachByTeam[$r->getTeamId()]),
        ));

        $names = $this->displayNames($relevant, $mainCoachByTeam);
        $conflicts = [];

        foreach ($relevant as $i => $first) {
            foreach (\array_slice($relevant, $i + 1) as $second) {
                if (!$this->collide($first, $second, $mainCoachByTeam)) {
                    continue;
                }

                $coachId = $mainCoachByTeam[$first->getTeamId()];
                $conflicts[] = [
                    'coachId' => $coachId,
                    'coachName' => $names['coach'][$coachId] ?? 'Ce coach',
                    'dayOfWeek' => $first->getDayOfWeek(),
                    'first' => $this->describe($first, $names),
                    'second' => $this->describe($second, $names),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Le message du récap, au gabarit donné par le fondateur. Il doit dire QUELLE
     * réservation retirer : une erreur bloquante sans recours actionnable enferme le
     * gestionnaire.
     *
     * @param array{coachId: string, coachName: string, dayOfWeek: int, first: array{teamName: string, venueName: string, startTime: string}, second: array{teamName: string, venueName: string, startTime: string}} $conflict
     */
    public function describeForRecap(array $conflict): string
    {
        return \sprintf(
            '%s se trouve à deux créneaux en même temps : %s à %s le %s à %s, et %s à %s à %s. Veuillez corriger vos réservations.',
            $conflict['coachName'],
            $conflict['first']['teamName'],
            $conflict['first']['venueName'],
            self::DAY_NAMES[$conflict['dayOfWeek']] ?? 'ce jour',
            $conflict['first']['startTime'],
            $conflict['second']['teamName'],
            $conflict['second']['venueName'],
            $conflict['second']['startTime'],
        );
    }

    /**
     * PR A (2026-08-06) — « le coach est indisponible et on a un créneau en DUR » :
     * remonté APRÈS génération en INFO (`diagnose_locked_slot_violations`) alors qu'on
     * peut s'en prémunir AVANT (décision fondateur). Même doctrine que `detect()` :
     * coach MAIN seul, le verrou est souverain donc c'est un AVERTISSEMENT (la
     * génération passera, la réservation écrasera l'indisponibilité), pas un bloqueur.
     *
     * Les intervalles bloqués sont le MIROIR du parse moteur (`constraints.py`,
     * branche COACH_AVAILABILITY) : `unavailableDays` ajoute (jour, from, until) ;
     * un `availableDays` NON VIDE bloque le complément (autres jours entiers + les
     * bords hors fenêtre des jours listés) ; vide/absent = aucune restriction. La
     * clé coach est `scopeTargetId` — comme le moteur, qui ignore `config.coachId`.
     * Le `ruleType` n'est pas filtré : le moteur applique TOUJOURS une dispo coach
     * en dur (une personne ne se dédouble pas).
     *
     * @param list<Reservation> $reservations le périmètre EXACT qui partira au solveur
     * @param list<Constraint>  $constraints  le jeu que le payload sérialisera (même source que le gate)
     *
     * @return list<array{coachId: string, coachName: string, dayOfWeek: int, teamName: string, venueName: string, startTime: string}>
     */
    public function detectUnavailabilityClashes(array $reservations, array $constraints, string $clubId, string $seasonId): array
    {
        if ([] === $reservations) {
            return [];
        }

        $blocked = $this->blockedIntervalsByCoach($constraints);
        if ([] === $blocked) {
            return [];
        }

        $mainCoachByTeam = $this->mainCoachByTeam($clubId, $seasonId);
        $relevant = array_values(array_filter(
            $reservations,
            static fn (Reservation $r): bool => isset($blocked[$mainCoachByTeam[$r->getTeamId()] ?? '']),
        ));
        if ([] === $relevant) {
            return [];
        }

        $names = $this->displayNames($relevant, $mainCoachByTeam);
        $clashes = [];
        foreach ($relevant as $reservation) {
            $coachId = $mainCoachByTeam[$reservation->getTeamId()];
            $start = (int) $reservation->getStartTime()->format('H') * 60 + (int) $reservation->getStartTime()->format('i');
            $end = $start + $reservation->getDurationMinutes();
            foreach ($blocked[$coachId] as [$day, $fromMinutes, $untilMinutes]) {
                // Intervalles demi-ouverts, comme `collide()` : un créneau qui COMMENCE
                // à la fin de l'indisponibilité ne la chevauche pas.
                if ($day !== $reservation->getDayOfWeek() || $start >= $untilMinutes || $fromMinutes >= $end) {
                    continue;
                }
                $described = $this->describe($reservation, $names);
                $clashes[] = [
                    'coachId' => $coachId,
                    'coachName' => $names['coach'][$coachId] ?? 'Ce coach',
                    'dayOfWeek' => $reservation->getDayOfWeek(),
                    'teamName' => $described['teamName'],
                    'venueName' => $described['venueName'],
                    'startTime' => $described['startTime'],
                ];
                break; // une réservation = un avertissement, même si plusieurs règles la couvrent
            }
        }

        return $clashes;
    }

    /**
     * @param array{coachId: string, coachName: string, dayOfWeek: int, teamName: string, venueName: string, startTime: string} $clash
     */
    public function describeUnavailabilityForRecap(array $clash): string
    {
        return \sprintf(
            '%s est indisponible le %s à %s, mais une réservation y place %s à %s : le créneau sera maintenu malgré son indisponibilité. Déplacez la réservation, ou ajustez la disponibilité du coach.',
            $clash['coachName'],
            self::DAY_NAMES[$clash['dayOfWeek']] ?? 'ce jour',
            $clash['startTime'],
            $clash['teamName'],
            $clash['venueName'],
        );
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return array<string, list<array{0: int, 1: int, 2: int}>> coachId => [jour ISO, début min, fin min]
     */
    private function blockedIntervalsByCoach(array $constraints): array
    {
        $blocked = [];
        foreach ($constraints as $constraint) {
            $coachId = $constraint->getScopeTargetId();
            if (ConstraintFamily::COACH_AVAILABILITY !== $constraint->getFamily() || !$constraint->getIsActive() || null === $coachId) {
                continue;
            }
            $config = $constraint->getConfig();
            $fromMinutes = $this->timeToMinutes($config['fromTime'] ?? null, 0);
            $untilMinutes = $this->timeToMinutes($config['untilTime'] ?? null, 1440);

            foreach ($this->dayIntSet($config['unavailableDays'] ?? null) as $day) {
                $blocked[$coachId][] = [$day, $fromMinutes, $untilMinutes];
            }

            $availableSet = $this->dayIntSet($config['availableDays'] ?? null);
            if ([] !== $availableSet) {
                foreach (range(1, 7) as $day) {
                    if (!\in_array($day, $availableSet, true)) {
                        $blocked[$coachId][] = [$day, 0, 1440];
                        continue;
                    }
                    if ($fromMinutes > 0) {
                        $blocked[$coachId][] = [$day, 0, $fromMinutes];
                    }
                    if ($untilMinutes < 1440) {
                        $blocked[$coachId][] = [$day, $untilMinutes, 1440];
                    }
                }
            }
        }

        return $blocked;
    }

    private function timeToMinutes(mixed $time, int $default): int
    {
        if (!\is_string($time) || 1 !== preg_match('/^(\d{2}):(\d{2})/', $time, $matches)) {
            return $default;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    /**
     * @return list<int>
     */
    private function dayIntSet(mixed $days): array
    {
        if (!\is_array($days)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $day): ?int => is_numeric($day) ? (int) $day : null,
            $days,
        ), static fn (?int $day): bool => null !== $day));
    }

    /**
     * @param array<string, string> $mainCoachByTeam
     */
    private function collide(Reservation $first, Reservation $second, array $mainCoachByTeam): bool
    {
        return self::bookingsCollide(
            $this->bookingOf($first, $mainCoachByTeam),
            $this->bookingOf($second, $mainCoachByTeam),
        );
    }

    /**
     * @param array<string, string> $mainCoachByTeam
     *
     * @return array{coachId: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}
     */
    private function bookingOf(Reservation $reservation, array $mainCoachByTeam): array
    {
        // Lot 4bis — un créneau LIBRE (teamId null) n'a pas de coach : il est déjà écarté en amont
        // (le filtre `isset($mainCoachByTeam[teamId])` de `detect`/`detectUnavailabilityClashes`),
        // jamais une réservation passée ici. Le `?? ''` ne fait que satisfaire le typage.
        return [
            'coachId' => $mainCoachByTeam[$reservation->getTeamId() ?? ''] ?? '',
            'teamId' => $reservation->getTeamId() ?? '',
            'venueId' => $reservation->getVenueId(),
            'dayOfWeek' => $reservation->getDayOfWeek(),
            'startMinutes' => (int) $reservation->getStartTime()->format('H') * 60 + (int) $reservation->getStartTime()->format('i'),
            'durationMinutes' => $reservation->getDurationMinutes(),
        ];
    }

    /**
     * @param list<Reservation>     $reservations
     * @param array<string, string> $mainCoachByTeam
     *
     * @return array{coach: array<string, string>, team: array<string, string>, venue: array<string, string>}
     */
    private function displayNames(array $reservations, array $mainCoachByTeam): array
    {
        $teamIds = array_values(array_unique(array_map(static fn (Reservation $r): string => $r->getTeamId() ?? '', $reservations)));
        $venueIds = array_values(array_unique(array_map(static fn (Reservation $r): string => $r->getVenueId(), $reservations)));
        $coachIds = array_values(array_unique(array_intersect_key($mainCoachByTeam, array_flip($teamIds))));

        $coach = [];
        foreach ([] === $coachIds ? [] : $this->entityManager->getRepository(Coach::class)->findBy(['id' => $coachIds]) as $entity) {
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
     * @return array{teamName: string, venueName: string, startTime: string}
     */
    private function describe(Reservation $reservation, array $names): array
    {
        return [
            'teamName' => $names['team'][$reservation->getTeamId()] ?? 'une équipe',
            'venueName' => $names['venue'][$reservation->getVenueId()] ?? 'un gymnase',
            'startTime' => $reservation->getStartTime()->format('H:i'),
        ];
    }

    /**
     * @return array<string, string> teamId => coachId du coach MAIN
     */
    private function mainCoachByTeam(string $clubId, string $seasonId): array
    {
        $map = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $link) {
            if (TeamCoachRole::MAIN === $link->getRole()) {
                $map[$link->getTeamId()] = $link->getCoachId();
            }
        }

        return $map;
    }
}
