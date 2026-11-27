<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\VenueMatchWindow;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Matches\CompetitionRegistry;
use App\Matches\FriendlyCompetitions;
use App\Plans\PlanComposition;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/**
 * Le filet des matchs (lot 7), posé sur les matchs du décor des demandes au moteur (références 16 à 19) :
 *
 *  16. deux équipes dans la même division : deux compétitions « DF2 » de même nom, une par équipe, un
 *      domicile à placer chacune ; U13 F résout la fenêtre de ligue du samedi, U13 F2 aucune ;
 *  17. les amicaux : un amical de « Amicaux » placé à la main (ancre), un autre non placé (absent),
 *      une compétition « Amical PNF » amicale (PR 4a) avec un domicile non placé (absent), un domicile
 *      posé par le système (ancre) et un extérieur ;
 *  18. le suivi fédéral : domicile saisi avec gymnase et heure, validé posé par le système, saisi sans
 *      gymnase, validé sans gymnase, extérieur saisi, extérieur validé sans gymnase, domicile importé
 *      avec heure et gymnase ;
 *  19. les coupes : U13 F (fenêtre de ligue le samedi seulement) a un domicile de coupe non placé un
 *      samedi, un non placé un mercredi, un posé par le système, un posé à la main, un extérieur ;
 *      U15 M (aucune fenêtre de ligue) un domicile de coupe non placé un mercredi ; une « Coupe … »
 *      rangée en championnat porte un domicile non placé.
 *
 * Chaque décor a ses témoins (`assertMatchesNetWitnesses…`), lus en base AVANT la composition : un
 * témoin absent fait échouer le test — une référence dont le décor ne porte plus son cas ne prouve rien.
 *
 * S'emploie AVEC `EngineRequestDecorTrait` et après `seedEngineRequestMatches()` (il en reprend les
 * équipes, les gymnases, la fenêtre de ligue U13 / Départemental / F du samedi 13:00–18:00).
 * Consommé par `Integration/EngineRequestReferenceTest`.
 */
trait EngineRequestMatchesNetDecorTrait
{
    private const string ER_NET_CATEGORY_U13 = 'ca7e0000-0000-4000-8000-000000000016';
    private const string ER_NET_TEAM_U13_F2 = '7ea00000-0000-4000-8000-000000000016';

    /** U13 F passe dans une catégorie « U13 », niveau départemental, féminine : elle résout la fenêtre de ligue du samedi. */
    private function giveU13ItsLeagueWindow(): void
    {
        $em = $this->erEm();
        $reference = $em->find(SportCategory::class, self::ER_CATEGORY);
        Assert::assertInstanceOf(SportCategory::class, $reference);
        $em->persist((new SportCategory)->setId(self::ER_NET_CATEGORY_U13)->setClubId(self::ER_CLUB)
            ->setSportId($reference->getSportId())->setName('U13')->setMatchMinutes(80)->setWarmupMinutes(30));
        $team = $em->find(Team::class, self::ER_TEAM_U13);
        Assert::assertInstanceOf(Team::class, $team);
        $team->setSportCategoryId(self::ER_NET_CATEGORY_U13)->setLevel(TeamLevel::DEPARTEMENTAL)->setGender(Gender::F);
        $em->flush();
    }

    /** Référence 16 : deux équipes dans une division importée. */
    private function seedMatchesNetTwoTeamsOneDivision(): void
    {
        $this->giveU13ItsLeagueWindow();
        $em = $this->erEm();
        // Seconde équipe de la division : même catégorie, niveau connu, sexe inconnu (aucune fenêtre ne la résout).
        $em->persist((new Team)->setId(self::ER_NET_TEAM_U13_F2)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setSportCategoryId(self::ER_NET_CATEGORY_U13)->setPriorityTierId(3)->setName('U13 F2')
            ->setLevel(TeamLevel::DEPARTEMENTAL)->setIsActive(true));
        $em->flush();
        static::getContainer()->get(PlanComposition::class)->ensureLines(self::ER_SEASON);

        $this->netCompetition(161, self::ER_TEAM_U13, 'DF2', CompetitionType::CHAMPIONSHIP, 'CLUB DE REFERENCE - 1');
        $this->netCompetition(162, self::ER_NET_TEAM_U13_F2, 'DF2', CompetitionType::CHAMPIONSHIP, 'CLUB DE REFERENCE - 2');
        $this->netFixture(161, self::ER_TEAM_U13, 161, '2026-02-07', FixtureHomeAway::HOME, externalRef: 'DF2-0001');
        $this->netFixture(162, self::ER_NET_TEAM_U13_F2, 162, '2026-02-07', FixtureHomeAway::HOME, externalRef: 'DF2-0002');
        $this->netFixture(163, self::ER_NET_TEAM_U13_F2, 162, '2026-02-14', FixtureHomeAway::AWAY, kickoff: '15:00', externalRef: 'DF2-0003');
        $em->flush();
    }

