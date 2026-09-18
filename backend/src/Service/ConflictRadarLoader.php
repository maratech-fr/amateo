<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\CoachPlayerMembership;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\FixtureHomeAway;
use App\Repository\ClubRepository;
use App\Repository\LeagueMatchWindowRepository;
use App\Repository\OpponentTravelRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * BCK-23 — la MAISON UNIQUE du radar de conflits d'un club. Charge tout via les
 * repositories mappés (les filtres Doctrine club+saison s'appliquent), résout
 * l'enveloppe ligue, les profils de durée par équipe et le trajet aller-retour par
 * rencontre, puis détecte. Rien n'est persisté (feed d'affichage recalculé à chaque
 * appel).
 *
 * Deux consommateurs se partageaient AUPARAVANT deux copies divergentes de ce corps :
 *  - {@see FixtureConflictsController} passait `profilesByTeam` + `roundTripByFixtureId`
 *    (le radar SPATIAL P2-54) ;
 *  - {@see MatchModuleDeltaComputer} ne les passait PAS — un conflit né UNIQUEMENT du
 *    trajet adverse existait dans le radar servi mais restait absent du jeu d'empreintes
 *    du delta, donc jamais compté comme « nouveau depuis ta dernière visite ».
 * Un seul chargeur supprime la divergence à la racine (garanti par MatchVisitDeltaParityTest).
 *
 * Le contrôleur décore ENSUITE en aval (fingerprint, opponentPlace, resolution) ; le
 * delta déduplique par empreinte. La détection, elle, est identique des deux côtés.
 */
final class ConflictRadarLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MatchConflictDetector $detector,
        private readonly TrainingCalendarContext $trainingCalendarContext,
        private readonly ClubRepository $clubRepository,
        private readonly LeagueMatchWindowRepository $leagueWindowRepository,
        private readonly LeagueEnvelopeResolver $envelopeResolver,
        private readonly MatchDurationResolver $matchDurationResolver,
        private readonly OpponentTravelRepository $opponentTravelRepository,
        private readonly ClubDay $clubDay,
        private readonly VenueLabelNormalizer $labelNormalizer,
    ) {}

    /**
     * Le radar courant du club+saison : la liste BRUTE des conflits (sans décoration)
     * et l'id de la version pointée de la saison (null = pas de calendrier pointé →
     * `MATCH_TRAINING` ne peut rien détecter hors période).
     *
     * @return array{conflicts: list<array<string, mixed>>, seasonScheduleId: string|null, fixtures: list<Fixture>}
     */
    public function conflicts(string $clubId, ?string $seasonId): array
    {
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([]);
        /** @var list<TeamCoach> $teamCoachRows */
        $teamCoachRows = $this->entityManager->getRepository(TeamCoach::class)->findBy([]);
        // Lot « une personne = ses équipes coachées + ses équipes où elle joue » —
        // les liens JOUEUR (CoachPlayerMembership) rejoignent les coachs dans la
        // carte personne→équipes du détecteur. Chargés sous les mêmes filtres tenant.
        /** @var list<CoachPlayerMembership> $playerMemberships */
        $playerMemberships = $this->entityManager->getRepository(CoachPlayerMembership::class)->findBy([]);
        /** @var list<VenueUnavailability> $unavailabilities */
        $unavailabilities = $this->entityManager->getRepository(VenueUnavailability::class)->findBy([]);
        /** @var list<TeamMatchHabit> $habits */
        $habits = $this->entityManager->getRepository(TeamMatchHabit::class)->findBy([]);
        /** @var list<TeamLink> $teamLinks */
        $teamLinks = $this->entityManager->getRepository(TeamLink::class)->findBy([]);
        /** @var list<VenueMatchWindow> $matchWindows */
        $matchWindows = $this->entityManager->getRepository(VenueMatchWindow::class)->findBy([]);
        // P1-4 PR E2 — the graded diagnostic needs the league envelope, resolved
        // by the SAME tolerant join as the solver (one implementation, PR D).
        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy([]);
        /** @var list<SportCategory> $categories */
        $categories = $this->entityManager->getRepository(SportCategory::class)->findBy([]);
        $club = $this->clubRepository->find($clubId);
        $league = $club?->getLeague();
        $envelope = $this->envelopeResolver->resolve($teams, $categories, $this->leagueWindowRepository->findEnvelopeForLeague($league));
        // D1 rule 3 — the club's civil today drops already-played matches from the
        // radar (foyer ClubDay, never rebuilt inline).
        $clubToday = $club instanceof Club ? $this->clubDay->todayFor($club) : null;
        // P2-54 RMM-9 — teamId → match duration profile: the category's own values
        // when set, else its family default (MatchDurationResolver). The detector
        // stays PURE (data injected), the resolution happens once, here.
        $categoriesById = [];
        foreach ($categories as $category) {
            $categoriesById[$category->getId()] = $category;
        }
        $profilesByTeam = [];
        foreach ($teams as $team) {
            $category = $categoriesById[$team->getSportCategoryId()] ?? null;
            if (null !== $category) {
                $profilesByTeam[$team->getId()] = $this->matchDurationResolver->resolve($category);
            }
        }
        // P1-4 PR F2 — severity 6 (completeness of PAIRED competitions).
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy([]);

        // ADR-0002 context (chosen season version, active periods + overlays,
        // slots) — shared with the unavailability impact (TrainingCalendarContext).
        $context = $this->trainingCalendarContext->load($seasonId);

        // P2-54 RMM-9 PR-3 — the SPATIAL radar: an AWAY fixture's footprint grows by
        // the round trip (2 × one-way car time) to the opponent's venue, read from
        // the tenant `opponent_travel` via the stamped organisme code. Grain ÉQUIPE
        // (P2-54 PR-1) : chaque rencontre résout son trajet par (code, libellé
        // normalisé) → override équipe, sinon défaut club. Une rencontre sans code /
        // sans trajet reste 0 (aucun conflit spatial — dit franchement).
        $roundTripByFixtureId = [];
        if (null !== $seasonId) {
            $travelBySeason = $this->opponentTravelRepository->travelMinutesBySeason($seasonId);
            if ([] !== $travelBySeason) {
                foreach ($fixtures as $fixture) {
                    $code = $fixture->getOpponentOrganismeCode();
                    if (FixtureHomeAway::AWAY !== $fixture->getHomeAway() || null === $code || !isset($travelBySeason[$code])) {
                        continue;
                    }
                    $teamKey = $this->labelNormalizer->normalize(trim($fixture->getOpponentLabel()));
                    $entry = $travelBySeason[$code];
                    $oneWay = $entry['teams'][$teamKey] ?? $entry['club'];
                    if (null !== $oneWay) {
                        $roundTripByFixtureId[$fixture->getId()] = 2 * $oneWay;
                    }
                }
            }
        }

        $conflicts = $this->detector->detect(
            $fixtures,
            $teamCoachRows,
            $context['seasonScheduleId'],
            $context['activePeriods'],
            $context['slotsBySchedule'],
            $unavailabilities,
            $habits,
            $teamLinks,
            $matchWindows,
            $envelope,
            $competitions,
            $profilesByTeam,
            $roundTripByFixtureId,
            $clubToday,
            $playerMemberships,
        );

        return [
            'conflicts' => $conflicts,
            'seasonScheduleId' => $context['seasonScheduleId'],
            'fixtures' => $fixtures,
        ];
    }
}
