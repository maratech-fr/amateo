<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Export\MatchesExportData;
use App\Export\MatchesExportDataProvider;
use App\Export\MatchesExportRow;
use App\Export\MatchesExportTeamSection;
use App\Export\MatchesExportWeekSection;
use App\Repository\FixtureRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Promesse : ce que le PDF dit d'un match est vrai — une heure n'est « à confirmer » que si elle
 * peut encore bouger, un lieu n'est imprimé que s'il est certain (jamais un gymnase supposé ni une
 * ville seule), et les équipes sortent dans l'ordre vu à l'écran.
 *
 * « Aujourd'hui » est le lundi 9 novembre 2026, par l'horloge réelle du domaine : un club de
 * démonstration à date simulée et une requête portant `_club_id`. Une lecture de l'horloge système
 * dans le provider fait donc rougir ces cas. Intégration et non unitaire : la projection des trajets
 * est `final` et lit la base, le contrat du provider ne s'éprouve qu'avec elle.
 */
#[Group('integration')]
final class MatchesExportDataProviderTest extends KernelTestCase
{
    use TenantGucTrait;

    private const float SIEGE_LAT = 45.70;

    private const float SIEGE_LON = 4.90;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private Team $team;

    private Competition $championship;

    // ─────────────────── « à confirmer » (cellule Heure) ───────────────────

    public function testAFutureLeagueHomeNotValidatedIsToConfirm(): void
    {
        $this->homeMatch('A', '2026-11-14', '15:30', FixtureStatus::PLACED, $this->championship->getId());

        self::assertTrue($this->row('A')->toConfirm);
    }

    public function testAMatchPlayedTodayIsNotYetPast(): void
    {
        $this->homeMatch('A', '2026-11-09', '15:30', FixtureStatus::PLACED, $this->championship->getId());

        self::assertTrue($this->row('A')->toConfirm);
    }

    public function testAFutureLeagueHomeValidatedIsFirm(): void
    {
        $this->homeMatch('A', '2026-11-14', '15:30', FixtureStatus::VALIDATED, $this->championship->getId());

        self::assertFalse($this->row('A')->toConfirm);
    }

    /** La ligue a déplacé le match et personne n'a traité l'écart : « validé » ne suffit plus. */
    public function testAnOpenFederalDeviationOverridesValidated(): void
    {
        $fixture = $this->homeMatch('A', '2026-11-14', '15:30', FixtureStatus::VALIDATED, $this->championship->getId());
        $fixture->putPendingDeviation(['field' => 'kickoff', 'appValue' => '15:30', 'sourceValue' => '17:00', 'channel' => 'FBI_XLSX', 'seenAt' => '2026-11-08T10:00:00+01:00', 'autoApplied' => false]);
        $this->em->flush();

        self::assertTrue($this->row('A')->toConfirm);
    }

    public function testAFutureFriendlyHomeWithoutCompetitionIsFirm(): void
    {
        $this->homeMatch('A', '2026-11-14', '10:00', FixtureStatus::PLACED, null);

        $row = $this->row('A');
        self::assertFalse($row->toConfirm);
        self::assertTrue($row->friendly);
    }

    public function testACompetitionLabelledFriendlyIsAFriendlyToo(): void
    {
        $friendly = $this->competition('Amical PNF');
        $this->homeMatch('A', '2026-11-14', '10:00', FixtureStatus::PLACED, $friendly->getId());

        $row = $this->row('A');
        self::assertFalse($row->toConfirm);
        self::assertTrue($row->friendly);
    }

    public function testAPlayedMatchIsFirm(): void
    {
        $this->homeMatch('A', '2026-11-07', '15:30', FixtureStatus::PLACED, $this->championship->getId());

        self::assertFalse($this->row('A')->toConfirm);
    }