    /**
     * Référence 17 : amicaux posés à la main, rangés dans « Amicaux » (la compétition d'office de la saison, par la
     * porte), et compétition « Amical PNF », amicale depuis la PR 4a du lot 7 (née amicale à l'import, ou convertie).
     */
    private function seedMatchesNetFriendlies(): void
    {
        $em = $this->erEm();
        $this->netCompetition(171, self::ER_TEAM_SM1, 'Amical PNF', CompetitionType::FRIENDLY);
        $amicaux = static::getContainer()->get(FriendlyCompetitions::class)->engageInDefault(self::ER_CLUB, self::ER_SEASON, self::ER_TEAM_U15)->getId();
        $this->netFixture(171, self::ER_TEAM_U15, null, '2026-02-14', FixtureHomeAway::HOME, status: FixtureStatus::PLACED, source: FixturePlacementSource::MANUAL, venueId: self::ER_VENUE_NORD, kickoff: '15:00', competitionId: $amicaux);
        $this->netFixture(172, self::ER_TEAM_U15, null, '2026-02-21', FixtureHomeAway::HOME, competitionId: $amicaux);
        $this->netFixture(173, self::ER_TEAM_SM1, 171, '2026-02-14', FixtureHomeAway::HOME, externalRef: 'APNF-0001');
        $this->netFixture(174, self::ER_TEAM_SM1, 171, '2026-02-21', FixtureHomeAway::HOME, status: FixtureStatus::PLACED, source: FixturePlacementSource::SOLVER, venueId: self::ER_VENUE_NORD, kickoff: '17:00', externalRef: 'APNF-0002');
        $this->netFixture(175, self::ER_TEAM_SM1, 171, '2026-02-28', FixtureHomeAway::AWAY, kickoff: '20:00', externalRef: 'APNF-0003');
        $em->flush();
    }

    /** Référence 18 : les stades du suivi fédéral, domicile et extérieur. */
    private function seedMatchesNetFederalTracking(): void
    {
        $em = $this->erEm();
        // Les championnats 1 (U13), 2 (U15) et 3 (SM1) de `seedEngineRequestMatches`.
        $this->netFixture(181, self::ER_TEAM_U13, 1, '2026-02-07', FixtureHomeAway::HOME, status: FixtureStatus::SUBMITTED, source: FixturePlacementSource::MANUAL, venueId: self::ER_VENUE_NORD, kickoff: '14:00');
        $this->netFixture(182, self::ER_TEAM_U13, 1, '2026-02-14', FixtureHomeAway::HOME, status: FixtureStatus::VALIDATED, source: FixturePlacementSource::SOLVER, venueId: self::ER_VENUE_NORD, kickoff: '16:00');
        $this->netFixture(183, self::ER_TEAM_U15, 2, '2026-02-07', FixtureHomeAway::HOME, status: FixtureStatus::SUBMITTED, kickoff: '15:00');
        $this->netFixture(184, self::ER_TEAM_U15, 2, '2026-02-14', FixtureHomeAway::HOME, status: FixtureStatus::VALIDATED);
        $this->netFixture(185, self::ER_TEAM_SM1, 3, '2026-02-08', FixtureHomeAway::AWAY, status: FixtureStatus::SUBMITTED, kickoff: '15:00');
        $this->netFixture(186, self::ER_TEAM_SM1, 3, '2026-02-15', FixtureHomeAway::AWAY, status: FixtureStatus::VALIDATED);
        $this->netFixture(187, self::ER_TEAM_U15, 2, '2026-02-22', FixtureHomeAway::HOME, venueId: self::ER_VENUE_SUD, kickoff: '10:30', externalRef: 'IMP-0187');
        $em->flush();
    }

