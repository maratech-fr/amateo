<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Enum\SeasonStatus;
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
 * PR-3a — the « Importer » review endpoints: mark rencontres treated (by line or
 * in bulk) and resolve one pending deviation. Tested end-to-end over HTTP.
 */
#[Group('integration')]
final class FixtureReviewApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testLineReviewClearsPendingAndMarksReviewed(): void
    {
        [$club, $user, $season] = $this->createClubUser('rl');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->deviatedFixture($club, $season, $team, '2026-10-04', '2026-10-11');

        $this->post($user, '/api/fixtures/review', ['fixtureIds' => [$fixture->getId()]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->responseData()['reviewed']);
        self::assertSame([], $this->responseData()['skipped']);

        $row = $this->reload($club, $fixture->getId());
        self::assertSame('REVIEWED', $row['review_state']);
        self::assertSame('[]', (string) $row['pending_deviations'], 'the line gesture clears the écart (keep-app implicit)');
        self::assertSame('2026-10-04', substr((string) $row['match_date'], 0, 10), 'keeping the app value never writes the source');
    }

    public function testLineReviewAcknowledgesAnAutoAppliedDeviationOnAnAlreadyReviewedFixture(): void
    {
        // P4-199 — une rencontre DÉJÀ traitée (REVIEWED) portant une entrée
        // `autoApplied` (la source a imposé une valeur hors périmètre : le bandeau
        // « Pris en compte ») : le geste ligne vide l'écart, elle reste traitée.
        [$club, $user, $season] = $this->createClubUser('ra');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->autoAppliedReviewedFixture($club, $season, $team, '2026-10-04', '2026-10-11');

        $this->post($user, '/api/fixtures/review', ['fixtureIds' => [$fixture->getId()]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->responseData()['reviewed']);

        $row = $this->reload($club, $fixture->getId());
        self::assertSame('REVIEWED', $row['review_state'], 'elle reste traitée');
        self::assertSame('[]', (string) $row['pending_deviations'], '« Pris en compte » vide l\'entrée autoApplied');
    }

    public function testBulkReviewSkipsFixturesStillCarryingPendingDeviations(): void
    {
        [$club, $user, $season] = $this->createClubUser('rb');
        $team = $this->createTeam($club, $season, 'SF3');
        $clean = $this->plainFixture($club, $season, $team, '2026-10-04');
        $deviated = $this->deviatedFixture($club, $season, $team, '2026-10-05', '2026-10-12');

        $this->post($user, '/api/fixtures/review', ['teamId' => $team->getId()]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame(1, $data['reviewed'], 'only the clean fixture is treated in bulk');
        self::assertSame([['fixtureId' => $deviated->getId(), 'reason' => 'pending_deviations']], $data['skipped']);

        self::assertSame('REVIEWED', $this->reload($club, $clean->getId())['review_state']);
        self::assertSame('OUT_OF_SYNC', $this->reload($club, $deviated->getId())['review_state'], 'the deviated one is left untouched');
    }

    public function testReviewRequiresExactlyOneGesture(): void
    {
        [$club, $user, $season] = $this->createClubUser('rx');
        $team = $this->createTeam($club, $season, 'SF3');

        $this->post($user, '/api/fixtures/review', []);
        self::assertResponseStatusCodeSame(422);

        $this->post($user, '/api/fixtures/review', ['fixtureIds' => ['x'], 'teamId' => $team->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMoreThanFiveHundredFixtureIdsIsRefusedAndNamesTheTeamGesture(): void
    {
        [, $user] = $this->createClubUser('rcap');

        $ids = array_map(static fn (int $i): string => \sprintf('forged-%d', $i), range(1, 501));
        $this->post($user, '/api/fixtures/review', ['fixtureIds' => $ids]);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertStringContainsString('geste par équipe', (string) $body['error']);
    }

    public function testTakeSourceOnDateUnplacesAndReviews(): void
    {
        [$club, $user, $season] = $this->createClubUser('rt');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->deviatedFixture($club, $season, $team, '2026-10-04', '2026-10-11');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'date',
            'choice' => 'take_source',
        ]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame('REVIEWED', $data['reviewState']);
        self::assertSame([], $data['pendingDeviations']);

        $row = $this->reload($club, $fixture->getId());
        self::assertSame('2026-10-11', substr((string) $row['match_date'], 0, 10), 'take_source adopted the persisted source date');
        self::assertSame('UNPLACED', $row['status'], 'a date take_source un-places the match');
        self::assertSame('REVIEWED', $row['review_state']);
    }

    public function testKeepAppRemovesTheDeviationWithoutWritingTheSource(): void
    {
        [$club, $user, $season] = $this->createClubUser('rk');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->deviatedFixture($club, $season, $team, '2026-10-04', '2026-10-11');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'date',
            'choice' => 'keep_app',
        ]);
        self::assertResponseStatusCodeSame(200);

        $row = $this->reload($club, $fixture->getId());
        self::assertSame('2026-10-04', substr((string) $row['match_date'], 0, 10));
        self::assertSame('REVIEWED', $row['review_state']);
    }

    public function testResolvingAFieldWithoutAPendingDeviationIs422(): void
    {
        [$club, $user, $season] = $this->createClubUser('rn');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->plainFixture($club, $season, $team, '2026-10-04');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'date',
            'choice' => 'keep_app',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownFixtureIs404(): void
    {
        [, $user] = $this->createClubUser('ru');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => '11111111-1111-4111-8111-111111111111',
            'field' => 'date',
            'choice' => 'keep_app',
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testKeepAppVenueOnAnUnplacedStampsTheKeptLabel(): void
    {
        // « Garder l'appli » sur l'écart salle d'un NON PLACÉ : le gymnase reste, le
        // libellé source est adopté (brut + normalisé mémorisé pour l'idempotence, E).
        [$club, $user, $season] = $this->createClubUser('rkv');
        $team = $this->createTeam($club, $season, 'SF3');
        [$fixture, $jdr] = $this->unplacedVenueDeviatedFixture($club, $season, $team, 'GYMNASE JDR');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'venue',
            'choice' => 'keep_app',
        ]);
        self::assertResponseStatusCodeSame(200);

        $reloaded = $this->reloadFixture($club, $fixture->getId());
        self::assertSame($jdr, $reloaded->getVenueId(), 'le gymnase est gardé');
        self::assertSame('SALLE RAPHAEL DE BARROS', $reloaded->getFbiVenueLabel(), 'le libellé source brut est adopté');
        self::assertSame('salle raphael de barros', $reloaded->getKeptVenueLabel(), 'le libellé normalisé est mémorisé');
        self::assertSame(FixtureReviewState::REVIEWED, $reloaded->getReviewState());
        self::assertSame([], $reloaded->getPendingDeviations());
    }

    public function testTakeSourceVenueOnAnUnplacedFollowsTheConfirmedAlias(): void
    {
        // « Prendre le fichier » sur l'écart salle d'un NON PLACÉ : le gymnase erroné
        // est vidé puis l'alias confirmé du libellé source repose le bon (Debarros) ;
        // le statut reste UNPLACED, le pense-bête keep_app est effacé.
        [$club, $user, $season] = $this->createClubUser('rtv');
        $team = $this->createTeam($club, $season, 'SF3');
        $debarros = $this->createAliasedVenue($club, $season, 'Debarros', ['salle raphael de barros']);
        [$fixture] = $this->unplacedVenueDeviatedFixture($club, $season, $team, 'GYMNASE JDR');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'venue',
            'choice' => 'take_source',
        ]);
        self::assertResponseStatusCodeSame(200);

        $reloaded = $this->reloadFixture($club, $fixture->getId());
        self::assertSame($debarros, $reloaded->getVenueId(), 'l\'alias confirmé repose le bon gymnase');
        self::assertSame(FixtureStatus::UNPLACED, $reloaded->getStatus(), 'le statut reste UNPLACED');
        self::assertNull($reloaded->getKeptVenueLabel());
        self::assertSame(FixtureReviewState::REVIEWED, $reloaded->getReviewState());
        self::assertSame([], $reloaded->getPendingDeviations());
    }

    // ── Chantier A PR 4 — « prendre la source » vers une salle INCONNUE ─────────
    //
    // CONTRAT (option A, appariement INLINE décidé fondateur 2026-10-22 Q1 = (b)) :
    // POST /api/fixtures/review/deviations accepte, sur un écart de SALLE, un champ
    // OPTIONNEL `venueId` : l'uuid d'un gymnase EXISTANT du club+saison, OU la
    // sentinelle 'NEUTRAL' (terrain neutre, patron import {@see ImportVenueMappingAbuseTest}).
    //  - `venueId` = uuid  → le libellé SOURCE de l'écart est rattaché à ce gymnase
    //    (MAISON {@see VenueExternalLabelAttacher}, gardes verbatim) et le venueId est
    //    posé ; l'écart est résolu, la rencontre reste UNPLACED (jamais placée d'office).
    //  - `venueId` = 'NEUTRAL' → la rencontre devient un TERRAIN NEUTRE
    //    (unplacedReason NEUTRAL_GROUND), l'écart est résolu.
    //  - pas de `venueId` → l'alias CONFIRMÉ du libellé repose le gymnase (comportement
    //    conservé, {@see testTakeSourceVenueOnAnUnplacedFollowsTheConfirmedAlias}) ; si le
    //    libellé est INCONNU (aucun alias confirmé), « prendre la source » est REFUSÉ avec
    //    une raison nommée, et RIEN n'est écrit — jamais un domicile laissé à venueId null.
    //
    // Promesse protégée : le canal API FFBB et l'arbitrage hors dépôt ne laissent JAMAIS
    // un match à domicile sans gymnase.

    public function testTakeSourceVenueTowardAnUnknownLabelWithoutAChoiceIsRefusedAndWritesNothing(): void
    {
        // Aujourd'hui « prendre la source » vers une salle inconnue dé-place et laisse
        // venueId null. Demain : refus NOMMÉ, zéro écriture — l'écart reste à trancher.
        [$club, $user, $season] = $this->createClubUser('ruk');
        $team = $this->createTeam($club, $season, 'SF3');
        // Pas de gymnase aliasé pour « SALLE RAPHAEL DE BARROS » → le libellé est INCONNU.
        [$fixture, $jdr] = $this->unplacedVenueDeviatedFixture($club, $season, $team, 'GYMNASE JDR');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'venue',
            'choice' => 'take_source',
        ]);
        self::assertResponseStatusCodeSame(422, 'prendre une salle inconnue sans choix de gymnase est refusé');

        $reloaded = $this->reloadFixture($club, $fixture->getId());
        self::assertSame($jdr, $reloaded->getVenueId(), 'le gymnase reste INTACT — jamais venueId null');
        self::assertSame(FixtureStatus::UNPLACED, $reloaded->getStatus());
        self::assertNotNull($reloaded->getPendingDeviation('venue'), 'l\'écart reste à trancher');
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $reloaded->getReviewState(), 'la rencontre reste déphasée');
    }

    public function testTakeSourceVenueWithAnInlineVenueChoiceAttachesTheAliasAndPostsTheVenue(): void
    {
        // Appariement INLINE : je choisis un gymnase existant sur la ligne d'écart →
        // le libellé source devient un alias de ce gymnase (MAISON VenueExternalLabelAttacher)
        // et le venueId est posé. L'écart est résolu, la rencontre reste UNPLACED.
        [$club, $user, $season] = $this->createClubUser('rik');
        $team = $this->createTeam($club, $season, 'SF3');
        $cosec2 = $this->createAliasedVenue($club, $season, 'Cosec2', []); // sans alias pour « SALLE RAPHAEL DE BARROS »
        [$fixture] = $this->unplacedVenueDeviatedFixture($club, $season, $team, 'GYMNASE JDR');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'venue',
            'choice' => 'take_source',
            'venueId' => $cosec2,
        ]);
        self::assertResponseStatusCodeSame(200);

        $reloaded = $this->reloadFixture($club, $fixture->getId());
        self::assertSame($cosec2, $reloaded->getVenueId(), 'le gymnase choisi inline est posé');
        self::assertSame(FixtureStatus::UNPLACED, $reloaded->getStatus(), 'apparier la salle ne place jamais');
        self::assertSame([], $reloaded->getPendingDeviations(), 'l\'écart est résolu');
        self::assertSame(FixtureReviewState::REVIEWED, $reloaded->getReviewState());

        $this->scopeGucToClub($club->getId());
        $venue = $this->em->getRepository(Venue::class)->find($cosec2);
        self::assertContains('salle raphael de barros', $venue?->getExternalLabels() ?? [], 'le libellé source est rattaché en alias (le re-dépôt sera muet)');
    }

    public function testTakeSourceVenueWithTheNeutralChoiceMarksItNeutralGround(): void
    {
        // « Terrain neutre (pas chez nous) » sur la ligne d'écart : la rencontre devient
        // un terrain neutre (traité comme un extérieur), l'écart est résolu. venueId null
        // y est VOULU (pas « chez nous ») — l'exception explicite de la promesse.
        [$club, $user, $season] = $this->createClubUser('rnk');
        $team = $this->createTeam($club, $season, 'SF3');
        [$fixture] = $this->unplacedVenueDeviatedFixture($club, $season, $team, 'GYMNASE JDR');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'venue',
            'choice' => 'take_source',
            'venueId' => 'NEUTRAL',
        ]);
        self::assertResponseStatusCodeSame(200);

        $reloaded = $this->reloadFixture($club, $fixture->getId());
        self::assertSame(FixtureUnplacedReason::NEUTRAL_GROUND, $reloaded->getUnplacedReason(), 'la rencontre devient un terrain neutre');
        self::assertSame([], $reloaded->getPendingDeviations(), 'l\'écart est résolu');
        self::assertSame(FixtureReviewState::REVIEWED, $reloaded->getReviewState());
    }

    public function testInlineVenueChoiceOfAForeignClubIsRefusedAndWritesNothing(): void
    {
        // Abus : choisir un gymnase d'un AUTRE club. Invisible par le filtre tenant →
        // refus, et le gymnase étranger ne reçoit aucun alias, la rencontre reste intacte.
        [$club, $user, $season] = $this->createClubUser('rfk');
        $team = $this->createTeam($club, $season, 'SF3');
        [$fixture, $jdr] = $this->unplacedVenueDeviatedFixture($club, $season, $team, 'GYMNASE JDR');
        [$foreignClub, , $foreignSeason] = $this->createClubUser('rfk2');
        $foreignVenue = $this->createAliasedVenue($foreignClub, $foreignSeason, 'Gymnase Étranger', []);

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'venue',
            'choice' => 'take_source',
            'venueId' => $foreignVenue,
        ]);
        self::assertResponseStatusCodeSame(404, 'un gymnase étranger est invisible → refus, jamais une écriture cross-tenant');

        $reloaded = $this->reloadFixture($club, $fixture->getId());
        self::assertSame($jdr, $reloaded->getVenueId(), 'le gymnase de la rencontre reste intact');
        self::assertNotNull($reloaded->getPendingDeviation('venue'));
        $this->scopeGucToClub($foreignClub->getId());
        $stolen = $this->em->getRepository(Venue::class)->find($foreignVenue);
        self::assertSame([], $stolen?->getExternalLabels() ?? ['x'], 'le gymnase étranger n\'a reçu aucun alias');
    }

    public function testInlineVenueChoiceOfAnArchivedSeasonVenueIsRefusedAndWritesNothing(): void
    {
        // Abus (patron A10) : un gymnase du MÊME club mais d'une AUTRE saison (archivée)
        // est invisible par le season_filter → refus, aucun alias dans la saison archivée.
        [$club, $user, $season] = $this->createClubUser('rsk');
        $team = $this->createTeam($club, $season, 'SF3');
        [$fixture, $jdr] = $this->unplacedVenueDeviatedFixture($club, $season, $team, 'GYMNASE JDR');
        $archivedVenue = $this->archivedSeasonVenue($club, 'Gymnase Saison Archivée');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'venue',
            'choice' => 'take_source',
            'venueId' => $archivedVenue,
        ]);
        self::assertResponseStatusCodeSame(404, 'un gymnase d\'une autre saison est hors de la saison d\'écriture → refus');

        $reloaded = $this->reloadFixture($club, $fixture->getId());
        self::assertSame($jdr, $reloaded->getVenueId(), 'le gymnase reste intact');
        self::assertNotNull($reloaded->getPendingDeviation('venue'));
    }

    public function testInlineVenueChoiceWhoseSourceLabelAlreadyAliasesAnotherVenueIs422(): void
    {
        // Abus : le libellé source est DÉJÀ un alias confirmé d'un AUTRE gymnase du club.
        // L'apparier à un troisième gymnase est refusé (422, « retirez-le d'abord »).
        [$club, $user, $season] = $this->createClubUser('rak');
        $team = $this->createTeam($club, $season, 'SF3');
        $this->createAliasedVenue($club, $season, 'Porteur', ['salle raphael de barros']);
        $other = $this->createAliasedVenue($club, $season, 'Autre', []);
        [$fixture, $jdr] = $this->unplacedVenueDeviatedFixture($club, $season, $team, 'GYMNASE JDR');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'venue',
            'choice' => 'take_source',
            'venueId' => $other,
        ]);
        self::assertResponseStatusCodeSame(422, 'un libellé déjà porté par un autre gymnase est refusé');

        $reloaded = $this->reloadFixture($club, $fixture->getId());
        self::assertSame($jdr, $reloaded->getVenueId(), 'le gymnase reste intact');
        $this->scopeGucToClub($club->getId());
        $other2 = $this->em->getRepository(Venue::class)->find($other);
        self::assertSame([], $other2?->getExternalLabels() ?? ['x'], 'le troisième gymnase n\'a pas volé l\'alias');
    }

    public function testInlineVenueChoiceHonoursTheAttacherBounds(): void
    {
        // Abus (bornes 120/30) : les gardes de la MAISON VenueExternalLabelAttacher
        // tiennent aussi à l'arbitrage — un libellé > 120 caractères, et un gymnase déjà
        // au plafond de 30 alias, sont refusés (422), rien n'est écrit.
        [$club, $user, $season] = $this->createClubUser('rbk');
        $team = $this->createTeam($club, $season, 'SF3');

        // 120 : un libellé source de 121 caractères.
        $longLabel = str_repeat('X', 121);
        $gym = $this->createAliasedVenue($club, $season, 'Gym Bornes', []);
        [$fixtureLong] = $this->unplacedVenueDeviatedFixtureWithLabel($club, $season, $team, 'GYMNASE JDR', $longLabel);
        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixtureLong->getId(),
            'field' => 'venue',
            'choice' => 'take_source',
            'venueId' => $gym,
        ]);
        self::assertResponseStatusCodeSame(422, 'un libellé > 120 caractères est refusé');

        // 30 : un gymnase déjà au plafond de 30 alias.
        $full = $this->createAliasedVenue($club, $season, 'Gym Plein', array_map(static fn (int $i): string => 'alias ' . $i, range(1, 30)));
        [$fixtureCeil] = $this->unplacedVenueDeviatedFixtureWithLabel($club, $season, $team, 'GYMNASE JDR2', 'SALLE EN TROP', 'RBK2');
        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixtureCeil->getId(),
            'field' => 'venue',
            'choice' => 'take_source',
            'venueId' => $full,
        ]);
        self::assertResponseStatusCodeSame(422, 'un 31ᵉ alias est refusé');
        $this->scopeGucToClub($club->getId());
        $fullReloaded = $this->em->getRepository(Venue::class)->find($full);
        self::assertCount(30, $fullReloaded?->getExternalLabels() ?? [], 'le gymnase garde ses 30 alias, pas un de plus');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function post(User $user, string $url, mixed $body): void
    {
        $this->client->request('POST', $url, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    private function plainFixture(Club $club, Season $season, Team $team, string $date): Fixture
    {
        $this->scopeGucToClub($club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setVenueId('22222222-2222-4222-8222-222222222222');
        $fixture->setKickoffTime(new DateTimeImmutable('15:30'));
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function deviatedFixture(Club $club, Season $season, Team $team, string $appDate, string $sourceDate): Fixture
    {
        $fixture = $this->plainFixture($club, $season, $team, $appDate);
        $this->scopeGucToClub($club->getId());
        $fixture->putPendingDeviation([
            'field' => 'date',
            'appValue' => $appDate,
            'sourceValue' => $sourceDate,
            'channel' => 'FBI_XLSX',
            'seenAt' => '2026-09-01T10:00:00+00:00',
            'autoApplied' => false,
        ]);
        $fixture->setReviewState(FixtureReviewState::OUT_OF_SYNC);
        $this->em->flush();

        return $fixture;
    }

    private function autoAppliedReviewedFixture(Club $club, Season $season, Team $team, string $appDate, string $sourceDate): Fixture
    {
        // Placée → REVIEWED (setStatus PLACED sans écart traite la rencontre), puis
        // une entrée autoApplied déposée SANS repasser OUT_OF_SYNC — l'état exact
        // d'un extérieur / domicile in-window dont la source a fait foi (P4-199).
        $fixture = $this->plainFixture($club, $season, $team, $appDate);
        $this->scopeGucToClub($club->getId());
        $fixture->putPendingDeviation([
            'field' => 'date',
            'appValue' => $appDate,
            'sourceValue' => $sourceDate,
            'channel' => 'FBI_XLSX',
            'seenAt' => '2026-09-01T10:00:00+00:00',
            'autoApplied' => true,
        ]);
        $fixture->markReviewed(new DateTimeImmutable);
        $this->em->flush();

        return $fixture;
    }

    /**
     * An UNPLACED home fixture attached to a REAL club gym, carrying an open venue
     * écart whose source names another salle (« SALLE RAPHAEL DE BARROS ») — the
     * exact « 20 »-case state the review endpoint arbitrates.
     *
     * @return array{0: Fixture, 1: string} [fixture, appVenueId]
     */
    private function unplacedVenueDeviatedFixture(Club $club, Season $season, Team $team, string $venueName): array
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($venueName);
        $venue->setSource('manual');
        $this->em->persist($venue);

        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setVenueId($venue->getId());
        $fixture->setFbiVenueLabel('SALLE RAPHAEL DE BARROS');
        $fixture->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
        $fixture->putPendingDeviation([
            'field' => 'venue',
            'appValue' => $venueName,
            'sourceValue' => 'SALLE RAPHAEL DE BARROS',
            'channel' => 'FBI_XLSX',
            'seenAt' => '2026-09-01T10:00:00+00:00',
            'autoApplied' => false,
        ]);
        $fixture->setReviewState(FixtureReviewState::OUT_OF_SYNC);
        $this->em->persist($fixture);
        $this->em->flush();

        return [$fixture, $venue->getId()];
    }

    /**
     * Même état que {@see unplacedVenueDeviatedFixture}, mais avec le libellé SOURCE
     * de l'écart et une référence externe au CHOIX (deux rencontres distinctes dans un
     * même scénario). Le gymnase courant (`$venueName`) est un gymnase RÉEL du club.
     *
     * @return array{0: Fixture, 1: string} [fixture, appVenueId]
     */
    private function unplacedVenueDeviatedFixtureWithLabel(Club $club, Season $season, Team $team, string $venueName, string $sourceLabel, string $ref = 'RBK1'): array
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($venueName);
        $venue->setSource('manual');
        $this->em->persist($venue);

        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setExternalRef($ref);
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setVenueId($venue->getId());
        $fixture->setFbiVenueLabel($sourceLabel);
        $fixture->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
        $fixture->putPendingDeviation([
            'field' => 'venue',
            'appValue' => $venueName,
            'sourceValue' => $sourceLabel,
            'channel' => 'FBI_XLSX',
            'seenAt' => '2026-09-01T10:00:00+00:00',
            'autoApplied' => false,
        ]);
        $fixture->setReviewState(FixtureReviewState::OUT_OF_SYNC);
        $this->em->persist($fixture);
        $this->em->flush();

        return [$fixture, $venue->getId()];
    }

    /**
     * Un gymnase du MÊME club vivant dans une saison ARCHIVÉE (passée) — hors de la
     * saison d'écriture courante, donc invisible au season_filter (patron A10).
     */
    private function archivedSeasonVenue(Club $club, string $name): string
    {
        $this->scopeGucToClub($club->getId());
        $currentYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $past = new Season;
        $past->setClubId($club->getId());
        $past->setName((string) ($currentYear - 1));
        $past->setStartDate(new DateTimeImmutable(($currentYear - 1) . '-08-01'));
        $past->setEndDate(new DateTimeImmutable($currentYear . '-07-15'));
        $past->setStatus(SeasonStatus::ARCHIVED);
        $past->setTransitionData([]);
        $this->em->persist($past);

        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($past->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    /** @param list<string> $aliases already-normalized labels */
    private function createAliasedVenue(Club $club, Season $season, string $name, array $aliases): string
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $venue->setExternalLabels($aliases);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    private function reloadFixture(Club $club, string $fixtureId): Fixture
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($club->getId());
        $em->clear();
        $fixture = $em->getRepository(Fixture::class)->find($fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture);

        return $fixture;
    }

    /** @return array<string, mixed> */
    private function reload(Club $club, string $fixtureId): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($club->getId());
        $row = $em->getConnection()->fetchAssociative(
            'SELECT status, review_state, match_date, pending_deviations FROM fixture WHERE id = ?',
            [$fixtureId],
        );
        self::assertIsArray($row);

        return $row;
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

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club review ' . $suffix);
        $club->setSlug('club-review-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('review' . $uid . '@test.com');
        $user->setFirstName('Review');
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
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        // Matches require a settled season plan (cockpit state 3 — SocleGuard).
        $this->settleSeasonPlan($season);

        return [$club, $user, $season];
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
