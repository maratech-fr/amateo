<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Repository\FixtureRepository;
use App\Service\Basketball\FbiDivisionSignature;
use App\Service\ClubDay;
use App\Service\OpponentTravelProjection;
use App\Storage\LogoStorage;
use App\Support\FrenchNameOrder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use finfo;
use RuntimeException;

/**
 * Ce que le PDF des matchs dit d'un match, décidé ici et nulle part ailleurs : une heure n'est
 * « à confirmer » que si elle peut encore bouger, un lieu n'est imprimé que s'il est certain.
 *
 * La lecture est bornée par la saison (`findBySeasonBetween`) : une plage de dix ans ne lit pas
 * plus qu'une saison de rencontres.
 */
final readonly class MatchesExportDataProvider
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FixtureRepository $fixtureRepository,
        private OpponentTravelProjection $travelProjection,
        private FbiDivisionSignature $divisionSignature,
        private ClubDay $clubDay,
        private LogoStorage $logoStorage,
    ) {}

    public function load(string $clubId, Season $season, DateTimeImmutable $from, DateTimeImmutable $to, ?Team $team): MatchesExportData
    {
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            throw new RuntimeException('Club introuvable.');
        }
        $today = $this->clubDay->todayFor($club);
        $seasonId = $season->getId();

        $fixtures = $this->fixtureRepository->findBySeasonBetween($seasonId, $from, $to, $team?->getId());
        $friendlyCompetitionIds = $this->friendlyCompetitionIds($seasonId);
        $venues = $this->venuesById($clubId, $seasonId);
        $linkedPlaces = $this->travelProjection->linkedPlaceByFixtureId($fixtures);
        $travels = $this->travelProjection->awayTravelByFixtureId($seasonId, $fixtures);

        $hasTravel = false;
        /** @var array<string, list<Fixture>> $fixturesByTeam */
        $fixturesByTeam = [];
        foreach ($fixtures as $fixture) {
            $fixturesByTeam[$fixture->getTeamId()][] = $fixture;
        }

        $sections = [];
        foreach ($this->teamsToPrint($clubId, $seasonId, $team) as $printed) {
            $teamFixtures = $fixturesByTeam[$printed->getId()] ?? [];
            usort($teamFixtures, $this->compareByDateThenKickoff(...));

            $rows = [];
            foreach ($teamFixtures as $fixture) {
                $where = $this->whereOf($fixture);
                $competitionId = $fixture->getCompetitionId();
                $friendly = null === $competitionId || isset($friendlyCompetitionIds[$competitionId]);
                $place = match ($where) {
                    'home' => $this->homePlace($fixture, $venues),
                    'away' => $this->awayPlace($linkedPlaces[$fixture->getId()] ?? null, $travels[$fixture->getId()]['oneWayMinutes'] ?? null),
                    default => null,
                };
                $hasTravel = $hasTravel || null !== $place?->travelMinutes;

                $rows[] = new MatchesExportRow(
                    date: $fixture->getMatchDate(),
                    kickoff: $fixture->getKickoffTime()?->format('H:i'),
                    toConfirm: $this->isToConfirm($fixture, $where, $friendly, $today),
                    opponent: $fixture->getOpponentLabel(),
                    friendly: $friendly,
                    where: $where,
                    place: $place,
                );
            }
            $sections[] = new MatchesExportTeamSection(teamName: $printed->getName(), rows: $rows);
        }

        return new MatchesExportData(
            clubId: $clubId,
            clubName: $club->getName(),
            logoDataUri: $this->logoDataUri($clubId),
            from: $from,
            to: $to,
            editedOn: $today,
            teamScopeName: $team?->getName(),
            hasTravel: $hasTravel,
            sections: $sections,
        );
    }

    private function compareByDateThenKickoff(Fixture $a, Fixture $b): int
    {
        $byDate = $a->getMatchDate()->format('Y-m-d') <=> $b->getMatchDate()->format('Y-m-d');
        if (0 !== $byDate) {
            return $byDate;
        }

        // Une heure inconnue passe après toutes les heures connues.
        return [!$a->getKickoffTime() instanceof DateTimeImmutable, $a->getKickoffTime()?->format('H:i')] <=> [!$b->getKickoffTime() instanceof DateTimeImmutable, $b->getKickoffTime()?->format('H:i')];
    }

    /**
     * Heure absente : toujours à confirmer. Match déjà joué : jamais, même avec un écart fédéral
     * resté ouvert — le passé prime. Sinon : un écart ouvert remet tout en question, même validé ;
     * et un domicile de championnat le reste tant que le gestionnaire ne l'a pas validé. Un amical
     * n'a pas de ligue pour le confirmer ; l'heure d'un extérieur ou d'un terrain neutre vient de
     * la fédération, le club ne peut rien y valider.
     *
     * @param 'home'|'away'|'neutral' $where
     */
    private function isToConfirm(Fixture $fixture, string $where, bool $friendly, DateTimeImmutable $today): bool
    {
        if (!$fixture->getKickoffTime() instanceof DateTimeImmutable) {
            return true;
        }
        if ($fixture->getMatchDate()->format('Y-m-d') < $today->format('Y-m-d')) {
            return false;
        }
        if ($fixture->hasPendingDeviations()) {
            return true;
        }

        return 'home' === $where && !$friendly && FixtureStatus::VALIDATED !== $fixture->getStatus();
    }

    /** @return 'home'|'away'|'neutral' */
    private function whereOf(Fixture $fixture): string
    {
        if (FixtureUnplacedReason::NEUTRAL_GROUND === $fixture->getUnplacedReason()) {
            return 'neutral';
        }

        return FixtureHomeAway::AWAY === $fixture->getHomeAway() ? 'away' : 'home';
    }

    /** @param array<string, Venue> $venues */
    private function homePlace(Fixture $fixture, array $venues): ?MatchesExportPlace
    {
        $venueId = $fixture->getVenueId();
        $venue = null === $venueId ? null : ($venues[$venueId] ?? null);
        if (!$venue instanceof Venue) {
            return null;
        }

        return new MatchesExportPlace(name: $venue->getName(), address: $venue->getAddress(), travelMinutes: null);
    }

    /** @param array{venueLabel: string, address: ?string, postalCode: ?string, city: ?string}|null $link */
    private function awayPlace(?array $link, ?int $oneWayMinutes): ?MatchesExportPlace
    {
        if (null === $link) {
            return null;
        }
        $locality = trim(($link['postalCode'] ?? '') . ' ' . ($link['city'] ?? ''));
        $parts = array_filter([$link['address'], $locality], static fn (?string $part): bool => null !== $part && '' !== $part);

        return new MatchesExportPlace(
            name: $link['venueLabel'],
            address: [] === $parts ? null : implode(', ', $parts),
            travelMinutes: $oneWayMinutes,
        );
    }

    /**
     * Rang, puis position dans le rang, puis nom en ordre français : le rang vu à l'écran. Tout le
     * club : les seules équipes qui ont un match dans la saison. Une équipe demandée sort toujours.
     *
     * @return list<Team>
     */
    private function teamsToPrint(string $clubId, string $seasonId, ?Team $team): array
    {
        if ($team instanceof Team) {
            return [$team];
        }
        $withMatches = array_flip($this->fixtureRepository->findTeamIdsWithFixtures($seasonId));
        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        $teams = array_values(array_filter($teams, static fn (Team $candidate): bool => isset($withMatches[$candidate->getId()])));
        usort($teams, static fn (Team $a, Team $b): int => [$a->getPriorityTierId(), $a->getTierOrder()] <=> [$b->getPriorityTierId(), $b->getTierOrder()]
            ?: FrenchNameOrder::compare($a->getName(), $b->getName()));

        return $teams;
    }

    /** @return array<string, true> */
    private function friendlyCompetitionIds(string $seasonId): array
    {
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy(['seasonId' => $seasonId]);

        $ids = [];
        foreach ($competitions as $competition) {
            if ($this->divisionSignature->isFriendlyCode($competition->getName())) {
                $ids[$competition->getId()] = true;
            }
        }

        return $ids;
    }

    /** @return array<string, Venue> */
    private function venuesById(string $clubId, string $seasonId): array
    {
        /** @var list<Venue> $venues */
        $venues = $this->entityManager->getRepository(Venue::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);

        $byId = [];
        foreach ($venues as $venue) {
            $byId[$venue->getId()] = $venue;
        }

        return $byId;
    }

    /** Inliné en data URI : le worker ne fait aucun appel réseau, le document reste auto-portant. */
    private function logoDataUri(string $clubId): ?string
    {
        $bytes = $this->logoStorage->read($clubId);
        if (null === $bytes || '' === $bytes) {
            return null;
        }
        $mime = new finfo(\FILEINFO_MIME_TYPE)->buffer($bytes) ?: 'image/png';

        return \sprintf('data:%s;base64,%s', $mime, base64_encode($bytes));
    }
}
