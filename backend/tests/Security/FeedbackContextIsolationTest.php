<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Tests\Integration\Api\AdminFeedbackTest;
use App\Tests\Integration\Api\FeedbackGenerationFailureTest;
use App\Tests\Integration\Api\FeedbackScreenshotTest;
use App\Tests\Integration\Api\FeedbackTest;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * NR d'axe §7.1 *tenant isolation* (Chantier F PR1, « contexte automatique large »).
 *
 * PROMESSE BLOQUANTE : le contexte automatique d'un signalement est calculé CÔTÉ
 * SERVEUR depuis le club AUTHENTIFIÉ et ne divulgue JAMAIS les données d'un autre
 * club — même quand le client injecte dans `context` les identifiants d'un club B
 * (`clubId`, `planId`, `seasonId`). Aucun NOUVEL id n'est accepté du client : ces
 * clés sont hors whitelist, donc ignorées, et l'enrichisseur ne lit QUE le club A.
 *
 * Témoin de falsification : `context.auto.season.id` vaut la saison de A, jamais
 * celle de B — si l'enrichisseur faisait confiance au `seasonId`/`clubId` client,
 * la saison de B apparaîtrait (ROUGE). Et le JSON stocké ENTIER ne contient aucun
 * identifiant de B (preuve d'effet, pas de lecture du site d'appel — cf. la défense
 * en profondeur TenantFilter/RLS, `.claude/rules/backend.md`).
 *
 * Angle VOULU en plus des gardes tenant existantes (point sensible) : l'injection
 * d'un `scheduleId` ÉTRANGER (404, rien créé) est déjà prouvée par
 * {@see FeedbackTest::testForeignClubScheduleIs404AndCreatesNoFeedback} ;
 * CE test couvre les NOUVEAUX vecteurs d'enrichissement (clubId/planId/seasonId) qui,
 * eux, ne passent pas par la porte 404 et pourraient fuiter en silence.
 */
