<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use App\Tests\BuildsTestImages;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * P5-6 PR 2 — le rail superadmin du canal de signalement.
 *
 * Prouve : le SA liste les signalements de PLUSIEURS clubs (cross-tenant), filtre
 * par statut, lit le détail avec son contexte lourd ; marquer traité enfile l'email
 * « traité » à l'auteur (double treat → 409) ; l'auteur effacé → treat passe, zéro
 * email, zéro erreur ; retour non-traité N'ENVOIE RIEN (double untreat → 409) ; le
 * bloc QoS est cohérent (semis daté, percentiles simples) ; CSRF manquant → 403 ;
 * un JWT club est refusé au firewall.
 *
 * Isolation : `feedback` est FORCE RLS ; on sème et on lit par la connexion `admin`
 * (hors DAMA, committée), donc on repart d'une table VIDE (le bloc QoS est GLOBAL)
 * et on nettoie au teardown. Le kernel se reboote entre requêtes : rien à garder en
 * transaction — tout est committé côté admin, la session SA vit dans son cookie.
 */
#[Group('integration')]
final class AdminFeedbackTest extends WebTestCase
{
    use BuildsTestImages;
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private string $requestIp;

    private ?string $adminId = null;

    /** @var list<string> */
    private array $clubIds = [];

    /** @var list<string> */
    private array $userIds = [];

    public function testAnonymousAndClubJwtAreRefusedAtTheFirewall(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');

        // Anonyme.
        $this->client->request('GET', '/api/admin/feedback');
        self::assertResponseStatusCodeSame(401);

        // JWT club : ne franchit pas le firewall admin.
        $token = $this->registerVerified('AFB0');
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/admin/feedback', [], [], $this->bearer($token));
        self::assertResponseStatusCodeSame(401);
    }

    public function testSuperAdminListsAcrossClubsFiltersByStatusAndReadsHeavyContext(): void
    {
        $clubA = $this->seedClub('Alpha');
        $clubB = $this->seedClub('Bravo');

        $treatedA = $this->seedFeedback($clubA, null, 'bug', 'Créneau en double', 'treated', '2026-08-01T10:00:00+00:00', '2026-08-01T12:00:00+00:00');
        $this->seedFeedback($clubB, null, 'idea', 'Un tri par gymnase', 'treated', '2026-08-02T09:00:00+00:00', '2026-08-02T13:00:00+00:00');
        $heavyId = $this->seedFeedback($clubA, null, 'missing_constraint', 'Il manque une contrainte', 'untreated', new DateTimeImmutable('-3 hours')->format(DateTimeImmutable::ATOM), null, [
            'screen' => 'planning',
            'scheduleId' => Uuid::v4()->toRfc4122(),
            'snapshot' => ['weeks' => [['index' => 1]]],
            'diagnostics' => [['type' => 'VENUE_OVERBOOKED']],
        ]);
        // Date FIXE d'août, pas `now-1h` : l'assertion QoS compte les bugs du mois 2026-08 en
        // dur — un semis relatif bascule de mois au passage de minuit le 1er (rouge CI du
        // 2026-09-01, PR #811). Rien n'exige sa fraîcheur : le « plus vieux non traité ≥ 2 h »
        // est porté par le feedback à -3 h ci-dessus.
        $this->seedFeedback($clubB, null, 'bug', 'Autre bug', 'untreated', '2026-08-05T10:00:00+00:00', null);

        $this->authenticateSuperAdmin('feedback-list@example.test');

        // Liste complète : les 4 signalements, deux clubs.
        $this->client->request('GET', '/api/admin/feedback?limit=50');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame(4, $body['pagination']['total'], 'les 4 signalements de deux clubs (cross-tenant)');
        $clubNames = array_column($body['items'], 'clubName');
        self::assertContains('Alpha', $clubNames);
        self::assertContains('Bravo', $clubNames, 'la liste franchit la frontière tenant');

        // Le contexte lourd n'est PAS dans la liste — seulement l'indicateur.
        $heavy = $this->itemById($body['items'], $heavyId);
        self::assertTrue($heavy['hasHeavyContext']);
        self::assertArrayNotHasKey('context', $heavy, 'la liste ne charrie jamais le contexte lourd');
        $treated = $this->itemById($body['items'], $treatedA);
        self::assertFalse($treated['hasHeavyContext']);

        // Filtre par statut.
        $this->client->request('GET', '/api/admin/feedback?status=untreated&limit=50');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->responseBody()['pagination']['total'], 'deux non-traités');