    /** Référence 19 : les coupes, pour une équipe à fenêtre de ligue le samedi et une équipe sans fenêtre. */
    private function seedMatchesNetCups(): void
    {
        $this->giveU13ItsLeagueWindow();
        $em = $this->erEm();
        // Le Nord ouvre aussi le mercredi soir aux matchs.
        $em->persist((new VenueMatchWindow)->setId(self::erId('a10d', 19))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setVenueId(self::ER_VENUE_NORD)->setDayOfWeek(3)->setStartTime(new DateTimeImmutable('18:00'))->setEndTime(new DateTimeImmutable('22:00')));
        $this->netCompetition(191, self::ER_TEAM_U13, 'Coupe du Rhône U13', CompetitionType::CUP);
        $this->netCompetition(192, self::ER_TEAM_U15, 'Coupe du Rhône U15', CompetitionType::CUP);
        $this->netCompetition(193, self::ER_TEAM_U13, 'Coupe Territoriale U13', CompetitionType::CHAMPIONSHIP);
        $this->netFixture(191, self::ER_TEAM_U13, 191, '2026-03-07', FixtureHomeAway::HOME);
        $this->netFixture(192, self::ER_TEAM_U13, 191, '2026-03-11', FixtureHomeAway::HOME);
        $this->netFixture(193, self::ER_TEAM_U13, 191, '2026-03-14', FixtureHomeAway::HOME, status: FixtureStatus::PLACED, source: FixturePlacementSource::SOLVER, venueId: self::ER_VENUE_NORD, kickoff: '15:00');
        $this->netFixture(194, self::ER_TEAM_U13, 191, '2026-03-18', FixtureHomeAway::HOME, status: FixtureStatus::PLACED, source: FixturePlacementSource::MANUAL, venueId: self::ER_VENUE_NORD, kickoff: '20:00');
        $this->netFixture(195, self::ER_TEAM_U13, 191, '2026-03-21', FixtureHomeAway::AWAY, kickoff: '16:00');
        $this->netFixture(196, self::ER_TEAM_U15, 192, '2026-03-11', FixtureHomeAway::HOME);
        $this->netFixture(197, self::ER_TEAM_U13, 193, '2026-03-28', FixtureHomeAway::HOME);
        $em->flush();
    }

    /** Témoins de la référence 16 : deux compétitions « DF2 », chacune portant un match d'une équipe différente. */
    private function assertMatchesNetTwoTeamsOneDivisionWitnesses(): void
    {
        Assert::assertSame(2, $this->netCount(
            'SELECT COUNT(DISTINCT f.team_id) FROM fixture f JOIN competition c ON c.id = f.competition_id JOIN engagement e ON e.competition_id = c.id WHERE c.name = :name AND e.team_id = f.team_id',
            ['name' => 'DF2'],
        ), 'témoin 16 : deux équipes portent chacune un match d\'une compétition « DF2 »');
        Assert::assertSame(2, $this->netCount('SELECT COUNT(*) FROM competition WHERE name = :name', ['name' => 'DF2']), 'témoin 16 : deux compétitions de même nom');
    }

