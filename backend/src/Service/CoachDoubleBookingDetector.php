<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTravelTime;
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
 * « Tant qu'un coach tient la séance, c'est OK » (fondateur 2026-10-30) : un coach partagé ne bloque
 * que si une séance n'a plus personne pour la tenir ({@see self::holdersFor}). Seul, il est
 * indispensable ; à plusieurs, un seul suffit. `isRequired` n'entre pas en jeu.
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
     * Chantier H — LA règle pure de TOLÉRANCE d'arrivée tardive, miroir MÉCANIQUE de
     * `wizard/lib/coachDoubleBooking.ts::lateArrivalTolerated` (cas partagés
     * `coachDoubleBooking.parity.json::toleranceCases`, gardé par `CoachDoubleBookingMirrorParityTest`).
     * `$a` = la séance que le coach QUITTE, `$b` = celle qu'il rejoint EN RETARD ; `$baremeMinutes`
     * = le trajet entre les deux gymnases. Le retard = `max(0, fin(a) + barème − début(b))` ; une
     * paire géométriquement dédoublante (déjà prouvée par {@see bookingsCollide}) est TOLÉRÉE ssi la
     * tolérance réglée est dans `[1, 30]` (plafond métier souverain, fondateur), le début de `b` est
     * STRICTEMENT après celui de `a` (sinon le coach manquerait le début entier, pas une arrivée
     * tardive), ET le retard ne dépasse pas la tolérance. La DISPONIBILITÉ de l'adjoint n'est PAS
     * dans cette règle pure (vérifiée serveur) — seule la géométrie du retard l'est.
     *
     * @param array{startMinutes: int, durationMinutes: int} $a
     * @param array{startMinutes: int, durationMinutes: int} $b
     */
    public static function lateArrivalTolerated(array $a, array $b, int $toleranceMinutes, int $baremeMinutes): bool
    {
        if ($toleranceMinutes < 1 || $toleranceMinutes > 30) {
            return false;
        }
        if ($b['startMinutes'] <= $a['startMinutes']) {
            return false;
        }
        $lateness = max(0, $a['startMinutes'] + $a['durationMinutes'] + $baremeMinutes - $b['startMinutes']);

        return $lateness <= $toleranceMinutes;
    }

    /**
     * Chantier H / P4-333 — deux équipes co-réservées partagent-elles AU MOINS un coach PRINCIPAL ?
     * Miroir MÉCANIQUE de `wizard/lib/coachDoubleBooking.ts::teamsShareMainCoach` (cas partagés
     * `coachDoubleBooking.parity.json::multiCoachCases`). Le bloqueur ne regardait que le PREMIER
     * principal par équipe (P4-319) ; il doit regarder TOUS les principaux : deux équipes se
     * dédoublent dès qu'elles partagent un principal (exemple roadmap Camille/Dorian).
     *
     * @param list<string> $coachIdsA
     * @param list<string> $coachIdsB
     */
    public static function teamsShareMainCoach(array $coachIdsA, array $coachIdsB): bool
    {
        return [] !== array_intersect($coachIdsA, $coachIdsB);
    }

    /**
     * « Tant qu'un coach tient la séance, c'est OK » — LA règle pure de l'affectation des tenues,
     * miroir MÉCANIQUE de `wizard/lib/coachDoubleBooking.ts::holdersFor` et du moteur
     * (`constraints/availability.py::assign_holders`). Cas partagés et règle complète :
     * `coachDoubleBooking.parity.json::holdCases`, gardé par `CoachDoubleBookingMirrorParityTest`.
     * Déterministe : séances dans l'ordre donné, candidats dans l'ordre des listes, retour arrière,
     * la PREMIÈRE solution.
     *
     * @param list<array{id: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>    $sessions
     * @param array<string, list<string>>                                                                                          $mainCoachesByTeam
     * @param array<string, list<array{0: int, 1: int, 2: int}>>                                                                   $blockedByCoach    coachId → [(jour, de, à)]
     * @param array<string, list<array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>> $playingByCoach    coachId → séances où il joue
     * @param list<list<string>>                                                                                                   $groups            groupes déclarés (séances communes) : les seuls à prêter leurs coachs
     *
     * @return array<string, string>|null sessionId → coachId, null si une séance n'a personne
     */
    public static function holdersFor(array $sessions, array $mainCoachesByTeam, array $blockedByCoach = [], array $playingByCoach = [], array $groups = []): ?array
    {
        $required = [];
        $candidates = [];
        foreach ($sessions as $session) {
            $own = self::holdCandidates($session, $sessions, $mainCoachesByTeam, $groups);
            if ([] === $own) {
                continue; // aucun coach principal : la séance n'exige personne.
            }
            $free = array_values(array_filter($own, static fn (string $coachId): bool => !self::blockedDuring($coachId, $session, $blockedByCoach)));
            $free = [] === $free ? $own : $free; // indisponible mais seul : avertissement, jamais bloqueur.
            $required[] = $session;
            $candidates[$session['id']] = array_values(array_filter($free, static fn (string $coachId): bool => !self::playsElsewhereDuring($coachId, $session, $playingByCoach)));
        }

        $holders = [];

        return self::assignHolders($required, $candidates, 0, $holders) ? $holders : null;
    }

    /**
     * {@see self::holdersFor}, grappe par grappe : deux séances ne se jugent ensemble que si elles
     * peuvent se disputer une personne. Une séance intenable ne condamne pas un autre créneau.
     *
     * @param list<array{id: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>    $sessions
     * @param array<string, list<string>>                                                                                          $mainCoachesByTeam
     * @param array<string, list<array{0: int, 1: int, 2: int}>>                                                                   $blockedByCoach
     * @param array<string, list<array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>> $playingByCoach
     * @param list<list<string>>                                                                                                   $groups
     *
     * @return array{holders: array<string, string>, unheld: array<array-key, true>}
     */
    public static function holdsByCluster(array $sessions, array $mainCoachesByTeam, array $blockedByCoach = [], array $playingByCoach = [], array $groups = []): array
    {
        $people = [];
        foreach ($sessions as $session) {
            $people[$session['id']] = self::holdCandidates($session, $sessions, $mainCoachesByTeam, $groups);
            foreach ($playingByCoach as $coachId => $games) {
                foreach ($games as $game) {
                    if (self::overlaps($session, $game)) {
                        $people[$session['id']][] = $coachId;
                        break;
                    }
                }
            }
        }

        $holders = [];
        $unheld = [];
        $seen = [];
        foreach ($sessions as $seed) {
            if (isset($seen[$seed['id']])) {
                continue;
            }
            $seen[$seed['id']] = true;
            $cluster = [$seed];
            for ($index = 0; isset($cluster[$index]); ++$index) { // la grappe grandit pendant le parcours
                foreach ($sessions as $other) {
                    if (!isset($seen[$other['id']]) && self::overlaps($cluster[$index], $other) && [] !== array_intersect($people[$cluster[$index]['id']], $people[$other['id']])) {
                        $seen[$other['id']] = true;
                        $cluster[] = $other;
                    }
                }
            }
            $clusterHolders = self::holdersFor(array_values(array_filter($sessions, static fn (array $session): bool => \in_array($session, $cluster, true))), $mainCoachesByTeam, $blockedByCoach, $playingByCoach, $groups);
            if (null === $clusterHolders) {
                foreach ($cluster as $session) {
                    $unheld[$session['id']] = true;
                }
                continue;
            }
            $holders += $clusterHolders;
        }

        return ['holders' => $holders, 'unheld' => $unheld];
    }

    /**
     * Le coach à stocker sur une séance qu'on vient de poser : le premier de l'équipe, ou le suivant si
     * le premier est pris ailleurs (une séance qui le nomme, un rôle de joueur, son indisponibilité).
     * Personne de libre : le premier, le radar le signalera.
     *
     * @param array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}                         $session
     * @param list<string>                                                                                                            $coachIds
     * @param list<array{coachId: ?string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}> $others
     * @param array<string, list<array{0: int, 1: int, 2: int}>>                                                                      $blockedByCoach
     * @param array<string, list<array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>>    $playingByCoach
     */
    public static function storedCoachFor(array $session, array $coachIds, array $others, array $blockedByCoach, array $playingByCoach): ?string
    {
        foreach ($coachIds as $coachId) {
            if (self::blockedDuring($coachId, $session, $blockedByCoach) || self::playsElsewhereDuring($coachId, $session, $playingByCoach)) {
                continue;
            }
            foreach ($others as $other) {
                if ($other['coachId'] === $coachId && self::bookingsCollide(['coachId' => $coachId] + $session, ['coachId' => $coachId] + $other)) {
                    continue 2;
                }
            }

            return $coachId;
        }

        return $coachIds[0] ?? null;
    }

    /**
     * Chantier H — le barème de trajet (minutes) entre deux gymnases : colonne VOITURE si le
     * coach est véhiculé, sinon la colonne non véhiculée (à vélo), défaut 20 si le couple n'est pas
     * arbitré ou la colonne est nulle. SOURCE PARTAGÉE du barème côté backend (récap + radar
     * entraînements), miroir du `travelTime.default_minutes` / `_barometer` du moteur.
     *
     * @param array<string, array{driving: ?int, walking: ?int}> $matrix couple TRIÉ `a|b` → barèmes
     */
    public static function travelBaremeMinutes(array $matrix, string $venueA, string $venueB, bool $vehicled, int $default = 20): int
    {
        [$a, $b] = $venueA < $venueB ? [$venueA, $venueB] : [$venueB, $venueA];
        $pair = $matrix[$a . '|' . $b] ?? null;
        if (null === $pair) {
            return $default;
        }
        $value = $vehicled ? $pair['driving'] : $pair['walking'];

        return null === $value ? $default : $value;
    }

    /**
     * Chantier H — LA maison partagée « adjoint disponible » (récap + radar entraînements) : l'id du
     * premier adjoint qui peut OUVRIR la fenêtre `[windowStart, windowEnd[` un jour ISO, ou null.
     * Un adjoint est éligible s'il n'a AUCUNE indisponibilité déclarée chevauchant la fenêtre NI
     * aucune séance où il est MAIN chevauchant la fenêtre dans un AUTRE gymnase (le même gymnase est
     * la séance qu'il ouvre, jamais un empêchement).
     *
     * @param list<string>                                                                                         $assistantIds        adjoints candidats (la personne dédoublée déjà écartée)
     * @param array<string, list<array{0: int, 1: int, 2: int}>>                                                   $blockedByCoach      coachId → [(jour, de, à)]
     * @param array<string, list<array{venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>> $mainBookingsByCoach coachId → séances où il est MAIN
     */
    public static function adjointOpening(array $assistantIds, array $blockedByCoach, array $mainBookingsByCoach, int $day, int $windowStart, int $windowEnd, string $windowVenueId): ?string
    {
        foreach ($assistantIds as $adjointId) {
            $blocked = false;
            foreach ($blockedByCoach[$adjointId] ?? [] as [$blockedDay, $from, $until]) {
                if ($blockedDay === $day && $from < $windowEnd && $windowStart < $until) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                continue;
            }
            $busyElsewhere = false;
            foreach ($mainBookingsByCoach[$adjointId] ?? [] as $booking) {
                if ($booking['dayOfWeek'] !== $day || $booking['venueId'] === $windowVenueId) {
                    continue; // même gymnase = la séance qu'il ouvre, pas un empêchement
                }
                $start = $booking['startMinutes'];
                $end = $start + $booking['durationMinutes'];
                if ($start < $windowEnd && $windowStart < $end) {
                    $busyElsewhere = true;
                    break;
                }
            }
            if (!$busyElsewhere) {
                return $adjointId;
            }
        }

        return null;
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return array<string, list<array{0: int, 1: int, 2: int}>> coachId => [jour ISO, début min, fin min]
     */
    public static function blockedIntervalsByCoach(array $constraints): array
    {
        $blocked = [];
        foreach ($constraints as $constraint) {
            $coachId = $constraint->getScopeTargetId();
            if (ConstraintFamily::COACH_AVAILABILITY !== $constraint->getFamily() || !$constraint->getIsActive() || null === $coachId) {
                continue;
            }
            $config = $constraint->getConfig();
            $fromMinutes = self::timeToMinutes($config['fromTime'] ?? null, 0);
            $untilMinutes = self::timeToMinutes($config['untilTime'] ?? null, 1440);

            foreach (self::dayIntSet($config['unavailableDays'] ?? null) as $day) {
                $blocked[$coachId][] = [$day, $fromMinutes, $untilMinutes];
            }

            $availableSet = self::dayIntSet($config['availableDays'] ?? null);
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

    /**
     * Les coachs de l'équipe, puis ceux des autres équipes de son groupe DÉCLARÉ sur la MÊME case.
     * Même case sans groupe = chacun ses coachs (décision fondateur 2026-10-30).
     *
     * @param array{id: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}       $session
     * @param list<array{id: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}> $sessions
     * @param array<string, list<string>>                                                                                       $mainCoachesByTeam
     * @param list<list<string>>                                                                                                $groups
     *
     * @return list<string>
     */
    private static function holdCandidates(array $session, array $sessions, array $mainCoachesByTeam, array $groups): array
    {
        $candidates = $mainCoachesByTeam[$session['teamId']] ?? [];
        foreach ($sessions as $other) {
            if ($other['venueId'] === $session['venueId'] && $other['dayOfWeek'] === $session['dayOfWeek'] && $other['startMinutes'] === $session['startMinutes'] && self::sameGroup($session['teamId'], $other['teamId'], $groups)) {
                $candidates = [...$candidates, ...($mainCoachesByTeam[$other['teamId']] ?? [])];
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param list<list<string>> $groups
     */
    private static function sameGroup(string $teamA, string $teamB, array $groups): bool
    {
        if ($teamA === $teamB) {
            return true;
        }
        foreach ($groups as $group) {
            if (\in_array($teamA, $group, true) && \in_array($teamB, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Même règle que le moteur (`availability.py::_coach_available_at`) : l'indisponibilité se teste
     * au DÉBUT de la séance.
     *
     * @param array{dayOfWeek: int, startMinutes: int, durationMinutes: int} $session
     * @param array<string, list<array{0: int, 1: int, 2: int}>>             $blockedByCoach
     */
    private static function blockedDuring(string $coachId, array $session, array $blockedByCoach): bool
    {
        foreach ($blockedByCoach[$coachId] ?? [] as [$day, $from, $until]) {
            if ($day === $session['dayOfWeek'] && $from <= $session['startMinutes'] && $session['startMinutes'] < $until) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}                      $session
     * @param array<string, list<array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>> $playingByCoach
     */
    private static function playsElsewhereDuring(string $coachId, array $session, array $playingByCoach): bool
    {
        foreach ($playingByCoach[$coachId] ?? [] as $game) {
            // Même règle que le moteur : jouer occupe, même gymnase compris (sauf dans sa propre équipe).
            if ($game['teamId'] !== $session['teamId'] && self::overlaps($session, $game)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{dayOfWeek: int, startMinutes: int, durationMinutes: int} $a
     * @param array{dayOfWeek: int, startMinutes: int, durationMinutes: int} $b
     */
    private static function overlaps(array $a, array $b): bool
    {
        return $a['dayOfWeek'] === $b['dayOfWeek']
            && $a['startMinutes'] < $b['startMinutes'] + $b['durationMinutes']
            && $b['startMinutes'] < $a['startMinutes'] + $a['durationMinutes'];
    }

    /**
     * @param list<array{id: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}> $sessions
     * @param array<string, list<string>>                                                                                       $candidates
     * @param array<string, string>                                                                                             $holders
     */
    private static function assignHolders(array $sessions, array $candidates, int $index, array &$holders): bool
    {
        if ($index === \count($sessions)) {
            return true;
        }
        $session = $sessions[$index];
        foreach ($candidates[$session['id']] ?? [] as $coachId) {
            foreach (\array_slice($sessions, 0, $index) as $other) {
                if (($holders[$other['id']] ?? null) === $coachId && self::bookingsCollide(['coachId' => $coachId] + $session, ['coachId' => $coachId] + $other)) {
                    continue 2;
                }
            }
            $holders[$session['id']] = $coachId;
            if (self::assignHolders($sessions, $candidates, $index + 1, $holders)) {
                return true;
            }
            unset($holders[$session['id']]);
        }

        return false;
    }

    private static function timeToMinutes(mixed $time, int $default): int
    {
        if (!\is_string($time) || 1 !== preg_match('/^(\d{2}):(\d{2})/', $time, $matches)) {
            return $default;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    /**
     * @return list<int>
     */
    private static function dayIntSet(mixed $days): array
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
     * Les groupes déclarés d'un périmètre : les équipes de chaque séance commune.
     *
     * @return list<list<string>>
     */
    public function declaredGroups(string $clubId, string $seasonId, ?string $schedulePlanId): array
    {
        $groups = [];
        foreach ($this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => $schedulePlanId]) as $member) {
            $groups[$member->getBlockId()][] = $member->getTeamId();
        }

        return array_values($groups);
    }

    /**
     * @param list<Reservation> $reservations le périmètre EXACT qui partira au solveur
     *                                        (socle : `schedulePlanId IS NULL` ; période :
     *                                        son plan, gymnases désactivés déjà retirés)
     * @param list<Constraint>  $constraints  le jeu de contraintes du récap (indispos coach) — sert
     *                                        à vérifier la disponibilité de l'adjoint (chantier H)
     *
     * @return list<array{coachId: string, coachName: string, dayOfWeek: int, first: array{teamName: string, venueName: string, startTime: string}, second: array{teamName: string, venueName: string, startTime: string}}>
     */
    public function detect(array $reservations, string $clubId, string $seasonId, array $constraints = []): array
    {
        // Chantier H — une paire dédoublante dont la séance REJOINTE tolère le retard ET qu'un
        // adjoint DISPONIBLE ouvre n'est plus un bloqueur : elle part en avertissement
        // ({@see detectRelayWarnings}). Avec la tolérance par défaut (0), aucune paire n'est
        // tolérée → comportement d'avant le chantier H strictement inchangé.
        return array_values(array_map(
            static fn (array $entry): array => $entry['conflict'],
            array_filter($this->analyzePairs($reservations, $clubId, $seasonId, $constraints), static fn (array $entry): bool => !$entry['tolerated']),
        ));
    }

    /**
     * Chantier H — les AVERTISSEMENTS du récap pour les paires TOLÉRÉES (retard ≤ tolérance de la
     * séance rejointe ET adjoint disponible qui l'ouvre). Un par conflit toléré, au gabarit
     * fondateur « {Coach} arrivera ~{N} min en retard aux {Équipe} : {Adjoint} (adjoint) ouvre la
     * séance. ».
     *
     * @param list<Reservation> $reservations
     * @param list<Constraint>  $constraints
     *
     * @return list<string>
     */
    public function detectRelayWarnings(array $reservations, array $constraints, string $clubId, string $seasonId): array
    {
        $warnings = [];
        foreach ($this->analyzePairs($reservations, $clubId, $seasonId, $constraints) as $entry) {
            if ($entry['tolerated'] && null !== $entry['warning']) {
                $warnings[] = $entry['warning'];
            }
        }

        return $warnings;
    }

    /**
     * La mention douce du récap : une séance dont un coach est pris ailleurs, tenue par un autre.
     * Ni bloqueur ni avertissement (« U15 tenue par B », fondateur 2026-10-30).
     *
     * @param list<Reservation> $reservations
     * @param list<Constraint>  $constraints
     *
     * @return list<array{place: string, message: string}>
     */
    public function detectHoldNotices(array $reservations, array $constraints, string $clubId, string $seasonId): array
    {
        $mainCoachesByTeam = $this->mainCoachesByTeam($clubId, $seasonId);
        $relevant = array_values(array_filter(
            $reservations,
            static fn (Reservation $r): bool => [] !== ($mainCoachesByTeam[$r->getTeamId() ?? ''] ?? []),
        ));
        $sessions = $this->holdSessions($relevant);
        $holders = self::holdsByCluster($sessions, $mainCoachesByTeam, self::blockedIntervalsByCoach($constraints), $this->playingByCoach($reservations, $clubId, $seasonId), $this->groupsOf($reservations, $clubId, $seasonId))['holders'];
        $names = $this->displayNames($relevant, $mainCoachesByTeam);

        $notices = [];
        foreach ($sessions as $index => $session) {
            $holder = $holders[$session['id']] ?? null;
            if (null === $holder || !$this->aCoachIsBusyElsewhere($session, $sessions, $holders, $mainCoachesByTeam[$session['teamId']] ?? [])) {
                continue;
            }
            $described = $this->describe($relevant[$index], $names);
            $notices[] = [
                'place' => \sprintf('%s · %s %s', $described['venueName'], self::DAY_NAMES[$session['dayOfWeek']] ?? 'ce jour', $described['startTime']),
                'message' => \sprintf('%s tenue par %s', $described['teamName'], $names['coach'][$holder] ?? 'un autre coach'),
            ];
        }

        return $notices;
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
     * peut s'en prémunir AVANT (décision fondateur). Coachs MAIN seuls, le verrou est
     * souverain donc c'est un AVERTISSEMENT (la génération passera, la réservation
     * écrasera l'indisponibilité), pas un bloqueur.
     *
     * ⚠ « UN COACH SUFFIT » (P4-319, même règle que le moteur — `availability.py` /
     * `structural/closures.py`) : une équipe peut avoir PLUSIEURS coachs principaux, et une
     * séance COMMUNE (plusieurs équipes co-localisées sur la même case gymnase/jour/début)
     * réunit l'UNION de leurs coachs. On n'avertit donc que si TOUS les coachs requis de la
     * case sont bloqués sur l'intervalle — un seul disponible rend la séance servable. Une
     * équipe SEULE sur sa case ⇒ ses propres coachs principaux.
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

        $blocked = self::blockedIntervalsByCoach($constraints);
        if ([] === $blocked) {
            return [];
        }

        $mainCoaches = $this->mainCoachesByTeam($clubId, $seasonId);
        // Une équipe sans coach MAIN ne peut « manquer de coach » : on l'écarte AVANT.
        $relevant = array_values(array_filter(
            $reservations,
            static fn (Reservation $r): bool => [] !== ($mainCoaches[$r->getTeamId() ?? ''] ?? []),
        ));
        if ([] === $relevant) {
            return [];
        }

        // Les équipes posées sur chaque case (gymnase, jour, début) : une séance COMMUNE DÉCLARÉE en
        // réunit plusieurs, et « un coach suffit » se décide sur l'UNION de leurs coachs. Même case
        // sans groupe = chacun ses coachs (décision fondateur 2026-10-30).
        $groups = $this->groupsOf($reservations, $clubId, $seasonId);
        $teamsByCase = [];
        foreach ($relevant as $reservation) {
            $teamsByCase[$this->caseKey($reservation)][$reservation->getTeamId() ?? ''] = true;
        }

        // Nommer / afficher reste mono-coach (le coach principal de l'équipe placée) : la liste
        // ne sert qu'au verdict « tous bloqués ». Le premier coach par équipe suffit à l'habillage.
        $firstCoachByTeam = array_map(static fn (array $coachIds): string => $coachIds[0], $mainCoaches);
        $names = $this->displayNames($relevant, $mainCoaches);

        $clashes = [];
        foreach ($relevant as $reservation) {
            // Coachs REQUIS sur la case = union des coachs principaux des équipes co-localisées
            // (séance commune) ; une équipe seule ⇒ ses seuls coachs.
            $requiredCoachIds = [];
            foreach (array_keys($teamsByCase[$this->caseKey($reservation)]) as $teamId) {
                if (!self::sameGroup($reservation->getTeamId() ?? '', (string) $teamId, $groups)) {
                    continue;
                }
                foreach ($mainCoaches[$teamId] ?? [] as $coachId) {
                    $requiredCoachIds[$coachId] = true;
                }
            }

            // « Un coach suffit » : conflit SEULEMENT si TOUS les coachs requis sont bloqués sur
            // l'intervalle. Un seul disponible rend la séance servable — aucun avertissement.
            $allBlocked = true;
            foreach (array_keys($requiredCoachIds) as $coachId) {
                if (!$this->coachBlockedDuring($coachId, $reservation, $blocked)) {
                    $allBlocked = false;
                    break;
                }
            }
            if (!$allBlocked) {
                continue;
            }

            // Le coach principal de l'équipe placée est FORCÉMENT bloqué (il fait partie des
            // coachs requis, tous bloqués) — cohérent avec « une réservation place CETTE équipe ».
            $coachId = $firstCoachByTeam[$reservation->getTeamId() ?? ''] ?? '';
            $described = $this->describe($reservation, $names);
            $clashes[] = [
                'coachId' => $coachId,
                'coachName' => $names['coach'][$coachId] ?? 'Ce coach',
                'dayOfWeek' => $reservation->getDayOfWeek(),
                'teamName' => $described['teamName'],
                'venueName' => $described['venueName'],
                'startTime' => $described['startTime'],
            ];
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
     * Le groupe déclaré du périmètre des réservations (socle si aucune).
     *
     * @param list<Reservation> $reservations
     *
     * @return list<list<string>>
     */
    private function groupsOf(array $reservations, string $clubId, string $seasonId): array
    {
        return $this->declaredGroups($clubId, $seasonId, [] === $reservations ? null : $reservations[0]->getSchedulePlanId());
    }

    /**
     * coachId → réservations où il JOUE (memberships actifs) : les mêmes rôles que le radar.
     *
     * @param list<Reservation> $reservations
     *
     * @return array<string, list<array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>>
     */
    private function playingByCoach(array $reservations, string $clubId, string $seasonId): array
    {
        $playersByTeam = [];
        foreach ($this->entityManager->getRepository(CoachPlayerMembership::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $membership) {
            if ($membership->getIsActive()) {
                $playersByTeam[$membership->getTeamId()][] = $membership->getCoachId();
            }
        }
        $playing = [];
        foreach ($reservations as $reservation) {
            foreach ($playersByTeam[$reservation->getTeamId() ?? ''] ?? [] as $coachId) {
                $playing[$coachId][] = $this->bookingOf($reservation);
            }
        }

        return $playing;
    }

    /**
     * @param array{id: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}       $session
     * @param list<array{id: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}> $sessions
     * @param array<string, string>                                                                                             $holders
     * @param list<string>                                                                                                      $ownCoaches
     */
    private function aCoachIsBusyElsewhere(array $session, array $sessions, array $holders, array $ownCoaches): bool
    {
        foreach ($sessions as $other) {
            $coachId = $holders[$other['id']] ?? null;
            if (null !== $coachId && $coachId !== $holders[$session['id']] && \in_array($coachId, $ownCoaches, true)
                && self::bookingsCollide(['coachId' => $coachId] + $session, ['coachId' => $coachId] + $other)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les séances de {@see self::holdersFor}, repérées par la position de la réservation.
     *
     * @param list<Reservation> $reservations
     *
     * @return list<array{id: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>
     */
    private function holdSessions(array $reservations): array
    {
        $sessions = [];
        foreach ($reservations as $index => $reservation) {
            $sessions[] = ['id' => (string) $index] + $this->bookingOf($reservation);
        }

        return $sessions;
    }

    /**
     * Le CŒUR du chantier H : chaque paire dédoublante (P4-333 : TOUS les principaux partagés)
     * classée TOLÉRÉE ou non. Une entrée par coach principal partagé, portant le conflit structuré
     * (forme historique, pour le bloqueur), un drapeau `tolerated`, et le message de relais quand
     * tolérée. Tolérée ssi la séance rejointe (la plus tardive) tolère un retard ≥ 1 (plafond 30),
     * le retard géométrique (fin de la 1ʳᵉ + barème de trajet − début de la 2ᵉ) tient dans cette
     * tolérance, ET un adjoint DISPONIBLE (lien ASSISTANT de l'équipe rejointe, sans indispo
     * déclarée ni séance MAIN chevauchante dans un autre gymnase) ouvre la séance.
     *
     * Une paire dont chaque séance a quelqu'un pour la tenir n'a aucune entrée : ni conflit, ni relais.
     *
     * @param list<Reservation> $reservations
     * @param list<Constraint>  $constraints
     *
     * @return list<array{conflict: array{coachId: string, coachName: string, dayOfWeek: int, first: array{teamName: string, venueName: string, startTime: string}, second: array{teamName: string, venueName: string, startTime: string}}, tolerated: bool, warning: ?string}>
     */
    private function analyzePairs(array $reservations, string $clubId, string $seasonId, array $constraints): array
    {
        if (\count($reservations) < 2) {
            return [];
        }

        $mainCoachesByTeam = $this->mainCoachesByTeam($clubId, $seasonId);
        $relevant = array_values(array_filter(
            $reservations,
            static fn (Reservation $r): bool => [] !== ($mainCoachesByTeam[$r->getTeamId() ?? ''] ?? []),
        ));

        $names = $this->displayNames($relevant, $mainCoachesByTeam);
        // Décor du relais : barème de trajet, statut véhiculé + nom de tout coach, adjoints par
        // équipe, intervalles bloqués (indispo déclarée), et les séances où chaque coach est MAIN.
        $matrix = $this->travelMatrix($clubId, $seasonId);
        [$coachNames, $coachVehicled] = $this->coachIndex($clubId, $seasonId);
        $assistantsByTeam = $this->assistantsByTeam($clubId, $seasonId);
        $blockedByCoach = self::blockedIntervalsByCoach($constraints);
        $mainBookingsByCoach = $this->mainBookingsByCoach($relevant, $mainCoachesByTeam);
        $unheld = self::holdsByCluster($this->holdSessions($relevant), $mainCoachesByTeam, $blockedByCoach, $this->playingByCoach($reservations, $clubId, $seasonId), $this->groupsOf($reservations, $clubId, $seasonId))['unheld'];

        $entries = [];
        foreach ($relevant as $i => $first) {
            foreach (\array_slice($relevant, $i + 1) as $offset => $second) {
                if (!isset($unheld[(string) $i]) && !isset($unheld[(string) ($i + 1 + $offset)])) {
                    continue; // chacune a quelqu'un pour la tenir.
                }
                $coachIdsA = $mainCoachesByTeam[$first->getTeamId() ?? ''] ?? [];
                $coachIdsB = $mainCoachesByTeam[$second->getTeamId() ?? ''] ?? [];
                if (!self::teamsShareMainCoach($coachIdsA, $coachIdsB) || !$this->geometricCollide($first, $second)) {
                    continue;
                }

                [$early, $late] = $this->startMinutesOf($first) <= $this->startMinutesOf($second) ? [$first, $second] : [$second, $first];
                $tolerance = $late->getCoachLateToleranceMinutes();

                foreach (array_intersect($coachIdsA, $coachIdsB) as $coachId) {
                    $conflict = [
                        'coachId' => $coachId,
                        'coachName' => $names['coach'][$coachId] ?? 'Ce coach',
                        'dayOfWeek' => $first->getDayOfWeek(),
                        'first' => $this->describe($first, $names),
                        'second' => $this->describe($second, $names),
                    ];

                    $tolerated = false;
                    $warning = null;
                    $bareme = self::travelBaremeMinutes($matrix, $early->getVenueId(), $late->getVenueId(), $coachVehicled[$coachId] ?? false);
                    if (self::lateArrivalTolerated($this->toBooking($early), $this->toBooking($late), $tolerance, $bareme)) {
                        $lateMinutes = max(0, $this->startMinutesOf($early) + $early->getDurationMinutes() + $bareme - $this->startMinutesOf($late));
                        $assistants = array_values(array_filter($assistantsByTeam[$late->getTeamId() ?? ''] ?? [], static fn (string $id): bool => $id !== $coachId));
                        $adjointId = self::adjointOpening($assistants, $blockedByCoach, $mainBookingsByCoach, $late->getDayOfWeek(), $this->startMinutesOf($late), $this->startMinutesOf($late) + $lateMinutes, $late->getVenueId());
                        if (null !== $adjointId) {
                            $tolerated = true;
                            $warning = \sprintf(
                                '%s arrivera ~%d min en retard aux %s : %s (adjoint) ouvre la séance.',
                                $conflict['coachName'],
                                $lateMinutes,
                                $names['team'][$late->getTeamId() ?? ''] ?? 'une équipe',
                                $coachNames[$adjointId] ?? 'Un adjoint',
                            );
                        }
                    }

                    $entries[] = ['conflict' => $conflict, 'tolerated' => $tolerated, 'warning' => $warning];
                }
            }
        }

        return $entries;
    }

    /** La case (gymnase, jour, minute de début) d'une réservation — clé de co-localisation. */
    private function caseKey(Reservation $reservation): string
    {
        $start = (int) $reservation->getStartTime()->format('H') * 60 + (int) $reservation->getStartTime()->format('i');

        return $reservation->getVenueId() . '|' . $reservation->getDayOfWeek() . '|' . $start;
    }

    /**
     * Le coach est-il indisponible pendant l'intervalle réel de la réservation ? Intervalles
     * demi-ouverts, comme `collide()` : un créneau qui COMMENCE à la fin de l'indisponibilité
     * ne la chevauche pas. Coach absent de la carte des blocages ⇒ disponible.
     *
     * @param array<string, list<array{0: int, 1: int, 2: int}>> $blocked
     */
    private function coachBlockedDuring(string $coachId, Reservation $reservation, array $blocked): bool
    {
        $start = (int) $reservation->getStartTime()->format('H') * 60 + (int) $reservation->getStartTime()->format('i');
        $end = $start + $reservation->getDurationMinutes();
        foreach ($blocked[$coachId] ?? [] as [$day, $fromMinutes, $untilMinutes]) {
            if ($day !== $reservation->getDayOfWeek() || $start >= $untilMinutes || $fromMinutes >= $end) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * La GÉOMÉTRIE seule du dédoublement (équipes et gymnases différents, même jour, intervalles
     * réels qui se chevauchent) — le partage d'un coach principal est jugé à part ({@see
     * teamsShareMainCoach}), P4-333. Même algèbre demi-ouverte que {@see bookingsCollide}.
     */
    private function geometricCollide(Reservation $first, Reservation $second): bool
    {
        $a = $this->bookingOf($first);
        $b = $this->bookingOf($second);

        return $a['teamId'] !== $b['teamId']
            && $a['venueId'] !== $b['venueId']
            && $a['dayOfWeek'] === $b['dayOfWeek']
            && $a['startMinutes'] < $b['startMinutes'] + $b['durationMinutes']
            && $b['startMinutes'] < $a['startMinutes'] + $a['durationMinutes'];
    }

    /**
     * @return array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}
     */
    private function bookingOf(Reservation $reservation): array
    {
        // Lot 4bis — un créneau LIBRE (teamId null) est déjà écarté en amont (le filtre « équipe a
        // un coach MAIN » de `detect`). Le `?? ''` ne fait que satisfaire le typage.
        return [
            'teamId' => $reservation->getTeamId() ?? '',
            'venueId' => $reservation->getVenueId(),
            'dayOfWeek' => $reservation->getDayOfWeek(),
            'startMinutes' => (int) $reservation->getStartTime()->format('H') * 60 + (int) $reservation->getStartTime()->format('i'),
            'durationMinutes' => $reservation->getDurationMinutes(),
        ];
    }

    /**
     * @param list<Reservation>           $reservations
     * @param array<string, list<string>> $mainCoachesByTeam
     *
     * @return array{coach: array<string, string>, team: array<string, string>, venue: array<string, string>}
     */
    private function displayNames(array $reservations, array $mainCoachesByTeam): array
    {
        $teamIds = array_values(array_unique(array_map(static fn (Reservation $r): string => $r->getTeamId() ?? '', $reservations)));
        $venueIds = array_values(array_unique(array_map(static fn (Reservation $r): string => $r->getVenueId(), $reservations)));
        $coachIds = array_values(array_unique(array_merge(...array_values(array_intersect_key($mainCoachesByTeam, array_flip($teamIds))) ?: [[]])));

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
     * teamId => coachIds des coachs PRINCIPAUX (MAIN), ordre d'insertion. Une équipe peut en
     * avoir plusieurs (P4-319) ; la règle « un coach suffit » en a besoin de la LISTE entière.
     *
     * @return array<string, list<string>>
     */
    private function mainCoachesByTeam(string $clubId, string $seasonId): array
    {
        $map = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $link) {
            if (TeamCoachRole::MAIN === $link->getRole() && !\in_array($link->getCoachId(), $map[$link->getTeamId()] ?? [], true)) {
                $map[$link->getTeamId()][] = $link->getCoachId();
            }
        }

        return $map;
    }

    /**
     * teamId => coachIds des ADJOINTS (ASSISTANT), ordre d'insertion (chantier H).
     *
     * @return array<string, list<string>>
     */
    private function assistantsByTeam(string $clubId, string $seasonId): array
    {
        $map = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $link) {
            if (TeamCoachRole::ASSISTANT === $link->getRole() && !\in_array($link->getCoachId(), $map[$link->getTeamId()] ?? [], true)) {
                $map[$link->getTeamId()][] = $link->getCoachId();
            }
        }

        return $map;
    }

    /**
     * La matrice de trajet du club/saison, indexée par couple TRIÉ `a|b` (chantier H).
     *
     * @return array<string, array{driving: ?int, walking: ?int}>
     */
    private function travelMatrix(string $clubId, string $seasonId): array
    {
        $matrix = [];
        foreach ($this->entityManager->getRepository(VenueTravelTime::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $row) {
            [$a, $b] = $row->getVenueAId() < $row->getVenueBId() ? [$row->getVenueAId(), $row->getVenueBId()] : [$row->getVenueBId(), $row->getVenueAId()];
            $matrix[$a . '|' . $b] = ['driving' => $row->getDrivingMinutes(), 'walking' => $row->getWalkingMinutes()];
        }

        return $matrix;
    }

    /**
     * Noms + statut véhiculé de tous les coachs du club/saison (chantier H).
     *
     * @return array{0: array<string, string>, 1: array<string, bool>}
     */
    private function coachIndex(string $clubId, string $seasonId): array
    {
        $names = [];
        $vehicled = [];
        foreach ($this->entityManager->getRepository(Coach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $coach) {
            $names[$coach->getId()] = trim($coach->getFirstName() . ' ' . $coach->getLastName());
            $vehicled[$coach->getId()] = $coach->isVehicled();
        }

        return [$names, $vehicled];
    }

    /**
     * Les séances (réservations) où chaque coach est MAIN — pour vérifier qu'un adjoint n'est pas
     * occupé ailleurs (chantier H).
     *
     * @param list<Reservation>           $reservations
     * @param array<string, list<string>> $mainCoachesByTeam
     *
     * @return array<string, list<array{venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>>
     */
    private function mainBookingsByCoach(array $reservations, array $mainCoachesByTeam): array
    {
        $byCoach = [];
        foreach ($reservations as $reservation) {
            $booking = [
                'venueId' => $reservation->getVenueId(),
                'dayOfWeek' => $reservation->getDayOfWeek(),
                'startMinutes' => $this->startMinutesOf($reservation),
                'durationMinutes' => $reservation->getDurationMinutes(),
            ];
            foreach ($mainCoachesByTeam[$reservation->getTeamId() ?? ''] ?? [] as $coachId) {
                $byCoach[$coachId][] = $booking;
            }
        }

        return $byCoach;
    }

    private function startMinutesOf(Reservation $reservation): int
    {
        return (int) $reservation->getStartTime()->format('H') * 60 + (int) $reservation->getStartTime()->format('i');
    }

    /**
     * @return array{startMinutes: int, durationMinutes: int}
     */
    private function toBooking(Reservation $reservation): array
    {
        return ['startMinutes' => $this->startMinutesOf($reservation), 'durationMinutes' => $reservation->getDurationMinutes()];
    }
}
