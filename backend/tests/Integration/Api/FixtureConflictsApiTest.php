<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\ConflictResolution;
use App\Entity\FbiCorrection;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\Season;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\ConflictResolutionStatus;
use App\Enum\FbiCorrectionCloseSource;
use App\Enum\FbiCorrectionField;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * On-the-fly conflict radar (spec gestion-matchs PR-2): the endpoint surfaces a
 * coach's overlapping matches, and stays strictly scoped to the caller's club
 * (§7.1 tenant axis — one club never sees another's conflicts).
 */
#[Group('phase1')]
#[Group('integration')]
final class FixtureConflictsApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testReturnsSameCoachMatchMatchConflict(): void
    {
        [, $userA, $coachAId] = $this->createClubWithOverlappingMatches('a');

        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);

        $data = $this->responseData();
        $conflicts = $data['conflicts'];
        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_MATCH', $conflicts[0]['type']);
        self::assertSame($coachAId, $conflicts[0]['coachId']);
        self::assertArrayHasKey('left', $conflicts[0]);
        self::assertArrayHasKey('right', $conflicts[0]);
        // P1-4 PR E2 — gravity is emitted by the SERVER (the UI only groups):
        // a MAIN coach double-booked = severity 3.
        self::assertSame(3, $conflicts[0]['severity']);
        self::assertSame('MAIN', $conflicts[0]['coachRole']);
    }

    public function testConflictsAreScopedToTheCallersClub(): void
    {
        [, $userA, $coachAId] = $this->createClubWithOverlappingMatches('a');
        [, , $coachBId] = $this->createClubWithOverlappingMatches('b');

        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);

        $coachIds = array_map(static fn (array $c): string => $c['coachId'], $this->responseData()['conflicts']);
        self::assertSame([$coachAId], array_values(array_unique($coachIds)));
        self::assertNotContains($coachBId, $coachIds);
    }

    /**
     * Lot « une personne = ses équipes » — a person who COACHES team-1 (MAIN) and
     * PLAYS team-2 is double-booked, and each side carries its own role over the
     * wire: MAIN on the coached side, PLAYER on the played side. Severity stays 3
     * (MAIN×PLAYER is a hard clash), coachRole is the aggregate PLAYER.
     */
    public function testMatchMatchCarriesPerSideRolesForACoachWhoAlsoPlays(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('cp', playsSecondTeam: true);

        $conflicts = $this->conflictsFor($user);
        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_MATCH', $conflicts[0]['type']);
        self::assertSame(3, $conflicts[0]['severity']);
        self::assertSame('PLAYER', $conflicts[0]['coachRole']);
        // team-1 (16:00, window 15:30) is chronologically first → left, coached MAIN;
        // team-2 (16:30) → right, played PLAYER.
        self::assertSame('MAIN', $conflicts[0]['left']['role']);
        self::assertSame('PLAYER', $conflicts[0]['right']['role']);
    }

    /**
     * §7.1 tenant axis — a player membership of ANOTHER club never leaks a conflict
     * into the caller's radar (the CoachPlayerMembership tenant filter applies like
     * every other loaded entity). Club B's coach↔player overlap stays invisible to A.
     */
    public function testMembershipsOfAnotherClubRaiseNoConflictForTheCaller(): void
    {
        [, $userA, $coachAId] = $this->createClubWithOverlappingMatches('ma');
        [, , $coachBId] = $this->createClubWithOverlappingMatches('mb', playsSecondTeam: true);

        $conflicts = $this->conflictsFor($userA);
        $coachIds = array_map(static fn (array $c): string => $c['coachId'], $conflicts);
        self::assertSame([$coachAId], array_values(array_unique($coachIds)));
        self::assertNotContains($coachBId, $coachIds);
    }

    /**
     * RMM-3 — le contrat HTTP porte désormais un champ ADDITIF `fingerprint` sur
     * chaque item : l'identité stable du conflit, calculée en aval du détecteur. Le
     * gardien (POST /api/matches/module-visit) s'en sert pour dire ce qui est neuf.
     */
    public function testEveryConflictCarriesAStableFingerprint(): void
    {
        [, $userA, $coachAId] = $this->createClubWithOverlappingMatches('fp');

        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);

        $conflicts = $this->responseData()['conflicts'];
        self::assertCount(1, $conflicts);
        self::assertArrayHasKey('fingerprint', $conflicts[0], 'chaque item porte son empreinte');
        $fingerprint = $conflicts[0]['fingerprint'];
        self::assertIsString($fingerprint);
        self::assertStringStartsWith('MATCH_MATCH:' . $coachAId . ':', $fingerprint, 'l\'empreinte porte le type et le coach, jamais la sévérité ni le segment');
    }

    /**
     * D1 rule 3 — a match already played no longer surfaces on the radar. The
     * clock is pinned to 2026-09-01 (see setUp), so this past pair (2026-08-01)
     * sits behind the club's civil today while the future pairs of the other
     * tests (2026-10-04) stay ahead of it.
     */
    public function testPastMatchesNoLongerSurface(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('past', '2026-08-01');

        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);

        self::assertSame([], $this->responseData()['conflicts'], 'un match passé ne remonte plus (D1 règle 3)');
    }

    /**
     * P2-54 « détail par côté » — chaque côté MATCH porte les champs ADDITIFS servis
     * pour le rendu par côté : durée de match, libellé adverse, trajet (null en
     * domicile), heure estimée (null quand le coup d'envoi est réel). Un côté HOME ne
     * porte JAMAIS `opponentPlace` (décoré côté AWAY seulement).
     */
    public function testSidesCarryTheAdditiveDetailFields(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('add');

        $conflicts = $this->conflictsFor($user);
        self::assertCount(1, $conflicts);
        foreach (['left', 'right'] as $key) {
            $side = $conflicts[0][$key];
            self::assertIsInt($side['matchDurationMinutes']);
            self::assertSame('Adv', $side['opponentLabel']);
            self::assertArrayHasKey('travelOneWayMinutes', $side);
            self::assertNull($side['travelOneWayMinutes'], 'domicile → jamais de trajet');
            self::assertNull($side['estimatedKickoffTime'], 'coup d\'envoi réel → pas d\'heure estimée');
            self::assertArrayNotHasKey('opponentPlace', $side, 'côté HOME → pas de lieu adverse');
        }
    }

    /**
     * P2-54 — le côté AWAY d'un MATCH_MATCH est décoré de `opponentPlace`, résolu ici
     * par l'annuaire fédéral (ville). Le côté HOME reste sans la clé.
     */
    public function testAwaySideCarriesOpponentPlaceFromTheDirectory(): void
    {
        [, $user] = $this->createClubWithHomeAwayOverlap('pl', 'ARA0069777', 'Villeurbanne');

        $conflicts = $this->conflictsFor($user);
        self::assertCount(1, $conflicts);
        $sides = [];
        foreach (['left', 'right'] as $key) {
            $sides[$conflicts[0][$key]['homeAway']] = $conflicts[0][$key];
        }
        self::assertArrayHasKey('opponentPlace', $sides['AWAY']);
        self::assertSame('Villeurbanne', $sides['AWAY']['opponentPlace']);
        self::assertArrayNotHasKey('opponentPlace', $sides['HOME'], 'jamais de lieu adverse décoré sur un domicile');
    }

    // ── P4-207 « Résolution des conflits » (champ additif + PUT/DELETE) ──

    public function testGetCarriesNullResolutionByDefault(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('rn');

        $conflicts = $this->conflictsFor($user);
        self::assertCount(1, $conflicts);
        self::assertArrayHasKey('resolution', $conflicts[0], 'le champ resolution est ADDITIF, toujours présent');
        self::assertNull($conflicts[0]['resolution'], 'aucune ligne = « à traiter » = null');
    }

    public function testPutThenGetCarriesTheResolution(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('put');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'DEROGATION_REQUESTED', 'note' => 'Vu avec la ligue']);
        self::assertResponseStatusCodeSame(200);
        $put = $this->responseData();
        self::assertSame($fingerprint, $put['fingerprint']);
        self::assertSame('DEROGATION_REQUESTED', $put['resolution']['status']);

        $conflict = $this->conflictsFor($user)[0];
        self::assertSame('DEROGATION_REQUESTED', $conflict['resolution']['status']);
        self::assertSame('Vu avec la ligue', $conflict['resolution']['note']);
        self::assertArrayHasKey('updatedAt', $conflict['resolution']);
    }

    public function testPutOnTheSameFingerprintReplacesTheRow(): void
    {
        [$club, $user] = $this->createClubWithOverlappingMatches('rep');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'DEROGATION_REQUESTED']);
        self::assertResponseStatusCodeSame(200);
        $this->putResolution($user, $fingerprint, ['status' => 'RESOLVED_INTERNALLY']);
        self::assertResponseStatusCodeSame(200);

        self::assertSame('RESOLVED_INTERNALLY', $this->conflictsFor($user)[0]['resolution']['status']);
        $this->scopeGucToClub($club->getId());
        self::assertCount(1, $this->em->getRepository(ConflictResolution::class)->findBy([]), 'un upsert, jamais une seconde ligne');
    }

    public function testDeleteResetsToNullAndIsIdempotent(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('del');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];
        $this->putResolution($user, $fingerprint, ['status' => 'NO_SOLUTION_YET']);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('DELETE', $this->resolutionUrl($fingerprint), [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->conflictsFor($user)[0]['resolution']);

        // « À traiter » EST l'absence de ligne : un second DELETE reste 204.
        $this->client->request('DELETE', $this->resolutionUrl($fingerprint), [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);
    }

    public function testUnknownStatusIs422(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('st');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'A_TRAITER']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNoteOver500CharactersIs422(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('nt');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'DEROGATION_REQUESTED', 'note' => str_repeat('x', 501)]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testWellFormedButAbsentFingerprintIs422(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('ab');
        // Passes the route requirement (TYPE:uuid) but is nowhere in the current radar.
        $absent = 'AWAY_NO_FOOTPRINT:11111111-1111-4111-8111-111111111111';

        $this->putResolution($user, $absent, ['status' => 'DEROGATION_REQUESTED']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPlayOnlyStatusIsAcceptedWhenThePersonPlays(): void
    {
        // La personne COACHE team-1 (MAIN) et JOUE team-2 (PLAYER) : le conflit porte un côté PLAYER.
        [, $user] = $this->createClubWithOverlappingMatches('pl', playsSecondTeam: true);
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'COACHES_NOT_PLAYING']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('COACHES_NOT_PLAYING', $this->conflictsFor($user)[0]['resolution']['status']);
    }

    public function testPlayOnlyStatusIs422WhenNobodyPlays(): void
    {
        // Deux équipes COACHÉES (aucun côté PLAYER) : un statut « joue/coache » n'a pas de sens.
        [, $user] = $this->createClubWithOverlappingMatches('nj');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'PLAYS_NOT_COACHING']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('réservé aux conflits où la personne joue', (string) ($this->responseData()['error'] ?? ''));
    }

    // ── Lot N : vocabulaire par famille + « erreur FBI » alimente le registre ──

    public function testFamilySpecificStatusIsRefusedOnAnotherFamily(): void
    {
        // Une collision de gymnase accepte FBI_ERROR / MATCH_TO_MOVE, mais pas
        // IMPORT_MISSING_MATCHES (propre au calendrier incomplet) → 422 parlant.
        [, $user] = $this->createClubWithVenueOverlap('fam');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'IMPORT_MISSING_MATCHES']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ne s\'applique pas à cette famille', (string) ($this->responseData()['error'] ?? ''));

        // …et MATCH_TO_MOVE, lui, est accepté sur cette même famille.
        $this->putResolution($user, $fingerprint, ['status' => 'MATCH_TO_MOVE']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('MATCH_TO_MOVE', $this->conflictsFor($user)[0]['resolution']['status']);
    }

    public function testFbiErrorOnAGymCollisionOpensExactlyOneLedgerEntryAndIsIdempotent(): void
    {
        [$club, $user, $fixtureId] = $this->createClubWithVenueOverlap('fbe');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        // Déclarer l'erreur FBI (salle) sur l'un des deux côtés → 200, la résolution est posée.
        $this->putResolution($user, $fingerprint, ['status' => 'FBI_ERROR', 'fbiCorrection' => ['fixtureId' => $fixtureId, 'field' => 'venue']]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('FBI_ERROR', $this->conflictsFor($user)[0]['resolution']['status']);

        // Exactement UNE entrée ouverte, valeur cible VIDE (l'appli ne l'invente pas).
        $this->scopeGucToClub($club->getId());
        $entries = $this->em->getRepository(FbiCorrection::class)->findBy(['fixtureId' => $fixtureId, 'field' => FbiCorrectionField::VENUE]);
        self::assertCount(1, $entries);
        self::assertNull($entries[0]->getAppValue(), 'la valeur cible reste vide — l\'appli a importé l\'erreur');
        self::assertTrue($entries[0]->isOpen());

        // Idempotence : un second appel (double-clic) ne crée PAS de doublon.
        $this->putResolution($user, $fingerprint, ['status' => 'FBI_ERROR', 'fbiCorrection' => ['fixtureId' => $fixtureId, 'field' => 'venue']]);
        self::assertResponseStatusCodeSame(200);
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(FbiCorrection::class)->findBy(['fixtureId' => $fixtureId, 'field' => FbiCorrectionField::VENUE]));
    }

    public function testFbiErrorWithoutAValidComplementIs422AndWritesNothing(): void
    {
        [$club, $user, $fixtureId] = $this->createClubWithVenueOverlap('fbx');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        // (1) FBI_ERROR sans complément → 422.
        $this->putResolution($user, $fingerprint, ['status' => 'FBI_ERROR']);
        self::assertResponseStatusCodeSame(422);

        // (2) FBI_ERROR visant une rencontre qui n'est PAS un côté du conflit → 422.
        $this->putResolution($user, $fingerprint, ['status' => 'FBI_ERROR', 'fbiCorrection' => ['fixtureId' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'field' => 'venue']]);
        self::assertResponseStatusCodeSame(422);

        // (3) FBI_ERROR avec un champ inconnu → 422.
        $this->putResolution($user, $fingerprint, ['status' => 'FBI_ERROR', 'fbiCorrection' => ['fixtureId' => $fixtureId, 'field' => 'opponent']]);
        self::assertResponseStatusCodeSame(422);

        // Aucune entrée n'a été créée, ni résolution posée (rien d'atomique n'a fui).
        $this->scopeGucToClub($club->getId());
        self::assertCount(0, $this->em->getRepository(FbiCorrection::class)->findBy(['fixtureId' => $fixtureId]));
        self::assertNull($this->conflictsFor($user)[0]['resolution']);
    }

    /**
     * Lot N, point 1 — « une seule déclaration d'erreur FBI vivante par conflit ». Re-déclarer
     * sur le MÊME conflit (autre rencontre + autre champ) FERME l'entrée précédente (REDECLARED)
     * et n'en ouvre qu'une nouvelle : le gestionnaire qui se ravise ne laisse pas une ligne
     * fausse. ⚠ Décision fondateur : remettre le conflit « à traiter » (DELETE) ne ferme PAS
     * l'entrée vivante — le rappel d'une erreur FBI réelle survit.
     */
    public function testRedeclaringFbiErrorOnTheSameConflictClosesThePreviousEntry(): void
    {
        [$club, $user, $f1, $f2] = $this->createClubWithVenueOverlap('rd');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        // 1re déclaration : (f1, salle).
        $this->putResolution($user, $fingerprint, ['status' => 'FBI_ERROR', 'fbiCorrection' => ['fixtureId' => $f1, 'field' => 'venue']]);
        self::assertResponseStatusCodeSame(200);

        // Re-déclaration sur le MÊME conflit, AUTRE rencontre + AUTRE champ : (f2, heure).
        $this->putResolution($user, $fingerprint, ['status' => 'FBI_ERROR', 'fbiCorrection' => ['fixtureId' => $f2, 'field' => 'kickoff']]);
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $repo = $this->em->getRepository(FbiCorrection::class);

        // Une SEULE entrée vivante, sur la nouvelle cible ; l'ancienne est fermée (REDECLARED).
        $open = array_values(array_filter($repo->findAll(), static fn (FbiCorrection $e): bool => $e->isOpen()));
        self::assertCount(1, $open, 'une seule déclaration vivante par conflit');
        self::assertSame($f2, $open[0]->getFixtureId());
        self::assertSame(FbiCorrectionField::KICKOFF, $open[0]->getField());

        $former = $repo->findOneBy(['fixtureId' => $f1, 'field' => FbiCorrectionField::VENUE]);
        self::assertInstanceOf(FbiCorrection::class, $former);
        self::assertFalse($former->isOpen(), 'la déclaration précédente est fermée');
        self::assertSame(FbiCorrectionCloseSource::REDECLARED, $former->getClosedBy());

        // Décision fondateur : DELETE (retour « à traiter ») ne ferme PAS l'entrée vivante.
        $this->client->request('DELETE', $this->resolutionUrl($fingerprint), [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $survivor = $this->em->getRepository(FbiCorrection::class)->findOneBy(['fixtureId' => $f2, 'field' => FbiCorrectionField::KICKOFF]);
        self::assertInstanceOf(FbiCorrection::class, $survivor);
        self::assertTrue($survivor->isOpen(), 'supprimer la résolution ne ferme pas l\'entrée (décision fondateur, lot N)');
    }

    /**
     * Lot N, point 3 — un complément « erreur FBI » envoyé avec un statut qui ne l'attend pas
     * est REFUSÉ explicitement (422 parlant), plutôt qu'ignoré en silence : le contrat ne
     * tolère pas une requête incohérente qui n'écrirait rien au registre.
     */
    public function testAComplementSentWithANonFbiStatusIsRefused(): void
    {
        [$club, $user, $f1] = $this->createClubWithVenueOverlap('cx');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        // MATCH_TO_MOVE est valide sur cette famille, mais accompagné d'un complément « erreur
        // FBI » → incohérent (le complément ne serait jamais lu) → 422 explicite.
        $this->putResolution($user, $fingerprint, ['status' => 'MATCH_TO_MOVE', 'fbiCorrection' => ['fixtureId' => $f1, 'field' => 'venue']]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('complément', (string) ($this->responseData()['error'] ?? ''));

        // Rien n'a été posé — ni résolution, ni entrée de registre.
        $this->scopeGucToClub($club->getId());
        self::assertCount(0, $this->em->getRepository(FbiCorrection::class)->findAll());
        self::assertNull($this->conflictsFor($user)[0]['resolution']);
    }

    /**
     * Lot N, point 2 — la pose est « lecture puis insertion » sans verrou : deux poses
     * simultanées du même (club, saison, empreinte) racent, et le contrôleur rattrape
     * désormais la violation d'unicité en 200 idempotent. On PROUVE ici le filet que ce
     * rattrapage suppose : l'unicité BASE `(club, saison, empreinte)` lève bien
     * UniqueConstraintViolationException.
     *
     * ⚠ La récupération HTTP elle-même n'est PAS simulable en test : sous DAMA une seule
     * transaction, un seul instantané — une lecture qui MANQUE la ligne concurrente et une
     * relecture qui la VOIT ne coexistent pas sur la même connexion, et deux connexions
     * vraiment concurrentes sont impraticables (cf. PeriodWindowRaceTest). Le chemin de
     * rattrapage relit donc sur la connexion vivante (l'EM clos par le flush échoué), scopé
     * club par la RLS.
     */
    public function testTheUniqueBackstopBehindTheIdempotentRecoveryFires(): void
    {
        [$club, $user] = $this->createClubWithVenueOverlap('bk');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->scopeGucToClub($club->getId());
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]);
        self::assertInstanceOf(Season::class, $season);
        $seasonId = $season->getId();

        $make = static fn (): ConflictResolution => (new ConflictResolution)
            ->setClubId($club->getId())
            ->setSeasonId($seasonId)
            ->setFingerprint($fingerprint)
            ->setStatus(ConflictResolutionStatus::DEROGATION_REQUESTED)
            ->setUpdatedBy($user->getId());

        $this->em->persist($make());
        $this->em->flush();

        // Une seconde ligne de MÊME clé (la « course perdante ») → le filet BASE mord.
        $this->em->persist($make());
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testMalformedFingerprintIs404(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('mf');

        // Lowercase, no TYPE:field shape → the route requirement rejects it (routing 404).
        $this->client->request('PUT', '/api/fixtures/conflicts/pas-une-empreinte/resolution', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['status' => 'DEROGATION_REQUESTED'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Pin the civil today so the seeded dates keep their past/future relation
        // whatever the wall clock — 2026-10-04 fixtures stay ahead, 2026-08-01
        // behind (D1 rule 3 filters strictly past matches).
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-01 10:00:00'));
    }

    protected function tearDown(): void
    {
        // Redis is shared and not rolled back — never leak the pin into another test.
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    /**
     * A club whose single coach runs two teams playing overlapping matches on the
     * same day → exactly one MATCH_MATCH conflict. When $playsSecondTeam is true the
     * person only COACHES team-1 (MAIN) and PLAYS team-2 (an active
     * CoachPlayerMembership) — the founder case that unions coaches with players.
     *
     * @return array{0: Club, 1: User, 2: string} club, user, coachId
     */
    private function createClubWithOverlappingMatches(string $suffix, string $matchDate = '2026-10-04', bool $playsSecondTeam = false): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club conflict ' . $suffix);
        $club->setSlug('club-conflict-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('conflict' . $uid . '@test.com');
        $user->setFirstName('Con');
        $user->setLastName('Flict');
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

        $coach = new Coach;
        $coach->setClubId($club->getId());
        $coach->setSeasonId($season->getId());
        $coach->setFirstName('Coach');
        $coach->setLastName($suffix);
        $this->em->persist($coach);
        $this->em->flush();

        $team1 = $this->uuid($suffix, 1);
        $team2 = $this->uuid($suffix, 2);
        // Coach both teams, OR coach team-1 and PLAY team-2 (the coach↔player case).
        $coachedTeams = $playsSecondTeam ? [$team1] : [$team1, $team2];
        foreach ($coachedTeams as $teamId) {
            $link = new TeamCoach;
            $link->setClubId($club->getId());
            $link->setSeasonId($season->getId());
            $link->setTeamId($teamId);
            $link->setCoachId($coach->getId());
            $link->setRole(TeamCoachRole::MAIN);
            $this->em->persist($link);
        }
        if ($playsSecondTeam) {
            $membership = new CoachPlayerMembership;
            $membership->setClubId($club->getId());
            $membership->setSeasonId($season->getId());
            $membership->setCoachId($coach->getId());
            $membership->setTeamId($team2);
            $membership->setIsActive(true);
            $this->em->persist($membership);
        }

        // Two home matches of the coach's two teams, windows 15:30–17:45 and
        // 16:00–18:15 → overlap.
        $this->fixture($club, $season, $team1, '16:00', $matchDate);
        $this->fixture($club, $season, $team2, '16:30', $matchDate);
        $this->em->flush();

        return [$club, $user, $coach->getId()];
    }

    private function fixture(Club $club, Season $season, string $teamId, string $kickoff, string $matchDate): void
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable($matchDate));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null);
        $this->em->persist($fixture);
    }

    /**
     * A club whose coach runs team-1 (HOME) and team-2 (AWAY) with overlapping
     * windows → one MATCH_MATCH. The away opponent carries an organisme code and a
     * directory entry (city), so its side gets an `opponentPlace`.
     *
     * @return array{0: Club, 1: User, 2: string} club, user, coachId
     */
    private function createClubWithHomeAwayOverlap(string $suffix, string $opponentCode, string $city): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club conflict ' . $suffix);
        $club->setSlug('club-conflict-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('conflict' . $uid . '@test.com');
        $user->setFirstName('Con');
        $user->setLastName('Flict');
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

        $coach = new Coach;
        $coach->setClubId($club->getId());
        $coach->setSeasonId($season->getId());
        $coach->setFirstName('Coach');
        $coach->setLastName($suffix);
        $this->em->persist($coach);
        $this->em->flush();

        $team1 = $this->uuid($suffix, 1);
        $team2 = $this->uuid($suffix, 2);
        foreach ([$team1, $team2] as $teamId) {
            $link = new TeamCoach;
            $link->setClubId($club->getId());
            $link->setSeasonId($season->getId());
            $link->setTeamId($teamId);
            $link->setCoachId($coach->getId());
            $link->setRole(TeamCoachRole::MAIN);
            $this->em->persist($link);
        }

        // team-1 HOME 16:00 ; team-2 AWAY 16:00 (real kickoff) → overlapping windows.
        $this->fixture($club, $season, $team1, '16:00', '2026-10-04');
        $away = new Fixture;
        $away->setClubId($club->getId());
        $away->setSeasonId($season->getId());
        $away->setTeamId($team2);
        $away->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $away->setHomeAway(FixtureHomeAway::AWAY);
        $away->setOpponentLabel('ASVEL - 2');
        $away->setOpponentOrganismeCode($opponentCode);
        $away->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', '16:00') ?: null);
        $this->em->persist($away);

        // The federal directory (GLOBAL, no club_id) knows where the opponent plays.
        $entry = new OpponentDirectoryEntry($opponentCode, 'ASVEL', OpponentLocationPrecision::CITY);
        $entry->setCity($city);
        $this->em->persist($entry);

        $this->em->flush();

        return [$club, $user, $coach->getId()];
    }

    /**
     * A club with two HOME matches on the SAME venue with overlapping windows → exactly
     * one VENUE_OVERLAP conflict (no coach, so no person conflict). Returns club, user,
     * and the two fixture ids (the 16:00 one is the conflict's `left`).
     *
     * @return array{0: Club, 1: User, 2: string, 3: string} club, user, fixtureId1, fixtureId2
     */
    private function createClubWithVenueOverlap(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club venue ' . $suffix);
        $club->setSlug('club-venue-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('venue' . $uid . '@test.com');
        $user->setFirstName('Ven');
        $user->setLastName('Ue');
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

        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Gymnase Coubertin');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        $f1 = $this->homeFixtureAtVenue($club, $season, $this->uuid($suffix, 1), '16:00', $venue->getId());
        $f2 = $this->homeFixtureAtVenue($club, $season, $this->uuid($suffix, 2), '16:30', $venue->getId());
        $this->em->flush();

        return [$club, $user, $f1, $f2];
    }

    private function homeFixtureAtVenue(Club $club, Season $season, string $teamId, string $kickoff, string $venueId): string
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setVenueId($venueId);
        $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null);
        $this->em->persist($fixture);

        return $fixture->getId();
    }

    private function uuid(string $suffix, int $n): string
    {
        $hex = substr(md5($suffix . $n), 0, 12);

        return \sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), '111', '111', '111111111111');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conflictsFor(User $user): array
    {
        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);

        /** @var list<array<string, mixed>> $conflicts */
        $conflicts = $this->responseData()['conflicts'];

        return $conflicts;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function putResolution(User $user, string $fingerprint, array $body): void
    {
        $this->client->request('PUT', $this->resolutionUrl($fingerprint), [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function resolutionUrl(string $fingerprint): string
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