    /**
     * Témoins de la référence 17 : un domicile non placé et un posé par le système d'une compétition « Amical … »
     * amicale, un amical de « Amicaux » placé et un non placé.
     */
    private function assertMatchesNetFriendliesWitnesses(): void
    {
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE c.name LIKE \'Amical %\' AND c.competition_type = \'FRIENDLY\' AND f.home_away = \'HOME\' AND f.status = \'UNPLACED\'',
        ), 'témoin 17 : un domicile non placé d\'une compétition « Amical … » amicale');
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE c.name LIKE \'Amical %\' AND c.competition_type = \'FRIENDLY\' AND f.home_away = \'HOME\' AND f.status = \'PLACED\' AND f.placement_source = \'SOLVER\'',
        ), 'témoin 17 : un domicile posé par le système d\'une compétition « Amical … » amicale');
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE c.default_friendly AND f.venue_id IS NOT NULL AND f.kickoff_time IS NOT NULL AND f.status = \'PLACED\'',
        ), 'témoin 17 : un amical de « Amicaux » placé');
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE c.default_friendly AND f.status = \'UNPLACED\'',
        ), 'témoin 17 : un amical de « Amicaux » non placé');
    }

    /** Témoins de la référence 18 : un domicile validé sans gymnase, un extérieur saisi, un domicile saisi placé. */
    private function assertMatchesNetFederalTrackingWitnesses(): void
    {
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture WHERE home_away = \'HOME\' AND status = \'VALIDATED\' AND venue_id IS NULL',
        ), 'témoin 18 : un domicile validé sans gymnase');
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture WHERE home_away = \'AWAY\' AND status = \'SUBMITTED\'',
        ), 'témoin 18 : un extérieur saisi');
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture WHERE home_away = \'AWAY\' AND status = \'VALIDATED\' AND venue_id IS NULL',
        ), 'témoin 18 : un extérieur validé sans gymnase');
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture WHERE home_away = \'HOME\' AND status = \'SUBMITTED\' AND venue_id IS NOT NULL AND kickoff_time IS NOT NULL',
        ), 'témoin 18 : un domicile saisi avec gymnase et heure');
    }

    /** Témoins de la référence 19 : un domicile de coupe non placé un mercredi pour chacune des deux équipes, un posé par le système. */
    private function assertMatchesNetCupsWitnesses(): void
    {
        Assert::assertSame(2, $this->netCount(
            'SELECT COUNT(DISTINCT f.team_id) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE c.competition_type = \'CUP\' AND f.home_away = \'HOME\' AND f.status = \'UNPLACED\' AND EXTRACT(ISODOW FROM f.match_date) = 3',
        ), 'témoin 19 : un domicile de coupe non placé un mercredi pour chacune des deux équipes');
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE c.competition_type = \'CUP\' AND f.home_away = \'HOME\' AND f.status = \'PLACED\' AND f.placement_source = \'SOLVER\'',
        ), 'témoin 19 : un domicile de coupe posé par le système');
        Assert::assertSame(1, $this->netCount(
            'SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE c.competition_type = \'CHAMPIONSHIP\' AND c.name LIKE \'Coupe %\' AND f.status = \'UNPLACED\'',
        ), 'témoin 19 : une « Coupe … » rangée en championnat avec un domicile non placé');
    }

    /**
     * Les fenêtres de ligue que la demande porte, par équipe : la résolution est calculée, elle se lit
     * dans la demande et non en base.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, list<mixed>>
     */
    private function leagueWindowDaysByTeam(array $body): array
    {
        $days = [];
        foreach (\is_array($body['teams'] ?? null) ? $body['teams'] : [] as $team) {
            if (!\is_array($team) || !\is_string($team['id'] ?? null)) {
                continue;
            }
            $days[$team['id']] = array_map(
                static fn (mixed $window): mixed => \is_array($window) ? ($window['dayOfWeek'] ?? null) : null,
                \is_array($team['leagueWindows'] ?? null) ? array_values($team['leagueWindows']) : [],
            );
        }

        return $days;
    }

    private function netCompetition(int $n, string $teamId, string $name, CompetitionType $type, ?string $fbiTeamLabel = null): void
    {
        $competition = (new Competition)->setId(self::erId('c0e9', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setName($name)->setCompetitionType($type);
        $this->erEm()->persist($competition);
        static::getContainer()->get(CompetitionRegistry::class)->engage($competition, $teamId, $fbiTeamLabel);
        $this->erEm()->flush();
    }

    /** `$competition` : le numéro de la compétition du décor (`c0e9`) ; `null` avec `$competitionId`, l'identifiant d'une compétition née hors du décor (« Amicaux »). */
    private function netFixture(
        int $n,
        string $teamId,
        ?int $competition,
        string $date,
        FixtureHomeAway $homeAway,
        FixtureStatus $status = FixtureStatus::UNPLACED,
        ?FixturePlacementSource $source = null,
        ?string $venueId = null,
        ?string $kickoff = null,
        ?string $externalRef = null,
        ?string $competitionId = null,
    ): void {
        $fixture = (new Fixture)->setId(self::erId('f1c7', $n));
        $fixture->setClubId(self::ER_CLUB);
        $fixture->setSeasonId(self::ER_SEASON);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId(null === $competition ? $competitionId : self::erId('c0e9', $competition));
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('Adversaire ' . $n);
        $fixture->setVenueId($venueId);
        $fixture->setKickoffTime(null === $kickoff ? null : new DateTimeImmutable($kickoff));
        $fixture->setExternalRef($externalRef);
        if (FixtureStatus::UNPLACED !== $status) {
            $fixture->setStatus($status, new DateTimeImmutable('2026-01-02T10:00:00+00:00'));
        }
        $fixture->setPlacementSource($source);
        $this->erEm()->persist($fixture);
    }

    /** @param array<string, mixed> $params */
    private function netCount(string $sql, array $params = []): int
    {
        $this->scopeGucToClub(self::ER_CLUB);

        return (int) $this->erEm()->getConnection()->fetchOne($sql, $params);
    }
}