        $this->client->request('GET', '/api/admin/feedback?status=bogus');
        self::assertResponseStatusCodeSame(400, 'un statut inconnu est refusé');

        // Détail : contexte COMPLET, snapshot inclus.
        $this->client->request('GET', '/api/admin/feedback/' . $heavyId);
        self::assertResponseIsSuccessful();
        $detail = $this->responseBody();
        self::assertSame('missing_constraint', $detail['topic']);
        self::assertNotEmpty($detail['context']['snapshot'] ?? [], 'le snapshot est lisible dans le détail');
        self::assertSame('planning', $detail['context']['screen'] ?? null);

        // Bloc QoS : part traitée 2/4, délai moyen (2h + 4h)/2 = 3h, p95 ≈ 3.9h, plus
        // vieux non traité ≈ 3h. Tout sur le mois 2026-08 (semis daté).
        $qos = $body['qos'];
        self::assertSame(0.5, $qos['treatedShare']);
        $august = array_values(array_filter($qos['treatDelayByMonth'], static fn (array $r): bool => '2026-08' === $r['month']));
        self::assertCount(1, $august);
        self::assertEqualsWithDelta(3.0, $august[0]['avgHours'], 0.001);
        self::assertEqualsWithDelta(3.9, $august[0]['p95Hours'], 0.05);
        $bugVolume = array_values(array_filter($qos['volumeByTopicMonth'], static fn (array $r): bool => '2026-08' === $r['month'] && 'bug' === $r['topic']));
        self::assertSame(2, $bugVolume[0]['count'] ?? null);
        self::assertNotNull($qos['oldestUntreatedAgeHours']);
        self::assertGreaterThanOrEqual(2.0, $qos['oldestUntreatedAgeHours']);
    }

    public function testTreatSetsStatusEmailsTheAuthorAndDoubleTreatIs409(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');
        $club = $this->seedClub('Charlie');
        $authorEmail = 'author-' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr';
        $userId = $this->seedUser($authorEmail);
        $id = $this->seedFeedback($club, $userId, 'bug', 'À traiter', 'untreated', new DateTimeImmutable('-2 hours')->format(DateTimeImmutable::ATOM), null);

        $csrf = $this->authenticateSuperAdmin('feedback-treat@example.test');

        $this->client->request('POST', '/api/admin/feedback/' . $id . '/treat', [], [], $this->csrf($csrf));
        self::assertResponseIsSuccessful();
        self::assertSame('treated', $this->responseBody()['status']);
        self::assertNotNull($this->responseBody()['treatedAt']);
        self::assertCount(1, $this->queuedEmails(), 'l\'email « traité » est enfilé pour l\'auteur');

        // En base : traité + treated_at posé.
        self::assertSame('treated', $this->admin()->fetchOne('SELECT status FROM feedback WHERE id = :id', ['id' => $id]));

        // Deuxième treat → 409 (idempotence de clôture).
        $this->client->request('POST', '/api/admin/feedback/' . $id . '/treat', [], [], $this->csrf($csrf));
        self::assertResponseStatusCodeSame(409);
    }

    public function testTreatWithAnErasedAuthorSucceedsWithoutEmailOrError(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');
        $club = $this->seedClub('Delta');
        // Auteur effacé (RGPD) : le user_id ne pointe plus vers aucun app_user.
        $id = $this->seedFeedback($club, Uuid::v4()->toRfc4122(), 'idea', 'Auteur parti', 'untreated', new DateTimeImmutable('-1 hour')->format(DateTimeImmutable::ATOM), null);

        $csrf = $this->authenticateSuperAdmin('feedback-erased@example.test');
        $this->client->request('POST', '/api/admin/feedback/' . $id . '/treat', [], [], $this->csrf($csrf));

        self::assertResponseIsSuccessful();
        self::assertSame('treated', $this->responseBody()['status']);
        self::assertCount(0, $this->queuedEmails(), 'auteur effacé → aucun email, aucune erreur');
    }

    public function testUntreatSendsNoEmailAndDoubleUntreatIs409(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');
        $club = $this->seedClub('Echo');
        $userId = $this->seedUser('echo-' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr');
        $id = $this->seedFeedback($club, $userId, 'bug', 'Déjà traité', 'treated', '2026-08-01T10:00:00+00:00', '2026-08-01T11:00:00+00:00');

        $csrf = $this->authenticateSuperAdmin('feedback-untreat@example.test');

        $this->client->request('POST', '/api/admin/feedback/' . $id . '/untreat', [], [], $this->csrf($csrf));
        self::assertResponseIsSuccessful();
        self::assertSame('untreated', $this->responseBody()['status']);
        self::assertNull($this->responseBody()['treatedAt']);
        self::assertCount(0, $this->queuedEmails(), 'le retour à non-traité ne remercie personne');

        // Deuxième untreat → 409.
        $this->client->request('POST', '/api/admin/feedback/' . $id . '/untreat', [], [], $this->csrf($csrf));
        self::assertResponseStatusCodeSame(409);
    }

    public function testWritesRequireCsrf(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');
        $club = $this->seedClub('Foxtrot');
        $id = $this->seedFeedback($club, Uuid::v4()->toRfc4122(), 'bug', 'x', 'untreated', '2026-08-01T10:00:00+00:00', null);

        $this->authenticateSuperAdmin('feedback-csrf@example.test');
        // Session admin valide mais sans jeton CSRF → 403.
        $this->client->request('POST', '/api/admin/feedback/' . $id . '/treat');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Promesse (amendement fondateur 2026-10-23) : la console RECHERCHE un signalement par
     * NOM DE CLUB ou par MOT du message (ILIKE, insensible à la casse), à travers les tenants.
     * Le rail superadmin reste étanche en ENTRÉE (anonyme/JWT club → 401) : déjà prouvé par
     * testAnonymousAndClubJwtAreRefusedAtTheFirewall, non redoublé ici.
     */
    public function testSuperAdminSearchesByClubNameOrMessageWord(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');
        $alpha = $this->seedClub('Alpha Basket');
        $bravo = $this->seedClub('Bravo Sport');
        $this->seedFeedback($alpha, null, 'bug', 'Le planning plante au chargement', 'untreated', '2026-08-01T10:00:00+00:00', null);
        $this->seedFeedback($bravo, null, 'idea', 'Un tri par gymnase serait utile', 'untreated', '2026-08-02T10:00:00+00:00', null);

        $this->authenticateSuperAdmin('feedback-q-club@example.test');

        // Par nom de club (fragment, casse indifférente) : ne garde que ce club.
        $this->client->request('GET', '/api/admin/feedback?q=ALPHA&limit=50');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame(1, $body['pagination']['total'], 'la recherche par nom de club ne garde que ce club');
        self::assertSame('Alpha Basket', $body['items'][0]['clubName']);

        // Par mot du message (dans l'autre club) : la recherche traverse le tenant.
        $this->client->request('GET', '/api/admin/feedback?q=gymnase&limit=50');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame(1, $body['pagination']['total'], 'la recherche par mot du message traverse le tenant');
        self::assertSame('Bravo Sport', $body['items'][0]['clubName']);
    }

    /**
     * Abus / injection : les métacaractères LIKE (`%`, `_`, `\`) de `q` sont ÉCHAPPÉS et
     * cherchés LITTÉRALEMENT — un `%` ne doit JAMAIS se comporter en joker qui ramène toute
     * la table (falsification : sans échappement, `?q=%` rend TOUT, `?q=a_b` matche aussi « axb »).
     */
    public function testSearchEscapesLikeWildcards(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');
        $club = $this->seedClub('Zeta');
        $this->seedFeedback($club, null, 'bug', '100% des creneaux occupes', 'untreated', '2026-08-01T10:00:00+00:00', null);
        $this->seedFeedback($club, null, 'bug', 'a_b litteral souligne', 'untreated', '2026-08-02T10:00:00+00:00', null);
        $this->seedFeedback($club, null, 'bug', 'axb variante quelconque', 'untreated', '2026-08-03T10:00:00+00:00', null);

        $this->authenticateSuperAdmin('feedback-q-escape@example.test');

        // `%` (encodé %25) cherché littéralement : seul le message qui contient vraiment « % ».
        $this->client->request('GET', '/api/admin/feedback?q=%25&limit=50');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->responseBody()['pagination']['total'], 'le % est un caractère recherché, jamais un joker');

        // `_` cherché littéralement : « a_b » ne doit PAS matcher « axb » (joker simple échappé).
        $this->client->request('GET', '/api/admin/feedback?q=a_b&limit=50');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->responseBody()['pagination']['total'], 'le _ est un caractère recherché, jamais un joker simple');
    }

    /**
     * Recherche ET filtre de statut se CUMULENT : `?q=…&status=untreated` ne garde que les
     * non-traités QUI matchent. Un autre club non-traité (qui ne matche pas) prouve que c'est
     * bien `q` qui discrimine, pas le seul statut (falsification : `q` ignoré → 2 non-traités).
     */
    public function testSearchCombinesWithTheStatusFilter(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');
        $omega = $this->seedClub('Omega');
        $other = $this->seedClub('Autre');
        $this->seedFeedback($omega, null, 'bug', 'Omega souci urgent', 'untreated', '2026-08-01T10:00:00+00:00', null);
        $this->seedFeedback($omega, null, 'idea', 'Omega idee deja traitee', 'treated', '2026-08-02T10:00:00+00:00', '2026-08-02T12:00:00+00:00');
        $this->seedFeedback($other, null, 'bug', 'Autre souci non traite', 'untreated', '2026-08-03T10:00:00+00:00', null);

        $this->authenticateSuperAdmin('feedback-q-status@example.test');
        $this->client->request('GET', '/api/admin/feedback?q=omega&status=untreated&limit=50');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame(1, $body['pagination']['total'], 'recherche ET filtre de statut se cumulent');
        self::assertSame('untreated', $body['items'][0]['status']);
        self::assertSame('Omega', $body['items'][0]['clubName']);
    }

    /** `q` trop long (> 100 caractères) → 400, même contrat que la recherche de clubs. */
    public function testSearchQueryOverHundredCharactersIsRejected(): void
    {
        $this->authenticateSuperAdmin('feedback-q-long@example.test');
        $this->client->request('GET', '/api/admin/feedback?q=' . str_repeat('a', 101));
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Chantier F PR3 — ROUND-TRIP de la capture : un membre l'envoie en multipart (porte club),
     * le blob est STOCKÉ HORS table (patron ClubLogoStorage) et n'est servi QUE par le rail
     * superadmin, octet pour octet. La route de service lit le stockage PAR ID (404 si absent,
     * AUCUNE lecture DB — patron ClubLogoController::serve), ce qui la rend lisible même quand la
     * ligne de signalement du membre vit dans une transaction de test (le blob, lui, est sur disque).
     *
     * Falsifiable : tant que le multipart n'est pas géré, le POST renvoie 400 (pas de `id`) ; tant
     * que la route de service n'existe pas, le GET superadmin renvoie 404 ≠ 200 + octets.
     */
    public function testMemberScreenshotIsServedOnlyToSuperAdmin(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');
        $token = $this->registerVerified('AFBSHOT');
        $png = $this->validPngBytes(6, 6);

        $this->client->request(
            'POST',
            '/api/feedback',
            ['payload' => json_encode(['topic' => 'bug', 'message' => 'Voici une capture.', 'context' => ['screen' => 'planning']], \JSON_THROW_ON_ERROR)],
            ['screenshot' => $this->uploadedImage($png, 'capture.png', 'image/png')],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => $this->requestIp],
        );
        self::assertResponseStatusCodeSame(201, 'un envoi multipart avec capture aboutit');
        $id = $this->responseBody()['id'];
        self::assertIsString($id);

        $this->startFreshBrowserSession($this->client);
        $this->authenticateSuperAdmin('feedback-shot@example.test');

        $this->client->request('GET', '/api/admin/feedback/' . $id . '/screenshot');
        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'), 'servie comme une image');
        self::assertSame($png, $response->getContent(), 'octet pour octet, la capture stockée hors table');
    }

    /** Un id sans capture → 404 (jamais une fuite ni un 500) — l'angle superadmin de l'étanchéité. */
    public function testScreenshotServeUnknownIdIs404ForSuperAdmin(): void
    {
        $this->authenticateSuperAdmin('feedback-shot-404@example.test');
        $this->client->request('GET', '/api/admin/feedback/' . Uuid::v4()->toRfc4122() . '/screenshot');
        self::assertResponseStatusCodeSame(404, 'un id sans capture → 404, jamais une fuite');
    }

    /**
     * PROMESSE : le détail expose `hasScreenshot` — un pointeur léger (colonne), pas le blob —
     * pour que la console sache afficher ou non l'aperçu. Vrai quand une capture est jointe, faux
     * sinon. Falsifiable : la colonne/le champ absents → clé manquante (null) ≠ true/false.
     */
    public function testDetailExposesHasScreenshot(): void
    {
        $this->admin()->executeStatement('DELETE FROM feedback');
        $club = $this->seedClub('ShotFlag');
        $withShot = $this->seedFeedbackWithScreenshotFlag($club, true);
        $without = $this->seedFeedbackWithScreenshotFlag($club, false);

        $this->authenticateSuperAdmin('feedback-shot-flag@example.test');

        $this->client->request('GET', '/api/admin/feedback/' . $withShot);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->responseBody()['hasScreenshot'] ?? null, 'le détail signale la présence d\'une capture');

        $this->client->request('GET', '/api/admin/feedback/' . $without);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->responseBody()['hasScreenshot'] ?? null, 'et son absence');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        // Table VIDE avant chaque test : le bloc QoS agrège TOUT le rail (aucun filtre
        // par club) — des lignes voisines fausseraient les percentiles/la part traitée.
        $this->admin()->executeStatement('DELETE FROM feedback');
    }

    protected function tearDown(): void
    {
        $this->cleanupTempImages();
        $this->admin()->executeStatement('DELETE FROM feedback');
        foreach ($this->userIds as $userId) {
            $this->admin()->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $userId]);
        }
        foreach ($this->clubIds as $clubId) {
            $this->admin()->executeStatement('DELETE FROM club WHERE id = :id', ['id' => $clubId]);
        }
        if (null !== $this->adminId) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    private function seedClub(string $name): string
    {
        $clubId = Uuid::v4()->toRfc4122();
        $suffix = strtolower(substr(md5($clubId), 0, 8));
        $this->admin()->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed) VALUES (:id, 1, NOW(), NOW(), :name, :slug, 0, :tz, :locale, FALSE)',
            ['id' => $clubId, 'name' => $name, 'slug' => 'fb-' . $suffix, 'tz' => 'Europe/Paris', 'locale' => 'fr'],
        );
        $this->clubIds[] = $clubId;

        return $clubId;
    }

    private function seedUser(string $email): string
    {
        $userId = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name) VALUES (:id, 1, NOW(), NOW(), :email, :hash, :first, :last)',
            ['id' => $userId, 'email' => $email, 'hash' => 'x', 'first' => 'Au', 'last' => 'Teur'],
        );
        $this->userIds[] = $userId;

        return $userId;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function seedFeedback(string $clubId, ?string $userId, string $topic, string $message, string $status, string $createdAt, ?string $treatedAt, ?array $context = null): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO feedback (id, version, club_id, user_id, topic, message, context, status, treated_at, created_at) VALUES (:id, 1, :club, :user, :topic, :message, :context, :status, :treated, :created)',
            [
                'id' => $id,
                'club' => $clubId,
                'user' => $userId ?? Uuid::v4()->toRfc4122(),
                'topic' => $topic,
                'message' => $message,
                'context' => null === $context ? null : json_encode($context, \JSON_THROW_ON_ERROR),
                'status' => $status,
                'treated' => $treatedAt,
                'created' => $createdAt,
            ],
        );

        return $id;
    }

    /** Sème un signalement portant UNIQUEMENT le pointeur `has_screenshot` (colonne PR3), via la connexion admin. */
    private function seedFeedbackWithScreenshotFlag(string $clubId, bool $hasScreenshot): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO feedback (id, version, club_id, user_id, topic, message, context, status, treated_at, created_at, has_screenshot) VALUES (:id, 1, :club, :user, :topic, :message, NULL, :status, NULL, :created, :shot)',
            ['id' => $id, 'club' => $clubId, 'user' => Uuid::v4()->toRfc4122(), 'topic' => 'bug', 'message' => 'x', 'status' => 'untreated', 'created' => '2026-08-01T10:00:00+00:00', 'shot' => $hasScreenshot],
            ['shot' => ParameterType::BOOLEAN],
        );

        return $id;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function itemById(array $items, string $id): array
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        self::fail('Signalement absent de la liste : ' . $id);
    }

    /** @return list<object> the queued SendEmailMessage instances of the last request */
    private function queuedEmails(): array
    {
        $transport = self::getContainer()->get('messenger.transport.mailer_in_memory');
        \assert($transport instanceof InMemoryTransport);

        return array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent());
    }

    /** @return array{HTTP_AUTHORIZATION: string, REMOTE_ADDR: string} */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => $this->requestIp];
    }

    /** @return array{HTTP_X_CSRF_TOKEN: string, CONTENT_TYPE: string, REMOTE_ADDR: string} */
    private function csrf(string $token): array
    {
        return ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->requestIp];
    }

    private function registerVerified(string $ara): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email,
            'password' => 'Password123!',
            'firstName' => 'Mem',
            'lastName' => 'Bre',
            'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $ara,
            'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        return $token;
    }

    private function authenticateSuperAdmin(string $email): string
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, 'VeryStrongPassword!'));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, TRUE, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret()],
        );

        $this->json('POST', '/api/admin/auth/password', ['email' => $email, 'password' => 'VeryStrongPassword!']);
        self::assertResponseIsSuccessful();
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();
        $csrf = $this->responseBody()['csrfToken'] ?? null;
        self::assertIsString($csrf);

        return $csrf;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
