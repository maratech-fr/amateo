<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\CoachPlayerMembership;
use App\Entity\Fixture;
use App\Entity\LeagueWindowInterface;
use App\Entity\MatchConstraint;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the engine `/place-matches` payload (contract 2.2, ADR-0003). The
 * backend PROJECTS, the engine stays flat:
 * - training occupancies are dated projections of the EFFECTIVE schedules
 *   (ADR-0002 rules via TrainingCalendarContext/EffectiveScheduleResolver —
 *   never re-implemented engine-side);
 * - away kickoffs are estimated by the SAME AwayKickoffEstimator the radar
 *   uses;
 * - teams carry their active shared PLAYERS (teams[].players, P4-240 ③) alongside
 *   coaches — a player is a person the solver protects like a MAIN coach; the
 *   coach role wins on the same team (parité MatchConflictDetector);
 * - the league envelope is resolved per team by LeagueEnvelopeResolver
 *   (tolerant join — unmapped team = no league HARD + an INFO diagnostic).
 *
 * Match kinds (a FRIENDLY = competitionId null is handled apart, below):
 * - competition HOME UNPLACED → TO_PLACE ;
 * - competition HOME PLACED by the SOLVER → TO_PLACE carrying its current
 *   placement (stability bonus + hint) ;
 * - competition HOME PLACED manually (or legacy null source), SUBMITTED,
 *   VALIDATED → FIXED anchors — IF they still carry venue+kickoff; a submitted
 *   match that lost its venue (DOC-2) is skipped: it can neither anchor nor be
 *   placed ;
 * - a FRIENDLY is NEVER handed to the solver (P4-193, founder 2026-09-10): a
 *   friendly plays whenever the club wants (a week night, a holiday, a
 *   match-less weekend) and its placement is FREE — the radar ALERTS on a match
 *   slot, it never blocks. So a friendly is never TO_PLACE: placed AND still
 *   anchored (venue+kickoff) → FIXED (its gym stays protected against the other
 *   matches, PLACED+SOLVER legacy included) ; UNPLACED or unanchored → absent
 *   from the payload (null) ;
 * - AWAY → informative footprint (real or estimated hour, else none).
 */
final class MatchPlacementPayloadBuilder
{
    /**
     * Version du CONTRAT backend⇄engine que ce payload s'attribue. Même contrat
     * que `/generate` (un seul contrat, 3 endpoints) : c'est la MÊME chose que
     * `engine/CONTRACT_VERSION`, comparée par l'engine au champ `version` reçu.
     * Elle DOIT valoir exactement la valeur du fichier — gardé par
     * `PayloadVersionMatchesContractVersionTest`.
     */
    public const string CONTRACT_VERSION = '1.0';