    /** Une fois joué, un écart resté ouvert ne concerne plus les parents : aucune mention. */
    public function testAPlayedMatchWithOpenDeviationIsFirm(): void
    {
        $fixture = $this->homeMatch('A', '2026-11-07', '15:30', FixtureStatus::VALIDATED, $this->championship->getId());
        $fixture->putPendingDeviation(['field' => 'kickoff', 'appValue' => '15:30', 'sourceValue' => '17:00', 'channel' => 'FBI_XLSX', 'seenAt' => '2026-11-06T10:00:00+01:00', 'autoApplied' => false]);
        $this->em->flush();

        self::assertFalse($this->row('A')->toConfirm);
    }

    public function testAPlayedMatchWithOpenDeviationAndNoKickoffKeepsTheMention(): void
    {
        $fixture = $this->homeMatch('A', '2026-11-07', null, FixtureStatus::UNPLACED, $this->championship->getId());
        $fixture->putPendingDeviation(['field' => 'kickoff', 'appValue' => null, 'sourceValue' => '17:00', 'channel' => 'FBI_XLSX', 'seenAt' => '2026-11-06T10:00:00+01:00', 'autoApplied' => false]);
        $this->em->flush();

        self::assertTrue($this->row('A')->toConfirm);
    }

    public function testAPlayedMatchWithoutKickoffIsToConfirm(): void
    {
        $this->homeMatch('A', '2026-11-07', null, FixtureStatus::UNPLACED, $this->championship->getId());

        $row = $this->row('A');
        self::assertTrue($row->toConfirm);
        self::assertNull($row->kickoff);
    }

    public function testAnAwayWithKickoffIsPrintedWithoutMention(): void
    {
        $this->awayMatch('A', '2026-11-15', '14:00');

        $row = $this->row('A');
        self::assertFalse($row->toConfirm);
        self::assertSame('14:00', $row->kickoff);
        self::assertSame('away', $row->where);
    }

    public function testAnAwayWithoutKickoffIsToConfirm(): void
    {
        $this->awayMatch('A', '2026-11-15', null);

        self::assertTrue($this->row('A')->toConfirm);
    }

    public function testANeutralGroundFollowsTheAwayRule(): void
    {
        $this->neutralMatch('AvecHeure', '14:00');
        $this->neutralMatch('SansHeure', null);

        self::assertFalse($this->row('AvecHeure')->toConfirm);
        self::assertSame('neutral', $this->row('AvecHeure')->where);
        self::assertTrue($this->row('SansHeure')->toConfirm);
    }

    // ─────────────────── Lieu ───────────────────

    public function testAHomeWithVenuePrintsNameAndAddress(): void
    {
        $fixture = $this->homeMatch('A', '2026-11-14', '15:30', FixtureStatus::PLACED, null);
        $fixture->setVenueId($this->venue('Gymnase du Parc', '3 av. des Sports, 69100 Villeurbanne')->getId());
        $this->em->flush();

        $place = $this->row('A')->place;
        self::assertSame('Gymnase du Parc', $place?->name);
        self::assertSame('3 av. des Sports, 69100 Villeurbanne', $place->address);
        self::assertNull($place->travelMinutes, 'le trajet est réservé à l\'extérieur');
    }

    public function testAHomeWithAddressLessVenuePrintsItsNameAlone(): void
    {
        $fixture = $this->homeMatch('A', '2026-11-14', '15:30', FixtureStatus::PLACED, null);
        $fixture->setVenueId($this->venue('Gymnase du Parc', null)->getId());
        $this->em->flush();

        self::assertNull($this->row('A')->place?->address);
    }

    public function testAHomeWithoutVenueHasNoPlace(): void
    {
        $this->homeMatch('A', '2026-11-14', null, FixtureStatus::UNPLACED, null);

        self::assertNull($this->row('A')->place);
    }

    public function testANeutralGroundHasNoPlaceEvenWithAFederalLabel(): void
    {
        $this->neutralMatch('A', '14:00');

        self::assertNull($this->row('A')->place);
    }