#[Group('phase1')]
final class FeedbackContextIsolationTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testInjectedForeignIdsNeverLeakAnotherClubIntoTheContext(): void
    {
        // Club B : la cible convoitée. On ne touche JAMAIS ses données ; on retient
        // seulement ses identifiants pour les injecter côté A.
        [, , $clubB] = $this->registerVerifiedMember('FBISOB');
        $seasonB = $this->currentSeasonId($clubB);
        $planB = Uuid::v4()->toRfc4122();

        // Club A : l'auteur. Navigateur neuf (le cookie JWT change d'identité).
        $this->startFreshBrowserSession($this->client);
        [$tokenA, , $clubA] = $this->registerVerifiedMember('FBISOA');
        $seasonA = $this->currentSeasonId($clubA);
        self::assertNotSame($seasonA, $seasonB, 'les deux clubs ont des saisons distinctes (pré-condition)');

        // A signale en INJECTANT les ids de B dans le contexte (sans scheduleId : on veut
        // un 201 et inspecter le contexte enrichi, pas la porte 404 du planning étranger).
        $this->post($tokenA, [
            'topic' => 'bug',
            'message' => 'Je glisse les identifiants d\'un autre club.',
            'context' => [
                'clubId' => $clubB,
                'planId' => $planB,
                'seasonId' => $seasonB,
                'screenLabel' => 'Planning',
            ],
        ]);
        self::assertResponseStatusCodeSame(201, 'des ids injectés hors whitelist sont ignorés, pas fatals');

        $id = $this->responseBody()['id'];
        $raw = $this->rawContextOf($clubA, $id);
        $context = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($context);

        // 1) Le signalement appartient bien à A.
        self::assertSame($clubA, $this->feedbackClubId($clubA, $id), 'la ligne est rattachée au club de l\'auteur');

        // 2) Les ids injectés sont ignorés (hors LIGHT_CONTEXT_FIELDS), le champ whitelisté passe.
        self::assertArrayNotHasKey('clubId', $context, 'un clubId client est ignoré');
        self::assertArrayNotHasKey('planId', $context, 'un planId client est ignoré');
        self::assertArrayNotHasKey('seasonId', $context, 'un seasonId client est ignoré (aucun NOUVEL id accepté)');
        self::assertSame('Planning', $context['screenLabel'] ?? null, 'le champ whitelisté, lui, passe');

        // 3) Le contexte AUTO est celui de A — sa saison, jamais celle de B.
        $auto = $context['auto'] ?? null;
        self::assertIsArray($auto, 'le contexte automatique serveur est présent');
        self::assertSame($seasonA, $auto['season']['id'] ?? null, 'la saison enrichie est celle de A (résolue serveur)');

        // 4) Preuve d'effet : AUCUN identifiant de B ne figure nulle part dans le JSON stocké.
        self::assertStringNotContainsString($clubB, $raw, 'le club B ne fuit jamais dans le contexte');
        self::assertStringNotContainsString($seasonB, $raw, 'la saison de B ne fuit jamais');
        self::assertStringNotContainsString($planB, $raw, 'le plan de B ne fuit jamais');
    }

    /**
     * NR d'axe *tenant isolation* — EXTENSION Chantier F PR3 (blob de capture HORS RLS).
     *
     * PROMESSE BLOQUANTE : l'image d'un signalement vit HORS de la table `feedback` et HORS du
     * filtre RLS (stockage par id, patron ClubLogoStorage). Sa SEULE porte de lecture est le rail
     * superadmin (`GET /api/admin/feedback/{id}/screenshot`). AUCUN tenant ne la lit : un JWT club
     * — quel que soit l'id DEVINÉ — frappe le firewall admin → 401, jamais un oracle d'existence.
     * C'est la parade au contournement : même si deux clubs partageaient un gymnase et des
     * adversaires, la capture de l'un ne fuit jamais vers l'autre, faute de porte club.
     *
     * Témoin de falsification : exposer une route CLUB du blob (ou déplacer la route hors du
     * firewall admin) rendrait ce test ROUGE (200/404 au lieu de 401). L'angle superadmin « id
     * inconnu → 404, jamais de fuite » est prouvé à côté par
     * {@see AdminFeedbackTest::testScreenshotServeUnknownIdIs404ForSuperAdmin} ;
     * l'angle intégration (JWT de l'auteur) par
     * {@see FeedbackScreenshotTest::testScreenshotIsUnreachableWithAClubJwt}
     * (doublon VOULU, point sensible sécurité/tenant).
     */
    public function testAClubJwtNeverReachesTheScreenshotServeRoute(): void
    {
        [$tokenB] = $this->registerVerifiedMember('FBSHOTISO');
        $guessedId = Uuid::v4()->toRfc4122();

        $this->client->request('GET', '/api/admin/feedback/' . $guessedId . '/screenshot', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB]);
        self::assertResponseStatusCodeSame(401, 'un JWT club ne lit jamais une capture, quel que soit l\'id deviné');

        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/admin/feedback/' . $guessedId . '/screenshot');
        self::assertResponseStatusCodeSame(401, 'et l\'anonyme non plus');
    }

    /**
     * NR d'axe *tenant isolation* — EXTENSION P4-355 (la RAISON d'un échec de génération jointe au
     * contexte automatique).
     *
     * PROMESSE BLOQUANTE : le résumé `auto.generationFailure` est résolu depuis la DERNIÈRE
     * génération de la saison du club AUTHENTIFIÉ. Un échec FRAIS (< 12 h) d'un AUTRE club ne doit
     * JAMAIS apparaître dans le signalement de A — ni la clé, ni (preuve d'effet) le texte de son
     * erreur n'importe où dans le JSON stocké. C'est l'angle tenant du résumé d'échec, DISTINCT de
     * la couverture fonctionnelle (12 h / COMPLETED / nature) prouvée à côté dans
     * {@see FeedbackGenerationFailureTest} (doublon d'angle assumé, point
     * sensible sécurité/tenant).
     *
     * Témoin de falsification : si l'enrichisseur cherchait un FAILED frais SANS le scoper au club
     * authentifié (ou faisait confiance à un id client), l'erreur de B remonterait → ROUGE.
     */
    public function testAForeignClubsFreshGenerationFailureNeverLeaksIntoTheContext(): void
    {
        // Club B : un échec FRAIS, dont on retient l'erreur DISTINCTIVE.
        [, , $clubB] = $this->registerVerifiedMember('FBGFISOB');
        $seasonB = $this->currentSeasonId($clubB);
        $secret = 'erreur-secrete-du-club-b-' . bin2hex(random_bytes(6));
        $scheduleIdB = $this->seedFreshFailure($clubB, $seasonB, $secret);

        // Club A : l'auteur, aucune génération. Navigateur neuf (l'identité change).
        $this->startFreshBrowserSession($this->client);
        [$tokenA, , $clubA] = $this->registerVerifiedMember('FBGFISOA');

        $this->post($tokenA, ['topic' => 'bug', 'message' => 'Ma propre génération.']);
        self::assertResponseStatusCodeSame(201);

        $raw = $this->rawContextOf($clubA, $this->responseBody()['id']);
        $context = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($context);

        self::assertArrayNotHasKey(
            'generationFailure',
            $context['auto'] ?? [],
            'le club A n\'a aucune génération — aucun résumé d\'échec joint',
        );
        self::assertStringNotContainsString($secret, $raw, 'un échec frais du club B ne fuit jamais dans le signalement de A');
        // EXTENSION D17 (PR B) — le serveur résout désormais, pour le club AUTHENTIFIÉ, l'id du dernier
        // planning FAILED frais + sa COPIE LOURDE (snapshot engineInput + diagnostics) + le résumé de
        // grille. L'id du planning de B est le pivot de toute cette copie : s'il n'apparaît NULLE PART
        // dans le JSON de A, ni le snapshot, ni les diagnostics, ni le résumé de B n'ont fui. (Aujourd'hui
        // D17 n'est pas implémenté → aucun scheduleId n'est encore joint : cette assertion est un GARDE
        // VERT qui le RESTE si NEO scope D17 au club, et ROUGIT s'il joint un id d'échec non scopé.)
        // ⚠ FALSIFICATION & COUCHE RÉELLEMENT PROUVÉE : TenantFilter (Doctrine) + RLS PostgreSQL doublent
        // ce garde — une requête d'enrichisseur NON scopée au club ne lirait de toute façon PAS le
        // Schedule de B (RLS bloque le cross-club). Ce test prouve donc l'EFFET COMBINÉ (enrichisseur +
        // TenantFilter + RLS) : « le signalement de A ne contient jamais la donnée d'échec de B ». Pour
        // isoler la couche ENRICHISSEUR seule (scope explicite dans la requête, refus d'un scheduleId
        // soufflé par le client), il faudrait couper RLS — hors de ce test ; la couverture fonctionnelle
        // 12 h / COMPLETED / nature vit à côté dans FeedbackGenerationFailureTest.
        self::assertStringNotContainsString($scheduleIdB, $raw, 'l\'id du planning en échec de B (pivot de la copie lourde D17) ne fuit jamais dans le signalement de A');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** Sème pour un club un échec de génération FRAIS (< 12 h), dont le diagnostic porte $errorText. */
    private function seedFreshFailure(string $clubId, string $seasonId, string $errorText): string
    {
        $this->scopeGucToClub($clubId);
        $em = $this->em();

        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($seasonId);
        $schedule->setSchedulePlanId(Uuid::v4()->toRfc4122());
        $schedule->setName('Planning B');
        $schedule->setVersionNumber(1);
        $schedule->setStatus(ScheduleStatus::FAILED);
        $schedule->setSolveStartedAt(new DateTimeImmutable('now')->modify('-1 hour'));
        $schedule->setCreatedAt(new DateTimeImmutable('now')->modify('-1 hour'));
        $em->persist($schedule);

        $diagnostic = new ScheduleDiagnostic;
        $diagnostic->setClubId($clubId);
        $diagnostic->setSeasonId($seasonId);
        $diagnostic->setScheduleId($schedule->getId());
        $diagnostic->setType('engine_error');
        $diagnostic->setSeverity(ScheduleDiagnosticSeverity::ERROR);
        $diagnostic->setMessage($errorText);
        $diagnostic->setSuggestions([]);
        $em->persist($diagnostic);

        $em->flush();
        $scheduleId = $schedule->getId();
        $em->clear();
        $this->clearGuc();

        return $scheduleId;
    }

    /**
     * Registre + vérifie un compte, puis le rétrograde en MEMBRE simple — l'endpoint
     * n'a pas de garde management, ce rôle doit écrire (patron de FeedbackTest).
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
            'firstName' => 'Mem', 'lastName' => 'Bre', 'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $ara, 'consent' => true,
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

    private function currentSeasonId(string $clubId): string
    {
        $this->scopeGucToClub($clubId);
        $seasonId = $this->em()->getConnection()->fetchOne(
            'SELECT id FROM season WHERE club_id = :cid LIMIT 1',
            ['cid' => $clubId],
        );
        $this->clearGuc();
        self::assertIsString($seasonId, 'le register/verify matérialise une saison');

        return $seasonId;
    }

    /** @param array<string, mixed> $body */
    private function post(string $token, array $body): void
    {
        $this->client->request('POST', '/api/feedback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function rawContextOf(string $clubId, string $id): string
    {
        $this->scopeGucToClub($clubId);
        $raw = $this->em()->getConnection()->fetchOne('SELECT context FROM feedback WHERE id = :id', ['id' => $id]);
        $this->clearGuc();
        self::assertIsString($raw, 'le contexte doit être stocké');

        return $raw;
    }

    private function feedbackClubId(string $clubId, string $id): string
    {
        $this->scopeGucToClub($clubId);
        $value = $this->em()->getConnection()->fetchOne('SELECT club_id FROM feedback WHERE id = :id', ['id' => $id]);
        $this->clearGuc();
        self::assertIsString($value);

        return $value;
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
