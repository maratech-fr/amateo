<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-22 — le limiteur d'import xlsx (`xlsx_import`, PAR UTILISATEUR) est consommé
 * dans FixtureImportGate::gate APRÈS la gate d'auth : un refus 403/404/409 ne
 * dépense jamais le budget, donc le 429 tombe TOUJOURS après. En `when@test` la
 * borne est 5/15min → le 429 se prouve en 6 requêtes.
 *
 * Keyé par utilisateur : cette suite n'empoisonne aucune autre (chaque test crée
 * sa propre identité, et aucune autre ne fait 6 imports sous un même user).
 */
#[Group('integration')]
final class XlsxImportRateLimitTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testTheSixthImportByOneManagerIsRateLimited(): void
    {
        [$token, $clubId] = $this->registerManager('RLA');
        $this->settleClubSocle($clubId);

        // Les 5 premières franchissent l'auth ET le limiteur, puis butent sur
        // « aucun fichier » (400) : le budget est bien consommé à chaque fois.
        for ($i = 1; $i <= 5; ++$i) {
            $this->postImport($token);
            self::assertResponseStatusCodeSame(400, \sprintf('la requête %d doit passer le limiteur', $i));
        }

        $this->postImport($token);
        self::assertResponseStatusCodeSame(429, 'la 6e dépasse la borne 5/15min');
    }

    public function testANonManagerAlwaysGetsFourOhThreeNeverTheRateLimit(): void
    {
        // Le foyer du limiteur est APRÈS le contrôle de rôle : un éditeur est refusé
        // en 403 sans jamais consommer le budget — même à la 6e requête, jamais 429.
        [, $clubId] = $this->registerManager('RLB');
        $this->settleClubSocle($clubId);
        $editorToken = $this->addActiveMember($clubId, 'editor');

        for ($i = 1; $i <= 6; ++$i) {
            $this->postImport($editorToken);
            self::assertResponseStatusCodeSame(403, \sprintf('un non-manager reste en 403 (requête %d), jamais 429', $i));
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function postImport(string $token): void
    {
        // Sans fichier : la gate (auth + limiteur) tranche avant le garde d'upload.
        $this->client->request('POST', '/api/fixtures/import', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    private function settleClubSocle(string $clubId): void
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertNotNull($season);
        $this->settleSeasonPlan($season);
    }

    /**
     * @return array{0: string, 1: string} [managerToken, clubId]
     */
    private function registerManager(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Rl', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $clubId = json_decode((string) $this->client->getResponse()->getContent(), true)['club']['id'];

        return [$token, $clubId];
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }
}
