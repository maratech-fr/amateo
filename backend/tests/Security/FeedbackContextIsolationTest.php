<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Integration\Api\FeedbackTest;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
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

    protected function setUp(): void
    {
        $this->client = self::createClient();
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
