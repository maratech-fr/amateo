<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/deployment-notice` — le bandeau d'annonce que TOUT club connecté voit dès qu'une mise
 * en prod est programmée (PR b ; le rendu est la PR c frontend). Les promesses :
 *
 *  - un déploiement `scheduled` (ou `dispatched`) → `{scheduledAt, expectedReturnAt}` ;
 *  - rien de programmé, ou un déploiement annulé/terminé → `null` (pas de bandeau) ;
 *  - un appel anonyme est rejeté (401) ;
 *  - ZÉRO FUITE : la charge ne porte ni la version, ni l'acteur (`created_by`), ni le jeton —
 *    un gestionnaire de club n'a pas à connaître la cuisine de déploiement.
 *
 * La ligne est posée via la connexion admin (committée, hors DAMA) ; le club la lit via sa propre
 * connexion applicative (la table `scheduled_deployment` est GLOBALE, jamais scopée tenant).
 *
 * Contrat FIXÉ pour NEO : route `GET /api/deployment-notice` (JWT club), corps `null` ou
 * `{"scheduledAt": ISO-8601, "expectedReturnAt": ISO-8601}` — aucune autre clé.
 */
#[Group('phase1')]
#[Group('integration')]
final class DeploymentNoticeTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private KernelBrowser $client;

    /** @var list<string> */
    private array $deploymentIds = [];

    private string $createdBy = '';

    public function testAScheduledDeploymentIsExposedAsAMinimalNotice(): void
    {
        $scheduledAt = new DateTimeImmutable('now')->modify('+2 hours');
        $expectedReturn = $scheduledAt->modify('+45 minutes');
        $this->seedDeployment('scheduled', $scheduledAt, $expectedReturn, 'v1.0.0');

        $token = $this->register('NOTA');
        $this->get($token);

        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertIsArray($body, 'un déploiement programmé rend un objet');
        self::assertEqualsWithDelta($scheduledAt->getTimestamp(), new DateTimeImmutable($body['scheduledAt'])->getTimestamp(), 5);
        self::assertEqualsWithDelta($expectedReturn->getTimestamp(), new DateTimeImmutable($body['expectedReturnAt'])->getTimestamp(), 5);
        self::assertSame(['expectedReturnAt', 'scheduledAt'], $this->sortedKeys($body), 'aucune clé au-delà des deux dates');
    }

    public function testTheNoticeNeverLeaksTheVersionTheActorOrAnything(): void
    {
        $scheduledAt = new DateTimeImmutable('now')->modify('+3 hours');
        $this->seedDeployment('scheduled', $scheduledAt, $scheduledAt->modify('+30 minutes'), 'v9.9.9');

        $token = $this->register('NOTB');
        $this->get($token);
        self::assertResponseIsSuccessful();

        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('v9.9.9', $raw, 'la version ne fuite jamais vers un club');
        self::assertStringNotContainsString($this->createdBy, $raw, 'l\'acteur (created_by) ne fuite jamais');
        self::assertStringNotContainsString('github', strtolower($raw), 'aucune cuisine GitHub/jeton ne transparaît');
    }

    public function testNothingScheduledYieldsNull(): void
    {
        $token = $this->register('NOTC');
        $this->get($token);

        self::assertResponseIsSuccessful();
        self::assertNull($this->responseBody(), 'sans déploiement programmé, le bandeau est null');
    }

    public function testACancelledDeploymentYieldsNull(): void
    {
        $scheduledAt = new DateTimeImmutable('now')->modify('+2 hours');
        $this->seedDeployment('cancelled', $scheduledAt, $scheduledAt->modify('+30 minutes'), 'v1.0.0');

        $token = $this->register('NOTD');
        $this->get($token);

        self::assertResponseIsSuccessful();
        self::assertNull($this->responseBody(), 'un déploiement annulé n\'annonce rien');
    }

    public function testAnonymousIsRejected(): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/deployment-notice');
        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->createdBy = Uuid::v4()->toRfc4122();
    }

    protected function tearDown(): void
    {
        if ([] !== $this->deploymentIds) {
            $this->admin()->executeStatement('DELETE FROM scheduled_deployment WHERE id IN (:ids)', ['ids' => $this->deploymentIds], ['ids' => ArrayParameterType::STRING]);
        }
        parent::tearDown();
    }

    private function seedDeployment(string $status, DateTimeImmutable $scheduledAt, DateTimeImmutable $expectedReturn, string $version): void
    {
        $id = Uuid::v4()->toRfc4122();
        $this->deploymentIds[] = $id;
        $this->admin()->executeStatement(
            'INSERT INTO scheduled_deployment (id, version, scheduled_at, expected_return_at, status, created_by, created_at, updated_at)'
            . ' VALUES (:id, :version, :scheduled, :ret, :status, :by, NOW(), NOW())',
            [
                'id' => $id,
                'version' => $version,
                'scheduled' => $scheduledAt->format('Y-m-d H:i:sP'),
                'ret' => $expectedReturn->format('Y-m-d H:i:sP'),
                'status' => $status,
                'by' => $this->createdBy,
            ],
            ['by' => ParameterType::STRING],
        );
    }

    private function register(string $ara): string
    {
        $this->startFreshBrowserSession($this->client);
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'I', 'lastName' => 'Notice', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        return $token;
    }

    private function get(string $token): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/deployment-notice', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** @return array<string, mixed>|null */
    private function responseBody(): ?array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue(null === $body || \is_array($body));

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function sortedKeys(array $body): array
    {
        $keys = array_keys($body);
        sort($keys);

        return $keys;
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