    public function testALinkedAwayPrintsItsAddressAndTravel(): void
    {
        $this->link('ORG1', 'SALLE DU PARC', '12 rue X', '69500', 'Bron');
        $this->cacheTravel(35);
        $this->awayMatch('A', '2026-11-15', '14:00', 'ORG1', 'SALLE DU PARC');

        $data = $this->load();
        $place = $this->rowOf($data, 'A')->place;
        self::assertSame('SALLE DU PARC', $place?->name);
        self::assertSame('12 rue X, 69500 Bron', $place->address);
        self::assertSame(35, $place->travelMinutes);
        self::assertTrue($data->hasTravel);
    }

    public function testAnOldLinkOmitsItsMissingParts(): void
    {
        $this->link('ORG1', 'SALLE DU PARC', null, '69500', 'Bron');
        $this->awayMatch('A', '2026-11-15', '14:00', 'ORG1', 'SALLE DU PARC');

        self::assertSame('69500 Bron', $this->row('A')->place?->address);
    }

    public function testALinkWithoutCachedTravelPrintsNoMinutes(): void
    {
        $this->link('ORG1', 'SALLE DU PARC', '12 rue X', '69500', 'Bron');
        $this->awayMatch('A', '2026-11-15', '14:00', 'ORG1', 'SALLE DU PARC');

        $data = $this->load();
        self::assertNull($this->rowOf($data, 'A')->place?->travelMinutes);
        self::assertFalse($data->hasTravel, 'pas de légende sans trajet imprimé');
    }

    public function testWithoutLocatedSiegeAddressesStayAndMinutesGo(): void
    {
        $this->link('ORG1', 'SALLE DU PARC', '12 rue X', '69500', 'Bron');
        $this->cacheTravel(35);
        $this->club->setLatitude(null);
        $this->club->setLongitude(null);
        $this->em->flush();
        $this->awayMatch('A', '2026-11-15', '14:00', 'ORG1', 'SALLE DU PARC');

        $data = $this->load();
        $place = $this->rowOf($data, 'A')->place;
        self::assertSame('12 rue X, 69500 Bron', $place?->address);
        self::assertNull($place->travelMinutes);
        self::assertFalse($data->hasTravel);
    }

    /** « Gymnase supposé » : la projection a un lieu, l'export refuse de le donner aux parents. */
    public function testASupposedGymIsNeverPrinted(): void
    {
        $this->link('ORG1', 'SALLE DU PARC', '12 rue X', '69500', 'Bron');
        $this->cacheTravel(35);
        $this->awayMatch('Apparie', '2026-11-15', '14:00', 'ORG1', 'SALLE DU PARC');
        $this->awayMatch('Suppose', '2026-11-22', '14:00', 'ORG1', 'SALLE INCONNUE');

        self::assertNull($this->row('Suppose')->place);
    }

    public function testACityOnlyPlaceIsNeverPrinted(): void
    {
        $code = 'ORG' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $entry = new OpponentDirectoryEntry($code, 'Adversaire', OpponentLocationPrecision::CITY);
        $entry->setCity('Bron');
        $this->em->persist($entry);
        $this->em->flush();
        $this->awayMatch('A', '2026-11-15', '14:00', $code, null);

        self::assertNull($this->row('A')->place);
    }

    public function testAnUnknownOpponentHasNoPlace(): void
    {
        $this->awayMatch('A', '2026-11-15', '14:00', 'ORGINCONNU', 'SALLE Z');

        self::assertNull($this->row('A')->place);
    }

    // ─────────────────── Sections et tri ───────────────────

    public function testTeamsComeInScreenRankOrderThenFrenchName(): void
    {
        $this->matchFor($this->makeTeam('Rang 2', 2, 0));
        $this->matchFor($this->makeTeam('Rang 1 pos 1', 1, 1));
        $this->matchFor($this->makeTeam('Rang 1 pos 0', 1, 0));
        $this->matchFor($this->makeTeam('Zola', 3, 0));
        $this->matchFor($this->makeTeam('École', 3, 0));

        $names = array_map(static fn (MatchesExportTeamSection $section): string => $section->teamName, $this->load()->sections);

        self::assertSame(['Rang 1 pos 0', 'Rang 1 pos 1', 'Rang 2', 'École', 'Zola'], $names);
    }

