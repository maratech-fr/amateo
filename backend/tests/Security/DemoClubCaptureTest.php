<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Service\TenantConnectionContext;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR d'axe « auth & memberships » / « tenant isolation » (§7.1) — BCK-33 : une VRAIE
 * inscription sur le code FFBB d'un club de DÉMONSTRATION n'entre JAMAIS dans la démo.
 *
 * L'unicité du code FFBB est partielle (`WHERE NOT is_demo`) et tous les chemins
 * d'inscription/approbation résolvent le club par `ClubRepository::findRealByFfbbCode`
 * (is_demo = false). Un `findOneBy(['ffbbClubCode' => …])` nu happerait la démo et ferait
 * du vrai inscrit un membre pending de l'espace vendeur — ce garde le falsifie : sur
 * l'ancien code, l'inscrit rejoignait la démo (même id) ; désormais il obtient son PROPRE
 * club réel, la démo restant intacte.
 */
#[Group('phase1')]
#[Group('integration')]
final class DemoClubCaptureTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testRealRegistrationDoesNotJoinADemoClubOnTheSameFfbbCode(): void
    {
        $code = 'ARA0000055';
        $demoClubId = $this->seedDemoClubWithActiveMember($code);

        [$token, $realClubId] = $this->register($code);

        self::assertNotSame($demoClubId, $realClubId, 'la vraie inscription ne rejoint PAS le club de démonstration');

        $me = $this->get('/api/me', $token);
        self::assertSame('active', $me['membershipStatus'], 'le vrai inscrit est gestionnaire de SON club réel');
        self::assertFalse($me['club']['isDemo'] ?? true, 'le club du vrai inscrit n\'est pas une démo');
        self::assertSame($code, $me['club']['ffbbClubCode'] ?? null, 'son club réel porte bien le code FFBB saisi');

        // La démo survit, intacte.
        $em = $this->em();
        $demo = $em->getRepository(Club::class)->find($demoClubId);
        self::assertInstanceOf(Club::class, $demo);
        self::assertTrue($demo->isDemo(), 'la démo reste une démo, non capturée');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function seedDemoClubWithActiveMember(string $code): string
    {
        $em = $this->em();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $club = new Club;
        $club->setName('Démo capture');
        $club->setSlug('demo-capture-' . substr(md5(uniqid('', true)), 0, 8));
        $club->setFfbbClubCode($code);
        $club->setIsDemo(true);
        $em->persist($club);

        $animator = new User;
        $animator->setEmail('demo-animator-' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr');
        $animator->setFirstName('Démo');
        $animator->setLastName('Animateur');
        $animator->setPasswordHash($hasher->hashPassword($animator, 'Password123!'));
        $em->persist($animator);
        // Club (hors RLS) + User (global) flushés d'abord ; le club_user ensuite,
        // sous le GUC tenant du club (RLS WITH CHECK).
        $em->flush();

        $tenant = self::getContainer()->get(TenantConnectionContext::class);
        \assert($tenant instanceof TenantConnectionContext);
        $tenant->setClubId($club->getId());
        try {
            $membership = new ClubUser;
            $membership->setClubId($club->getId());
            $membership->setUserId($animator->getId());
            $membership->setRole('manager');
            $membership->setIsActive(true);
            $em->persist($membership);
            $em->flush();
        } finally {
            $tenant->clear();
        }

        $clubId = $club->getId();
        $em->clear();

        return $clubId;
    }

    /**
     * @return array{0: string, 1: string} [token, clubId]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'realowner' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'Real', 'lastName' => 'Owner', 'ara' => $ara, 'club_name' => 'Vrai club', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $me = $this->get('/api/me', $token);

        return [$token, $me['club']['id']];
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $uri, string $token, array $body = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], [] === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function get(string $uri, string $token): array
    {
        $this->request('GET', $uri, $token);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
