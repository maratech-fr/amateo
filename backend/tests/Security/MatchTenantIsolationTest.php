<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\ConflictResolution;
use App\Entity\FbiCorrection;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\OpponentTravel;
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
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Enum\TeamLinkType;
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
     * NR axe §7.1 tenant isolation : le trajet adverse vit dans une table TENANT
     * (le trajet dépend du siège d'UN club) — club A ne lit/écrit jamais
     * l'`OpponentTravel` de club B, et un MANUAL de A ne fuit pas à B. Falsifié
     * dans les deux sens : chaque club voit SA ligne et RIEN de l'autre.
     */
    public function testOpponentTravelIsTenantScopedAndManualDoesNotLeak(): void
    {
        [$clubA, , $seasonA] = $this->createClubUser('a');
        [$clubB, $userB, $seasonB] = $this->createClubUser('b');

        // A MANUAL travel row for club A, one for club B — same opponent code, so the
        // ONLY thing separating them is the tenant boundary.
        $this->seedManualTravel($clubA, $seasonA, 'ORGSHARED', 42, 'Gymnase A');
        $this->seedManualTravel($clubB, $seasonB, 'ORGSHARED', 99, 'Gymnase B');

        // Club A's RLS-scoped repository sees its own row and nothing of B's.
        $this->scopeGucToClub($clubA->getId());
        $rowsA = $this->em->getRepository(OpponentTravel::class)->findBy(['opponentOrganismeCode' => 'ORGSHARED']);
        self::assertCount(1, $rowsA);
        self::assertSame($clubA->getId(), $rowsA[0]->getClubId());
        self::assertSame(42, $rowsA[0]->getTravelMinutes());

        // Club B's RLS-scoped repository sees ITS row only (99), never A's 42.
        $this->scopeGucToClub($clubB->getId());
        $rowsB = $this->em->getRepository(OpponentTravel::class)->findBy(['opponentOrganismeCode' => 'ORGSHARED']);
        self::assertCount(1, $rowsB);
        self::assertSame($clubB->getId(), $rowsB[0]->getClubId());
        self::assertSame(99, $rowsB[0]->getTravelMinutes());

        // The travel READ endpoint of club B never surfaces club A's opponents
        // (B has no away fixtures → empty, and A's row is invisible either way).
        $this->client->request('GET', '/api/opponents/travel', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseData()['opponents'] ?? ['sentinel']);
    }

    /**
     * The MANUAL/AUTO travel writes are management-gated (SEC-07): a non-management
     * member is refused, and a foreign opponent code (no away fixture) is a 422.
     */
    public function testOpponentTravelWritesAreManagementGated(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        $editor = $this->createMember($clubA, 'editor');

        // A non-management member cannot pin a gym (403 wins over the 422 below).
        $this->client->request('POST', '/api/opponents/travel/manual', [], [], $this->authHeaders($editor) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'opponentOrganismeCode' => 'ORGX', 'venueLabel' => 'Gymnase', 'latitude' => 45.7, 'longitude' => 4.9,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        // A code with no away fixture of the club this season → 422, no write.
        $this->client->request('POST', '/api/opponents/travel/manual', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'opponentOrganismeCode' => 'ORGX', 'venueLabel' => 'Gymnase', 'latitude' => 45.7, 'longitude' => 4.9,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        $this->scopeGucToClub($clubA->getId());
        self::assertCount(0, $this->em->getRepository(OpponentTravel::class)->findBy(['opponentOrganismeCode' => 'ORGX']));
    }

    /**
     * NR axe §7.1 tenant isolation — grain ÉQUIPE (P2-54 « adversaire multi-gymnases »).
     * Une ligne de trajet PAR ÉQUIPE (teamKey) reste scopée tenant : club B ne lit ni
     * n'écrase la ligne équipe de A ; un `manual` de B portant le code ET le teamKey
     * d'une équipe de A est refusé (422 : aucune rencontre AWAY correspondante chez B),
     * jamais une fuite ni un écrasement. Falsifié : la ligne équipe de A reste intacte.
     */
    public function testOpponentTeamTravelIsTenantScopedAndAForeignTeamKeyIsRejected(): void
    {
        [$clubA, , $seasonA] = $this->createClubUser('teama');
        [$clubB, $userB] = $this->createClubUser('teamb');

        // A team-grain MANUAL row for club A only (code + teamKey).
        $this->seedTeamManualTravel($clubA, $seasonA, 'ORGTEAM', 'equipe alpha', 42, 'Gymnase A');

        // Club A's RLS-scoped repository sees its team row; club B sees nothing.
        $this->scopeGucToClub($clubA->getId());
        $rowsA = $this->em->getRepository(OpponentTravel::class)->findBy(['opponentOrganismeCode' => 'ORGTEAM', 'opponentTeamKey' => 'equipe alpha']);
        self::assertCount(1, $rowsA);
        self::assertSame(42, $rowsA[0]->getTravelMinutes());

        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(OpponentTravel::class)->findBy(['opponentOrganismeCode' => 'ORGTEAM']));

        // Club B tries to pin a gym for A's team (same code + teamKey) → 422, no write:
        // B has no AWAY fixture matching that (code, teamKey).
        $this->client->request('POST', '/api/opponents/travel/manual', [], [], $this->authHeaders($userB) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'opponentOrganismeCode' => 'ORGTEAM', 'opponentTeamKey' => 'equipe alpha', 'venueLabel' => 'Gymnase pirate', 'latitude' => 45.7, 'longitude' => 4.9,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // A's team row is untouched (still 42, still « Gymnase A »). The kernel request
        // left the Doctrine filters scoped to B; disable them and rely on the RLS GUC
        // (scoped to A) so the assertion reads A's rows at the DB level.
        $this->scopeGucToClub($clubA->getId());
        $this->em->clear();
        $filters = $this->em->getFilters();
        foreach (['tenant_filter', 'season_filter'] as $filter) {
            if ($filters->isEnabled($filter)) {
                $filters->disable($filter);
            }
        }
        $survivor = $this->em->getRepository(OpponentTravel::class)->findOneBy(['opponentOrganismeCode' => 'ORGTEAM', 'opponentTeamKey' => 'equipe alpha']);
        self::assertInstanceOf(OpponentTravel::class, $survivor);
        self::assertSame($clubA->getId(), $survivor->getClubId());
        self::assertSame(42, $survivor->getTravelMinutes());
        self::assertSame('Gymnase A', $survivor->getOverrideVenueLabel());
    }

    /**
     * NR axe §7.1 tenant isolation — grain ÉQUIPE AUTO (P2-54 PR-2b, auto-localisation
     * depuis le libellé du fichier). Une ligne de trajet ÉQUIPE `source = AUTO` (portant
     * un ref de salle fédéral) reste scopée TENANT : club B ne la lit jamais, et
     * l'ORCHESTRATEUR `POST /api/opponents/refresh` lancé par B — qui enchaîne rattrapage
     * des codes, auto-localisation et recalcul des trajets — n'écrit RIEN chez A.
     * Falsifié : la ligne AUTO de A reste byte-identique après le refresh de B.
     */
    public function testOpponentTeamAutoTravelIsTenantScopedAndRefreshOfBWritesNothingAtA(): void
    {
        [$clubA, , $seasonA] = $this->createClubUser('autoa');
        [$clubB, $userB] = $this->createClubUser('autob');

        // A TEAM AUTO row for club A only (code + teamKey + federal salle ref, source AUTO).
        $this->seedTeamAutoTravel($clubA, $seasonA, 'ORGAUTO', 'equipe auto', 33, '166900777', 'GYMNASE FEDERAL A');

        // Club A's RLS-scoped repository sees its team AUTO row; club B sees nothing.
        $this->scopeGucToClub($clubA->getId());
        $rowsA = $this->em->getRepository(OpponentTravel::class)->findBy(['opponentOrganismeCode' => 'ORGAUTO']);
        self::assertCount(1, $rowsA);
        self::assertSame(OpponentTravelSource::AUTO, $rowsA[0]->getSource());

        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(OpponentTravel::class)->findBy(['opponentOrganismeCode' => 'ORGAUTO']));

        // Club B runs the refresh orchestrator — B has no away fixtures of its own, so the
        // three passes write nothing, and A's tenant row is unreachable to B either way.
        $this->client->request('POST', '/api/opponents/refresh', [], [], $this->authHeaders($userB) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);

        // A's team AUTO row survives byte-identical. Disable the request-scoped Doctrine
        // filters (left pointing at B) and rely on the RLS GUC scoped to A.
        $this->scopeGucToClub($clubA->getId());
        $this->em->clear();
        $filters = $this->em->getFilters();
        foreach (['tenant_filter', 'season_filter'] as $filter) {
            if ($filters->isEnabled($filter)) {
                $filters->disable($filter);
            }
        }
        $survivor = $this->em->getRepository(OpponentTravel::class)->findOneBy(['opponentOrganismeCode' => 'ORGAUTO', 'opponentTeamKey' => 'equipe auto']);
        self::assertInstanceOf(OpponentTravel::class, $survivor);
        self::assertSame($clubA->getId(), $survivor->getClubId());
        self::assertSame(33, $survivor->getTravelMinutes());
        self::assertSame(OpponentTravelSource::AUTO, $survivor->getSource());
        self::assertSame('166900777', $survivor->getOverrideVenueExternalRef());
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

    private function seedManualTravel(Club $club, Season $season, string $code, int $minutes, string $venueLabel): void
    {
        $this->scopeGucToClub($club->getId());
        $row = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode($code)
            ->setSource(OpponentTravelSource::MANUAL)
            ->setTravelMinutes($minutes)
            ->setOverrideVenueExternalRef(null)
            ->setOverrideVenueLabel($venueLabel)
            ->setOverrideLatitude(45.75)
            ->setOverrideLongitude(4.85)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($row);
        $this->em->flush();
    }

    private function seedTeamManualTravel(Club $club, Season $season, string $code, string $teamKey, int $minutes, string $venueLabel): void
    {
        $this->scopeGucToClub($club->getId());
        $row = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode($code)
            ->setOpponentTeamKey($teamKey)
            ->setSource(OpponentTravelSource::MANUAL)
            ->setTravelMinutes($minutes)
            ->setOverrideVenueLabel($venueLabel)
            ->setOverrideLatitude(45.75)
            ->setOverrideLongitude(4.85)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($row);
        $this->em->flush();
    }

    private function seedTeamAutoTravel(Club $club, Season $season, string $code, string $teamKey, int $minutes, string $venueRef, string $venueLabel): void
    {
        $this->scopeGucToClub($club->getId());
        $row = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode($code)
            ->setOpponentTeamKey($teamKey)
            ->setSource(OpponentTravelSource::AUTO)
            ->setTravelMinutes($minutes)
            ->setOverrideVenueExternalRef($venueRef)
            ->setOverrideVenueLabel($venueLabel)
            ->setOverrideLatitude(45.75)
            ->setOverrideLongitude(4.85)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($row);
        $this->em->flush();
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
