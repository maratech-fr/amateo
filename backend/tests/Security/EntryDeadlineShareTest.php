<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR BLOQUANT — RMM-6 (P2-50) « échéances ligue/comité : saisie par compétition + défaut
 * communautaire surchargeable + outlook J-7 » (axe §7.1 : aucun, mais l'invariant de PARTAGE
 * hors-tenant est structurant — cf. le patron annuaire adverse, à ne jamais laisser dériver).
 *
 * POURQUOI ce test existe : le défaut communautaire est une table PARTAGÉE entre clubs
 * (`shared_competition_deadline`) — la seule surface du module matchs qui traverse la frontière
 * tenant. La décision fondateur est stricte : « si le premier club set la date, le club de la
 * même ligue aura une date par défaut qu'il peut surcharger », et la table ne porte AUCUNE donnée
 * club-identifiante (par conception — sinon c'est une fuite tenant). Sans ce test, une colonne
 * `club_id`/`user_id` pourrait s'y glisser, un club pourrait lire la proposition d'une AUTRE
 * ligue, ou l'écrasement « dernière écriture gagne » pourrait piétiner la valeur souveraine d'un
 * club. Les huit volets sont falsifiés dans les DEUX sens.
 *
 * (a) le SCHÉMA du partagé n'a aucune colonne club-identifiante (catalogue Postgres, liste blanche
 *     exacte) · (b) un club apparié à la MÊME compétition fédérale LIT la proposition, un club
 *     apparié à une AUTRE ne la voit PAS · (c) une échéance sur compétition NON appariée n'écrit
 *     RIEN au partagé · (d) la valeur club gagne TOUJOURS · (e) la réponse servie est
 *     BYTE-IDENTIQUE quel que soit le club auteur (zéro oracle) · (f) dernière écriture gagne
 *     (l'exemple BCCL/Meyzieu) ET le premier club n'est pas touché ; effacer sa valeur club
 *     n'efface pas le partagé · (g) gardes : management 403, tenant (compétition étrangère → 422
 *     sans écriture), 409 saison archivée · (h) l'outlook ne STAMPE pas : un POST module-visit
 *     postérieur sert le MÊME delta.
 */
