<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET/POST /api/fixtures/league-validation — le geste « validé ligue » en lot (lot L).
 *
 * Le compte et l'application partagent le MÊME prédicat : un domicile UNPLACED
 * portant heure + gymnase identifié, sans écart en attente. L'application pose
 * VALIDATED + source MANUAL, et cette rencontre ressort alors en ANCRE FIXE dans la
 * charge utile envoyée au solveur de placement (la preuve passe par l'engine réel du
 * docker-compose ; il se skippe s'il est indisponible, même rituel que le placement).
 */
#[Group('integration')]
final class LeagueValidatedFixturesControllerTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testNonManagementMemberIs403OnBothRoutes(): void
    {
        [, $clubId] = $this->createClub();
        $editorToken = $this->addMember($clubId, 'editor');

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSocleNotChosenIs409OnBothRoutes(): void
    {
        [$token] = $this->createClub(settleSocle: false);

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(409);

        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testOutlookCountsOnlyMaturedValidatableHomeFixtures(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Validable : domicile UNPLACED, heure + gymnase, sans écart, championnat ÉCHU
        // (échéance passée par défaut). Une date de match FUTURE reste validable — c'est
        // l'ÉCHÉANCE du championnat, pas la date du match, qui ouvre la validation.
        $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2099-03-14');

        // Inéligibles au prédicat, championnat échu — un par raison, donc À TRAITER (nommés).
        $noKickoff = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-15', '2020-09-10');
        $noKickoff->setVenueId($venue->getId());
        $noVenue = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-16', '2020-09-10');
        $noVenue->setKickoffTime(new DateTimeImmutable('15:30'));
        // Extérieur : jamais un candidat, jamais nommé.
        $away = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-17', '2020-09-10');
        $away->setHomeAway(FixtureHomeAway::AWAY);
        $away->setVenueId($venue->getId());
        $away->setKickoffTime(new DateTimeImmutable('15:30'));
        $withDeviation = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-18', '2020-09-10');
        $withDeviation->setVenueId($venue->getId());
        $withDeviation->setKickoffTime(new DateTimeImmutable('15:30'));
        $withDeviation->putPendingDeviation(['field' => 'date', 'appValue' => '2099-03-18', 'sourceValue' => '2099-03-25', 'channel' => 'FBI_XLSX', 'seenAt' => '2026-10-01T00:00:00+00:00', 'autoApplied' => false]);
        $this->em->flush();

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['totalValidatable']);
        self::assertCount(1, $data['matured']);
        self::assertSame(1, $data['matured'][0]['validatableCount']);
        self::assertSame('2020-09-10', $data['matured'][0]['deadline']);
        self::assertSame('club', $data['matured'][0]['deadlineSource']);

        // Les trois candidats domicile non validables sont NOMMÉS (l'extérieur, non).
        $reasons = array_column($data['toTreat'], 'reason');
        sort($reasons);
        self::assertSame(['NO_KICKOFF', 'NO_VENUE', 'PENDING_DEVIATION'], $reasons);
        self::assertNotContains($away->getId(), array_column($data['toTreat'], 'fixtureId'));
    }

    /**
     * NR — LE cœur du lot O : l'échéance du championnat est le déclencheur. Une rencontre
     * qui passerait le prédicat (heure + gymnase) mais dont le championnat n'est PAS encore
     * échu (nouvelle vague d'octobre, dates provisoires) n'est proposée NULLE PART — ni
     * comptée, ni nommée à traiter, ni signalée sans échéance. La verrouiller en ancre fixe
     * au moment où il faut pouvoir la bouger serait le piège que ce lot ferme.
     */
    public function testAFutureDeadlineCompetitionIsProposedNowhere(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Échu → compté. Échéance FUTURE → invisible bien que parfaitement daté.
        $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2099-03-14', '15:30', '2020-09-10');
        $future = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2099-03-15', '15:30', '2099-12-31');

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['totalValidatable']);
        self::assertCount(1, $data['matured']);
        self::assertNotContains($future->getId(), array_column($data['toTreat'], 'fixtureId'));
        self::assertSame([], $data['missingDeadline']);
    }

    /**
     * NR — un championnat SANS échéance n'est jamais proposé à la validation, mais il est
     * SIGNALÉ nommément (avec son compte de rencontres prêtes) pour renvoyer le gestionnaire
     * vers l'écran des échéances : sur la base réelle, toutes les compétitions ont une
     * échéance, donc une absence est une anomalie à corriger, pas un silence.
     */
    public function testACompetitionWithoutDeadlineIsNamedNotValidated(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Un domicile prêt (heure + gymnase) mais son championnat n'a AUCUNE échéance.
        $ready = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2099-03-14', '15:30', competitionDeadline: null);
        $competitionName = $this->competitionOf($ready)->getName();

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(0, $data['totalValidatable']);
        self::assertSame([], $data['matured']);
        self::assertCount(1, $data['missingDeadline']);
        self::assertSame($competitionName, $data['missingDeadline'][0]['name']);
        self::assertSame(1, $data['missingDeadline'][0]['validatableCount']);
    }

    /**
     * NR (D2) — un championnat SANS échéance dont le PREMIER match est déjà joué a DÉMARRÉ :
     * il est proposé, non pas comme « sans échéance » mais comme échu « par premier match
     * joué ». Le libellé porte la date du premier match (`firstMatchDate`), l'échéance reste
     * nulle. Sans ce critère élargi, un championnat commencé sans échéance renseignée serait
     * bloqué en « à traiter/à renseigner » alors qu'il faut pouvoir le valider.
     */
    public function testAFirstMatchPlayedCompetitionWithoutDeadlineIsProposed(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Domicile prêt (heure + gymnase), AUCUNE échéance, mais son 1er match est PASSÉ.
        $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2020-09-19', '15:30', competitionDeadline: null);

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['totalValidatable']);
        self::assertCount(1, $data['matured']);
        self::assertNull($data['matured'][0]['deadline']);
        self::assertSame('firstMatchPlayed', $data['matured'][0]['maturedBy']);
        self::assertSame('2020-09-19', $data['matured'][0]['firstMatchDate']);
        self::assertSame([], $data['missingDeadline']);
    }

    /**
     * NR (D2) — l'échéance est dans le FUTUR, mais le premier match est déjà joué : le
     * championnat a démarré, il est proposé (le premier match joué l'emporte sur une échéance
     * provisoire non passée).
     */
    public function testAFutureDeadlineButFirstMatchPlayedCompetitionIsProposed(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Échéance FUTURE, mais 1er match PASSÉ.
        $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2020-09-19', '15:30', '2099-12-31');

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['totalValidatable']);
        self::assertCount(1, $data['matured']);
        self::assertSame('firstMatchPlayed', $data['matured'][0]['maturedBy']);
    }

    /**
     * NR (D3) — une compétition dont le LIBELLÉ est un amical (« Amical PNF ») typée
     * CHAMPIONSHIP côté import n'est JAMAIS candidate : ni comptée, ni nommée à traiter, ni
     * signalée sans échéance, et le POST ne la bascule pas. « Validé ligue » n'a pas de sens
     * pour un amical.
     */
    public function testAFriendlyLabelledChampionshipIsNeverCandidate(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Un domicile PARFAITEMENT prêt (heure + gymnase, échéance passée) MAIS dont la
        // compétition s'appelle « Amical PNF ». Date FUTURE : le balayage des amicaux ne le
        // touche pas non plus (il reste UNPLACED, on prouve qu'il n'est jamais candidat au lot).
        $friendly = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-14', '2020-09-10', 'Amical PNF');
        $friendly->setVenueId($venue->getId());
        $friendly->setKickoffTime(new DateTimeImmutable('15:30'));
        $this->em->flush();
        $friendlyId = $friendly->getId();

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $data['totalValidatable']);
        self::assertSame([], $data['matured']);
        self::assertSame([], $data['missingDeadline']);
        self::assertNotContains($friendlyId, array_column($data['toTreat'], 'fixtureId'));

        // Et le POST ne le bascule pas : il reste UNPLACED (jamais « validé ligue »).
        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $confirmed = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $confirmed['confirmed']);
        $this->em->clear();
        $fresh = $this->em->find(Fixture::class, $friendlyId);
        self::assertInstanceOf(Fixture::class, $fresh);
        self::assertSame(FixtureStatus::UNPLACED, $fresh->getStatus());
    }

    /**
     * NR (D4) — les AMICAUX passés se valident tout seuls quand un GESTIONNAIRE ouvre la vue :
     * un amical HOME passé (ici via un libellé « Amical ») bascule VALIDATED + source MANUAL
     * (même invariant que le lot championnat), un amical AWAY passé (sans compétition) bascule
     * VALIDATED mais garde sa source intacte. Ils ne sont JAMAIS proposés au lot.
     */
    public function testPastFriendliesAreAutoValidatedBySweepHomeGetsManualAwayKeepsSource(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);

        // HOME amical passé, reconnu au LIBELLÉ de sa compétition (« Amical »).
        $home = $this->createFixture($clubId, $seasonId, $team->getId(), '2020-09-19', null, 'Amical M');
        $homeId = $home->getId();
        // AWAY amical passé, SANS compétition.
        $awayId = $this->friendlyNoCompetition($clubId, $seasonId, $team->getId(), '2020-09-19', FixtureHomeAway::AWAY)->getId();

        // Une simple LECTURE par un gestionnaire déclenche le balayage.
        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        // Aucun amical n'est jamais proposé au lot.
        self::assertSame(0, $data['totalValidatable']);

        $this->em->clear();
        $freshHome = $this->em->find(Fixture::class, $homeId);
        self::assertInstanceOf(Fixture::class, $freshHome);
        self::assertSame(FixtureStatus::VALIDATED, $freshHome->getStatus());
        self::assertSame(FixturePlacementSource::MANUAL, $freshHome->getPlacementSource());

        $freshAway = $this->em->find(Fixture::class, $awayId);
        self::assertInstanceOf(Fixture::class, $freshAway);
        self::assertSame(FixtureStatus::VALIDATED, $freshAway->getStatus());
        self::assertNull($freshAway->getPlacementSource(), 'un extérieur garde sa source intacte (jamais MANUAL)');
    }

    /**
     * NR (D4) — le balayage ne touche PAS un amical FUTUR (pas encore joué) ni un amical passé
     * portant un ÉCART en attente (jamais tranché en balayage) : les deux restent UNPLACED.
     */
    public function testFutureOrDeviatedFriendlyIsUntouchedBySweep(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);

        $futureId = $this->friendlyNoCompetition($clubId, $seasonId, $team->getId(), '2099-03-14', FixtureHomeAway::HOME)->getId();
        $deviated = $this->friendlyNoCompetition($clubId, $seasonId, $team->getId(), '2020-09-19', FixtureHomeAway::HOME);
        $deviated->putPendingDeviation(['field' => 'date', 'appValue' => '2020-09-19', 'sourceValue' => '2020-09-26', 'channel' => 'FBI_XLSX', 'seenAt' => '2026-10-01T00:00:00+00:00', 'autoApplied' => false]);
        $this->em->flush();
        $deviatedId = $deviated->getId();

        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $freshFuture = $this->em->find(Fixture::class, $futureId);
        self::assertInstanceOf(Fixture::class, $freshFuture);
        self::assertSame(FixtureStatus::UNPLACED, $freshFuture->getStatus());
        $freshDeviated = $this->em->find(Fixture::class, $deviatedId);
        self::assertInstanceOf(Fixture::class, $freshDeviated);
        self::assertSame(FixtureStatus::UNPLACED, $freshDeviated->getStatus());
    }

    public function testConfirmSwitchesEligibleToValidatedManualAndIsReplayable(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $eligible = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2099-03-14');
        $untouched = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-15'); // no venue/kickoff → skipped

        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['confirmed']);

        $this->em->refresh($eligible);
        self::assertSame(FixtureStatus::VALIDATED, $eligible->getStatus());
        self::assertSame(FixturePlacementSource::MANUAL, $eligible->getPlacementSource());

        $this->em->refresh($untouched);
        self::assertSame(FixtureStatus::UNPLACED, $untouched->getStatus());

        // Rejouable : le prédicat exclut VALIDATED, une seconde application ne trouve rien.
        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $again = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $again['confirmed']);
    }

    /**
     * NR — l'application RECALCULE la maturité au moment du POST : un domicile prêt dont le
     * championnat n'est pas encore échu N'EST PAS basculé (il reste UNPLACED, donc mobile),
     * seul l'échu l'est. Sans corps envoyé : le client ne choisit aucun championnat.
     */
    public function testConfirmSkipsAFutureDeadlineCompetition(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $matured = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2099-03-14', '15:30', '2020-09-10');
        $future = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2099-03-15', '15:30', '2099-12-31');
        [$maturedId, $futureId] = [$matured->getId(), $future->getId()];

        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['confirmed']);

        $this->em->clear();
        $freshMatured = $this->em->find(Fixture::class, $maturedId);
        self::assertInstanceOf(Fixture::class, $freshMatured);
        self::assertSame(FixtureStatus::VALIDATED, $freshMatured->getStatus());
        $freshFuture = $this->em->find(Fixture::class, $futureId);
        self::assertInstanceOf(Fixture::class, $freshFuture);
        self::assertSame(FixtureStatus::UNPLACED, $freshFuture->getStatus());
    }

    /**
     * NR — une rencontre basculée « validé ligue » ressort en ANCRE FIXE : le solveur
     * range les autres matchs autour d'elle et ne la réécrit jamais (source MANUAL).
     * Miroir de {@see PlaceMatchesControllerTest::testAManualAnchorIsNeverRewritten},
     * mais l'ancre naît ici du geste « validé ligue », pas d'un placement manuel.
     */
    public function testAConfirmedFixtureBecomesAFixedAnchorForTheSolver(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '22:30');
        // Un domicile déjà daté côté fédération (samedi 20:30), validé ligue en lot.
        $anchor = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), '2026-10-03', '20:30');
        $other = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');

        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $this->em->refresh($anchor);
        self::assertSame(FixtureStatus::VALIDATED, $anchor->getStatus());
        self::assertSame(FixturePlacementSource::MANUAL, $anchor->getPlacementSource());

        // La charge utile envoyée au solveur porte cette rencontre en kind=FIXED.
        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        $builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
        self::assertInstanceOf(MatchPlacementPayloadBuilder::class, $builder);
        $matches = $builder->build($club, $seasonId)['payload']['matches'];
        self::assertIsArray($matches);
        $anchorRow = null;
        foreach ($matches as $row) {
            self::assertIsArray($row);
            if (($row['id'] ?? null) === $anchor->getId()) {
                $anchorRow = $row;
            }
        }
        self::assertIsArray($anchorRow, 'la rencontre validée ligue doit figurer dans la charge utile du solveur');
        self::assertSame('FIXED', $anchorRow['kind'] ?? null);
        self::assertSame($venue->getId(), $anchorRow['venueId'] ?? null);
        self::assertSame('20:30', $anchorRow['kickoff'] ?? null);

        // Et à la résolution réelle, l'ancre n'est jamais réécrite ; l'autre se range autour.
        // Le rail de placement vide l'EM : on RELIT par id (find réattache depuis la base)
        // plutôt que refresh sur une entité devenue détachée.
        [$anchorId, $otherId] = [$anchor->getId(), $other->getId()];
        $this->placeOrSkip($token);
        $this->em->clear();
        $freshAnchor = $this->em->find(Fixture::class, $anchorId);
        self::assertInstanceOf(Fixture::class, $freshAnchor);
        self::assertSame('20:30', $freshAnchor->getKickoffTime()?->format('H:i'));
        self::assertSame(FixturePlacementSource::MANUAL, $freshAnchor->getPlacementSource());
        $freshOther = $this->em->find(Fixture::class, $otherId);
        self::assertInstanceOf(Fixture::class, $freshOther);
        self::assertSame(FixtureStatus::PLACED, $freshOther->getStatus());
        $kickoff = $freshOther->getKickoffTime()?->format('H:i');
        self::assertNotNull($kickoff);
        self::assertLessThanOrEqual('19:00', $kickoff);
    }

    /**
     * NR — la DIVERGENCE ASSUMÉE avec le contrôle d'accès du geste unitaire : une
     * rencontre à domicile dont l'heure tombe HORS des créneaux d'accès match déclarés
     * du gymnase reste ÉLIGIBLE et bascule (la source fédérale fait foi), et le radar la
     * signale (`ACCESS_WINDOW_LOST`). Sans ce témoin, la divergence se refermerait par
     * accident à la prochaine passe (le prédicat se remettrait à contrôler l'accès).
     */
    public function testAnOutOfAccessWindowHomeFixtureStaysEligibleAndTheRadarSignalsIt(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Un créneau d'accès match 14:00–18:00 le jour du match — que le coup d'envoi
        // (20:30) NE couvre PAS. Le geste unitaire refuserait cette pose ; le lot, non.
        $date = '2099-03-14';
        $day = (int) new DateTimeImmutable($date)->format('N');
        $this->createWindow($clubId, $seasonId, $venue->getId(), $day, '14:00', '18:00');
        $fixtureId = $this->eligible($clubId, $seasonId, $team->getId(), $venue->getId(), $date, '20:30')->getId();

        // (1) Le prédicat ne fait PAS le contrôle d'accès : la rencontre reste validable.
        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $count = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $count['totalValidatable'], 'hors créneau d\'accès mais enregistrée côté fédération → validable');

        // (2) Elle bascule malgré tout (la réalité fédérale fait foi). L'EM a été vidé par
        // le GET intermédiaire : on RELIT par id plutôt que refresh sur une entité détachée.
        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $this->em->clear();
        $fixture = $this->em->find(Fixture::class, $fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureStatus::VALIDATED, $fixture->getStatus());
        self::assertSame(FixturePlacementSource::MANUAL, $fixture->getPlacementSource());

        // (3) L'incohérence n'est pas tue : le radar la signale (ACCESS_WINDOW_LOST).
        $this->client->request('GET', '/api/fixtures/conflicts', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $radar = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($radar['conflicts'] ?? null);
        $signalled = array_filter(
            $radar['conflicts'],
            static fn (array $c): bool => 'ACCESS_WINDOW_LOST' === ($c['type'] ?? null) && ($c['fixture']['fixtureId'] ?? null) === $fixture->getId(),
        );
        self::assertCount(1, $signalled, 'le radar signale la rencontre validée hors créneau d\'accès');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le test enchaîne DEUX requêtes (validation ligue, puis placement) : sans ça le
        // noyau redémarre entre les deux et détache les entités (refresh impossible).
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** POST /api/fixtures/place — skip the test when the engine is not up (502). */
    private function placeOrSkip(string $token): void
    {
        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        if (502 === $this->client->getResponse()->getStatusCode()) {
            self::markTestSkipped('Engine not available');
        }
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * A home fixture made eligible: UNPLACED + venue + kickoff, no pending deviation, and
     * its competition MATURED (entry deadline already passed — the default is well in the
     * past). Pass a future `$competitionDeadline` to build the danger case (a validatable
     * fixture whose championship deadline has NOT passed yet).
     */
    private function eligible(string $clubId, string $seasonId, string $teamId, string $venueId, string $date, string $kickoff = '15:30', ?string $competitionDeadline = '2020-09-10'): Fixture
    {
        $fixture = $this->createFixture($clubId, $seasonId, $teamId, $date, $competitionDeadline);
        $fixture->setVenueId($venueId);
        $fixture->setKickoffTime(new DateTimeImmutable($kickoff));
        $this->em->flush();

        return $fixture;
    }

    /** The competition a fixture belongs to (to assert on its outlook entry). */
    private function competitionOf(Fixture $fixture): Competition
    {
        $competition = $this->em->find(Competition::class, (string) $fixture->getCompetitionId());
        self::assertInstanceOf(Competition::class, $competition);

        return $competition;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [adminToken, clubId, seasonId]
     */
    private function createClub(bool $settleSocle = true): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('BC League ' . $uid);
        $club->setSlug('bc-league-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        // Club démo = jamais bridé (P4-240 ④) : ces tests portent sur la MÉCANIQUE de placement.
        // Sans cela un club frais (Découverte) exigerait une fenêtre {from,to} et l'appel sans
        // corps de `/api/fixtures/place` serait refusé 403 (la règle crédit a sa propre garde,
        // PlanEntitlementsTest).
        $club->setIsDemo(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('league' . $uid . '@test.com');
        $user->setFirstName('League');
        $user->setLastName('User');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        if ($settleSocle) {
            $this->settleSeasonPlan($season);
        }

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $club->getId(), $season->getId()];
    }

    private function addMember(string $clubId, string $role): string
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User;
        $user->setEmail($role . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('M');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function createTeam(string $clubId, string $seasonId): Team
    {
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SF3');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createVenue(string $clubId, string $seasonId): Venue
    {
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName('Mateo');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createWindow(string $clubId, string $seasonId, string $venueId, int $day, string $start, string $end): void
    {
        $window = new VenueMatchWindow;
        $window->setClubId($clubId);
        $window->setSeasonId($seasonId);
        $window->setVenueId($venueId);
        $window->setDayOfWeek($day);
        $window->setStartTime(new DateTimeImmutable($start));
        $window->setEndTime(new DateTimeImmutable($end));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function createFixture(string $clubId, string $seasonId, string $teamId, string $date, ?string $competitionDeadline = null, ?string $competitionName = null): Fixture
    {
        // Match de COMPÉTITION : un domicile sans compétition sortirait du payload de
        // placement (les amicaux ne sont plus confiés au solveur, P4-193). Chaque
        // rencontre porte SA compétition (jetable) — dont l'échéance de saisie pilote
        // désormais la validation ligue. Un `competitionName` explicite permet le cas
        // « Amical X » typé CHAMPIONSHIP (jamais candidat, reconnu au libellé).
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName($competitionName ?? 'D2-' . uniqid('', true));
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        if (null !== $competitionDeadline) {
            $competition->setEntryDeadline(new DateTimeImmutable($competitionDeadline));
        }
        $this->em->persist($competition);
        $this->em->flush();

        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    /** Un amical SANS compétition (competitionId null), domicile ou extérieur, à une date donnée. */
    private function friendlyNoCompetition(string $clubId, string $seasonId, string $teamId, string $date, FixtureHomeAway $homeAway): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId(null);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('Amical Adv');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