    public function testATeamWithoutAnyMatchInTheSeasonIsAbsent(): void
    {
        $this->homeMatch('A', '2026-11-14', '15:30', FixtureStatus::PLACED, null);
        $this->makeTeam('Sans match', 1, 0);

        $names = array_map(static fn (MatchesExportTeamSection $section): string => $section->teamName, $this->load()->sections);

        self::assertSame([$this->team->getName()], $names);
    }

    public function testATeamWithMatchesOutsideTheRangeKeepsAnEmptySection(): void
    {
        $this->homeMatch('Hors plage', '2027-02-14', '15:30', FixtureStatus::PLACED, null);

        $sections = $this->load()->sections;

        self::assertCount(1, $sections);
        self::assertSame([], $sections[0]->rows);
    }

    public function testRowsAreSortedByDateThenKickoffWithMissingKickoffLast(): void
    {
        $this->homeMatch('Sans heure', '2026-11-14', null, FixtureStatus::UNPLACED, null);
        $this->homeMatch('Tard', '2026-11-14', '17:00', FixtureStatus::PLACED, null);
        $this->homeMatch('Tot', '2026-11-14', '10:00', FixtureStatus::PLACED, null);
        $this->homeMatch('Veille', '2026-11-13', '20:00', FixtureStatus::PLACED, null);

        $opponents = array_map(static fn (MatchesExportRow $row): string => $row->opponent, $this->load()->sections[0]->rows);

        self::assertSame(['Veille', 'Tot', 'Tard', 'Sans heure'], $opponents);
    }

    public function testATeamScopeKeepsOnlyThatTeamAndNamesIt(): void
    {
        $this->homeMatch('A', '2026-11-14', '15:30', FixtureStatus::PLACED, null);
        $this->matchFor($this->makeTeam('Autre', 1, 0));

        $data = $this->load($this->team);

        self::assertSame($this->team->getName(), $data->teamScopeName);
        self::assertCount(1, $data->sections);
        self::assertSame($this->team->getName(), $data->sections[0]->teamName);
    }

    public function testAClubWithoutMatchHasNoSection(): void
    {
        self::assertSame([], $this->load()->sections);
    }

    public function testTheHeaderDataComesFromTheClubAndTheDomainClock(): void
    {
        $data = $this->load();

        self::assertSame($this->club->getName(), $data->clubName);
        self::assertSame('2026-11-09', $data->from->format('Y-m-d'));
        self::assertSame('2026-11-15', $data->to->format('Y-m-d'));
        self::assertSame('2026-11-09', $data->editedOn->format('Y-m-d'), 'le jour d\'édition passe par l\'horloge du club');
        self::assertNull($data->logoDataUri);
    }

    // ─────────────────── « À domicile seulement » et regroupement par jour (E2) ───────────────────

    /** Domicile = reçu chez nous : un extérieur et un terrain neutre sortent du document. */
    public function testHomeOnlyKeepsHomeRowsAndLeavesAwayAndNeutralOut(): void
    {
        $this->homeMatch('Reçu', '2026-11-14', '15:30', FixtureStatus::PLACED, null);
        $this->awayMatch('Visité', '2026-11-15', '14:00');
        $this->neutralMatch('Neutre', '14:00');

        $data = $this->loadFor('2026-11-09', '2026-11-15', homeOnly: true);

        self::assertTrue($data->homeOnly);
        self::assertSame(['Reçu'], $this->opponentsOf($data->sections[0]->rows));
    }