    /**
     * Borne du trajet aller-retour AWAY émis, alignée sur le schéma engine
     * (`match_input_schema.py`, `round_trip_minutes` `le=1440`). Un aller-simple IGN
     * aberrant (> 720 min) donnerait un aller-retour > 1440 qui ferait rejeter TOUT le
     * payload en 422 : on clampe ici pour dégrader proprement (empreinte plafonnée à 24 h).
     */
    private const int MAX_ROUND_TRIP_MINUTES = 1440;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrainingCalendarContext $trainingCalendarContext,
        private readonly AwayKickoffEstimator $awayKickoffEstimator,
        private readonly LeagueEnvelopeResolver $leagueEnvelopeResolver,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
        private readonly MatchDurationResolver $matchDurationResolver,
        private readonly OpponentTravelProjection $opponentTravelProjection,
    ) {}

    /**
     * @param array{from: string, to: string}|null $window Fenêtre calendaire (dates Y-m-d incluses)
     *                                                     restreignant les matchs À PLACER (« Placer ce
     *                                                     week-end », P4-240 ④). `null` = tout le club, à
     *                                                     l'octet comme avant. Dans la fenêtre : les
     *                                                     candidats TO_PLACE partent à placer ; HORS
     *                                                     fenêtre : un domicile déjà POSÉ (venue+kickoff,
     *                                                     SOLVER compris) devient une ANCRE FIXED (sa salle
     *                                                     reste protégée, il ne bouge pas), un domicile non
     *                                                     posé disparaît du payload, et un extérieur hors
     *                                                     fenêtre ne sert plus (son empreinte personne est
     *                                                     ignorée depuis ③, il ne portait que sa date).
     *
     * @return array{payload: array<string, mixed>, toPlaceCount: int, infoDiagnostics: list<array<string, mixed>>}
     */
    public function build(Club $club, ?string $seasonId, ?array $window = null): array
    {
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([]);
        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy([]);
        /** @var list<SportCategory> $categories */
        $categories = $this->entityManager->getRepository(SportCategory::class)->findBy([]);
        /** @var list<Venue> $venues */
        $venues = $this->entityManager->getRepository(Venue::class)->findBy([]);
        /** @var list<VenueMatchWindow> $matchWindows */
        $matchWindows = $this->entityManager->getRepository(VenueMatchWindow::class)->findBy([]);
        /** @var list<VenueUnavailability> $unavailabilities */
        $unavailabilities = $this->entityManager->getRepository(VenueUnavailability::class)->findBy([]);
        /** @var list<TeamMatchHabit> $habits */
        $habits = $this->entityManager->getRepository(TeamMatchHabit::class)->findBy([]);
        /** @var list<TeamLink> $teamLinks */
        $teamLinks = $this->entityManager->getRepository(TeamLink::class)->findBy([]);
        /** @var list<TeamCoach> $teamCoaches */
        $teamCoaches = $this->entityManager->getRepository(TeamCoach::class)->findBy([]);
        /** @var list<CoachPlayerMembership> $playerMemberships */
        $playerMemberships = $this->entityManager->getRepository(CoachPlayerMembership::class)->findBy([]);
        // P4-272 ① — la COPIE club de l'enveloppe ligue (scopée club+saison par les
        // filtres Doctrine). C'est la MAISON UNIQUE de lecture au placement : le
        // catalogue global ne sert plus qu'à semer cette copie. Copie VIDE = zéro
        // règle de ligue (aucun HARD, un seul diagnostic club plus bas).
        /** @var list<ClubLeagueWindow> $clubWindows */
        $clubWindows = $this->entityManager->getRepository(ClubLeagueWindow::class)->findBy([]);
        // P4-272 ③ — les RÈGLES DE MATCH du club (scope CLUB), émises en bloc
        // top-level `clubRules`. Une règle HARD est HONORÉE par le solveur (le
        // coup d'envoi doit tomber dans la fourchette les jours couverts), une
        // règle PREFERRED est une pénalité. Les amicaux en sont exemptés
        // (structurel : jamais confiés au solveur). Scopé club+saison par les
        // filtres Doctrine.
        /** @var list<MatchConstraint> $clubRules */
        $clubRules = $this->entityManager->getRepository(MatchConstraint::class)->findBy(['scope' => ConstraintScope::CLUB]);
        // P4-272 ④ — les INTERDICTIONS de gymnase par équipe (scope TEAM, toujours HARD).
        // Émises par équipe dans teams[].forbiddenVenueIds : le solveur retire ces gymnases
        // du domaine de l'équipe. Scopé club+saison par les filtres Doctrine.
        /** @var list<MatchConstraint> $teamVenueBans */
        $teamVenueBans = $this->entityManager->getRepository(MatchConstraint::class)->findBy(['scope' => ConstraintScope::TEAM]);
        $forbiddenVenuesByTeam = [];
        foreach ($teamVenueBans as $ban) {
            // Défensif : seule une règle HARD portant équipe + gymnase interdit compte
            // (le processeur n'écrit rien d'autre en scope TEAM).
            $teamId = $ban->getScopeTargetId();
            $venueId = $ban->getVenueId();
            if (ConstraintRuleType::HARD !== $ban->getRuleType() || null === $teamId || null === $venueId) {
                continue;
            }
            $forbiddenVenuesByTeam[$teamId][$venueId] = true;
        }
        // P4-272 ⑤ — les INDISPONIBILITÉS d'entraîneur (scope COACH, toujours SOFT). Émises
        // en bloc top-level `coachUnavailabilities` : le solveur pénalise (W_COACH_UNAVAILABLE)
        // un candidat dont le coup d'envoi tombe dans la plage d'un coach de l'équipe, sans
        // jamais bloquer. Scopé club+saison par les filtres Doctrine.
        /** @var list<MatchConstraint> $coachUnavailabilities */
        $coachUnavailabilities = $this->entityManager->getRepository(MatchConstraint::class)->findBy(['scope' => ConstraintScope::COACH]);
        $coachUnavailabilityRows = [];
        foreach ($coachUnavailabilities as $unavailability) {
            // Défensif : un coach est requis (le processeur l'exige) ; une ligne sans cible
            // ne porterait aucune indisponibilité exploitable.
            $coachId = $unavailability->getScopeTargetId();
            if (null === $coachId) {
                continue;
            }
            $coachUnavailabilityRows[] = [
                'coachId' => $coachId,
                'daysOfWeek' => $unavailability->getDaysOfWeek(),
                'kickoffMin' => $unavailability->getKickoffMin()?->format('H:i'),
                'kickoffMax' => $unavailability->getKickoffMax()?->format('H:i'),
            ];
        }

        $habitIndex = $this->awayKickoffEstimator->indexHabits($habits);

        // D3 — le trajet aller-retour par rencontre AWAY (2 × aller simple), projeté
        // par la MAISON UNIQUE partagée avec le radar. Envoyé au solveur pour qu'il
        // protège le coach PENDANT son déplacement (fenêtre AWAY étendue du trajet,
        // réplique de MatchFootprint). Absent = 0 (rien de modélisé).
        $roundTripByFixtureId = $this->opponentTravelProjection->roundTripByFixtureId($seasonId, $fixtures);

        // Matches.
        $toPlaceCount = 0;
        $matchRows = [];
        foreach ($fixtures as $fixture) {
            $row = $this->matchRow($fixture, $habitIndex, $roundTripByFixtureId[$fixture->getId()] ?? 0, $window);
            if (null === $row) {
                continue;
            }
            if ('TO_PLACE' === $row['kind']) {
                ++$toPlaceCount;
            }
            $matchRows[] = $row;
        }

        // Venues with their capacity data.
        $windowsByVenue = [];
        foreach ($matchWindows as $window) {
            $windowsByVenue[$window->getVenueId()][] = [
                'dayOfWeek' => $window->getDayOfWeek(),
                'start' => $window->getStartTime()->format('H:i'),
                'end' => $window->getEndTime()->format('H:i'),
            ];
        }
        $unavailabilitiesByVenue = [];
        foreach ($unavailabilities as $unavailability) {
            $unavailabilitiesByVenue[$unavailability->getVenueId()][] = [
                'startDate' => $unavailability->getStartDate()->format('Y-m-d'),
                'endDate' => $unavailability->getEndDate()->format('Y-m-d'),
            ];
        }
        $venueRows = [];
        foreach ($venues as $venue) {
            $venueRows[] = [
                'id' => $venue->getId(),
                'name' => $venue->getName(),
                'matchWindows' => $windowsByVenue[$venue->getId()] ?? [],
                'unavailabilities' => $unavailabilitiesByVenue[$venue->getId()] ?? [],
            ];
        }

        // Teams: league envelope (tolerant), habits, coaches, per-category
        // durations (P4-203 — resolved by MatchDurationResolver, the SAME service
        // the radar footprint uses).
        $envelope = $this->leagueEnvelopeResolver->resolve($teams, $categories, $clubWindows);
        $categoriesById = [];
        foreach ($categories as $category) {
            $categoriesById[$category->getId()] = $category;
        }
        // Toutes les habitudes voyagent (P4-271 : la chaîne rotation/suppléance est
        // supprimée). Le tag de semaine A/B NE VOYAGE PAS — le moteur voit une
        // habitude sans étiquette de semaine.
        $habitsByTeam = [];
        foreach ($habits as $habit) {
            $habitsByTeam[$habit->getTeamId()][] = [
                'dayOfWeek' => $habit->getDayOfWeek(),
                'kickoff' => $habit->getKickoffTime()->format('H:i'),
                'venueId' => $habit->getVenueId(),
            ];
        }
        $coachesByTeam = [];
        $coachIdSetByTeam = [];
        foreach ($teamCoaches as $link) {
            $coachesByTeam[$link->getTeamId()][] = [
                'coachId' => $link->getCoachId(),
                'role' => $link->getRole()->value,
            ];
            $coachIdSetByTeam[$link->getTeamId()][$link->getCoachId()] = true;
        }
        // P4-240 ③ — active shared players by team, MINUS anyone who ALSO coaches
        // the team (the coach role wins, parité MatchConflictDetector : émise UNE
        // fois côté coach, jamais en double malus). Person ids sorted so the payload
        // is deterministic (UUID-insensible). Feeds both teams[].players and
        // the training occupancies below.
        $playersByTeam = [];
        foreach ($playerMemberships as $membership) {
            if (!$membership->getIsActive()) {
                continue;
            }
            if (isset($coachIdSetByTeam[$membership->getTeamId()][$membership->getCoachId()])) {
                continue;
            }
            $playersByTeam[$membership->getTeamId()][$membership->getCoachId()] = true;
        }
        $playerIdsByTeam = [];
        foreach ($playersByTeam as $teamId => $personIds) {
            $ids = array_keys($personIds);
            sort($ids);
            $playerIdsByTeam[$teamId] = $ids;
        }
        $teamRows = [];
        $infoDiagnostics = [];
        foreach ($teams as $team) {
            $windows = $envelope[$team->getId()] ?? [];
            $category = $categoriesById[$team->getSportCategoryId()] ?? null;
            $profile = null !== $category
                ? $this->matchDurationResolver->resolve($category)
                : MatchDurationProfile::fallback();
            $teamRows[] = [
                'id' => $team->getId(),
                'name' => $team->getName(),
                'leagueWindows' => array_map(static fn (LeagueWindowInterface $w): array => [
                    'dayOfWeek' => $w->getDayOfWeek(),
                    'kickoffMin' => $w->getKickoffMin()->format('H:i'),
                    'kickoffMax' => $w->getKickoffMax()->format('H:i'),
                ], $windows),
                'habits' => $habitsByTeam[$team->getId()] ?? [],
                'coaches' => $coachesByTeam[$team->getId()] ?? [],
                'players' => $playerIdsByTeam[$team->getId()] ?? [],
                'matchMinutes' => $profile->matchMinutes,
                'warmupMinutes' => $profile->warmupMinutes,
                // P4-272 ④ — les gymnases INTERDITS à cette équipe (scope TEAM HARD). Le
                // solveur les retire de son domaine ; trié pour un payload déterministe.
                'forbiddenVenueIds' => $this->sortedKeys($forbiddenVenuesByTeam[$team->getId()] ?? []),
            ];
            // Copie NON vide mais équipe non mappée : diag PAR ÉQUIPE (« ta fenêtre
            // n'a pas trouvé preneuse »). Copie VIDE : on N'ÉMET PAS ce diag par
            // équipe — un seul diagnostic CLUB est posé après la boucle (décision
            // fondateur : pas un par équipe à chaque placement).
            if ([] === $windows && [] !== $clubWindows) {
                $infoDiagnostics[] = [
                    'type' => 'league_envelope_unresolved',
                    'severity' => 'info',
                    'teamId' => $team->getId(),
                    'message' => \sprintf(
                        'Équipe « %s » : enveloppe ligue non résolue — placement sans contrainte de fenêtre fédérale.',
                        $team->getName(),
                    ),
                ];
            }
        }

        // P4-272 ① — copie de ligue VIDE = le placement n'applique plus aucune
        // règle fédérale : UN seul diagnostic INFO pour tout le club (jamais un par
        // équipe). Le gestionnaire l'assume, comme une pose manuelle hors fenêtre.
        if ([] === $clubWindows) {
            $infoDiagnostics[] = [
                'type' => 'league_envelope_empty',
                'severity' => 'info',
                'message' => 'Aucune fenêtre de ligue définie — le placement n\'applique plus de règle fédérale.',
            ];
        }

        return [
            'payload' => [
                'version' => self::CONTRACT_VERSION,
                'clubId' => $club->getId(),
                'seasonId' => $seasonId ?? '',
                'solverSeed' => 42,
                'solverTimeoutSeconds' => 60,
                'matches' => $matchRows,
                'venues' => $venueRows,
                'teams' => $teamRows,
                'teamLinks' => array_map(static fn (TeamLink $link): array => [
                    'teamAId' => $link->getTeamAId(),
                    'teamBId' => $link->getTeamBId(),
                    'type' => $link->getLinkType()->value,
                ], $teamLinks),
                'trainingOccupancies' => $this->trainingOccupancies($fixtures, $seasonId, $teamCoaches, $playerIdsByTeam),
                // P4-272 ③ — bloc top-level : chaque règle porte son type, ses jours
                // ISO et sa fourchette de coup d'envoi (bornes nullables). Le solveur
                // fait respecter les HARD et pénalise les PREFERRED violées.
                'clubRules' => array_map(static fn (MatchConstraint $rule): array => [
                    'ruleType' => $rule->getRuleType()->value,
                    'daysOfWeek' => $rule->getDaysOfWeek(),
                    'kickoffMin' => $rule->getKickoffMin()?->format('H:i'),
                    'kickoffMax' => $rule->getKickoffMax()?->format('H:i'),
                ], $clubRules),
                // P4-272 ⑤ — bloc top-level : chaque indisponibilité porte son coach, ses
                // jours ISO et sa fourchette de coup d'envoi (bornes nullables). Le solveur
                // pénalise (SOFT) un candidat dans la plage pour un coach de l'équipe.
                'coachUnavailabilities' => $coachUnavailabilityRows,
            ],
            'toPlaceCount' => $toPlaceCount,
            'infoDiagnostics' => $infoDiagnostics,
        ];
    }

    /**
     * @param array<string, array<int, TeamMatchHabit>> $habitIndex
     * @param int                                       $roundTripMinutes D3 — trajet aller-retour AWAY (0 = inconnu / non AWAY)
     * @param array{from: string, to: string}|null      $window           P4-240 ④ — fenêtre de placement (dates Y-m-d incluses), null = tout le club
     *
     * @return array<string, mixed>|null null = skipped (unanchorable submitted match, or dropped out-of-window)
     */
    private function matchRow(Fixture $fixture, array $habitIndex, int $roundTripMinutes, ?array $window): ?array
    {
        $base = [
            'id' => $fixture->getId(),
            'teamId' => $fixture->getTeamId(),
            'date' => $fixture->getMatchDate()->format('Y-m-d'),
        ];

        // P4-240 ④ — dans la fenêtre ? (dates Y-m-d zero-paddées ⇒ comparaison
        // lexicographique = comparaison de dates). Fenêtre nulle = toujours dedans,
        // d'où un payload à l'octet identique à l'ancien comportement.
        $inWindow = null === $window || ($base['date'] >= $window['from'] && $base['date'] <= $window['to']);

        if (FixtureHomeAway::AWAY === $fixture->getHomeAway()) {
            // Depuis ③ le solveur IGNORE l'empreinte personne d'un extérieur ; il ne sert
            // plus qu'à porter SA date (team_dates : libérer la protection d'habitude le jour
            // où l'équipe est dehors). Hors fenêtre, cette date ne concerne aucun match à
            // placer → l'extérieur est inutile, on l'omet (aucun autre usage engine ne le
            // requiert : ni venues, ni teams, ni occupancies ne le lisent).
            if (!$inWindow) {
                return null;
            }
            $estimated = $this->awayKickoffEstimator->estimate($fixture, $habitIndex);
            $kickoff = $fixture->getKickoffTime() ?? $estimated;

            return $base + [
                'kind' => 'AWAY',
                'kickoff' => $kickoff?->format('H:i'),
                'kickoffEstimated' => !$fixture->getKickoffTime() instanceof DateTimeImmutable && $estimated instanceof DateTimeImmutable,
                // D3 — le trajet aller-retour vers l'adversaire (2 × aller simple, 0 si
                // inconnu), clampé à la borne du schéma engine (24 h). Le solveur étend la
                // fenêtre AWAY du coach de cette durée.
                'roundTripMinutes' => min($roundTripMinutes, self::MAX_ROUND_TRIP_MINUTES),
            ];
        }

        // A FRIENDLY (competitionId null) is NEVER handed to the solver (P4-193):
        // it is never TO_PLACE. It falls straight through to the anchor branch —
        // placed+anchored → FIXED (its gym stays protected), UNPLACED/unanchored
        // → null (absent). The PLACED+SOLVER legacy case is caught here too.
        $isFriendly = null === $fixture->getCompetitionId();

        $isSolverPlaced = FixtureStatus::PLACED === $fixture->getStatus()
            && FixturePlacementSource::SOLVER === $fixture->getPlacementSource();
        // TO_PLACE seulement DANS la fenêtre (P4-240 ④). Hors fenêtre, le fixture tombe
        // dans la branche d'ancrage ci-dessous : déjà posé (venue+kickoff, SOLVER compris)
        // → FIXED (ancre, sa salle protégée, il ne bouge pas — le résultat ne le réécrit
        // pas, il n'est jamais renvoyé par le moteur) ; non posé → null (absent du payload).
        if (!$isFriendly && $inWindow && (FixtureStatus::UNPLACED === $fixture->getStatus() || $isSolverPlaced)) {
            return $base + [
                'kind' => 'TO_PLACE',
                'currentVenueId' => $isSolverPlaced ? $fixture->getVenueId() : null,
                'currentKickoff' => $isSolverPlaced ? $fixture->getKickoffTime()?->format('H:i') : null,
            ];
        }

        // Anchors — competition matches placed manually / submitted / validated,
        // and ANY placed friendly — only if still fully anchored (a match whose
        // venue was deleted, DOC-2, can do neither; a friendly UNPLACED lands
        // here and is skipped). P4-240 ④ : a to-place candidate OUT of the window
        // reaches here too — placed (venue+kickoff) → FIXED anchor, unplaced → null.
        if (null === $fixture->getVenueId() || !$fixture->getKickoffTime() instanceof DateTimeImmutable) {
            return null;
        }

        return $base + [
            'kind' => 'FIXED',
            'venueId' => $fixture->getVenueId(),
            'kickoff' => $fixture->getKickoffTime()->format('H:i'),
        ];
    }

    /**
     * Dated projection of the training sessions on every date the horizon
     * touches — one occupancy per (slot, person). The slot's own coach when set,
     * else every coach of the slot's team (the radar's exact rule), PLUS the
     * slot's team's active players in EVERY case (P4-240 ③ : an assigned coach
     * replaces the other COACHES, never the players — parité MatchConflictDetector).
     * The `coachId` field carries any PERSON id (a player rides the same field).
     *
     * @param list<Fixture>               $fixtures
     * @param list<TeamCoach>             $teamCoaches
     * @param array<string, list<string>> $playerIdsByTeam teamId → active player person ids (coach-excluded)
     *
     * @return list<array<string, string>>
     */
    private function trainingOccupancies(array $fixtures, ?string $seasonId, array $teamCoaches, array $playerIdsByTeam): array
    {
        $context = $this->trainingCalendarContext->load($seasonId);
        $coachesByTeam = [];
        foreach ($teamCoaches as $link) {
            $coachesByTeam[$link->getTeamId()][] = $link->getCoachId();
        }

        $dates = [];
        foreach ($fixtures as $fixture) {
            $dates[$fixture->getMatchDate()->format('Y-m-d')] = $fixture->getMatchDate();
        }

        $occupancies = [];
        foreach ($dates as $dateKey => $date) {
            $scheduleId = $this->effectiveScheduleResolver->resolve(
                $date,
                $context['activePeriods'],
                $context['seasonScheduleId'],
            );
            if (null === $scheduleId) {
                continue;
            }
            $isoWeekday = (int) $date->format('N');
            foreach ($context['slotsBySchedule'][$scheduleId] ?? [] as $slot) {
                if ($slot->getDayOfWeek() !== $isoWeekday) {
                    continue;
                }
                $start = $slot->getStartTime()->format('H:i');
                $end = $slot->getStartTime()->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'))->format('H:i');
                $slotCoach = $slot->getCoachId();
                $coachIds = null !== $slotCoach ? [$slotCoach] : ($coachesByTeam[$slot->getTeamId()] ?? []);
                // Coaches (assigned or all) PLUS the team's active players — a player
                // is held by his team's training too (P4-240 ③, parité radar). Deduped
                // so an id never yields two occupancies.
                $personIds = array_values(array_unique([
                    ...$coachIds,
                    ...($playerIdsByTeam[$slot->getTeamId()] ?? []),
                ]));
                foreach ($personIds as $personId) {
                    $occupancies[] = ['date' => $dateKey, 'start' => $start, 'end' => $end, 'coachId' => $personId];
                }
            }
        }

        return $occupancies;
    }

    /**
     * Les clés d'un set (valeurs `true`), triées — pour un payload déterministe
     * insensible à l'ordre d'insertion des uuid.
     *
     * @param array<string, true> $set
     *
     * @return list<string>
     */
    private function sortedKeys(array $set): array
    {
        $keys = array_keys($set);
        sort($keys);

        return $keys;
    }
}
