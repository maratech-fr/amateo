<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\BuildsTestImages;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Chantier F PR3 — la CAPTURE D'ÉCRAN jointe à un signalement, côté MEMBRE (porte d'entrée
 * du blob : `POST /api/feedback` en multipart).
 *
 * Prouve la membrane d'entrée : le POST passe en multipart (champ `payload` = JSON existant +
 * fichier `screenshot`) SANS casser le chemin JSON ; un envoi multipart compte dans la MÊME
 * borne 10/h (pas un contournement de l'anti-abus) ; et surtout les chemins d'ABUS sont fermés
 * AVANT tout décodage — SVG (XSS), bombe de dimensions, fichier > 2 Mo, corps non-image — chacun
 * refusé 422 SANS rien persister (le signalement est atomique : pas d'image valide → pas de ligne).
 *
 * Le STOCKAGE hors table et le rail de lecture superadmin sont prouvés côté console
 * ({@see AdminFeedbackTest::testMemberScreenshotIsServedOnlyToSuperAdmin} /
 * ::testScreenshotServeUnknownIdIs404ForSuperAdmin / ::testDetailExposesHasScreenshot) ;
 * l'étanchéité tenant du blob (aucune porte club) est bloquante
 * ({@see \App\Tests\Security\FeedbackContextIsolationTest::testAClubJwtNeverReachesTheScreenshotServeRoute}).
 */
#[Group('integration')]
final class FeedbackScreenshotTest extends WebTestCase
{
    use BuildsTestImages;
    use StartsFreshBrowserSession;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    /**
     * PROMESSE : un SVG est TOUJOURS refusé (script embarqué → XSS dans la console superadmin
     * qui le servirait). Jamais stocké, jamais servi : ici on prouve qu'il n'entre même pas —
     * 422 et AUCUNE ligne de signalement créée (l'envoi est atomique).
     *
     * Falsifiable : tant que le multipart n'est pas géré, le POST renvoie 400 (JSON invalide) ≠ 422.
     */
    public function testSvgScreenshotIsRejected(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBSVG');

        $this->postMultipart($token, ['topic' => 'bug', 'message' => 'Capture piégée.'], $this->uploadedImage($this->svgBytes(), 'capture.svg', 'image/svg+xml'));

        self::assertResponseStatusCodeSame(422, 'un SVG est refusé (anti-XSS)');
        self::assertSame(0, $this->feedbackCount($clubId), 'une capture invalide ne laisse AUCUNE ligne — l\'envoi est atomique');
    }

    /**
     * PROMESSE : une bombe de décompression — fichier minuscule déclarant des dimensions
     * énormes — est refusée AVANT tout décodage (dimensions lues via `getimagesizefromstring`,
     * jamais via une allocation d'image). 422, rien persisté.
     *
     * Falsifiable au niveau « jamais stocké » : retirer toute validation d'image laisserait
     * entrer la bombe (ligne créée) → ROUGE.
     */
    public function testOversizedDimensionsAreRejectedBeforeDecode(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBDIM');

        $this->postMultipart($token, ['topic' => 'bug', 'message' => 'Bombe de décompression.'], $this->uploadedImage($this->pngDeclaringDimensions(50000, 50000), 'capture.png', 'image/png'));

        self::assertResponseStatusCodeSame(422, 'des dimensions énormes sont refusées avant tout décodage');
        self::assertSame(0, $this->feedbackCount($clubId));
    }

    /**
     * PROMESSE : au-delà de 2 Mo, la capture est refusée (borne de taille, en premier — patron
     * ClubLogoController). On envoie un PNG VALIDE suivi de bourrage : finfo le reconnaît image,
     * ses dimensions sont saines, seule la TAILLE le fait tomber — retirer la borne de taille
     * le laisserait entrer (ROUGE).
     */
    public function testScreenshotOverTwoMegabytesIsRejected(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBBIG');
        $oversized = $this->validPngBytes() . str_repeat('A', 2_200_000);

        $this->postMultipart($token, ['topic' => 'bug', 'message' => 'Trop lourde.'], $this->uploadedImage($oversized, 'capture.png', 'image/png'));

        self::assertResponseStatusCodeSame(422, 'une capture > 2 Mo est refusée');
        self::assertSame(0, $this->feedbackCount($clubId));
    }

    /**
     * PROMESSE : un corps NON-image présenté comme `image/png` (mensonge de content-type /
     * polyglotte) est refusé — le serveur ne fait pas confiance au type déclaré par le client, il
     * SONDE les octets. 422, rien persisté.
     */
    public function testContentTypeLieIsRejected(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBLIE');

        $this->postMultipart($token, ['topic' => 'bug', 'message' => 'Faux PNG.'], $this->uploadedImage('ceci n\'est pas une image', 'capture.png', 'image/png'));

        self::assertResponseStatusCodeSame(422, 'un corps non-image déclaré png est refusé (type client non fiable)');
        self::assertSame(0, $this->feedbackCount($clubId));
    }

    /**
     * PROMESSE : un envoi MULTIPART (avec capture) consomme la MÊME borne 10/h par utilisateur —
     * la capture n'ouvre pas une voie de contournement (un canal d'upload non borné serait un DoS).
     * La borne est prise AVANT tout travail ; multipart ou JSON, un seul compteur.
     *
     * Falsifiable : la première aboutit (201, exige le chemin multipart) ; la 11ᵉ est throttlée (429).
     */
    public function testMultipartSubmitCountsAgainstTheSameRateLimiter(): void
    {
        [$token, $userId] = $this->registerVerifiedMember('FBLIM');
        $this->resetFeedbackLimiterFor($userId);

        $statuses = [];
        for ($i = 0; $i < 11; ++$i) {
            // Multipart SANS fichier (champ `payload` seul) : exerce le même branchement multipart,
            // sans écrire de blob — on mesure le COMPTEUR, pas le stockage.
            $this->postMultipart($token, ['topic' => 'bug', 'message' => 'multipart ' . $i], null);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame(201, $statuses[0], 'un envoi multipart valide aboutit (201) — le chemin multipart existe');
        self::assertSame(429, $statuses[10], 'la 11ᵉ dépasse la MÊME borne 10/h — pas un contournement de l\'anti-abus');
    }

    /**
     * PROMESSE (étanchéité, angle intégration) : le blob de capture vit HORS table et HORS RLS ;
     * un JWT club — quel que soit l'id DEVINÉ — frappe le firewall admin → 401. Aucun lecteur club.
     * L'angle BLOQUANT (gate tenant) est {@see \App\Tests\Security\FeedbackContextIsolationTest::testAClubJwtNeverReachesTheScreenshotServeRoute}
     * (doublon VOULU, point sensible sécurité/tenant).
     */
    public function testScreenshotIsUnreachableWithAClubJwt(): void
    {
        [$token] = $this->registerVerifiedMember('FBUNR');
        $guessedId = Uuid::v4()->toRfc4122();

        $this->client->request('GET', '/api/admin/feedback/' . $guessedId . '/screenshot', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401, 'un JWT club ne franchit jamais le rail superadmin');

        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/admin/feedback/' . $guessedId . '/screenshot');
        self::assertResponseStatusCodeSame(401, 'et l\'anonyme non plus');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempImages();
        parent::tearDown();
    }

    /**
     * Enregistre + vérifie un compte puis le rétrograde en MEMBRE simple (patron FeedbackTest) :
     * l'endpoint n'a pas de garde management, un non-gestionnaire doit pouvoir écrire.
     *
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function registerVerifiedMember(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'Mem', 'lastName' => 'Bre', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(\is_array($me));
        $userId = $me['id'];
        self::assertIsString($userId);

        $clubId = $this->em()->getConnection()->fetchOne(
            'SELECT club_id FROM club_user WHERE user_id = :uid LIMIT 1',
            ['uid' => $userId],
        );
        self::assertIsString($clubId);

        $this->scopeGucToClub($clubId);
        $this->em()->getConnection()->executeStatement(
            'UPDATE club_user SET role = :role WHERE club_id = :cid AND user_id = :uid',
            ['role' => 'member', 'cid' => $clubId, 'uid' => $userId],
        );
        $this->clearGuc();

        return [$token, $userId, $clubId];
    }

    /**
     * POST /api/feedback en MULTIPART : le corps JSON existant passe dans le champ de formulaire
     * `payload`, la capture dans le fichier `screenshot` (absent = multipart sans image).
     *
     * @param array<string, mixed> $payload
     */
    private function postMultipart(string $token, array $payload, ?UploadedFile $screenshot): void
    {
        $files = $screenshot instanceof UploadedFile ? ['screenshot' => $screenshot] : [];
        $this->client->request(
            'POST',
            '/api/feedback',
            ['payload' => json_encode($payload, \JSON_THROW_ON_ERROR)],
            $files,
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
    }

    private function feedbackCount(string $clubId): int
    {
        $this->scopeGucToClub($clubId);
        $count = (int) $this->em()->getConnection()->fetchOne(
            'SELECT count(*) FROM feedback WHERE club_id = :cid',
            ['cid' => $clubId],
        );
        $this->clearGuc();

        return $count;
    }

    private function resetFeedbackLimiterFor(string $userId): void
    {
        $factory = self::getContainer()->get('limiter.feedback');
        \assert($factory instanceof RateLimiterFactory);
        $factory->create($userId)->reset();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