    /** Une équipe qui ne joue qu'à l'extérieur garde sa section, vide : le document dit qu'elle ne reçoit pas. */
    public function testHomeOnlyKeepsATeamWithoutHomeMatchAsAnEmptySection(): void
    {
        $this->awayMatch('Visité', '2026-11-15', '14:00');

        $sections = $this->loadFor('2026-11-09', '2026-11-15', homeOnly: true)->sections;

        self::assertCount(1, $sections);
        self::assertSame([], $sections[0]->rows);
    }

    public function testEveryRowNamesItsTeam(): void
    {
        $this->homeMatch('A', '2026-11-14', '15:30', FixtureStatus::PLACED, null);

        self::assertSame($this->team->getName(), $this->row('A')->teamName);
    }

    /** Par jour : l'heure d'abord, puis l'équipe ; une heure inconnue passe en dernier. */
    public function testADayIsSortedByKickoffThenTeamWithUnknownKickoffLast(): void
    {
        $alpha = $this->makeTeam('Alpha', 1, 0);
        $this->homeMatch('U13 à 15:30', '2026-11-14', '15:30', FixtureStatus::PLACED, null);
        $this->baseFixture($alpha, 'Alpha sans heure', '2026-11-14', FixtureHomeAway::HOME, null);
        $this->baseFixture($alpha, 'Alpha à 15:30', '2026-11-14', FixtureHomeAway::HOME, '15:30');
        $this->homeMatch('U13 à 10:00', '2026-11-14', '10:00', FixtureStatus::PLACED, null);

        $data = $this->loadFor('2026-11-14', '2026-11-14', groupBy: 'day');

        self::assertSame('day', $data->groupBy);
        self::assertCount(1, $data->weeks);
        self::assertCount(1, $data->weeks[0]->days);
        self::assertSame(['U13 à 10:00', 'Alpha à 15:30', 'U13 à 15:30', 'Alpha sans heure'], $this->opponentsOf($data->weeks[0]->days[0]->rows));
    }

    public function testADayWithoutMatchIsAbsent(): void
    {
        $this->homeMatch('Samedi', '2026-11-14', '15:30', FixtureStatus::PLACED, null);
        $this->homeMatch('Lundi', '2026-11-16', '20:00', FixtureStatus::PLACED, null);

        self::assertSame(['2026-11-14', '2026-11-16'], $this->dayDatesOf($this->loadFor('2026-11-14', '2026-11-16', groupBy: 'day')));
    }

    /** Une semaine ISO va du lundi au dimanche : dimanche 15 et lundi 16 novembre 2026 sont sur deux semaines. */
    public function testWeeksSplitBetweenSundayAndMonday(): void
    {
        $this->homeMatch('Dimanche', '2026-11-15', '15:30', FixtureStatus::PLACED, null);
        $this->homeMatch('Lundi', '2026-11-16', '20:00', FixtureStatus::PLACED, null);

        $weeks = $this->loadFor('2026-11-15', '2026-11-16', groupBy: 'day')->weeks;

        self::assertSame(['2026-W46', '2026-W47'], array_map(static fn (MatchesExportWeekSection $week): string => $week->isoWeek, $weeks));
    }

    /** Le 31/12/2026 et le 01/01/2027 sont dans la même semaine ISO, 2026-W53 : une seule page. */
    public function testNewYearStaysInTheSameIsoWeek(): void
    {
        $this->homeMatch('Réveillon', '2026-12-31', '15:30', FixtureStatus::PLACED, null);
        $this->homeMatch('Jour de l\'an', '2027-01-01', '15:30', FixtureStatus::PLACED, null);

        $weeks = $this->loadFor('2026-12-31', '2027-01-01', groupBy: 'day')->weeks;

        self::assertSame(['2026-W53'], array_map(static fn (MatchesExportWeekSection $week): string => $week->isoWeek, $weeks));
        self::assertCount(2, $weeks[0]->days);
    }

