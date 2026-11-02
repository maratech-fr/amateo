<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\PlaceMatchesController;
use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\CoachPlayerMembership;
use App\Entity\Fixture;
use App\Entity\LeagueWindowInterface;
use App\Entity\MatchConstraint;
use App\Entity\Season;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\ConflictPersonRole;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Service\Conflicts\DayHoldings;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the engine `/place-matches` payload (contract 1.3, ADR-0003). The
 * backend PROJECTS, the engine stays flat:
 * - training occupancies say who each session of the EFFECTIVE schedule retains on a date carrying a
 *   match to place (ADR-0002 rules via TrainingCalendarContext/EffectiveScheduleResolver —
 *   never re-implemented engine-side): its holder of the day and its players;
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
 * - AWAY → informative footprint (real or estimated hour, else none). The solver IGNORES an
 *   AWAY match's person footprint entirely (P4-240 ③ décision B), so neither its estimated-hour
 *   flag nor its round-trip travel is sent (contract 1.3, ALIGN-20) — the conflict radar computes
 *   both from the entity, backend-side.
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
    public const string CONTRACT_VERSION = '1.9';

    /**
     * Budget du solveur PAR SEMAINE ISO (secondes). Depuis ENG-50 le moteur découpe la
     * demande semaine par semaine et résout chacune sous ce budget (une seule réponse
     * fusionnée) — c'est donc le budget d'UNE semaine, pas de tout le run. Le rail
     * asynchrone s'en sert pour dimensionner le TTL du verrou et le timeout HTTP du worker
     * (nb de semaines × ce budget + marge, {@see PlaceMatchesController}).
     */
    public const int WEEK_BUDGET_SECONDS = 35;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrainingCalendarContext $trainingCalendarContext,
        private readonly AwayKickoffEstimator $awayKickoffEstimator,
        private readonly LeagueEnvelopeResolver $leagueEnvelopeResolver,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
        private readonly MatchDurationResolver $matchDurationResolver,
        private readonly PlanVenueClosures $planVenueClosures,
        private readonly ClubPublicHolidays $clubPublicHolidays,
        private readonly MatchConflictDetector $conflictDetector,
        private readonly OpponentTravelProjection $opponentTravelProjection,
        private readonly PlanHoldingInputs $planHoldingInputs,
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
        // L'ordre de lien départage qui tient une séance ou un match, ici et côté moteur.
        /** @var list<TeamCoach> $teamCoaches */
        $teamCoaches = $this->entityManager->getRepository(TeamCoach::class)->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);
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

        // PR2 — les dates FÉRIÉES du territoire du club dans la fenêtre de saison. Émises
        // top-level `publicHolidays` : pour une date fériée le moteur utilise la ligne « Jours
        // fériés » du gymnase (pas la fenêtre du jour de semaine) et ignore la fenêtre de ligue du
        // jour de semaine (la ligue a posé ce jour exprès, décision fondateur 2026-10-27). Pas de
        // saison = aucune fenêtre = aucun férié.
        $season = null !== $seasonId ? $this->entityManager->getRepository(Season::class)->find($seasonId) : null;
        $publicHolidays = $season instanceof Season
            ? $this->clubPublicHolidays->datesInWindow($club->getId(), $season->getStartDate(), $season->getEndDate())
            : [];

        $habitIndex = $this->awayKickoffEstimator->indexHabits($habits);

        // Matches.
        $toPlaceCount = 0;
        $matchRows = [];
        foreach ($fixtures as $fixture) {
            $row = $this->matchRow($fixture, $habitIndex, $window);
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
        // Une ouverture datée ne part que si sa date porte un match à placer, fenêtre du run ou pas :
        // la saison peut en compter plus que le plafond moteur par gymnase. Le motif ne part jamais.
        $datesToPlace = array_column(array_filter($matchRows, static fn (array $row): bool => 'TO_PLACE' === $row['kind']), 'date');
        $datedWindows = [];
        foreach ($matchWindows as $window) {
            if ($window->getOpeningDate() instanceof DateTimeImmutable) {
                $datedWindows[] = $window;

                continue;
            }
            // PR2 — chaque fenêtre porte `appliesOnPublicHolidays` (additif) ; la ligne « Jours
            // fériés » a un `dayOfWeek` NULL + ce drapeau à true, une ligne de jour de semaine le
            // porte à false. Le moteur choisit la fenêtre à lire selon que la date du match est fériée.
            $windowsByVenue[$window->getVenueId()][] = [
                'dayOfWeek' => $window->getDayOfWeek(),
                'start' => $window->getStartTime()->format('H:i'),
                'end' => $window->getEndTime()->format('H:i'),
                'appliesOnPublicHolidays' => $window->isAppliesOnPublicHolidays(),
            ];
        }
        foreach ($datedWindows as $window) {
            $openingDate = $window->getOpeningDate()?->format('Y-m-d');
            if (!\in_array($openingDate, $datesToPlace, true)) {
                continue;
            }
            $windowsByVenue[$window->getVenueId()][] = [
                'dayOfWeek' => null,
                'start' => $window->getStartTime()->format('H:i'),
                'end' => $window->getEndTime()->format('H:i'),
                'appliesOnPublicHolidays' => false,
                'openingDate' => $openingDate,
            ];
        }
        $unavailabilitiesByVenue = [];
        foreach ($unavailabilities as $unavailability) {
            $unavailabilitiesByVenue[$unavailability->getVenueId()][] = [
                'startDate' => $unavailability->getStartDate()->format('Y-m-d'),
                'endDate' => $unavailability->getEndDate()->format('Y-m-d'),
            ];
        }
        // P4-300 — une FERMETURE de gymnase du calendrier (`venue_closed`) s'applique AUSSI aux
        // matchs (décision fondateur D1/D2) : on FUSIONNE son intervalle brut dans le MÊME bloc
        // `venues[].unavailabilities` que l'engine lit déjà — contrat 1.2 inchangé, zéro code
        // moteur. Le fait brut vient de `PlanVenueClosures` (config, repli legacy), jamais de la
        // composition `VenuePeriodOverride`. Pas de saison sélectionnée = aucune fermeture à lire.
        if (null !== $seasonId) {
            foreach ($this->planVenueClosures->closureIntervals($club->getId(), $seasonId) as $closure) {
                $unavailabilitiesByVenue[$closure['venueId']][] = [
                    'startDate' => $closure['startDate'],
                    'endDate' => $closure['endDate'],
                ];
            }
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
                // Budget PAR SEMAINE ISO : depuis ENG-50 le moteur découpe la demande semaine
                // par semaine et résout chacune sous ce budget (une seule réponse fusionnée).
                'solverTimeoutSeconds' => self::WEEK_BUDGET_SECONDS,
                'matches' => $matchRows,
                'venues' => $venueRows,
                'teams' => $teamRows,
                'teamLinks' => array_map(static fn (TeamLink $link): array => [
                    'teamAId' => $link->getTeamAId(),
                    'teamBId' => $link->getTeamBId(),
                    'type' => $link->getLinkType()->value,
                ], $teamLinks),
                'trainingOccupancies' => $this->trainingOccupancies($club->getId(), $fixtures, $seasonId, $matchRows, $habitIndex, $categoriesById, $teams, $teamCoaches, $playerIdsByTeam, $coachUnavailabilityRows, $publicHolidays),
                // PR2 — dates fériées du club : le moteur les lit pour juger/placer un match férié
                // contre la ligne « Jours fériés » (jamais la fenêtre du jour de semaine ni la ligue).
                'publicHolidays' => $publicHolidays,
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
     * @param array{from: string, to: string}|null      $window     P4-240 ④ — fenêtre de placement (dates Y-m-d incluses), null = tout le club
     *
     * @return array<string, mixed>|null null = skipped (unanchorable submitted match, or dropped out-of-window)
     */
    private function matchRow(Fixture $fixture, array $habitIndex, ?array $window): ?array
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

        // Un domicile sur TERRAIN NEUTRE (« pas chez nous ») n'est jamais confié au solveur
        // comme un match à placer : il voyage comme un extérieur (il ne porte que SA date,
        // team_dates), jamais TO_PLACE ni FIXED. Le contrat engine reste inchangé (1.5).
        if (FixtureHomeAway::AWAY === $fixture->getHomeAway()
            || FixtureUnplacedReason::NEUTRAL_GROUND === $fixture->getUnplacedReason()) {
            // Depuis ③ le solveur IGNORE l'empreinte personne d'un extérieur ; il ne sert
            // plus qu'à porter SA date (team_dates : libérer la protection d'habitude le jour
            // où l'équipe est dehors). Hors fenêtre, cette date ne concerne aucun match à
            // placer → l'extérieur est inutile, on l'omet (aucun autre usage engine ne le
            // requiert : ni venues, ni teams, ni occupancies ne le lisent).
            if (!$inWindow) {
                return null;
            }
            $kickoff = $fixture->getKickoffTime() ?? $this->awayKickoffEstimator->estimate($fixture, $habitIndex);

            // Contrat 1.3 (ALIGN-20) — ni `kickoffEstimated` ni `roundTripMinutes` : le solveur
            // IGNORE l'empreinte personne d'un extérieur depuis P4-240 ③ (décision B), il n'a donc
            // ni heure à pondérer ni trajet à porter. Le radar de conflits les recalcule depuis
            // l'entité, hors payload. L'AWAY ne sert plus qu'à porter SA date (team_dates).
            return $base + [
                'kind' => 'AWAY',
                'kickoff' => $kickoff?->format('H:i'),
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
            $row = $base + [
                'kind' => 'TO_PLACE',
                'currentVenueId' => $isSolverPlaced ? $fixture->getVenueId() : null,
                'currentKickoff' => $isSolverPlaced ? $fixture->getKickoffTime()?->format('H:i') : null,
            ];
            // Contrat 1.9 — un match importé confie sa position au moteur, qui la garde si elle est
            // légale. Valeurs de l'entité : un match déjà posé par le solveur et resté légal n'est donc
            // pas réoptimisé. Les deux ou rien : une heure sans gymnase n'est pas une position.
            $importedKickoff = $fixture->getKickoffTime();
            if (null !== $fixture->getExternalRef() && null !== $fixture->getVenueId() && $importedKickoff instanceof DateTimeImmutable) {
                $row['importedVenueId'] = $fixture->getVenueId();
                $row['importedKickoff'] = $importedKickoff->format('H:i');
            }

            return $row;
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
     * Qui les séances retiennent, sur chaque date qui porte un match à placer : le tenant du jour et les
     * joueurs de l'équipe. Une séance que personne ne peut tenir retient tous ses candidats, ceux qu'une
     * indisponibilité d'entraînement n'a pas retirés. Une séance dont l'équipe joue un match figé ou un
     * extérieur n'a pas lieu et ne retient personne ; face à un match À PLACER de son équipe elle retient
     * encore, l'occupation ne portant pas d'équipe. Le champ `coachId` porte un id de personne.
     *
     * Les matchs figés et les extérieurs du jour prennent leur tenant d'abord (mêmes fenêtres que le
     * radar, {@see DayMatches}) ; à candidats égaux, celui qui n'a pas de match à placer tient. Un jour
     * férié n'a pas de séance.
     *
     * @param list<Fixture>                                                                                         $fixtures
     * @param list<array<string, mixed>>                                                                            $matchRows
     * @param array<string, array<int, TeamMatchHabit>>                                                             $habitIndex
     * @param array<string, SportCategory>                                                                          $categoriesById
     * @param list<Team>                                                                                            $teams
     * @param list<TeamCoach>                                                                                       $teamCoaches
     * @param array<string, list<string>>                                                                           $playerIdsByTeam         teamId → active player person ids (coach-excluded)
     * @param list<array{coachId: string, daysOfWeek: list<int>, kickoffMin: string|null, kickoffMax: string|null}> $coachUnavailabilityRows
     * @param list<string>                                                                                          $publicHolidays          dates fériées (Y-m-d)
     *
     * @return list<array<string, string>>
     */
    private function trainingOccupancies(string $clubId, array $fixtures, ?string $seasonId, array $matchRows, array $habitIndex, array $categoriesById, array $teams, array $teamCoaches, array $playerIdsByTeam, array $coachUnavailabilityRows, array $publicHolidays): array
    {
        $toPlaceRows = array_filter($matchRows, static fn (array $row): bool => 'TO_PLACE' === $row['kind']);
        if ([] === $toPlaceRows) {
            return [];
        }

        $roleByTeamPerson = [];
        foreach ($teamCoaches as $link) {
            $role = ConflictPersonRole::from($link->getRole()->value);
            if (ConflictPersonRole::MAIN !== ($roleByTeamPerson[$link->getTeamId()][$link->getCoachId()] ?? null)) {
                $roleByTeamPerson[$link->getTeamId()][$link->getCoachId()] = $role;
            }
        }
        foreach ($playerIdsByTeam as $teamId => $personIds) {
            foreach ($personIds as $personId) {
                $roleByTeamPerson[$teamId][$personId] = ConflictPersonRole::PLAYER;
            }
        }

        $busyToPlaceByDate = [];
        $toPlaceIds = [];
        foreach ($toPlaceRows as $row) {
            $toPlaceIds[$row['id']] = true;
            foreach (array_keys($roleByTeamPerson[$row['teamId']] ?? []) as $personId) {
                $busyToPlaceByDate[$row['date']][$personId] = true;
            }
        }

        $profilesByTeam = [];
        foreach ($teams as $team) {
            $category = $categoriesById[$team->getSportCategoryId()] ?? null;
            if (null !== $category) {
                $profilesByTeam[$team->getId()] = $this->matchDurationResolver->resolve($category);
            }
        }
        $roundTrips = $this->opponentTravelProjection->roundTripByFixtureId($seasonId, $fixtures);
        $views = [];
        foreach ($fixtures as $fixture) {
            $personIds = array_map(strval(...), array_keys($roleByTeamPerson[$fixture->getTeamId()] ?? []));
            if (isset($toPlaceIds[$fixture->getId()]) || [] === $personIds) {
                continue;
            }
            $view = $this->conflictDetector->fixtureView($fixture, $profilesByTeam, $roundTrips, $habitIndex, $personIds);
            if (null !== $view) {
                $views[] = $view;
            }
        }

        $unavailabilities = [];
        foreach ($coachUnavailabilityRows as $row) {
            foreach ($row['daysOfWeek'] as $dayOfWeek) {
                $unavailabilities[] = ['coachId' => $row['coachId'], 'dayOfWeek' => $dayOfWeek, 'kickoffMin' => $row['kickoffMin'], 'kickoffMax' => $row['kickoffMax']];
            }
        }

        $context = $this->trainingCalendarContext->load($seasonId);
        [$wizardBlocked, $groups] = $this->planHoldingInputs->load($clubId, $seasonId, array_keys($context['slotsBySchedule']));
        $holdings = new DayHoldings($views, $roleByTeamPerson, $this->effectiveScheduleResolver, $context['seasonScheduleId'], $context['activePeriods'], $context['slotsBySchedule'], $unavailabilities, $publicHolidays, $wizardBlocked, $groups, $busyToPlaceByDate);

        $occupancies = [];
        foreach (array_unique(array_column($toPlaceRows, 'date')) as $dateKey) {
            foreach ($holdings->sessionsOn(new DateTimeImmutable($dateKey)) as $session) {
                $coachIds = null !== $session['holder'] ? [$session['holder']] : $session['candidates'];
                foreach (array_unique([...$coachIds, ...$session['players']]) as $personId) {
                    $occupancies[] = [
                        'date' => $dateKey,
                        'start' => $this->clockTime($session['startMinutes']),
                        'end' => $this->clockTime($session['endMinutes']),
                        'coachId' => $personId,
                    ];
                }
            }
        }

        return $occupancies;
    }

    private function clockTime(int $minutes): string
    {
        return \sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
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
