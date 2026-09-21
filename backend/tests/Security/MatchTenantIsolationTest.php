<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\ClubTravelCache;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\ConflictResolution;
use App\Entity\FbiCorrection;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\CompetitionType;
use App\Enum\ConflictResolutionStatus;
use App\Enum\FbiCorrectionField;
use App\Enum\FbiIngestionSource;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Enum\FixtureStatus;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Enum\TeamLinkType;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
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
 * Tenant/season isolation NR for the new match entities (spec gestion-matchs,
 * §7.1 tenant axis): Competition/Fixture of club/season A never leak to club B,
 * writes stamp the resolved club+season, and archived-season writes are
 * refused (409, inherited SeasonAccessGuard).
 */
#[Group('phase1')]
#[Group('integration')]
final class MatchTenantIsolationTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testFixturesAreScopedToTheCallersClub(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        $this->createFixture($clubA, 'Adversaire A');
        [$clubB] = $this->createClubUser('b');
        $this->createFixture($clubB, 'Adversaire B');

        $this->client->request('GET', '/api/fixtures', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);
        $labels = array_map(
            static fn (array $m): string => $m['opponentLabel'],
            $this->responseData()['member'] ?? [],
        );
        self::assertSame(['Adversaire A'], $labels);
    }

    public function testItemOfAnotherClubIs404(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        [$clubB] = $this->createClubUser('b');
        $foreign = $this->createFixture($clubB, 'Adversaire B');
        $this->em->clear();

        $this->client->request('GET', '/api/fixtures/' . $foreign->getId(), [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPostStampsTheResolvedClubAndSeason(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $teamId = '11111111-1111-4111-8111-111111111111';

        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => $teamId,
            'matchDate' => '2026-10-04',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Nouvel adversaire',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['opponentLabel' => 'Nouvel adversaire']);
        self::assertNotNull($fixture);
        self::assertSame($clubA->getId(), $fixture->getClubId());
        self::assertSame($seasonA->getId(), $fixture->getSeasonId());
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus());
    }

    public function testCompetitionCollectionIsScoped(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $this->createCompetition($clubA, $seasonA, 'Championnat A');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $this->createCompetition($clubB, $seasonB, 'Championnat B');

        $this->client->request('GET', '/api/competitions', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);
        $names = array_map(static fn (array $m): string => $m['name'], $this->responseData()['member'] ?? []);
        self::assertSame(['Championnat A'], $names);
    }

    public function testWriteOnArchivedSeasonIsRefused(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        // Add a PAST season → it becomes archived (read-only).
        $this->scopeGucToClub($clubA->getId());
        $past = $this->season($clubA, SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1);
        $this->em->flush();

        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders($userA) + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'teamId' => '11111111-1111-4111-8111-111111111111',
            'matchDate' => '2025-10-04',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Archive',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    // ── Capacité matchs (P1-4 PR B) : mêmes frontières pour les deux nouvelles entités ──

    public function testVenueMatchWindowsAreScopedAndStampTheResolvedClub(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Gymnase A');
        [$clubB, $userB, $seasonB] = $this->createClubUser('b');
        $this->createVenue($clubB, $seasonB, 'Gymnase B');

        $this->client->request('POST', '/api/venue_match_windows', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueA->getId(),
            'dayOfWeek' => 6,
            'startTime' => '14:00',
            'endTime' => '22:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $window = $this->em->getRepository(VenueMatchWindow::class)->findOneBy(['venueId' => $venueA->getId()]);
        self::assertNotNull($window);
        self::assertSame($clubA->getId(), $window->getClubId());
        self::assertSame($seasonA->getId(), $window->getSeasonId());

        // Club B sees nothing of it.
        $this->client->request('GET', '/api/venue_match_windows', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $this->responseData()['member'] ?? ['sentinel']);
    }

    public function testCapacityWritesCannotTargetAForeignVenue(): void
    {
        // The venue of the OTHER club is invisible through the tenant filters →
        // 422, no dangling cross-club reference (both new entities).
        [, $userA] = $this->createClubUser('a');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $venueB = $this->createVenue($clubB, $seasonB, 'Gymnase B');

        $this->client->request('POST', '/api/venue_match_windows', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueB->getId(), 'dayOfWeek' => 6, 'startTime' => '14:00', 'endTime' => '22:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/venue_unavailabilities', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueB->getId(), 'startDate' => '2027-02-04', 'endDate' => '2027-02-28',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(VenueMatchWindow::class)->findBy(['venueId' => $venueB->getId()]));
        self::assertCount(0, $this->em->getRepository(VenueUnavailability::class)->findBy(['venueId' => $venueB->getId()]));
    }

    public function testExternalLabelAttachCannotTargetAForeignVenueNorBackfillAcrossClubs(): void
    {
        // P4-187a NR (axe tenant §7.1) — rattacher un libellé sur le gymnase d'un
        // AUTRE club est invisible → 404, zéro écriture ; et le backfill d'un rattachement
        // légitime ne touche jamais les rencontres d'un autre club.
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Gymnase A');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $venueB = $this->createVenue($clubB, $seasonB, 'Gymnase B');
        $foreignFixtureId = $this->createHomeFixtureWithLabel($clubB, $seasonB, 'GYMNASE MATEO');

        // (1) POST sur le gymnase de B, en tant que A → 404, rien écrit chez B.
        $this->client->request('POST', '/api/venues/' . $venueB->getId() . '/external-labels', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode(['label' => 'GYMNASE MATEO'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        // (2) Un rattachement LÉGITIME chez A ne backfille aucune rencontre de B.
        $this->client->request('POST', '/api/venues/' . $venueA->getId() . '/external-labels', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode(['label' => 'GYMNASE MATEO'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->responseData()['attached'] ?? -1, 'aucune rencontre de A ne porte ce libellé → 0 rattaché');

        // Lecture BRUTE sous le scope de B (le season_filter épinglerait la lecture
        // ORM à la saison de A ; la RLS scope le club sur la connexion dama partagée).
        $this->scopeGucToClub($clubB->getId());
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('[]', (string) $connection->fetchOne('SELECT external_labels FROM venue WHERE id = ?', [$venueB->getId()]), 'le gymnase de B n\'a rien reçu');
        self::assertNull($connection->fetchOne('SELECT venue_id FROM fixture WHERE id = ?', [$foreignFixtureId]) ?: null, 'la rencontre de B n\'est jamais backfillée par un rattachement de A');
    }

    public function testFbiLabelInventoryAndReassignNeverCrossClubs(): void
    {
        // E1 NR (axe tenant §7.1) — l'inventaire ne liste jamais les libellés d'un
        // autre club, et une ré-affectation ne re-pointe jamais un domicile étranger.
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Gymnase A');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $venueB = $this->createVenue($clubB, $seasonB, 'Gymnase B');
        // B : un domicile NON PLACÉ au même libellé, déjà pointé sur son propre gymnase.
        $foreignHome = $this->createHomeFixtureWithLabel($clubB, $seasonB, 'GYMNASE MATEO', $venueB->getId());

        // (1) L'inventaire de A (aucun domicile) ne remonte aucun libellé de B.
        $this->client->request('GET', '/api/venues/fbi-labels', [], [], $this->authHeaders($userA));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->responseData()['labels'] ?? ['?'], 'A n\'a aucun domicile → aucun libellé, jamais ceux de B');

        // (2) Une ré-affectation légitime chez A ne re-pointe aucune rencontre de B.
        $this->client->request('POST', '/api/venues/' . $venueA->getId() . '/external-labels', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode(['label' => 'GYMNASE MATEO', 'reassign' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->responseData()['attached'] ?? -1, 'aucune rencontre de A ne porte ce libellé → 0 re-pointé');

        // Lecture brute sous le scope de B : son domicile garde son gymnase.
        $this->scopeGucToClub($clubB->getId());
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame($venueB->getId(), (string) $connection->fetchOne('SELECT venue_id FROM fixture WHERE id = ?', [$foreignHome]), 'le domicile de B n\'est jamais re-pointé par une ré-affectation de A');
    }

    public function testVenueUnavailabilityIsManagementGatedAndSeasonGuarded(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Gymnase A');

        // Cockpit-surface write (SEC-07): a non-management member is refused.
        $editor = $this->createMember($clubA, 'editor');
        $this->client->request('POST', '/api/venue_unavailabilities', [], [], $this->authHeaders($editor) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueA->getId(), 'startDate' => '2027-02-04', 'endDate' => '2027-02-28',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        // Archived-season writes refused (inherited SeasonAccessGuard).
        $this->scopeGucToClub($clubA->getId());
        $past = $this->season($clubA, SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1);
        $this->em->flush();
        $this->client->request('POST', '/api/venue_unavailabilities', [], [], $this->authHeaders($userA) + [
            'HTTP_X-Season-Id' => $past->getId(), 'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'venueId' => $venueA->getId(), 'startDate' => '2026-02-04', 'endDate' => '2026-02-28',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    // ── Préférences matchs (P1-4 PR C) : habitudes + passerelles ──────────────

    public function testHabitsAreScopedStampedAndUniquePerDay(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $teamA = $this->createTeam($clubA, $seasonA, 'SF3');
        [, $userB] = $this->createClubUser('b');
        $headers = $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/team_match_habits', [], [], $headers, json_encode([
            'teamId' => $teamA->getId(), 'dayOfWeek' => 7, 'kickoffTime' => '17:30',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $habit = $this->em->getRepository(TeamMatchHabit::class)->findOneBy(['teamId' => $teamA->getId()]);
        self::assertSame($clubA->getId(), $habit?->getClubId());
        self::assertSame($seasonA->getId(), $habit?->getSeasonId());

        // One habit per weekday: readable 422, not a DB 500.
        $this->client->request('POST', '/api/team_match_habits', [], [], $headers, json_encode([
            'teamId' => $teamA->getId(), 'dayOfWeek' => 7, 'kickoffTime' => '10:30',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // Club B sees nothing.
        $this->client->request('GET', '/api/team_match_habits', [], [], $this->authHeaders($userB));
        self::assertCount(0, $this->responseData()['member'] ?? ['sentinel']);
    }

    public function testTeamLinkIsSymmetricUniqueAndTenantScoped(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $sm1 = $this->createTeam($clubA, $seasonA, 'SM1');
        $sm2 = $this->createTeam($clubA, $seasonA, 'SM2');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $foreign = $this->createTeam($clubB, $seasonB, 'Étrangère');
        $headers = $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $sm1->getId(), 'teamBId' => $sm2->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // SM2–SM1 is the SAME couple (normalized) → readable 422 duplicate.
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $sm2->getId(), 'teamBId' => $sm1->getId(), 'linkType' => 'BACK_TO_BACK',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // A foreign team is invisible → 422, no cross-club write.
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $sm1->getId(), 'teamBId' => $foreign->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->em->getRepository(TeamLink::class)->findBy(['clubId' => $clubA->getId()]));

        // A team linked to itself is refused.
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $sm1->getId(), 'teamBId' => $sm1->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Lot PASSERELLES PR-1 — le plafond se refuse à la SAISIE, jamais à la génération.
     * Sans lui, la 51ᵉ passerelle passerait ici et ferait 422-FAILED le solve (le bord
     * Pydantic `MAX_TEAM_LINKS` la refuse) : une panne loin de sa cause. Le miroir des
     * deux littéraux est gardé par TeamLinkPayloadParityTest::testWriteCapMirrorsTheEngineEdgeCap.
     */
    public function testTheFiftyFirstTeamLinkIsRefusedAtWriteTime(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('cap');
        $headers = $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'];

        // 11 équipes offrent 55 couples : on en persiste 50 directement (le rail API
        // n'est pas le sujet ici), la 51ᵉ passe par l'API et doit être refusée.
        $teams = [];
        for ($i = 0; $i < 11; ++$i) {
            $teams[] = $this->createTeam($clubA, $seasonA, 'Cap' . $i);
        }
        $seeded = 0;
        for ($a = 0; $a < 11 && $seeded < 50; ++$a) {
            for ($b = $a + 1; $b < 11 && $seeded < 50; ++$b) {
                [$low, $high] = strcasecmp($teams[$a]->getId(), $teams[$b]->getId()) <= 0
                    ? [$teams[$a], $teams[$b]] : [$teams[$b], $teams[$a]];
                $link = new TeamLink;
                $link->setClubId($clubA->getId());
                $link->setSeasonId($seasonA->getId());
                $link->setTeamAId($low->getId());
                $link->setTeamBId($high->getId());
                $link->setLinkType(TeamLinkType::NOT_SIMULTANEOUS);
                $this->em->persist($link);
                ++$seeded;
            }
        }
        $this->em->flush();

        // La 51ᵉ (le couple encore libre) est refusée avec un message NOMMÉ.
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $teams[9]->getId(), 'teamBId' => $teams[10]->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('nombre maximal de passerelles', (string) $this->client->getResponse()->getContent());

        // Supprimer une passerelle rouvre la porte : le cap borne, il ne fige pas.
        $one = $this->em->getRepository(TeamLink::class)->findOneBy(['clubId' => $clubA->getId()]);
        self::assertInstanceOf(TeamLink::class, $one);
        $this->em->remove($one);
        $this->em->flush();
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $teams[9]->getId(), 'teamBId' => $teams[10]->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    // ── Rotation A/B (RMM-5) : le créneau de match partagé et ses membres, scopés+stampés ──

    public function testMatchSlotRotationsAreScopedStampedAndUnique(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Coubertin');
        $sm1 = $this->createTeam($clubA, $seasonA, 'SM1');
        $sm2 = $this->createTeam($clubA, $seasonA, 'SM2');
        [, $userB] = $this->createClubUser('b');
        $headers = $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, json_encode([
            'venueId' => $venueA->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30',
            'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Parent AND member rows carry the resolved club+season (denormalized on the member).
        $rotation = $this->em->getRepository(MatchSlotRotation::class)->findOneBy(['venueId' => $venueA->getId()]);
        self::assertSame($clubA->getId(), $rotation?->getClubId());
        self::assertSame($seasonA->getId(), $rotation?->getSeasonId());
        $members = $this->em->getRepository(MatchSlotRotationTeam::class)->findBy(['rotationId' => $rotation?->getId()]);
        self::assertCount(2, $members);
        foreach ($members as $member) {
            self::assertSame($clubA->getId(), $member->getClubId());
            self::assertSame($seasonA->getId(), $member->getSeasonId());
        }

        // Same physical slot → readable 422 (the DB unique is the backstop).
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, json_encode([
            'venueId' => $venueA->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30',
            'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // Club B sees nothing of it.
        $this->client->request('GET', '/api/match_slot_rotations', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $this->responseData()['member'] ?? ['sentinel']);
    }

    public function testMatchSlotRotationCannotTargetAForeignTeam(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Coubertin');
        $sm1 = $this->createTeam($clubA, $seasonA, 'SM1');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $foreign = $this->createTeam($clubB, $seasonB, 'Étrangère');

        // A foreign team is invisible through the tenant filters → 422, no cross-club write.
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueA->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30',
            'teamIds' => [$sm1->getId(), $foreign->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(MatchSlotRotation::class)->findBy(['clubId' => $clubA->getId()]));
    }

    // ── Trajet adverse (P2-54 RMM-9 PR-3) : le trajet est CLUB-spécifique (siège) ──

    /**
     * NR axe §7.1 tenant isolation : l'appariement « libellé → gymnase » vit dans une table
     * TENANT (club-scoped, sans saison — amendement 2026-09-20). Club A ne lit/écrit jamais
     * l'`OpponentVenueLink` de club B. Falsifié dans les deux sens : chaque club voit SON lien
     * et RIEN de l'autre.
     */
    public function testOpponentVenueLinkIsTenantScopedAndDoesNotLeak(): void
    {
        [$clubA] = $this->createClubUser('a');
        [$clubB, $userB] = $this->createClubUser('b');

        // Un lien pour A, un pour B — MÊME code adverse et MÊME libellé : seule la frontière
        // tenant (RLS) les sépare.
        $this->seedLink($clubA, 'ORGSHARED', 'SALLE X', '166900001', OpponentVenueLinkSource::MANUAL);
        $this->seedLink($clubB, 'ORGSHARED', 'SALLE X', '166900002', OpponentVenueLinkSource::MANUAL);

        // Club A ne voit QUE son lien (ref …001), jamais celui de B.
        $this->scopeGucToClub($clubA->getId());
        $rowsA = $this->em->getRepository(OpponentVenueLink::class)->findBy(['opponentOrganismeCode' => 'ORGSHARED']);
        self::assertCount(1, $rowsA);
        self::assertSame($clubA->getId(), $rowsA[0]->getClubId());
        self::assertSame('166900001', $rowsA[0]->getVenueExternalRef());

        // Club B ne voit QUE …002.
        $this->scopeGucToClub($clubB->getId());
        $rowsB = $this->em->getRepository(OpponentVenueLink::class)->findBy(['opponentOrganismeCode' => 'ORGSHARED']);
        self::assertCount(1, $rowsB);
        self::assertSame('166900002', $rowsB[0]->getVenueExternalRef());

        // Le feed de B ne montre jamais les adversaires de A (B n'a aucune rencontre AWAY).
        $this->client->request('GET', '/api/opponents/travel', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseData()['opponents'] ?? ['sentinel']);
    }

    public function testClubTravelCacheIsTenantScoped(): void
    {
        [$clubA] = $this->createClubUser('a');
        [$clubB] = $this->createClubUser('b');
        $cache = self::getContainer()->get(TravelTimeCache::class);

        // Même clé EXACTE (mêmes coordonnées, même profil) pour les deux clubs — seule la
        // frontière tenant (RLS) sépare leurs deux valeurs.
        $this->scopeGucToClub($clubA->getId());
        $cache->store($clubA->getId(), IgnRoutingClient::PROFILE_CAR, 45.5, 4.5, 46.0, 5.0, 42);
        $this->scopeGucToClub($clubB->getId());
        $cache->store($clubB->getId(), IgnRoutingClient::PROFILE_CAR, 45.5, 4.5, 46.0, 5.0, 99);

        // Club A ne voit QUE sa valeur (42) — jamais la ligne de B.
        $this->scopeGucToClub($clubA->getId());
        self::assertSame(42, $cache->lookup($clubA->getId(), IgnRoutingClient::PROFILE_CAR, 45.5, 4.5, 46.0, 5.0));
        $rowsA = $this->em->getRepository(ClubTravelCache::class)->findAll();
        self::assertCount(1, $rowsA, 'la RLS ne montre au club A que sa propre ligne de cache');
        self::assertSame($clubA->getId(), $rowsA[0]->getClubId());

        // Club B ne voit QUE 99.
        $this->scopeGucToClub($clubB->getId());
        self::assertSame(99, $cache->lookup($clubB->getId(), IgnRoutingClient::PROFILE_CAR, 45.5, 4.5, 46.0, 5.0));
    }

    /**
     * Les gestes d'appariement sont management-gated (SEC-07) : un membre non-management est
     * refusé (403), et un code d'adversaire inconnu (aucune rencontre AWAY) rend 422 sans écrire.
     */
    public function testOpponentVenueWritesAreManagementGated(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        $editor = $this->createMember($clubA, 'editor');

        // Un membre non-management ne peut pas apparier (403 gagne sur le 422).
        $this->client->request('POST', '/api/opponents/ORGX/venues', [], [], $this->authHeaders($editor) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueLabel' => 'Gymnase', 'fbiLabel' => 'SALLE X', 'latitude' => 45.7, 'longitude' => 4.9,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        // Un code sans rencontre AWAY du club cette saison → 422, aucune écriture.
        $this->client->request('POST', '/api/opponents/ORGX/venues', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueLabel' => 'Gymnase', 'fbiLabel' => 'SALLE X', 'latitude' => 45.7, 'longitude' => 4.9,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        $this->scopeGucToClub($clubA->getId());
        self::assertCount(0, $this->em->getRepository(OpponentVenueLink::class)->findBy(['opponentOrganismeCode' => 'ORGX']));
    }

    /**
     * NR axe §7.1 tenant isolation — un lien d'un club reste scopé TENANT : club B ne peut ni
     * lire ni ré-apparier/supprimer le lien de A. Un PUT/DELETE de B sur l'id du lien de A rend
     * 404 byte-identique (RLS : le lien de A est invisible à B). Falsifié : le lien de A intact.
     */
    public function testAForeignClubCannotReadOrRepointAnotherClubsLink(): void
    {
        [$clubA] = $this->createClubUser('teama');
        [$clubB, $userB] = $this->createClubUser('teamb');

        $link = $this->seedLink($clubA, 'ORGTEAM', 'SALLE A', '166900042', OpponentVenueLinkSource::MANUAL);

        // Club A voit son lien ; club B ne voit rien.
        $this->scopeGucToClub($clubA->getId());
        self::assertCount(1, $this->em->getRepository(OpponentVenueLink::class)->findBy(['opponentOrganismeCode' => 'ORGTEAM']));
        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(OpponentVenueLink::class)->findBy(['opponentOrganismeCode' => 'ORGTEAM']));

        // Club B tente de ré-apparier le lien de A → 404 (le lien de A est invisible, RLS).
        $this->client->request('PUT', '/api/opponents/venue-links/' . $link->getId(), [], [], $this->authHeaders($userB) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueLabel' => 'Gymnase pirate', 'latitude' => 45.7, 'longitude' => 4.9,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        // Le lien de A est intact.
        $this->scopeGucToClub($clubA->getId());
        $this->em->clear();
        $filters = $this->em->getFilters();
        foreach (['tenant_filter', 'season_filter'] as $filter) {
            if ($filters->isEnabled($filter)) {
                $filters->disable($filter);
            }
        }
        $survivor = $this->em->getRepository(OpponentVenueLink::class)->findOneBy(['opponentOrganismeCode' => 'ORGTEAM']);
        self::assertInstanceOf(OpponentVenueLink::class, $survivor);
        self::assertSame($clubA->getId(), $survivor->getClubId());
        self::assertSame('166900042', $survivor->getVenueExternalRef());
        self::assertSame('Gymnase A', $survivor->getVenueLabel());
    }

    /**
     * NR axe §7.1 tenant isolation — l'ORCHESTRATEUR `POST /api/opponents/refresh` lancé par B
     * (rattrapage des codes, auto-appariement des libellés, recalcul des trajets) n'écrit RIEN
     * chez A. Falsifié : le lien AUTO de A reste byte-identique après le refresh de B.
     */
    public function testRefreshOfBWritesNothingAtA(): void
    {
        [$clubA] = $this->createClubUser('autoa');
        [$clubB, $userB] = $this->createClubUser('autob');

        $this->seedLink($clubA, 'ORGAUTO', 'GYMNASE FEDERAL A', '166900777', OpponentVenueLinkSource::AUTO);

        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(OpponentVenueLink::class)->findBy(['opponentOrganismeCode' => 'ORGAUTO']));

        // Club B lance l'orchestrateur — B n'a aucune rencontre AWAY, les trois passes n'écrivent
        // rien, et le lien tenant de A est de toute façon inatteignable pour B.
        $this->client->request('POST', '/api/opponents/refresh', [], [], $this->authHeaders($userB) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubA->getId());
        $this->em->clear();
        $filters = $this->em->getFilters();
        foreach (['tenant_filter', 'season_filter'] as $filter) {
            if ($filters->isEnabled($filter)) {
                $filters->disable($filter);
            }
        }
        $survivor = $this->em->getRepository(OpponentVenueLink::class)->findOneBy(['opponentOrganismeCode' => 'ORGAUTO']);
        self::assertInstanceOf(OpponentVenueLink::class, $survivor);
        self::assertSame($clubA->getId(), $survivor->getClubId());
        self::assertSame(OpponentVenueLinkSource::AUTO, $survivor->getSource());
        self::assertSame('166900777', $survivor->getVenueExternalRef());
    }

    // ── Résolution des conflits (P4-207) : le statut vit dans une table TENANT ──

    /**
     * NR axe §7.1 tenant isolation : le statut de traitement d'un conflit vit dans
     * une table TENANT keyée sur l'empreinte. Club A pose un statut ; club B ne le
     * voit jamais dans son radar, et un PUT de B sur l'empreinte de A est refusé
     * (422 : absente du flux de B) — jamais une fuite ni un écrasement. Falsifié :
     * la ligne de A reste intacte en base après la tentative de B.
     */
    public function testConflictResolutionIsTenantScoped(): void
    {
        // Le radar tait les matchs passés (civil today) : on épingle l'horloge pour
        // que la rencontre du décor (2026-10-04) reste future quoi qu'il arrive.
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-01 10:00:00'));

        [$clubA, $userA, $seasonA] = $this->createClubUser('cra');
        [$clubB, $userB, $seasonB] = $this->createClubUser('crb');
        $this->createAwayNoFootprintFixture($clubA, $seasonA);
        $this->createAwayNoFootprintFixture($clubB, $seasonB);

        // Club A stamps a status on its own conflict.
        $fingerprintA = $this->firstConflict($userA)['fingerprint'];
        self::assertIsString($fingerprintA);
        $this->putConflictResolution($userA, $fingerprintA, ['status' => 'DEROGATION_REQUESTED', 'note' => 'A seulement']);
        self::assertResponseStatusCodeSame(200);

        // A sees its resolution; B sees its OWN conflict (a distinct fingerprint) with
        // resolution null — never A's row.
        self::assertSame('DEROGATION_REQUESTED', $this->firstConflict($userA)['resolution']['status']);
        $conflictB = $this->firstConflict($userB);
        self::assertNull($conflictB['resolution'], 'club B ne voit jamais la résolution de A');
        self::assertNotSame($fingerprintA, $conflictB['fingerprint'], 'chaque club a sa propre empreinte (fixture distincte)');

        // B stamps A's fingerprint → 422 (absent from B's flow): no leak, no overwrite.
        $this->putConflictResolution($userB, $fingerprintA, ['status' => 'RESOLVED_INTERNALLY']);
        self::assertResponseStatusCodeSame(422);

        // A's row is untouched in the DB: still one row, club A, DEROGATION_REQUESTED.
        $this->em->clear();
        $this->scopeGucToClub($clubA->getId());
        $rows = $this->em->getRepository(ConflictResolution::class)->findBy(['fingerprint' => $fingerprintA]);
        self::assertCount(1, $rows);
        self::assertSame($clubA->getId(), $rows[0]->getClubId());
        self::assertSame(ConflictResolutionStatus::DEROGATION_REQUESTED, $rows[0]->getStatus());
        self::assertSame('A seulement', $rows[0]->getNote());
    }

    /**
     * The conflict-resolution writes are management-gated (SEC-07): a non-management
     * member is refused on PUT and on DELETE (403 wins, before any flow check).
     */
    public function testConflictResolutionWritesAreManagementGated(): void
    {
        [$clubA] = $this->createClubUser('crg');
        $editor = $this->createMember($clubA, 'editor');
        // Well-formed fingerprint (passes the route requirement) — the 403 must fire
        // before any flow/existence check.
        $fingerprint = 'AWAY_NO_FOOTPRINT:11111111-1111-4111-8111-111111111111';

        $this->putConflictResolution($editor, $fingerprint, ['status' => 'DEROGATION_REQUESTED']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', $this->conflictResolutionUrl($fingerprint), [], [], $this->authHeaders($editor));
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * NR axe §7.1 tenant isolation (lot N) — « erreur FBI » ouvre un NOUVEAU chemin d'écriture
     * vers le registre « à corriger dans FBI ». Un club qui déclare une erreur FBI en visant le
     * conflit d'un AUTRE club est refusé (422 : empreinte absente de son flux), et AUCUNE entrée
     * n'est créée — le chemin d'écriture se trouve derrière la validation tenant du flux.
     */
    public function testFbiErrorDeclarationCannotTargetAnotherClubsConflict(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-01 10:00:00'));

        [$clubA, $userA, $seasonA] = $this->createClubUser('fea');
        [$clubB, $userB, $seasonB] = $this->createClubUser('feb');
        $fixtureAId = $this->createAwayNoFootprintFixture($clubA, $seasonA);
        $this->createAwayNoFootprintFixture($clubB, $seasonB);

        $fingerprintA = $this->firstConflict($userA)['fingerprint'];
        self::assertIsString($fingerprintA);

        // B déclare une erreur FBI en visant l'empreinte de A → 422 (absente du flux de B).
        $this->putConflictResolution($userB, $fingerprintA, [
            'status' => 'FBI_ERROR',
            'fbiCorrection' => ['fixtureId' => $fixtureAId, 'field' => 'venue'],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Aucune entrée « à corriger dans FBI » n'a été créée, ni chez A ni chez B.
        $this->scopeGucToClub($clubA->getId());
        self::assertCount(0, $this->em->getRepository(FbiCorrection::class)->findBy(['fixtureId' => $fixtureAId]));
        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(FbiCorrection::class)->findAll());

        unset($clubB, $seasonB);
    }

    /**
     * NR axe §7.1 tenant isolation (lot N, point 4) — LA garde qui vérifie que la rencontre
     * désignée par une « erreur FBI » appartient bien AU CONFLIT. Le club B poste sur SON
     * PROPRE conflit de collision (valide, empreinte présente dans SON flux : la garde AMONT
     * est franchie), mais nomme dans le complément une rencontre qui n'est PAS un côté de ce
     * conflit → refus, aucune ligne créée.
     *
     * ⚠ Écrit pour être FALSIFIABLE (backend.md §défense en profondeur) : la cible du cas (1)
     * est une rencontre VISIBLE de B mais HORS du conflit — désactiver la SEULE garde
     * d'appartenance la laisse alors ouvrir une entrée (test rouge, preuve de chute faite).
     * Nommer une rencontre d'un AUTRE club (cas (2)) ne prouverait PAS cette garde : la
     * recherche de fixture tenant-filtrée la réduit déjà à null (« introuvable »), seconde
     * ceinture qui garderait le test vert la garde d'appartenance désactivée. Le cas existant
     * {@see self::testFbiErrorDeclarationCannotTargetAnotherClubsConflict} couvre, lui, la
     * garde AMONT (empreinte absente du flux). On vérifie tout de même ici la sécurité
     * cross-club (cas (2)), sans aucune fuite.
     */
    public function testFbiErrorComplementMustNameASideOfTheOwnConflict(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-01 10:00:00'));

        [$clubA, , $seasonA] = $this->createClubUser('csa');
        [$clubB, $userB, $seasonB] = $this->createClubUser('csb');

        // B a un VRAI conflit de collision (empreinte dans SON flux) ...
        $this->createVenueOverlapConflict($clubB, $seasonB);
        // ... plus une rencontre VISIBLE de B hors du conflit (la cible qui isole la garde) ...
        $bystanderB = $this->createLoneHomeFixture($clubB, $seasonB);
        // ... et A a une rencontre à lui (cible cross-club du cas 2).
        $foreignA = $this->createLoneHomeFixture($clubA, $seasonA);

        $fingerprintB = $this->firstConflict($userB)['fingerprint'];
        self::assertIsString($fingerprintB);

        // (1) B nomme une rencontre à LUI HORS du conflit → la garde d'appartenance refuse.
        $this->putConflictResolution($userB, $fingerprintB, [
            'status' => 'FBI_ERROR',
            'fbiCorrection' => ['fixtureId' => $bystanderB, 'field' => 'venue'],
        ]);
        self::assertResponseStatusCodeSame(422);

        // (2) B nomme une rencontre du club A → refusé aussi.
        $this->putConflictResolution($userB, $fingerprintB, [
            'status' => 'FBI_ERROR',
            'fbiCorrection' => ['fixtureId' => $foreignA, 'field' => 'venue'],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Aucune entrée « à corriger dans FBI » nulle part, ni chez A ni chez B.
        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(FbiCorrection::class)->findAll());
        $this->scopeGucToClub($clubA->getId());
        self::assertCount(0, $this->em->getRepository(FbiCorrection::class)->findAll());
    }

    /**
     * NR axe §7.1 tenant isolation — le registre « à corriger dans FBI » vit dans une
     * table TENANT. Club A ouvre une entrée ; club B ne la lit jamais (sa
     * `findOpenBySeason`/`findOpen` reste vide sur la MÊME rencontre+champ), et une
     * fermeture par B sur l'entrée de A est impossible (invisible sous le GUC de B).
     * Falsifié dans les deux sens : chaque club voit SA ligne et RIEN de l'autre.
     */
    public function testFbiCorrectionsAreTenantScoped(): void
    {
        [$clubA, , $seasonA] = $this->createClubUser('fca');
        [$clubB, , $seasonB] = $this->createClubUser('fcb');

        // Même rencontre (id) et même champ des deux côtés : SEULE la frontière tenant
        // les sépare — une fuite se verrait immédiatement.
        $fixtureId = '33333333-3333-4333-8333-333333333333';
        $this->seedFbiCorrection($clubA, $seasonA, $fixtureId, '15:00', '15:30');
        $this->seedFbiCorrection($clubB, $seasonB, $fixtureId, '18:00', '18:30');

        $repo = $this->em->getRepository(FbiCorrection::class);

        // Le registre de A voit SON entrée (app 15:00), jamais celle de B.
        $this->scopeGucToClub($clubA->getId());
        $openA = $repo->findOpenBySeason($seasonA->getId());
        self::assertCount(1, $openA);
        self::assertSame($clubA->getId(), $openA[0]->getClubId());
        self::assertSame('15:00', $openA[0]->getAppValue());
        self::assertInstanceOf(FbiCorrection::class, $repo->findOpen($fixtureId, FbiCorrectionField::KICKOFF));

        // Le registre de B voit SON entrée (app 18:00), jamais celle de A.
        $this->scopeGucToClub($clubB->getId());
        $openB = $repo->findOpenBySeason($seasonB->getId());
        self::assertCount(1, $openB);
        self::assertSame($clubB->getId(), $openB[0]->getClubId());
        self::assertSame('18:00', $openB[0]->getAppValue());

        // B ne « ferme » jamais l'entrée de A : sous le GUC de B, la ligne de A est
        // introuvable par son id (RLS), donc rien à fermer côté B.
        self::assertNull($repo->findOneBy(['id' => $openA[0]->getId()]));

        // La ligne de A reste OUVERTE en base sous le scope de A.
        $this->scopeGucToClub($clubA->getId());
        $this->em->clear();
        $survivor = $repo->findOneBy(['fixtureId' => $fixtureId, 'field' => FbiCorrectionField::KICKOFF]);
        self::assertInstanceOf(FbiCorrection::class, $survivor);
        self::assertTrue($survivor->isOpen());
        self::assertSame($clubA->getId(), $survivor->getClubId());
    }

    public function testFbiIngestionsAreScopedToTheClub(): void
    {
        [$clubA, , $seasonA] = $this->createClubUser('a');
        $this->scopeGucToClub($clubA->getId());
        $this->em->persist(new FbiIngestion($clubA->getId(), $seasonA->getId(), FbiIngestionSource::FBI_XLSX, new DateTimeImmutable, 3, 1, 0, 0));
        $this->em->flush();

        [$clubB, $userB] = $this->createClubUser('b');

        // Club B's freshness read (open to any member) sees nothing of club A's deposit.
        $this->client->request('GET', '/api/fbi-ingestions/latest', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertArrayHasKey('latest', $data);
        self::assertNull($data['latest']);

        // And the RLS-scoped repository confirms the boundary directly.
        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(FbiIngestion::class)->findBy(['clubId' => $clubA->getId()]));
    }

    public function testReviewEndpointsNeverTouchAForeignClubsFixtures(): void
    {
        [$clubA, $userA] = $this->createClubUser('rvA');
        [$clubB] = $this->createClubUser('rvB');
        $foreign = $this->createDeviatedFixture($clubB, 'Adversaire B');

        // Bulk/line review by fixtureIds: the foreign id is invisible → ignored.
        $this->client->request('POST', '/api/fixtures/review', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'fixtureIds' => [$foreign->getId()],
        ]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->responseData()['reviewed']);

        // Per-deviation review on a foreign fixture: 404 (tenant), nothing written.
        $this->client->request('POST', '/api/fixtures/review/deviations', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'fixtureId' => $foreign->getId(),
            'field' => 'date',
            'choice' => 'take_source',
        ]));
        self::assertResponseStatusCodeSame(404);

        // Club B's fixture is untouched: still OUT_OF_SYNC with its écart, its
        // date never adopted. Read raw (bypassing the ORM identity map) under B's
        // scope, on the shared dama connection.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($clubB->getId());
        $row = $em->getConnection()->fetchAssociative(
            'SELECT review_state, match_date, pending_deviations FROM fixture WHERE id = ?',
            [$foreign->getId()],
        );
        self::assertIsArray($row);
        self::assertSame('OUT_OF_SYNC', $row['review_state']);
        self::assertSame('2026-10-04', substr((string) $row['match_date'], 0, 10), 'take_source never fired cross-club');
        self::assertNotSame('[]', (string) $row['pending_deviations'], 'the écart is untouched');

        unset($clubA);
    }

    /**
     * NR axe §7.1 tenant isolation (revue lot L) — le geste « validé ligue » en lot ne
     * lit ni n'écrit jamais les rencontres d'un AUTRE club. Le club B possède une
     * rencontre ÉLIGIBLE ; sous SON identité B la compte à 1 (témoin : elle est bien
     * éligible), mais le club A la compte à 0 et n'en bascule aucune — et la rencontre
     * de B reste UNPLACED, sans source de placement. Cloisonnement club ET saison.
     */
    public function testLeagueValidationNeverReadsOrWritesAForeignClubsFixtures(): void
    {
        [$clubA, $userA] = $this->createClubUser('lva');
        [$clubB, $userB, $seasonB] = $this->createClubUser('lvb');
        $venueB = $this->createVenue($clubB, $seasonB, 'Gymnase B');

        // B : un domicile UNPLACED portant heure + gymnase, sans écart → éligible.
        $foreign = $this->createFixture($clubB, 'Adversaire B');
        $this->scopeGucToClub($clubB->getId());
        $foreign->setVenueId($venueB->getId());
        $foreign->setKickoffTime(new DateTimeImmutable('15:30'));
        $this->em->flush();
        $foreignId = $foreign->getId();

        // Témoin : sous SA propre identité, B compte bien 1 — le 0 vu par A ne peut donc
        // venir que du cloisonnement, jamais de l'inéligibilité de la rencontre.
        $this->client->request('GET', '/api/fixtures/league-validation', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->responseData()['count'] ?? -1);

        // A ne voit RIEN : compte 0…
        $this->client->request('GET', '/api/fixtures/league-validation', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->responseData()['count'] ?? -1);

        // …et une bascule par A ne touche aucune rencontre de B.
        $this->client->request('POST', '/api/fixtures/league-validation', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->responseData()['confirmed'] ?? -1);

        // La rencontre de B est intacte : toujours UNPLACED, aucune source de placement.
        // Lecture BRUTE sous le scope de B (la connexion dama partagée est scopée club).
        $this->scopeGucToClub($clubB->getId());
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $row = $connection->fetchAssociative('SELECT status, placement_source FROM fixture WHERE id = ?', [$foreignId]);
        self::assertIsArray($row);
        self::assertSame('UNPLACED', $row['status'], 'la bascule de A ne valide jamais la rencontre de B');
        self::assertNull($row['placement_source'], 'aucune source de placement posée cross-club');

        unset($clubA);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        // The conflict radar filters strictly past matches by the club's civil today
        // (DevAwareClock → DevClockStore, Redis, NOT rolled back by dama). Any test
        // that pins it must release it, whatever happened.
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    /** A placed HOME fixture carrying one open pending deviation (OUT_OF_SYNC). */
    private function createDeviatedFixture(Club $club, string $opponent): Fixture
    {
        $fixture = $this->createFixture($club, $opponent);
        $this->scopeGucToClub($club->getId());
        $fixture->setVenueId('22222222-2222-4222-8222-222222222222');
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $fixture->putPendingDeviation([
            'field' => 'date',
            'appValue' => '2026-10-04',
            'sourceValue' => '2026-10-11',
            'channel' => 'FBI_XLSX',
            'seenAt' => '2026-09-01T10:00:00+00:00',
            'autoApplied' => false,
        ]);
        $fixture->setReviewState(FixtureReviewState::OUT_OF_SYNC);
        $this->em->flush();

        return $fixture;
    }

    /** An OPEN « à corriger dans FBI » kickoff entry for the club+season. */
    private function seedFbiCorrection(Club $club, Season $season, string $fixtureId, string $appValue, string $fbiValue): void
    {
        $this->scopeGucToClub($club->getId());
        $row = (new FbiCorrection)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setFixtureId($fixtureId)
            ->setField(FbiCorrectionField::KICKOFF)
            ->setAppValue($appValue)
            ->setFbiValue($fbiValue)
            ->setDecidedBy('44444444-4444-4444-8444-444444444444');
        $this->em->persist($row);
        $this->em->flush();
    }

    private function seedLink(Club $club, string $code, string $fbiLabel, string $venueRef, OpponentVenueLinkSource $source): OpponentVenueLink
    {
        $this->scopeGucToClub($club->getId());
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        $link = (new OpponentVenueLink)
            ->setClubId($club->getId())
            ->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)
            ->setFbiLabelNorm($normalizer->normalize($fbiLabel))
            ->setVenueExternalRef($venueRef)
            ->setVenueLabel('Gymnase A')
            ->setLatitude(45.75)
            ->setLongitude(4.85)
            ->setSource($source);
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    private function createTeam(Club $club, Season $season, string $name): Team
    {
        $this->scopeGucToClub($club->getId());
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
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createVenue(Club $club, Season $season, string $name): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createMember(Club $club, string $role): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User;
        $user->setEmail($role . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club match ' . $suffix);
        $club->setSlug('club-match-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('match' . $uid . '@test.com');
        $user->setFirstName('Match');
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

        $season = $this->season($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $this->em->flush();

        return [$club, $user, $season];
    }

    private function season(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        // Matches require a settled season plan (cockpit state 3) — point the plan
        // at a version so these tests exercise the tenant boundary, not the guard.
        $this->settleSeasonPlan($season);

        return $season;
    }

    private function createFixture(Club $club, string $opponent): Fixture
    {
        $this->scopeGucToClub($club->getId());
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]);
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel($opponent);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function createHomeFixtureWithLabel(Club $club, Season $season, string $venueLabel, ?string $venueId = null): string
    {
        $this->scopeGucToClub($club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setFbiVenueLabel($venueLabel);
        if (null !== $venueId) {
            $fixture->setVenueId($venueId);
        }
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    private function createCompetition(Club $club, Season $season, string $name): Competition
    {
        $this->scopeGucToClub($club->getId());
        $competition = new Competition;
        $competition->setClubId($club->getId());
        $competition->setSeasonId($season->getId());
        $competition->setTeamId('11111111-1111-4111-8111-111111111111');
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    /** An AWAY fixture with no kickoff → exactly one AWAY_NO_FOOTPRINT conflict. */
    private function createAwayNoFootprintFixture(Club $club, Season $season): string
    {
        $this->scopeGucToClub($club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel('Adversaire sans empreinte');
        // No kickoff and no habit on the team's weekday → AWAY_NO_FOOTPRINT (severity 7).
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    /**
     * Two HOME fixtures (distinct teams) on the SAME venue with overlapping windows →
     * exactly one VENUE_OVERLAP conflict (no coach → no person conflict). The family on
     * which « erreur FBI » is accepted.
     */
    private function createVenueOverlapConflict(Club $club, Season $season): void
    {
        $venue = $this->createVenue($club, $season, 'Gymnase collision');
        $this->persistHomeFixtureAtVenue($club, $season, '22222222-2222-4222-8222-222222222222', '16:00', $venue->getId());
        $this->persistHomeFixtureAtVenue($club, $season, '33333333-3333-4333-8333-333333333333', '16:30', $venue->getId());
        $this->em->flush();
    }

    /** A standalone HOME fixture VISIBLE to the club but part of NO conflict (own venue, off-peak). */
    private function createLoneHomeFixture(Club $club, Season $season): string
    {
        $venue = $this->createVenue($club, $season, 'Gymnase à part ' . uniqid('', true));
        $id = $this->persistHomeFixtureAtVenue($club, $season, '44444444-4444-4444-8444-444444444444', '09:00', $venue->getId());
        $this->em->flush();

        return $id;
    }

    private function persistHomeFixtureAtVenue(Club $club, Season $season, string $teamId, string $kickoff, string $venueId): string
    {
        $this->scopeGucToClub($club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setVenueId($venueId);
        $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null);
        $this->em->persist($fixture);

        return $fixture->getId();
    }

    /** @return array<string, mixed> the first conflict of the caller's radar */
    private function firstConflict(User $user): array
    {
        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $conflicts = $this->responseData()['conflicts'];
        self::assertIsArray($conflicts);
        self::assertNotEmpty($conflicts, 'le radar du club devrait porter au moins un conflit');

        /** @var array<string, mixed> $first */
        $first = $conflicts[0];

        return $first;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function putConflictResolution(User $user, string $fingerprint, array $body): void
    {
        $this->client->request('PUT', $this->conflictResolutionUrl($fingerprint), [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function conflictResolutionUrl(string $fingerprint): string
    {
        return '/api/fixtures/conflicts/' . $fingerprint . '/resolution';
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