    public function testTheDayGroupingHonoursHomeOnly(): void
    {
        $this->homeMatch('Reçu', '2026-11-14', '15:30', FixtureStatus::PLACED, null);
        $this->awayMatch('Visité', '2026-11-14', '14:00');

        $data = $this->loadFor('2026-11-14', '2026-11-14', homeOnly: true, groupBy: 'day');

        self::assertCount(1, $data->weeks);
        self::assertSame(['Reçu'], $this->opponentsOf($data->weeks[0]->days[0]->rows));
    }

    // ─────────────────── Lecture bornée ───────────────────

    public function testTheRepositoryReadIsInclusiveAndScoped(): void
    {
        $this->homeMatch('Debut', '2026-11-09', null, FixtureStatus::UNPLACED, null);
        $this->homeMatch('Fin', '2026-11-15', null, FixtureStatus::UNPLACED, null);
        $this->homeMatch('Apres', '2026-11-16', null, FixtureStatus::UNPLACED, null);
        $other = $this->makeTeam('Autre', 1, 0);
        $this->matchFor($other);

        $repository = self::getContainer()->get(FixtureRepository::class);
        $from = new DateTimeImmutable('2026-11-09');
        $to = new DateTimeImmutable('2026-11-15');

        $labels = array_map(static fn (Fixture $f): string => $f->getOpponentLabel(), $repository->findBySeasonBetween($this->season->getId(), $from, $to, $this->team->getId()));
        sort($labels);

        self::assertSame(['Debut', 'Fin'], $labels);
        self::assertCount(3, $repository->findBySeasonBetween($this->season->getId(), $from, $to, null), 'sans équipe : tout le club sur la plage');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $this->club = new Club;
        $this->club->setName('Les Lions ' . $uid);
        $this->club->setSlug('lions-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setLatitude(self::SIEGE_LAT);
        $this->club->setLongitude(self::SIEGE_LON);
        $this->club->setIsDemo(true);
        $this->club->setSimulatedToday(new DateTimeImmutable('2026-11-09'));
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        $request = new Request;
        $request->attributes->set('_club_id', $this->club->getId());
        self::getContainer()->get(RequestStack::class)->push($request);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2026');
        $this->season->setStartDate(new DateTimeImmutable('2026-08-01'));
        $this->season->setEndDate(new DateTimeImmutable('2027-07-15'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->season->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->team = $this->makeTeam('U13 M1', 1, 0);
        $this->championship = $this->competition('DM3');
    }

    private function load(?Team $team = null): MatchesExportData
    {
        $this->em->flush();

        return self::getContainer()->get(MatchesExportDataProvider::class)->load(
            $this->club->getId(),
            $this->season,
            new DateTimeImmutable('2026-11-09'),
            new DateTimeImmutable('2026-11-15'),
            $team,
        );
    }

    private function loadFor(string $from, string $to, bool $homeOnly = false, string $groupBy = 'team'): MatchesExportData
    {
        $this->em->flush();

        return self::getContainer()->get(MatchesExportDataProvider::class)->load(
            $this->club->getId(),
            $this->season,
            new DateTimeImmutable($from),
            new DateTimeImmutable($to),
            null,
            $homeOnly,
            $groupBy,
        );
    }

    /**
     * @param list<MatchesExportRow> $rows
     *
     * @return list<string>
     */
    private function opponentsOf(array $rows): array
    {
        return array_map(static fn (MatchesExportRow $row): string => $row->opponent, $rows);
    }

    /** @return list<string> les jours imprimés, toutes semaines confondues */
    private function dayDatesOf(MatchesExportData $data): array
    {
        $dates = [];
        foreach ($data->weeks as $week) {
            foreach ($week->days as $day) {
                $dates[] = $day->date->format('Y-m-d');
            }
        }

        return $dates;
    }

    private function row(string $opponent): MatchesExportRow
    {
        // Plage large pour les seuls tests de ligne : un cas peut viser le 7 ou le 22 novembre.
        $this->em->flush();
        $data = self::getContainer()->get(MatchesExportDataProvider::class)->load(
            $this->club->getId(),
            $this->season,
            new DateTimeImmutable('2026-11-01'),
            new DateTimeImmutable('2026-11-30'),
            null,
        );

        return $this->rowOf($data, $opponent);
    }

    private function rowOf(MatchesExportData $data, string $opponent): MatchesExportRow
    {
        foreach ($data->sections as $section) {
            foreach ($section->rows as $row) {
                if ($opponent === $row->opponent) {
                    return $row;
                }
            }
        }
        self::fail(\sprintf('aucune ligne pour l\'adversaire « %s »', $opponent));
    }

    private function homeMatch(string $opponent, string $date, ?string $kickoff, FixtureStatus $status, ?string $competitionId): Fixture
    {
        $fixture = $this->baseFixture($this->team, $opponent, $date, FixtureHomeAway::HOME, $kickoff);
        $fixture->setCompetitionId($competitionId);
        $fixture->setStatus($status, new DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        return $fixture;
    }

    private function awayMatch(string $opponent, string $date, ?string $kickoff, ?string $code = null, ?string $fbiLabel = null): void
    {
        $fixture = $this->baseFixture($this->team, $opponent, $date, FixtureHomeAway::AWAY, $kickoff);
        $fixture->setCompetitionId($this->championship->getId());
        $fixture->setOpponentOrganismeCode($code);
        $fixture->setFbiVenueLabel($fbiLabel);
        $this->em->flush();
    }

    private function neutralMatch(string $opponent, ?string $kickoff): void
    {
        $fixture = $this->baseFixture($this->team, $opponent, '2026-11-14', FixtureHomeAway::HOME, $kickoff);
        $fixture->setCompetitionId($this->championship->getId());
        $fixture->setFbiVenueLabel('SALLE NEUTRE');
        $fixture->setUnplacedReason(FixtureUnplacedReason::NEUTRAL_GROUND);
        $this->em->flush();
    }

    private function matchFor(Team $team): void
    {
        $this->baseFixture($team, 'Les Lions', '2026-11-14', FixtureHomeAway::HOME, '15:30');
        $this->em->flush();
    }

    private function baseFixture(Team $team, string $opponent, string $date, FixtureHomeAway $homeAway, ?string $kickoff): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel($opponent);
        $fixture->setKickoffTime(null === $kickoff ? null : new DateTimeImmutable($kickoff));
        $this->em->persist($fixture);

        return $fixture;
    }

    private function makeTeam(string $name, int $tierId, int $tierOrder): Team
    {
        $team = (new Team)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')
            ->setPriorityTierId($tierId)
            ->setTierOrder($tierOrder)
            ->setName($name)
            ->setSessionsPerWeek(2)
            ->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function competition(string $name): Competition
    {
        $competition = (new Competition)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setTeamId($this->team->getId())
            ->setName($name)
            ->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    private function venue(string $name, ?string $address): Venue
    {
        $venue = new Venue;
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName($name);
        $venue->setAddress($address);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function link(string $code, string $fbiLabel, ?string $address, ?string $postalCode, ?string $city): void
    {
        $link = (new OpponentVenueLink)
            ->setClubId($this->club->getId())
            ->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)
            ->setFbiLabelNorm(self::getContainer()->get(VenueLabelNormalizer::class)->normalize($fbiLabel))
            ->setVenueLabel($fbiLabel)
            ->setLatitude(45.76)
            ->setLongitude(4.86)
            ->setAddress($address)
            ->setPostalCode($postalCode)
            ->setCity($city)
            ->setSource(OpponentVenueLinkSource::AUTO);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function cacheTravel(int $minutes): void
    {
        self::getContainer()->get(TravelTimeCache::class)->store($this->club->getId(), IgnRoutingClient::PROFILE_CAR, self::SIEGE_LAT, self::SIEGE_LON, 45.76, 4.86, $minutes);
    }
}