#[Group('phase1')]
#[Group('security')]
final class EntryDeadlineShareTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    // ─────────────────────────────────────────────────────────────────────────
    // (a) le SCHÉMA du partagé — aucune colonne club-identifiante
    // ─────────────────────────────────────────────────────────────────────────

    public function testSharedTableSchemaHasNoClubIdentifyingColumn(): void
    {
        /** @var list<string> $columns */
        $columns = $this->conn()->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'shared_competition_deadline\' ORDER BY column_name',
        );
        sort($columns);

        // Liste blanche EXACTE — falsifiée dans les deux sens (une colonne en plus ou en moins rougit).
        self::assertSame(
            ['created_at', 'entry_deadline', 'ffbb_competition_id', 'id', 'updated_at'],
            $columns,
            'la table partagée ne porte QUE ces colonnes — aucune donnée club-identifiante',
        );

        // Explicite pour le lecteur : les colonnes interdites PAR CONCEPTION sont absentes.
        foreach (['club_id', 'user_id', 'season_id', 'author_id', 'set_count', 'usage_count'] as $forbidden) {
            self::assertNotContains($forbidden, $columns, \sprintf('« %s » est interdite par conception sur le partagé', $forbidden));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (b) même compétition fédérale → proposition lue ; autre → invisible
    // ─────────────────────────────────────────────────────────────────────────

    public function testSameFederationCompetitionInheritsProposalOthersDoNot(): void
    {
        $ffbbX = 'FFBB-COMP-' . uniqid('x', false);
        $ffbbY = 'FFBB-COMP-' . uniqid('y', false);

        // Club A pose une échéance sur SA compétition appariée à X → le partagé X naît.
        [, $seasonA, $userA] = $this->createClub('ba');
        $compA = $this->makeCompetition($seasonA, 1, $ffbbX);
        $this->postDeadlines($userA, [$compA->getId()], '2026-09-10');

        // Club B, apparié à la MÊME compétition fédérale X, SANS valeur club → LIT la proposition.
        [, $seasonB, $userB] = $this->createClub('bb');
        $compB = $this->makeCompetition($seasonB, 1, $ffbbX);
        $readB = $this->getCompetition($userB, $compB->getId());
        self::assertNull($readB['entryDeadline'], 'club B n\'a pas de valeur propre');
        self::assertSame('2026-09-10', $readB['effectiveEntryDeadline'], 'club B hérite du défaut communautaire');
        self::assertSame('community', $readB['deadlineSource']);

        // Club C, apparié à une AUTRE compétition fédérale Y → ne voit RIEN de X.
        [, $seasonC, $userC] = $this->createClub('bc');
        $compC = $this->makeCompetition($seasonC, 1, $ffbbY);
        $readC = $this->getCompetition($userC, $compC->getId());
        self::assertNull($readC['entryDeadline']);
        self::assertNull($readC['effectiveEntryDeadline'], 'une autre compétition fédérale ne voit pas la proposition de X');
        self::assertNull($readC['deadlineSource']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (c) échéance sur compétition NON appariée → rien au partagé
    // ─────────────────────────────────────────────────────────────────────────

    public function testDeadlineOnUnpairedCompetitionWritesNothingToShared(): void
    {
        [, $season, $user] = $this->createClub('unp');
        $comp = $this->makeCompetition($season, 1, null); // NON appariée (ffbbCompetitionId null)

        $before = $this->sharedRowCount();
        $this->postDeadlines($user, [$comp->getId()], '2026-09-10');

        // La valeur club est bien écrite…
        $read = $this->getCompetition($user, $comp->getId());
        self::assertSame('2026-09-10', $read['entryDeadline']);
        self::assertSame('2026-09-10', $read['effectiveEntryDeadline']);
        self::assertSame('club', $read['deadlineSource']);

        // …mais RIEN n'est ajouté au partagé.
        self::assertSame($before, $this->sharedRowCount(), 'une compétition non appariée n\'écrit rien au partagé');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (d) la valeur club gagne toujours
    // ─────────────────────────────────────────────────────────────────────────

    public function testClubValueAlwaysWinsOverCommunityDefault(): void
    {
        $ffbbX = 'FFBB-COMP-' . uniqid('d', false);

        // Un premier club fixe le partagé X à 2026-09-10.
        [, $seasonA, $userA] = $this->createClub('da');
        $compA = $this->makeCompetition($seasonA, 1, $ffbbX);
        $this->postDeadlines($userA, [$compA->getId()], '2026-09-10');

        // Club B, apparié X, pose SA PROPRE valeur 2026-09-12 → sa valeur club prime.
        [, $seasonB, $userB] = $this->createClub('db');
        $compB = $this->makeCompetition($seasonB, 1, $ffbbX);
        $this->postDeadlines($userB, [$compB->getId()], '2026-09-12');

        $readB = $this->getCompetition($userB, $compB->getId());
        self::assertSame('2026-09-12', $readB['entryDeadline']);
        self::assertSame('2026-09-12', $readB['effectiveEntryDeadline'], 'la valeur club gagne, jamais le partagé');
        self::assertSame('club', $readB['deadlineSource']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (e) réponse byte-identique quel que soit le club auteur (zéro oracle)
    // ─────────────────────────────────────────────────────────────────────────

    public function testServedDeadlineIsByteIdenticalRegardlessOfAuthor(): void
    {
        // Deux compétitions fédérales distinctes, deux AUTEURS distincts, même date.
        $ffbbX = 'FFBB-COMP-' . uniqid('ex', false);
        $ffbbZ = 'FFBB-COMP-' . uniqid('ez', false);

        [, $seasonAuthor1, $author1] = $this->createClub('ea1');
        $this->postDeadlines($author1, [$this->makeCompetition($seasonAuthor1, 1, $ffbbX)->getId()], '2026-09-10');

        [, $seasonAuthor2, $author2] = $this->createClub('ea2');
        $this->postDeadlines($author2, [$this->makeCompetition($seasonAuthor2, 1, $ffbbZ)->getId()], '2026-09-10');

        // Deux lecteurs, chacun apparié à l'une des deux compétitions → ils reçoivent le MÊME
        // fragment d'échéance (aucune trace de qui l'a saisie).
        [, $seasonReader1, $reader1] = $this->createClub('er1');
        $fragment1 = $this->deadlineFragment($this->getCompetition($reader1, $this->makeCompetition($seasonReader1, 1, $ffbbX)->getId()));

        [, $seasonReader2, $reader2] = $this->createClub('er2');
        $fragment2 = $this->deadlineFragment($this->getCompetition($reader2, $this->makeCompetition($seasonReader2, 1, $ffbbZ)->getId()));

        self::assertSame(
            $fragment1,
            $fragment2,
            'ce qui est servi ne dépend pas de l\'auteur — la table partagée est sans identité',
        );
        self::assertSame(
            '{"entryDeadline":null,"effectiveEntryDeadline":"2026-09-10","deadlineSource":"community"}',
            $fragment1,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (f) dernière écriture gagne ; le premier club intact ; effacer ≠ effacer le partagé
    // ─────────────────────────────────────────────────────────────────────────

    public function testLastWriteWinsAndFirstClubStaysSovereign(): void
    {
        $ffbbX = 'FFBB-COMP-' . uniqid('f', false);

        // BCCL pose 10 sept → partagé = 10, valeur club BCCL = 10.
        [, $seasonBccl, $bccl] = $this->createClub('fbccl');
        $compBccl = $this->makeCompetition($seasonBccl, 1, $ffbbX);
        $this->postDeadlines($bccl, [$compBccl->getId()], '2026-09-10');
        self::assertSame('2026-09-10', $this->sharedDeadlineOf($ffbbX));

        // Meyzieu surcharge 12 → sa valeur club = 12 ET partagé = 12.
        [, $seasonMey, $mey] = $this->createClub('fmey');
        $compMey = $this->makeCompetition($seasonMey, 1, $ffbbX);
        $this->postDeadlines($mey, [$compMey->getId()], '2026-09-12');
        self::assertSame('2026-09-12', $this->sharedDeadlineOf($ffbbX), 'dernière écriture gagne sur le partagé');

        $readMey = $this->getCompetition($mey, $compMey->getId());
        self::assertSame('2026-09-12', $readMey['effectiveEntryDeadline']);
        self::assertSame('club', $readMey['deadlineSource']);

        // BCCL n'est PAS touché : sa valeur club reste 10 (souveraine — le partagé n'est relu que
        // FAUTE de valeur club).
        $readBccl = $this->getCompetition($bccl, $compBccl->getId());
        self::assertSame('2026-09-10', $readBccl['entryDeadline'], 'le premier club n\'est pas touché par la surcharge');
        self::assertSame('2026-09-10', $readBccl['effectiveEntryDeadline']);
        self::assertSame('club', $readBccl['deadlineSource']);

        // Effacer sa valeur club (deadline: null) n'efface PAS la ligne partagée.
        $this->postDeadlines($bccl, [$compBccl->getId()], null);
        self::assertSame('2026-09-12', $this->sharedDeadlineOf($ffbbX), 'effacer sa valeur club ne touche pas le partagé');

        $readBcclCleared = $this->getCompetition($bccl, $compBccl->getId());
        self::assertNull($readBcclCleared['entryDeadline'], 'la valeur club est effacée');
        self::assertSame('2026-09-12', $readBcclCleared['effectiveEntryDeadline'], 'BCCL retombe alors sur le partagé (12)');
        self::assertSame('community', $readBcclCleared['deadlineSource']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (g) gardes : management 403, tenant (422 étranger sans écriture), 409 archivée
    // ─────────────────────────────────────────────────────────────────────────

    public function testNonManagementMemberIsForbidden(): void
    {
        [$club, $season] = $this->createClub('gm');
        $comp = $this->makeCompetition($season, 1, null);
        $member = $this->addMember($club, 'coach'); // rôle non-management

        [$status] = $this->postDeadlinesRaw($member, [$comp->getId()], '2026-09-10');
        self::assertSame(403, $status, 'la saisie bulk est management-only');
    }

    public function testForeignCompetitionIsRejectedWithoutWriting(): void
    {
        // Club A ne peut pas viser la compétition du club B (invisible sous les filtres tenant).
        [, $seasonA, $userA] = $this->createClub('gta');
        [, $seasonB] = $this->createClub('gtb');
        $foreign = $this->makeCompetition($seasonB, 1, 'FFBB-COMP-' . uniqid('gt', false));
        $mine = $this->makeCompetition($seasonA, 1, 'FFBB-COMP-' . uniqid('gt2', false));

        $before = $this->sharedRowCount();
        [$status] = $this->postDeadlinesRaw($userA, [$mine->getId(), $foreign->getId()], '2026-09-10');
        self::assertSame(422, $status, 'une compétition étrangère fait 422');

        // RIEN n'est écrit : ni la valeur club de la compétition légitime, ni le partagé.
        self::assertNull($this->getCompetition($userA, $mine->getId())['entryDeadline'], 'aucune écriture partielle');
        self::assertSame($before, $this->sharedRowCount(), 'aucune ligne partagée créée');
    }

    public function testArchivedSeasonIsRejectedWith409(): void
    {
        [$club, , $user] = $this->createClub('garc');
        $archived = $this->addOlderSeason($club);
        $comp = $this->makeCompetition($archived, 1, null);

        [$status] = $this->postDeadlinesRaw($user, [$comp->getId()], '2026-09-10', $archived->getId());
        self::assertSame(409, $status, 'une saison archivée est en lecture seule');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (h) l'outlook ne STAMPE pas
    // ─────────────────────────────────────────────────────────────────────────

    public function testOutlookDoesNotStampTheVisit(): void
    {
        [$club, $season, $user] = $this->createClub('h');
        $comp = $this->makeCompetition($season, 1, null);

        // Une fenêtre J-7 ouverte : échéance dans 3 jours + un domicile encore à saisir.
        $this->setClubDeadline($comp, new DateTimeImmutable('today')->modify('+3 days'));
        $this->homeFixture($season, $comp->getTeamId(), $comp->getId(), FixtureStatus::UNPLACED);

        // Première visite : la référence est figée.
        $first = $this->stampVisit($user);
        self::assertTrue($first['firstVisit']);

        // On recule la référence d'une heure (les fixtures « maintenant » comptent comme neuves),
        // puis on crée un match neuf.
        $this->ageReference($club->getId(), $season->getId());
        $this->homeFixture($season, $comp->getTeamId(), $comp->getId(), FixtureStatus::UNPLACED);

        $visitBefore = $this->visitRow($club->getId(), $season->getId(), $user->getId());

        // L'outlook : la fenêtre est ouverte → le bloc gardien est servi.
        $outlook = $this->deadlineOutlook($user);
        self::assertNotSame([], $outlook['windows'], 'une fenêtre J-7 est ouverte');
        self::assertArrayHasKey('guardianDelta', $outlook, 'le bloc gardien est joint quand une fenêtre est ouverte');
        $outlookNew = $outlook['guardianDelta']['newFixturesCount'];
        self::assertGreaterThan(0, $outlookNew, 'le delta voit le match neuf');

        // La visite n'a PAS bougé : ni référence, ni instantané, ni dernière ouverture.
        self::assertSame($visitBefore, $this->visitRow($club->getId(), $season->getId(), $user->getId()), 'l\'outlook n\'écrit rien sur la visite');

        // Preuve comportementale : un module-visit HORS grâce (qui, lui, tournerait la référence)
        // sert EXACTEMENT le même delta — l'outlook n'a pas replié le match neuf dans la référence.
        $this->expireGrace($user, $season->getId());
        $second = $this->stampVisit($user);
        self::assertFalse($second['firstVisit']);
        self::assertSame($outlookNew, $second['newFixturesCount'], 'le module-visit postérieur sert le MÊME delta que l\'outlook');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    // --- gestes HTTP ---------------------------------------------------------

    /**
     * @param list<string> $competitionIds
     *
     * @return array{updated: list<string>, deadline: string|null}
     */
    private function postDeadlines(User $user, array $competitionIds, ?string $deadline): array
    {
        [$status, $payload] = $this->postDeadlinesRaw($user, $competitionIds, $deadline);
        self::assertSame(200, $status, json_encode($payload, \JSON_THROW_ON_ERROR));

        /* @var array{updated: list<string>, deadline: string|null} $payload */
        return $payload;
    }

    /**
     * @param list<string> $competitionIds
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function postDeadlinesRaw(User $user, array $competitionIds, ?string $deadline, ?string $seasonId = null): array
    {
        $server = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];
        if (null !== $seasonId) {
            $server['HTTP_X_SEASON_ID'] = $seasonId;
        }
        $this->client->request('POST', '/api/competitions/entry-deadlines', [], [], $server, json_encode([
            'competitionIds' => $competitionIds,
            'deadline' => $deadline,
        ], \JSON_THROW_ON_ERROR));

        // Le corps d'un 403/409 est une erreur framework (problem+json / hydra), pas notre JSON :
        // on ne s'intéresse alors qu'au code. On décode au mieux, sans jamais casser sur non-JSON.
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        $payload = \is_array($decoded) ? $decoded : [];

        return [$this->client->getResponse()->getStatusCode(), $payload];
    }

    /**
     * @return array{entryDeadline: string|null, effectiveEntryDeadline: string|null, deadlineSource: string|null}
     */
    private function getCompetition(User $user, string $id): array
    {
        $this->client->request('GET', '/api/competitions/' . $id, [], [], $this->authHeaders($user) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array{entryDeadline: string|null, effectiveEntryDeadline: string|null, deadlineSource: string|null} $payload */
        return $payload;
    }

    /**
     * @return array{firstVisit: bool, newFixturesCount: int, newConflictFingerprints: list<string>, planningChanged: bool}
     */
    private function stampVisit(User $user): array
    {
        $this->client->request('POST', '/api/matches/module-visit', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array{firstVisit: bool, newFixturesCount: int, newConflictFingerprints: list<string>, planningChanged: bool} $payload */
        return $payload;
    }

    /**
     * @return array{windows: list<array<string, mixed>>, guardianDelta?: array{newFixturesCount: int, newConflictFingerprints: list<string>, planningChanged: bool}}
     */
    private function deadlineOutlook(User $user): array
    {
        $this->client->request('GET', '/api/matches/deadline-outlook', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array{windows: list<array<string, mixed>>, guardianDelta?: array{newFixturesCount: int, newConflictFingerprints: list<string>, planningChanged: bool}} $payload */
        return $payload;
    }

    /** @param array{entryDeadline: string|null, effectiveEntryDeadline: string|null, deadlineSource: string|null} $competition */
    private function deadlineFragment(array $competition): string
    {
        return json_encode([
            'entryDeadline' => $competition['entryDeadline'],
            'effectiveEntryDeadline' => $competition['effectiveEntryDeadline'],
            'deadlineSource' => $competition['deadlineSource'],
        ], \JSON_THROW_ON_ERROR);
    }

    // --- lectures SQL --------------------------------------------------------

    private function sharedRowCount(): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM shared_competition_deadline');
    }

    private function sharedDeadlineOf(string $ffbbCompetitionId): ?string
    {
        $value = $this->conn()->fetchOne(
            'SELECT entry_deadline FROM shared_competition_deadline WHERE ffbb_competition_id = :id',
            ['id' => $ffbbCompetitionId],
        );

        return false === $value ? null : substr((string) $value, 0, 10);
    }

    /** @return array{taken: string, snapshot: string, opened: string} */
    private function visitRow(string $clubId, string $seasonId, string $userId): array
    {
        $this->scopeGucToClub($clubId);
        $row = $this->conn()->fetchAssociative(
            'SELECT reference_taken_at, reference_snapshot, last_opened_at FROM match_module_visit WHERE user_id = :uid AND season_id = :sid',
            ['uid' => $userId, 'sid' => $seasonId],
        );
        self::assertIsArray($row);

        return [
            'taken' => (string) $row['reference_taken_at'],
            'snapshot' => (string) $row['reference_snapshot'],
            'opened' => (string) $row['last_opened_at'],
        ];
    }

    private function ageReference(string $clubId, string $seasonId): void
    {
        $this->scopeGucToClub($clubId);
        $this->conn()->executeStatement(
            'UPDATE match_module_visit SET reference_taken_at = NOW() - INTERVAL \'1 hour\' WHERE season_id = :sid',
            ['sid' => $seasonId],
        );
    }

    private function expireGrace(User $user, string $seasonId): void
    {
        $this->scopeGucToClub($this->clubIdOf($user));
        $this->conn()->executeStatement(
            'UPDATE match_module_visit SET last_opened_at = NOW() - INTERVAL \'40 minutes\' WHERE user_id = :uid AND season_id = :sid',
            ['uid' => $user->getId(), 'sid' => $seasonId],
        );
    }

    private function clubIdOf(User $user): string
    {
        return (string) $this->conn()->fetchOne('SELECT club_id FROM club_user WHERE user_id = :uid LIMIT 1', ['uid' => $user->getId()]);
    }

    private function conn(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    // --- semis ---------------------------------------------------------------

    /** @return array{0: Club, 1: Season, 2: User} */
    private function createClub(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club ' . $suffix);
        $club->setSlug('club-deadline-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('deadline' . $uid . '@test.com');
        $user->setFirstName('De');
        $user->setLastName('Adline');
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

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season, $user];
    }

    private function addOlderSeason(Club $club): Season
    {
        $this->scopeGucToClub($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 2;
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ARCHIVED);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function addMember(Club $club, string $role): User
    {
        $uid = uniqid('m', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $member = new User;
        $member->setEmail('member' . $uid . '@test.com');
        $member->setFirstName('Me');
        $member->setLastName('Mber');
        $member->setPasswordHash($hasher->hashPassword($member, 'pass'));
        $this->em->persist($member);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($member->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $member;
    }

    private function makeCompetition(Season $season, int $teamN, ?string $ffbbCompetitionId): Competition
    {
        $this->scopeGucToClub($season->getClubId());
        $competition = new Competition;
        $competition->setClubId($season->getClubId());
        $competition->setSeasonId($season->getId());
        $competition->setTeamId($this->teamUuid($season->getClubId(), $teamN));
        $competition->setName('Championnat ' . $teamN);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        if (null !== $ffbbCompetitionId) {
            $competition->setFfbbCompetitionId($ffbbCompetitionId);
            $competition->setFfbbCompetitionName('Compétition ' . $teamN);
        }
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    private function setClubDeadline(Competition $competition, DateTimeImmutable $deadline): void
    {
        $this->scopeGucToClub($competition->getClubId());
        $managed = $this->em->find(Competition::class, $competition->getId());
        self::assertInstanceOf(Competition::class, $managed);
        $managed->setEntryDeadline($deadline);
        $this->em->flush();
    }

    private function homeFixture(Season $season, string $teamId, string $competitionId, FixtureStatus $status): Fixture
    {
        $this->scopeGucToClub($season->getClubId());
        $fixture = new Fixture;
        $fixture->setClubId($season->getClubId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competitionId);
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setStatus($status);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function teamUuid(string $seed, int $n): string
    {
        $hex = substr(md5($seed . '-' . $n), 0, 12);

        return \sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), '111', '111', '111111111111');
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
