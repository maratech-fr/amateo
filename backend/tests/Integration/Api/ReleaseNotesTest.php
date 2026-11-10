<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * P5-12 — le journal de nouveautés (côté membre) et son atelier superadmin.
 *
 * Le membre ne voit QUE les notes publiées (un brouillon semé reste invisible)
 * et son filigrane de lecture (`seenUpTo`) se pose au POST /seen. L'atelier SA
 * crée/édite/publie/supprime ; publier deux fois est un 409. La surface admin
 * refuse un JWT club.
 */
#[Group('integration')]
final class ReleaseNotesTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private string $requestIp;

    private ?string $adminId = null;

    /**
     * Promesse : une note sans version (champ absent, vide, ou null) s'écrit SANS version — jamais
     * une chaîne vide — et la clé `version` reste présente, à null, dans la réponse.
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function absentVersionPayloads(): iterable
    {
        yield 'champ absent' => [[]];
        yield 'chaîne vide' => [['version' => '']];
        yield 'espaces seuls' => [['version' => '   ']];
        yield 'null' => [['version' => null]];
    }

    /**
     * Promesse : le motif accepte 2 à 4 segments numériques (`1.3`, `1.3.5`, `1.3.5.6`).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function acceptedVersions(): iterable
    {
        yield 'deux segments' => ['1.3'];
        yield 'trois segments' => ['1.3.5'];
        yield 'quatre segments' => ['1.3.5.6'];
    }

    /**
     * Promesse : une version hors motif (balise, 5 segments, lettres, 41 caractères, type non
     * chaîne) est REFUSÉE en 400 avec le message lisible — et rien n'est écrit.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function rejectedVersions(): iterable
    {
        yield 'balise html' => ['<b>1</b>'];
        yield 'cinq segments' => ['1.3.5.6.7'];
        yield 'lettres' => ['abc'];
        yield '41 caractères' => [str_repeat('1', 41)];
        yield 'nombre JSON' => [135];
    }

    /**
     * Promesse (B2, ancien bundle) : **`bodyRich` absent ou null = note TEXTE** — le `body` brut est
     * requis et la note se relit avec `bodyRich: null` (comme toute note d'avant l'éditeur riche).
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function plainBodyPayloads(): iterable
    {
        yield 'bodyRich absent (ancien bundle)' => [[]];
        yield 'bodyRich null' => [['bodyRich' => null]];
    }

    /**
     * Payloads forgés (`curl`) hors liste blanche — A8, XSS stockée.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function forgedRichBodies(): iterable
    {
        $doc = static fn (array ...$blocks): array => ['type' => 'doc', 'content' => $blocks];
        $p = static fn (array ...$inline): array => ['type' => 'paragraph', 'content' => $inline];

        yield 'nœud image' => [$doc(['type' => 'image', 'attrs' => ['src' => 'x', 'onerror' => 'alert(1)']])];
        yield 'marque lien' => [$doc($p(['type' => 'text', 'text' => 'clic', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]))];
        yield 'attribut style' => [$doc(['type' => 'paragraph', 'attrs' => ['style' => 'background:url(x)'], 'content' => [['type' => 'text', 'text' => 'x']]])];
        yield 'titre dans une puce' => [$doc(['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a']]],
            ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'b']]],
        ]]]])];
        yield 'couleur violet' => [$doc($p(['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'noteColor', 'attrs' => ['name' => 'violet']]]]))];
        yield 'couleur __proto__' => [$doc($p(['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'noteColor', 'attrs' => ['name' => '__proto__']]]]))];
        yield 'JSON chaîne' => ['{"type":"doc","content":[{"type":"paragraph"}]}'];
    }

    /**
     * Promesse (m3) : **titre, corps brut et texte riche refusent les caractères de contrôle et bidi**
     * (U+202E retourne l'affichage d'un texte chez tous les clubs), chacun avec son message.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function forbiddenCharPayloads(): iterable
    {
        $rich = static fn (string $text): array => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];

        yield 'U+202E dans un texte riche' => [['title' => 'Note', 'bodyRich' => $rich("facture\u{202E}fdp.exe")], 'Le contenu contient des caractères non autorisés.'];
        yield 'U+202E dans le titre' => [['title' => "Note\u{202E}etoN", 'body' => 'corps'], 'Le titre contient des caractères non autorisés.'];
        yield 'U+0000 dans le corps brut' => [['title' => 'Note', 'body' => "a\u{0000}b"], 'Le contenu contient des caractères non autorisés.'];
        yield 'U+2066 dans le corps brut' => [['title' => 'Note', 'body' => "a\u{2066}b"], 'Le contenu contient des caractères non autorisés.'];
    }

    public function testAnonymousCannotReadTheJournal(): void
    {
        $this->client->request('GET', '/api/release-notes');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMarkSeenIsSelfOnly(): void
    {
        // Aucune identité : marquer « vu » n'a personne à modifier — 401.
        $this->client->request('POST', '/api/release-notes/seen');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMemberSeesOnlyPublishedNotesAndSeenWatermarkMoves(): void
    {
        $token = $this->registerVerified('RN1');

        // L'atelier SA sème un brouillon (invisible) et une note publiée.
        $csrf = $this->authenticateSuperAdmin('release-notes@example.test');
        $this->createNote($csrf, 'Brouillon interne', 'Pas encore prêt', '2026-08-10');
        $publishedId = $this->createNote($csrf, 'Nouvelle vue planning', 'Un récapitulatif plus lisible.', '2026-08-13');
        $this->publishNote($csrf, $publishedId);

        // Le membre repart d'un navigateur neuf (le cookie de session admin ne doit
        // pas voyager) et lit via son JWT.
        $this->startFreshBrowserSession($this->client);
        $body = $this->memberGet($token);
        self::assertNull($body['seenUpTo']);
        $titles = array_column($body['items'], 'title');
        self::assertContains('Nouvelle vue planning', $titles);
        self::assertNotContains('Brouillon interne', $titles, 'un brouillon reste invisible');
        self::assertCount(1, $body['items']);
        self::assertSame('2026-08-13', $body['items'][0]['date']);
        self::assertNotNull($body['items'][0]['publishedAt']);

        // Marquer « vu » pose le filigrane.
        $this->client->request('POST', '/api/release-notes/seen', [], [], $this->bearer($token));
        self::assertResponseStatusCodeSame(204);

        $afterSeen = $this->memberGet($token);
        self::assertNotNull($afterSeen['seenUpTo']);
    }

    public function testSuperAdminCanCreatePublishAndDelete(): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-crud@example.test');

        // Créer (brouillon) — publishedAt null.
        $id = $this->createNote($csrf, 'Titre initial', 'Corps initial', '2026-08-12');
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->responseBody()['publishedAt']);

        // Validation : titre vide → 400.
        $this->json('POST', '/api/admin/release-notes', ['title' => '  ', 'body' => 'x', 'noteDate' => '2026-08-12'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);

        // Publier — publishedAt posé.
        $this->client->request('POST', '/api/admin/release-notes/' . $id . '/publish', [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->responseBody()['publishedAt']);

        // Publier une seconde fois → 409.
        $this->client->request('POST', '/api/admin/release-notes/' . $id . '/publish', [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json']);
        self::assertResponseStatusCodeSame(409);

        // La liste admin voit la note (publiée).
        $this->client->request('GET', '/api/admin/release-notes');
        self::assertResponseIsSuccessful();
        self::assertContains($id, array_column($this->responseBody()['items'], 'id'));

        // Supprimer.
        $this->client->request('DELETE', '/api/admin/release-notes/' . $id, [], [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/admin/release-notes');
        self::assertNotContains($id, array_column($this->responseBody()['items'], 'id'));
    }

    /**
     * Promesse : la version saisie par la SA est NORMALISÉE avant d'être écrite (espaces, `v` de
     * tête retirés ; vide → sans version) et relue telle quelle par la console ET par le membre.
     */
    public function testVersionIsNormalisedAndExposedToMemberAndAdmin(): void
    {
        $token = $this->registerVerified('RN3');
        $csrf = $this->authenticateSuperAdmin('release-notes-version@example.test');

        $this->json('POST', '/api/admin/release-notes', ['title' => 'Note versionnée', 'body' => 'Corps', 'noteDate' => '2026-10-13', 'version' => ' v1.3.5 '], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->responseBody();
        self::assertArrayHasKey('version', $created, 'le 201 expose la version');
        self::assertSame('1.3.5', $created['version'], '« v1.3.5 » entouré d\'espaces → « 1.3.5 »');
        $id = $created['id'];
        self::assertIsString($id);
        $this->publishNote($csrf, $id);

        $this->client->request('GET', '/api/admin/release-notes');
        self::assertResponseIsSuccessful();
        $adminItem = $this->itemWithId($this->responseBody()['items'], $id);
        self::assertArrayHasKey('version', $adminItem, 'la liste console expose la version');
        self::assertSame('1.3.5', $adminItem['version']);

        $this->startFreshBrowserSession($this->client);
        $memberItem = $this->itemWithId($this->memberGet($token)['items'], $id);
        self::assertArrayHasKey('version', $memberItem, 'le GET membre expose la version');
        self::assertSame('1.3.5', $memberItem['version']);
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('absentVersionPayloads')]
    public function testMissingVersionIsStoredAsNull(array $extra): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-noversion-' . substr(md5(uniqid('', true)), 0, 6) . '@example.test');

        $this->json('POST', '/api/admin/release-notes', ['title' => 'Note sans version', 'body' => 'Corps', 'noteDate' => '2026-10-13', ...$extra], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->responseBody();
        self::assertArrayHasKey('version', $created, 'la clé `version` est toujours présente');
        self::assertNull($created['version']);
    }

    #[DataProvider('acceptedVersions')]
    public function testVersionPatternAcceptsTwoToFourNumericSegments(string $version): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-okversion-' . substr(md5(uniqid('', true)), 0, 6) . '@example.test');

        $this->json('POST', '/api/admin/release-notes', ['title' => 'Note', 'body' => 'Corps', 'noteDate' => '2026-10-13', 'version' => $version], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($version, $this->responseBody()['version'] ?? 'clé `version` absente');
    }

    #[DataProvider('rejectedVersions')]
    public function testVersionOutsideThePatternIsRefusedWithAReadableMessage(mixed $version): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-badversion-' . substr(md5(uniqid('', true)), 0, 6) . '@example.test');
        $title = 'Note refusée ' . substr(md5(uniqid('', true)), 0, 8);

        $this->json('POST', '/api/admin/release-notes', ['title' => $title, 'body' => 'Corps', 'noteDate' => '2026-10-13', 'version' => $version], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('La version est invalide (format attendu : 1.3.5).', $this->responseBody()['error'] ?? null);

        $this->client->request('GET', '/api/admin/release-notes');
        self::assertNotContains($title, array_column($this->responseBody()['items'], 'title'), 'un 400 n\'écrit rien');
    }

    /**
     * Promesse (PR B, B1/B2) : **un corps riche valide est STOCKÉ reconstruit et relu tel quel par
     * la console ET par le membre ; le texte brut `body` en est DÉRIVÉ par le serveur** (un `body`
     * envoyé en même temps est ignoré) — un ancien bundle qui ne lit que `body` garde une note lisible.
     * Une balise saisie comme TEXTE reste du texte (le rendu, lui, est prouvé par `rich-text.test.tsx`).
     */
    public function testRichBodyIsStoredRebuiltAndPlainBodyIsDerived(): void
    {
        $token = $this->registerVerified('RN4');
        $csrf = $this->authenticateSuperAdmin('release-notes-rich@example.test');
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'Titre']]],
                ['type' => 'bulletList', 'content' => [
                    ['type' => 'listItem', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '<img src=x onerror=alert(1)>', 'marks' => [['type' => 'bold'], ['type' => 'noteColor', 'attrs' => ['name' => 'bleu']]]]]],
                    ]],
                ]],
            ],
        ];

        $this->json('POST', '/api/admin/release-notes', ['title' => 'Note riche', 'body' => 'autre chose', 'bodyRich' => $doc, 'noteDate' => '2026-11-10'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->responseBody();
        self::assertEquals($doc, $created['bodyRich'] ?? 'clé `bodyRich` absente', 'le 201 rend le corps riche reconstruit');
        self::assertSame("Titre\n- <img src=x onerror=alert(1)>", $created['body'], '`body` est DÉRIVÉ du corps riche, le `body` envoyé est ignoré');
        $id = $created['id'];
        self::assertIsString($id);
        $this->publishNote($csrf, $id);

        $this->client->request('GET', '/api/admin/release-notes');
        self::assertEquals($doc, $this->itemWithId($this->responseBody()['items'], $id)['bodyRich'] ?? null, 'la liste console relit le corps riche');

        $this->startFreshBrowserSession($this->client);
        $memberItem = $this->itemWithId($this->memberGet($token)['items'], $id);
        self::assertEquals($doc, $memberItem['bodyRich'] ?? null, 'le GET membre relit le corps riche');
        self::assertSame("Titre\n- <img src=x onerror=alert(1)>", $memberItem['body']);
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('plainBodyPayloads')]
    public function testWithoutRichBodyThePlainBodyIsRequiredAndRichBodyStaysNull(array $extra): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-plain-' . substr(md5(uniqid('', true)), 0, 6) . '@example.test');

        $this->json('POST', '/api/admin/release-notes', ['title' => 'Note texte', 'body' => "Ligne 1\nLigne 2", 'noteDate' => '2026-11-10', ...$extra], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->responseBody();
        self::assertArrayHasKey('bodyRich', $created, 'la clé `bodyRich` est toujours présente');
        self::assertNull($created['bodyRich']);
        self::assertSame("Ligne 1\nLigne 2", $created['body']);
    }

    public function testNeitherBodyNorRichBodyIsRefused(): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-empty@example.test');

        $this->json('POST', '/api/admin/release-notes', ['title' => 'Note vide', 'noteDate' => '2026-11-10', 'bodyRich' => null], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Le contenu est requis.', $this->responseBody()['error'] ?? null);
    }

    /**
     * Promesse (A8) : **un corps riche forgé hors liste blanche est refusé en 400 au message lisible,
     * RIEN n'est écrit, et la tentative est tracée dans l'audit SA0** (un `body` valide joint ne le
     * fait pas passer : `bodyRich` présent prime).
     */
    #[DataProvider('forgedRichBodies')]
    public function testForgedRichBodyIsRefusedNothingWrittenAndAudited(mixed $bodyRich): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-forged-' . substr(md5(uniqid('', true)), 0, 6) . '@example.test');
        $title = 'Note forgée ' . substr(md5(uniqid('', true)), 0, 8);

        $this->json('POST', '/api/admin/release-notes', ['title' => $title, 'body' => 'corps valide', 'bodyRich' => $bodyRich, 'noteDate' => '2026-11-10'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Le contenu mis en forme est invalide.', $this->responseBody()['error'] ?? null);

        $this->client->request('GET', '/api/admin/release-notes');
        self::assertNotContains($title, array_column($this->responseBody()['items'], 'title'), 'un 400 n\'écrit rien');

        // AdminAuditSubscriber écrit toujours `action = 'admin.http_access'` et range l'action du
        // contrôleur (`_admin_audit_context`) dans `details` (JSONB).
        $audited = $this->admin()->fetchOne(
            'SELECT COUNT(*) FROM admin_audit_log WHERE super_admin_id = :id AND action = \'admin.http_access\' AND details->>\'action\' = :action AND status_code = 400',
            ['id' => $this->adminId, 'action' => 'release-note.create'],
        );
        self::assertSame(1, (int) $audited, 'la tentative refusée est tracée dans l\'audit SA0');
    }

    public function testFourListLevelsAreRefusedWithTheDepthMessage(): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-depth@example.test');
        $item = static fn (string $text, array ...$sub): array => ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]], ...$sub]];
        $list = static fn (array $item): array => ['type' => 'bulletList', 'content' => [$item]];
        $doc = ['type' => 'doc', 'content' => [$list($item('1', $list($item('2', $list($item('3', $list($item('4'))))))))]];

        $this->json('POST', '/api/admin/release-notes', ['title' => 'Quatre niveaux', 'bodyRich' => $doc, 'noteDate' => '2026-11-10'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Les listes ne peuvent pas dépasser 3 niveaux.', $this->responseBody()['error'] ?? null);
    }

    /**
     * Promesse (m2) : **un corps HTTP de plus de 64 Kio est refusé AVANT tout décodage JSON** — le
     * corps envoyé ici n'est PAS du JSON valide : s'il était décodé d'abord, le message serait
     * « Corps de requête invalide. ».
     */
    public function testRawBodyOver64KiBIsRefusedBeforeJsonDecoding(): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-raw@example.test');
        $raw = '{"title":"x","body":"' . str_repeat('a', 65537) . '"';
        self::assertGreaterThan(65536, \strlen($raw));

        $this->client->request('POST', '/api/admin/release-notes', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->requestIp, 'HTTP_X_CSRF_TOKEN' => $csrf], $raw);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Corps de requête trop volumineux.', $this->responseBody()['error'] ?? null);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('forbiddenCharPayloads')]
    public function testControlAndBidiCharactersAreRefusedWithTheirMessage(array $payload, string $message): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-chars-' . substr(md5(uniqid('', true)), 0, 6) . '@example.test');

        $this->json('POST', '/api/admin/release-notes', [...$payload, 'noteDate' => '2026-11-10'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($message, $this->responseBody()['error'] ?? null);
    }

    /** Promesse (m3) : **le corps BRUT a la même borne de 10 000 caractères que le corps riche.** */
    public function testPlainBodyIsBoundedAt10000Characters(): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-long@example.test');

        $this->json('POST', '/api/admin/release-notes', ['title' => 'Note longue', 'body' => str_repeat('é', 10001), 'noteDate' => '2026-11-10'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Le contenu dépasse 10 000 caractères.', $this->responseBody()['error'] ?? null);

        $this->json('POST', '/api/admin/release-notes', ['title' => 'Note pile', 'body' => str_repeat('é', 10000), 'noteDate' => '2026-11-10'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testWritesRequireCsrf(): void
    {
        $this->authenticateSuperAdmin('release-notes-csrf@example.test');
        // Session admin valide mais sans jeton CSRF → 403.
        $this->json('POST', '/api/admin/release-notes', ['title' => 'x', 'body' => 'y', 'noteDate' => '2026-08-12']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testClubJwtCannotReachTheAdminWorkshop(): void
    {
        $token = $this->registerVerified('RN2');
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/admin/release-notes', [], [], $this->bearer($token));
        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        if (null !== $this->adminId) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function memberGet(string $token): array
    {
        $this->client->request('GET', '/api/release-notes', [], [], $this->bearer($token));
        self::assertResponseIsSuccessful();

        return $this->responseBody();
    }

    /** @return array<array-key, mixed> */
    private function itemWithId(mixed $items, string $id): array
    {
        self::assertIsArray($items);
        foreach ($items as $item) {
            if (\is_array($item) && ($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        self::fail(\sprintf('La note %s est absente de la liste.', $id));
    }

    /** @return array{HTTP_AUTHORIZATION: string, REMOTE_ADDR: string} */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => $this->requestIp];
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
            'firstName' => 'Membre',
            'lastName' => 'Nouveautés',
            'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $ara,
            'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        return $token;
    }

    private function createNote(string $csrf, string $title, string $body, string $noteDate): string
    {
        $this->json('POST', '/api/admin/release-notes', ['title' => $title, 'body' => $body, 'noteDate' => $noteDate], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);

        return $this->responseBody()['id'];
    }

    private function publishNote(string $csrf, string $id): void
    {
        $this->client->request('POST', '/api/admin/release-notes/' . $id . '/publish', [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json']);
        self::assertResponseIsSuccessful();
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
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     */
    private function json(string $method, string $uri, array $body, array $server = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
            ...$server,
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
