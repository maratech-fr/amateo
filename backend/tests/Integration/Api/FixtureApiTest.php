<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\OpponentVenueLink;
use App\Entity\PublicHoliday;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\CalendarEntryKind;
use App\Enum\CompetitionType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fixture CRUD surface (spec gestion-matchs palier A): friendly (no
 * competition), home placement (venue + kickoff), and DTO validation.
 */
#[Group('phase1')]
#[Group('integration')]
final class FixtureApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const TEAM_ID = '11111111-1111-4111-8111-111111111111';

    private const VENUE_ID = '22222222-2222-4222-8222-222222222222';

    // ── Ouverture datée d'un gymnase ─────────────────────────────────────────────────────────
    private const string HOLIDAY_WEDNESDAY = '2026-11-11';

    private const string ORDINARY_WEDNESDAY = '2026-11-18';

    private const string ORDINARY_SATURDAY = '2026-11-21';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $user;

    /**
     * A2 : témoins de l'autre sens, un vrai changement de position ou de nature repasse par la garde.
     * Verts aujourd'hui (la garde tourne à chaque PUT) ; ils rougiraient si NEO coupait la garde trop large.
     *
     * @return array<string, array{0: array<string, string>}>
     */
    public static function placementChanges(): array
    {
        return [
            'heure' => [['kickoffTime' => '17:00']],
            'date' => [['matchDate' => '2026-11-14']],
        ];
    }

    public function testCreatesAFriendlyWithNoCompetition(): void
    {
        $data = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Amical voisin',
        ]);
        self::assertResponseStatusCodeSame(201);
        // Null props are omitted from the serialized output.
        self::assertNull($data['competitionId'] ?? null);
        self::assertSame('UNPLACED', $data['status']);
        self::assertNull($data['kickoffTime'] ?? null);
    }

    public function testAManualCreationIsTreatedAndExposesTheReviewFields(): void
    {
        // PR-3a — a hand-entered rencontre is a manager gesture → REVIEWED, and
        // the resource serves reviewState/reviewedAt/pendingDeviations/ffbbRencontreId.
        $data = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Amical voisin',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('REVIEWED', $data['reviewState']);
        self::assertNotNull($data['reviewedAt'] ?? null);
        self::assertSame([], $data['pendingDeviations']);
        self::assertNull($data['ffbbRencontreId'] ?? null);
    }

    public function testPuttingStatusPlacedTreatsTheFixtureAndCannotWriteReviewState(): void
    {
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
        ]);
        // A forged reviewState in the PUT body is ignored (FixtureInput has no such
        // field); the D6 rule alone drives it — placing treats the fixture.
        $this->putFixture($created['id'], [
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
            'reviewState' => 'NEW',
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('REVIEWED', $this->responseData()['reviewState'], 'placing treats it (D6), the echoed reviewState is ignored');
    }

    public function testPlacesAHomeFixtureWithVenueAndKickoff(): void
    {
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
        ]);

        // PUT = full replace → resend the required identity fields plus the placement.
        $this->client->request('PUT', '/api/fixtures/' . $created['id'], [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame('16:30', $data['kickoffTime']);
        self::assertSame('PLACED', $data['status']);
        self::assertSame('22222222-2222-4222-8222-222222222222', $data['venueId']);
    }

    public function testHandBackToSolverOnUntouchedPlacementEcho(): void
    {
        $placed = $this->createPlaced('2026-11-15');
        $this->putFixture($placed['id'], $placed + ['placementSource' => 'SOLVER']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('SOLVER', $this->responseData()['placementSource']);
    }

    public function testHandBackRejectedWhenPlacementChangesInTheSamePut(): void
    {
        $placed = $this->createPlaced('2026-11-22');
        $this->putFixture($placed['id'], ['kickoffTime' => '18:00', 'placementSource' => 'SOLVER'] + $placed);
        self::assertResponseStatusCodeSame(422);
    }

    public function testHandBackRejectedWhenTheMatchLeavesPlaced(): void
    {
        $placed = $this->createPlaced('2026-11-29');
        $this->putFixture($placed['id'], ['status' => 'UNPLACED', 'placementSource' => 'SOLVER'] + $placed);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEchoPutWithoutSourceStampsManual(): void
    {
        $placed = $this->createPlaced('2026-12-06');
        $this->putFixture($placed['id'], $placed);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('MANUAL', $this->responseData()['placementSource']);
    }

    public function testUnplacingClearsThePlacementSource(): void
    {
        $placed = $this->createPlaced('2026-12-13');
        $this->putFixture($placed['id'], ['status' => 'UNPLACED', 'venueId' => '', 'kickoffTime' => ''] + $placed);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseData()['placementSource'] ?? null);
    }

    /**
     * PR4 « fermetures à la source » (décision fondateur 2026-10-22) — un match à domicile
     * PLACÉ sans gymnase est un état IMPOSSIBLE : vider `venueId` d'un domicile
     * PLACED/SUBMITTED/VALIDATED SANS le repasser « à placer » dans le MÊME geste est refusé
     * (422 nommé), zéro écriture. Le dé-placement légitime (statut UNPLACED + salle vidée
     * ensemble) reste vert : {@see self::testUnplacingClearsThePlacementSource} — son jumeau,
     * ici on prouve le trou qu'il laissait ouvert (statut INCHANGÉ).
     */
    public function testClearingVenueOfAPlacedHomeFixtureWithoutUnplacingIsRefused(): void
    {
        $placed = $this->createPlaced('2027-01-10'); // HOME + salle + statut PLACED
        // venueId vidé, mais le statut reste PLACED (le geste ne repasse PAS « à placer »).
        $this->putFixture($placed['id'], ['venueId' => ''] + $placed);
        self::assertResponseStatusCodeSame(422, (string) $this->client->getResponse()->getContent());

        // Zéro écriture : le match garde sa salle ET son statut.
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $reloaded = $this->em->getRepository(Fixture::class)->find($placed['id']);
        self::assertNotNull($reloaded);
        self::assertSame('22222222-2222-4222-8222-222222222222', $reloaded->getVenueId(), 'la salle n\'a pas été vidée');
        self::assertSame(FixtureStatus::PLACED, $reloaded->getStatus(), 'le statut n\'a pas bougé');
    }

    public function testCreatingWithSolverSourceIsRejected(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-12-20',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Faux solveur',
            'placementSource' => 'SOLVER',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * BCK-27 — un libellé d'adversaire plus long que la colonne (VARCHAR(180)) doit
     * être refusé en 422 PARLANT (violations non vides), jamais franchir jusqu'au 500
     * SQL. La borne DTO (Assert\Length) est la maison unique de ce refus.
     */
    public function testRejectsOverlongOpponentLabel(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => str_repeat('A', 181), // colonne opponent_label = VARCHAR(180)
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, (string) $this->client->getResponse()->getContent());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertNotEmpty($data['violations'] ?? [], 'un 422 muet (violations vides) afficherait « An error occurred »');
    }

    public function testRejectsMalformedKickoffTime(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Bad time',
            'kickoffTime' => '25h',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsUnknownHomeAway(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'NEUTRAL',
            'opponentLabel' => 'Bad side',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsNonUuidTeamId(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => 'not-a-uuid',
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Bad id',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsCompetitionOutsideScope(): void
    {
        // A well-formed but out-of-scope competition id (tenant/season filter hides
        // it → the processor cannot resolve it) must be rejected, not silently kept.
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'competitionId' => '33333333-3333-4333-8333-333333333333',
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Ghost competition',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRefusesPlacementOnAnUnavailableVenueEvenForAFriendly(): void
    {
        // D2 rule 1 — a venue unavailable on the match date refuses ANY placement,
        // friendly included (no competition here).
        $this->persistVenue();
        $this->persistUnavailability('2026-11-01', '2026-11-30', 'Travaux');
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Voisin',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('indisponible', (string) $this->client->getResponse()->getContent());
    }

    public function testRefusesPlacementOnAVenueClosedByTheCalendarEvenForAFriendly(): void
    {
        // P4-300 D4 — une FERMETURE du calendrier (`venue_closed`) couvrant la date du match
        // refuse TOUTE pose, amical compris (aucune compétition ici). Le message nomme la
        // fermeture (« fermée … »), jamais un 422 muet.
        $this->persistVenue();
        $this->persistClosure('2026-11-01', '2026-11-30', 'Gymnase en travaux');
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Voisin',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('fermée', (string) $this->client->getResponse()->getContent());
    }

    public function testRefusesCompetitionPlacementWhenNoMatchWindowThatDay(): void
    {
        // D2 rule 2 — the club declares match access, but on Monday, while the match
        // is a Saturday (2026-11-07). A competition match then has no slot that day.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->persistMatchWindow(1, '18:00', '22:00');
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'competitionId' => $competitionId,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Gymnase Matéo n\'a pas d\'accès match le samedi 7 novembre. Choisissez un autre gymnase, ou ouvrez-le ce jour-là depuis le panneau du match.', $this->firstViolation());
    }

    public function testRefusesCompetitionPlacementWhenKickoffOutsideMatchWindow(): void
    {
        // D2 rule 2 — a Saturday access window exists but the kickoff (20:00) sits
        // outside it. Exercised through the PUT (update) path.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->persistMatchWindow(6, '14:00', '18:00');
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'competitionId' => $competitionId,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire',
        ]);
        $this->putFixture($created['id'], [
            'teamId' => self::TEAM_ID,
            'competitionId' => $competitionId,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '20:00',
            'status' => 'PLACED',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Coup d\'envoi hors des accès match de Gymnase Matéo le samedi 7 novembre (14:00 – 18:00). Choisissez une heure dans ces plages, ou ouvrez-le ce jour-là depuis le panneau du match.', $this->firstViolation());
    }

    public function testAnOpeningLetsACompetitionMatchLandOnThatDate(): void
    {
        // Promesse centrale : refus sans ouverture, pose acceptée avec. Ligne samedi = club adopté.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->persistMatchWindow(6, '14:00', '18:00');
        $body = $this->competitionBody($competitionId, self::ORDINARY_WEDNESDAY, '19:00');

        $this->post($body);
        self::assertResponseStatusCodeSame(422);

        $this->persistOpening(self::ORDINARY_WEDNESDAY, '18:00', '22:00');
        $this->post($body);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAnOpeningDoesNotOpenAnotherHourNorAnotherDate(): void
    {
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->persistMatchWindow(6, '14:00', '18:00');
        $this->persistOpening(self::ORDINARY_WEDNESDAY, '18:00', '22:00');

        $this->post($this->competitionBody($competitionId, self::ORDINARY_WEDNESDAY, '16:00'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Coup d\'envoi hors des accès match de Gymnase Matéo le mercredi 18 novembre (18:00 – 22:00). Choisissez une heure dans ces plages, ou ouvrez-le ce jour-là depuis le panneau du match.', $this->firstViolation());

        $this->post($this->competitionBody($competitionId, '2026-11-25', '19:00'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Gymnase Matéo n\'a pas d\'accès match le mercredi 25 novembre. Choisissez un autre gymnase, ou ouvrez-le ce jour-là depuis le panneau du match.', $this->firstViolation());
    }

    public function testAnOpeningNeverLiftsAnUnavailabilityNorACalendarClosure(): void
    {
        // Les fermetures restent refusées, amical comme compétition, et leur message reste le leur.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->persistOpening(self::ORDINARY_WEDNESDAY, '18:00', '22:00');
        $this->persistUnavailability(self::ORDINARY_WEDNESDAY, self::ORDINARY_WEDNESDAY, 'travaux');
        $this->post($this->competitionBody($competitionId, self::ORDINARY_WEDNESDAY, '19:00'));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('indisponible', (string) $this->client->getResponse()->getContent());

        $this->persistOpening('2026-11-25', '18:00', '22:00');
        $this->persistClosure('2026-11-25', '2026-11-25', 'Fermeture mairie');
        $this->post($this->competitionBody($competitionId, '2026-11-25', '19:00'));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('fermée', (string) $this->client->getResponse()->getContent());
    }

    public function testAClubWithOnlyAnOpeningKeepsAnOrdinarySaturdayFree(): void
    {
        // D1bis/A3 : une ouverture ne fait pas basculer tout le club en « accès obligatoire ».
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->persistOpening(self::HOLIDAY_WEDNESDAY, '14:00', '20:00');

        $this->post($this->competitionBody($competitionId, self::ORDINARY_SATURDAY, '15:00'));
        self::assertResponseStatusCodeSame(201);
    }

    public function testAMatchDatedOutsideTheSeasonFindsItsOpening(): void
    {
        // A4 : rien n'interdit ce match sur `Fixture` : la ligne hors bornes doit lui donner une issue.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->persistMatchWindow(6, '14:00', '18:00');
        $outside = $this->season->getEndDate()->modify('+10 days')->format('Y-m-d');
        $this->persistOpening($outside, '09:00', '22:00');

        $this->post($this->competitionBody($competitionId, $outside, '15:00'));
        self::assertResponseStatusCodeSame(201);
    }

    public function testTheHolidayLineAloneAllowsAPlacementOnAHoliday(): void
    {
        // D3 : la pose lit enfin le férié comme le radar et le moteur (422 sur le seul jour de semaine jusqu'ici).
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->seedHoliday(self::HOLIDAY_WEDNESDAY);
        $this->persistHolidayLine('14:00', '20:00');

        $this->post($this->competitionBody($competitionId, self::HOLIDAY_WEDNESDAY, '15:00'));
        self::assertResponseStatusCodeSame(201);
    }

    public function testTheWeekdayLineDoesNotAllowAPlacementOnAHoliday(): void
    {
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->seedHoliday(self::HOLIDAY_WEDNESDAY);
        $this->persistMatchWindow(3, '14:00', '20:00');

        $this->post($this->competitionBody($competitionId, self::HOLIDAY_WEDNESDAY, '15:00'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Gymnase Matéo n\'a pas d\'accès match le mercredi 11 novembre, jour férié. Choisissez un autre gymnase, ou ouvrez-le ce jour-là depuis le panneau du match.', $this->firstViolation());
    }

    public function testAnOpeningAddsToTheHolidayLineAtPlacement(): void
    {
        // D2 : « Jours fériés 10:00-12:00 » + ouverture 14:00-20:00 → 11:00 ok, 15:00 ok, 13:00 refusé.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->seedHoliday(self::HOLIDAY_WEDNESDAY);
        $this->persistHolidayLine('10:00', '12:00');
        $this->persistOpening(self::HOLIDAY_WEDNESDAY, '14:00', '20:00');

        $this->post($this->competitionBody($competitionId, self::HOLIDAY_WEDNESDAY, '11:00'));
        self::assertResponseStatusCodeSame(201);
        $this->post($this->competitionBody($competitionId, self::HOLIDAY_WEDNESDAY, '15:00'));
        self::assertResponseStatusCodeSame(201);
        $this->post($this->competitionBody($competitionId, self::HOLIDAY_WEDNESDAY, '13:00'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Coup d\'envoi hors des accès match de Gymnase Matéo le mercredi 11 novembre (10:00 – 12:00, 14:00 – 20:00). Choisissez une heure dans ces plages, ou ouvrez-le ce jour-là depuis le panneau du match.', $this->firstViolation());
    }

    public function testFollowUpGesturesOnAMatchOutOfAccessAreNotRefused(): void
    {
        // A2 : « Marquer saisi », « Corriger », « Verrouiller », « Rendre au système » sont des PUT d'écho :
        // ils ne posent rien et ne doivent plus buter sur la garde d'accès (cul-de-sac des 422 d'aujourd'hui).
        [$id, $body] = $this->placedOutOfAccess();

        foreach ([
            'Marquer saisi' => ['status' => 'SUBMITTED'],
            'Corriger' => ['status' => 'PLACED'],
            'Verrouiller' => [],
            'Rendre au système' => ['placementSource' => 'SOLVER'],
        ] as $gesture => $change) {
            $this->putFixture($id, $change + $body);
            self::assertResponseStatusCodeSame(200, $gesture . ' doit passer : ' . $this->client->getResponse()->getContent());
        }
    }

    public function testCorrectingAValidatedMatchOutOfAccessIsNotRefused(): void
    {
        // D3bis : un match VALIDATED à l'import garde toujours la sortie « Corriger ».
        [$id, $body] = $this->placedOutOfAccess();
        $fixture = $this->em->find(Fixture::class, $id);
        self::assertInstanceOf(Fixture::class, $fixture);
        $fixture->setStatus(FixtureStatus::VALIDATED, new DateTimeImmutable);
        $this->em->flush();

        $this->putFixture($id, $body);
        self::assertResponseStatusCodeSame(200);
    }

    /** @param array<string, string> $change */
    #[DataProvider('placementChanges')]
    public function testAChangedPlacementIsGuardedAgain(array $change): void
    {
        [$id, $body] = $this->placedOutOfAccess();

        $this->putFixture($id, $change + $body);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMovingToAnotherVenueIsGuardedAgain(): void
    {
        [$id, $body] = $this->placedOutOfAccess();
        $other = new Venue;
        $other->setClubId($this->club->getId());
        $other->setSeasonId($this->season->getId());
        $other->setName('Gymnase Autre');
        $other->setSource('manual');
        $this->em->persist($other);
        $this->em->flush();

        $this->putFixture($id, ['venueId' => $other->getId()] + $body);
        self::assertResponseStatusCodeSame(422);
    }

    public function testChangingTheCompetitionIsGuardedAgain(): void
    {
        [$id, $body] = $this->placedOutOfAccess();

        $coupe = new Competition;
        $coupe->setClubId($this->club->getId());
        $coupe->setSeasonId($this->season->getId());
        $coupe->setTeamId(self::TEAM_ID);
        $coupe->setName('Coupe');
        $coupe->setCompetitionType(CompetitionType::CUP);
        $this->em->persist($coupe);
        $this->em->flush();

        $this->putFixture($id, ['competitionId' => $coupe->getId()] + $body);
        self::assertResponseStatusCodeSame(422);
    }

    public function testComingBackHomeIsGuardedAgain(): void
    {
        [$id, $body] = $this->placedOutOfAccess();
        $fixture = $this->em->find(Fixture::class, $id);
        self::assertInstanceOf(Fixture::class, $fixture);
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $this->em->flush();

        $this->putFixture($id, ['homeAway' => 'HOME'] + $body);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnUnplacedMatchBecomingPlacedIsGuardedAgainWithoutAnyOtherChange(): void
    {
        // UNPLACED importé avec son gymnase et son heure : la pose ne change que le statut.
        [$id, $body] = $this->placedOutOfAccess();
        $fixture = $this->em->find(Fixture::class, $id);
        self::assertInstanceOf(Fixture::class, $fixture);
        $fixture->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
        $this->em->flush();

        $this->putFixture($id, $body);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMarkingSubmittedInAClosedVenueStaysRefused(): void
    {
        // A2 : les étapes indisponibilité et fermeture restent à chaque PUT, seule l'étape accès s'allège.
        [$id, $body] = $this->placedOutOfAccess();
        $this->persistClosure('2026-11-07', '2026-11-07', 'Fermeture mairie');

        $this->putFixture($id, ['status' => 'SUBMITTED'] + $body);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('fermée', (string) $this->client->getResponse()->getContent());
    }

    public function testAllowsFriendlyPlacementOutsideMatchWindow(): void
    {
        // D2 rule 2 — a FRIENDLY (no competition) is free off any match slot: the
        // kickoff (20:00) is outside the only Saturday window, yet the placement holds.
        $this->persistVenue();
        $this->persistMatchWindow(6, '14:00', '18:00');
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Amical voisin',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '20:00',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    public function testAllowsCompetitionPlacementWhenClubDeclaresNoMatchWindow(): void
    {
        // D2 rule 2 — a club that has NOT adopted match windows has nothing to
        // enforce: a competition placement with no window at all is allowed.
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'competitionId' => $competitionId,
            'matchDate' => '2026-11-07',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Le trajet d'une rencontre EXTÉRIEURE est DÉRIVÉ de la rencontre (champ additif
     * `awayTravel`), calculé EN BATCH par le provider de collection. Preuve de toute la PR :
     * deux rencontres du MÊME club adverse dans DEUX salles différentes portent DEUX trajets
     * différents. Repli « gymnase supposé » marqué ; domicile → null.
     */
    public function testAwayTravelIsDerivedFromEachFixtureVenue(): void
    {
        // Siège du club localisé (sans quoi aucun trajet ne se calcule).
        $this->scopeGucToClub($this->club->getId());
        $this->club->setLatitude(45.70)->setLongitude(4.90);
        $this->em->flush();

        // ADV1 : deux salles distinctes → deux liens → deux trajets (le cœur de la PR).
        $a = $this->seedAway('ARA0069AAA', 'ASVEL - 1', 'SALLE ALPHA');
        $b = $this->seedAway('ARA0069AAA', 'ASVEL - 2', 'SALLE BRAVO');
        $this->seedLink('ARA0069AAA', 'SALLE ALPHA', 'Gymnase Alpha', 45.80, 5.00);
        $this->seedLink('ARA0069AAA', 'SALLE BRAVO', 'Gymnase Bravo', 45.90, 5.10);
        $this->seedCache(45.80, 5.00, 25);
        $this->seedCache(45.90, 5.10, 40);

        // ADV2 : une salle appariée (deux rencontres) + une salle orpheline → repli « gymnase
        // le plus fréquent » (Gymnase Charlie), marqué approché.
        $this->seedAway('ARA0069BBB', 'BC - 1', 'SALLE CHARLIE');
        $this->seedAway('ARA0069BBB', 'BC - 2', 'SALLE CHARLIE');
        $orphan = $this->seedAway('ARA0069BBB', 'BC - 3', 'SALLE INCONNUE');
        $this->seedLink('ARA0069BBB', 'SALLE CHARLIE', 'Gymnase Charlie', 46.00, 5.20);
        $this->seedCache(46.00, 5.20, 55);

        // Un domicile → awayTravel null.
        $home = $this->seedHome('ARA0069AAA', 'ASVEL - 1');

        $this->client->request('GET', '/api/fixtures', [], [], $this->authHeaders());
        self::assertResponseStatusCodeSame(200);
        $byId = [];
        foreach ($this->responseData()['member'] ?? [] as $row) {
            $byId[$row['id']] = $row;
        }

        // Deux trajets DIFFÉRENTS pour deux salles du même adversaire.
        self::assertSame('linked', $byId[$a]['awayTravel']['basis']);
        self::assertSame(25, $byId[$a]['awayTravel']['oneWayMinutes']);
        self::assertSame('Gymnase Alpha', $byId[$a]['awayTravel']['venueLabel']);
        self::assertFalse($byId[$a]['awayTravel']['approximated']);
        self::assertSame(40, $byId[$b]['awayTravel']['oneWayMinutes'], 'la 2ᵉ salle porte SON propre trajet');

        // Repli « gymnase supposé » approché.
        self::assertSame('most_frequent', $byId[$orphan]['awayTravel']['basis']);
        self::assertSame('Gymnase Charlie', $byId[$orphan]['awayTravel']['venueLabel']);
        self::assertSame(55, $byId[$orphan]['awayTravel']['oneWayMinutes']);
        self::assertTrue($byId[$orphan]['awayTravel']['approximated']);

        // Domicile : aucun trajet adverse (champ omis à la sérialisation quand null).
        self::assertNull($byId[$home]['awayTravel'] ?? null);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->createClubUser();
    }

    /**
     * Un match de compétition posé le samedi 7 nov. à 16:30 AVANT que le club n'adopte une ligne qui
     * ne le couvre pas : l'état réel d'un match importé validé, ou d'une fenêtre déplacée après la pose.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function placedOutOfAccess(): array
    {
        $this->persistVenue();
        $competitionId = $this->createCompetition();
        $body = $this->competitionBody($competitionId, '2026-11-07', '16:30');
        $created = $this->post($body);
        self::assertResponseStatusCodeSame(201);
        $this->persistMatchWindow(1, '18:00', '22:00');

        return [(string) $created['id'], $body];
    }

    /** @return array<string, mixed> */
    private function competitionBody(string $competitionId, string $date, string $kickoff): array
    {
        return [
            'teamId' => self::TEAM_ID,
            'competitionId' => $competitionId,
            'matchDate' => $date,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire',
            'venueId' => self::VENUE_ID,
            'kickoffTime' => $kickoff,
            'status' => 'PLACED',
        ];
    }

    private function firstViolation(): string
    {
        $violations = $this->responseData()['violations'] ?? [];
        self::assertIsArray($violations);
        self::assertIsArray($violations[0] ?? null, 'le 422 porte une violation, jamais muet');

        return (string) $violations[0]['message'];
    }

    private function persistOpening(string $date, string $start, string $end): void
    {
        $window = new VenueMatchWindow;
        $window->setClubId($this->club->getId());
        $window->setSeasonId($this->season->getId());
        $window->setVenueId(self::VENUE_ID);
        $window->setOpeningDate(new DateTimeImmutable($date));
        $window->setStartTime(new DateTimeImmutable($start));
        $window->setEndTime(new DateTimeImmutable($end));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function persistHolidayLine(string $start, string $end): void
    {
        $window = new VenueMatchWindow;
        $window->setClubId($this->club->getId());
        $window->setSeasonId($this->season->getId());
        $window->setVenueId(self::VENUE_ID);
        $window->setAppliesOnPublicHolidays(true);
        $window->setStartTime(new DateTimeImmutable($start));
        $window->setEndTime(new DateTimeImmutable($end));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function seedHoliday(string $date): void
    {
        $repository = $this->em->getRepository(PublicHoliday::class);
        if (null !== $repository->findOneBy(['zone' => PublicHoliday::NATIONAL, 'date' => new DateTimeImmutable($date)])) {
            return;
        }
        $holiday = new PublicHoliday;
        $holiday->setZone(PublicHoliday::NATIONAL);
        $holiday->setDate(new DateTimeImmutable($date));
        $holiday->setLabel('11 novembre');
        $this->em->persist($holiday);
        $this->em->flush();
    }

    /** @return array<string, mixed> the full-replace body of a freshly PLACED fixture */
    private function createPlaced(string $date): array
    {
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => $date,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Boucle manuelle',
        ]);
        $body = [
            'teamId' => self::TEAM_ID,
            'matchDate' => $date,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Boucle manuelle',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ];
        $this->putFixture($created['id'], $body);
        self::assertResponseStatusCodeSame(200);

        return ['id' => $created['id']] + $body;
    }

    /** @param array<string, mixed> $body */
    private function putFixture(string $id, array $body): void
    {
        unset($body['id']);
        $this->client->request('PUT', '/api/fixtures/' . $id, [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function seedAway(string $code, string $opponentLabel, string $fbiVenueLabel): string
    {
        return $this->seedFixture($code, $opponentLabel, $fbiVenueLabel, FixtureHomeAway::AWAY);
    }

    private function seedHome(string $code, string $opponentLabel): string
    {
        return $this->seedFixture($code, $opponentLabel, null, FixtureHomeAway::HOME);
    }

    private function seedFixture(string $code, string $opponentLabel, ?string $fbiVenueLabel, FixtureHomeAway $homeAway): string
    {
        $this->scopeGucToClub($this->club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId(self::TEAM_ID);
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel($opponentLabel);
        $fixture->setOpponentOrganismeCode($code);
        $fixture->setFbiVenueLabel($fbiVenueLabel);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    private function seedLink(string $code, string $fbiLabel, string $venueLabel, float $lat, float $lon): void
    {
        $this->scopeGucToClub($this->club->getId());
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        $link = (new OpponentVenueLink)
            ->setClubId($this->club->getId())
            ->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)
            ->setFbiLabelNorm($normalizer->normalize($fbiLabel))
            ->setVenueLabel($venueLabel)
            ->setLatitude($lat)
            ->setLongitude($lon)
            ->setSource(OpponentVenueLinkSource::AUTO);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function seedCache(float $destLat, float $destLon, int $minutes): void
    {
        self::getContainer()->get(TravelTimeCache::class)->store($this->club->getId(), IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, $destLat, $destLon, $minutes);
    }

    private function post(array $body): array
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->responseData();
    }

    private function createClubUser(): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club fixture');
        $club->setSlug('club-fixture-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('fixture' . $uid . '@test.com');
        $user->setFirstName('Fix');
        $user->setLastName('Ture');
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
        // Matches need a settled season plan (cockpit state 3); point the plan at a
        // version so this API test targets fixture behaviour, not the socle guard.
        $this->settleSeasonPlan($season);
        $this->club = $club;
        $this->season = $season;

        return $user;
    }

    private function persistVenue(): void
    {
        if (null !== $this->em->find(Venue::class, self::VENUE_ID)) {
            return;
        }
        $venue = new Venue;
        $venue->setId(self::VENUE_ID);
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName('Gymnase Matéo');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
    }

    private function persistMatchWindow(int $dayOfWeek, string $start, string $end): void
    {
        $window = new VenueMatchWindow;
        $window->setClubId($this->club->getId());
        $window->setSeasonId($this->season->getId());
        $window->setVenueId(self::VENUE_ID);
        $window->setDayOfWeek($dayOfWeek);
        $window->setStartTime(new DateTimeImmutable($start));
        $window->setEndTime(new DateTimeImmutable($end));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function persistUnavailability(string $start, string $end, ?string $label = null): void
    {
        $unavailability = new VenueUnavailability;
        $unavailability->setClubId($this->club->getId());
        $unavailability->setSeasonId($this->season->getId());
        $unavailability->setVenueId(self::VENUE_ID);
        $unavailability->setStartDate(new DateTimeImmutable($start));
        $unavailability->setEndDate(new DateTimeImmutable($end));
        $unavailability->setLabel($label);
        $this->em->persist($unavailability);
        $this->em->flush();
    }

    private function persistClosure(string $start, string $end, string $title): void
    {
        // P4-300 — une fermeture `venue_closed` portée par une entrée de calendrier (période),
        // comme en production : son id borne le repli legacy, le `config` porte les dates.
        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Fermeture gymnase');
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        $closure = new Constraint;
        $closure->setClubId($this->club->getId());
        $closure->setSeasonId($this->season->getId());
        $closure->setFamily(ConstraintFamily::FACILITY);
        $closure->setScope(ConstraintScope::FACILITY);
        $closure->setScopeTargetId(self::VENUE_ID);
        $closure->setRuleType(ConstraintRuleType::HARD);
        $closure->setName($title);
        $closure->setCalendarEntryId($entry->getId());
        $closure->setConfig(['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end]);
        $this->em->persist($closure);
        $this->em->flush();
    }

    private function createCompetition(): string
    {
        $competition = new Competition;
        $competition->setClubId($this->club->getId());
        $competition->setSeasonId($this->season->getId());
        $competition->setTeamId(self::TEAM_ID);
        $competition->setName('Championnat');
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition->getId();
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);

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
