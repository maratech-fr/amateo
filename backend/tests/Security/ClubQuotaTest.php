<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\User;
use App\EventListener\ClubQuotaSubscriber;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * P4-45 — les caps métier comptent PAR CLUB, pas par gestionnaire.
 *
 * ⚑ Le défaut que ces caps ferment : le solveur peut tenir **600 s de CPU** par appel, et
 * rien ne le bornait. Le throttle SEC-11 existant compte par UTILISATEUR — un club à trois
 * gestionnaires disposait donc de **trois fois** le budget. Or c'est le club qui consomme la
 * ressource, pas la personne : la clé devait suivre le consommateur réel.
 *
 * ⚠ Les deux propriétés vont par paire, et se contredisent si on n'en garde qu'une :
 * **partagé** entre les gestionnaires d'un même club (sinon le cap ne borne rien), et
 * **isolé** entre clubs (sinon un club actif ferme la porte à son voisin — un défaut bien
 * pire que celui qu'on corrige).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubQuotaTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    /** Le cap de génération déclaré pour la PRODUCTION. */
    private const int GENERATION_LIMIT = 5;

    private KernelBrowser $client;

    /**
     * Comme D-12 l'a fait pour SEC-11 : la valeur qui protège réellement la production doit
     * être assertée quelque part. Sans cela, la passer à 5000 laisserait la suite verte et
     * ferait disparaître la borne en silence.
     */
    public function testTheProductionCapsAreTheOnesWeDecided(): void
    {
        $config = Yaml::parseFile(__DIR__ . '/../../config/packages/rate_limiter.yaml');
        self::assertIsArray($config);

        $generation = $config['framework']['rate_limiter']['schedule_generation'] ?? null;
        self::assertIsArray($generation, 'Le cap de génération a disparu de rate_limiter.yaml.');
        self::assertSame(self::GENERATION_LIMIT, $generation['limit'] ?? null, 'Le budget de solve d\'un club a changé sans que personne ne le décide ici.');
        self::assertSame('1 hour', $generation['interval'] ?? null);

        $export = $config['framework']['rate_limiter']['schedule_pdf_export'] ?? null;
        self::assertIsArray($export, 'Le cap d\'export PDF a disparu de rate_limiter.yaml.');
        self::assertSame(10, $export['limit'] ?? null);
        self::assertSame('1 hour', $export['interval'] ?? null);
    }

    /**
     * LE point de P4-45 : deux gestionnaires du même club puisent dans le MÊME seau.
     *
     * Sans cette propriété, le cap ne borne rien — il suffit d'inviter un collègue pour
     * doubler le budget de solve.
     */
    public function testTheGenerationBudgetIsSharedBetweenTwoManagersOfTheSameClub(): void
    {
        [$token, $clubId] = $this->registerClub('QA');

        $statuses = [];
        for ($i = 0; $i < self::GENERATION_LIMIT; ++$i) {
            $statuses[] = $this->generate($token);
        }
        self::assertNotContains(429, $statuses, 'les premières générations doivent tenir dans le budget');

        // Le SECOND gestionnaire du MÊME club arrive après coup : le seau est déjà vide.
        $second = $this->addActiveMember($clubId);
        self::assertSame(429, $this->generate($second), 'un collègue du même club ne doit pas rouvrir le budget — sinon le cap ne borne rien');
    }

    /** Le pendant obligatoire : un club actif ne ferme jamais la porte de son voisin. */
    public function testAnotherClubKeepsItsOwnBudget(): void
    {
        [$exhausted] = $this->registerClub('QB');
        for ($i = 0; $i < self::GENERATION_LIMIT + 1; ++$i) {
            $this->generate($exhausted);
        }

        [$neighbour] = $this->registerClub('QC');
        self::assertNotSame(429, $this->generate($neighbour), 'le quota est PAR club : un voisin actif ne doit jamais bloquer un autre club');
    }

    /**
     * ⚠ Le trou que l'item de roadmap ne voyait pas : il ne parlait que de `POST /generate`.
     *
     * `/regenerate`, `/regenerate-from` et `/fill` (le comblement d'une période, P2-44)
     * déclenchent un solve tout autant — et ce sont ELLES que le work-loop emprunte, donc
     * l'usage réel. Un cap posé sur la seule première route se contourne sans même le vouloir.
     */
    public function testRegenerateRoutesDrawFromTheSameBudget(): void
    {
        [$token] = $this->registerClub('QD');

        for ($i = 0; $i < self::GENERATION_LIMIT; ++$i) {
            $this->generate($token);
        }

        self::assertSame(429, $this->call($token, 'POST', '/api/schedules/00000000-0000-4000-8000-000000000001/regenerate'), '/regenerate doit puiser dans le même seau que /generate');
        self::assertSame(429, $this->call($token, 'POST', '/api/schedules/00000000-0000-4000-8000-000000000001/regenerate-from'), '/regenerate-from aussi');
        self::assertSame(429, $this->call($token, 'POST', '/api/schedules/00000000-0000-4000-8000-000000000001/fill'), '/fill (comblement de période) puise dans le même seau : c\'est un 4e déclencheur de solve');
    }

    /** La borne de production de l'export des matchs : assez pour 25 équipes et des reprises, décidée ici. */
    public function testTheMatchesExportCapIsTheOneWeDecided(): void
    {
        $config = Yaml::parseFile(__DIR__ . '/../../config/packages/rate_limiter.yaml');
        self::assertIsArray($config);

        $export = $config['framework']['rate_limiter']['fixtures_pdf_export'] ?? null;
        self::assertIsArray($export, 'Le cap d\'export des matchs a disparu de rate_limiter.yaml.');
        self::assertSame('sliding_window', $export['policy'] ?? null);
        self::assertSame(60, $export['limit'] ?? null);
        self::assertSame('1 hour', $export['interval'] ?? null);
    }

    /**
     * Le 61ᵉ export des matchs de l'heure est refusé, et le seau de l'export du planning n'a pas
     * bougé : les deux ressources se comptent à part. Le subscriber est piloté directement : en HTTP,
     * le limiteur `api` (30/min en test) répondrait 429 bien avant, et le test passerait à tort.
     */
    public function testTheMatchesExportHasItsOwnBucket(): void
    {
        $clubId = 'quota-matchs-' . uniqid('', true);
        $subscriber = self::getContainer()->get(ClubQuotaSubscriber::class);

        for ($i = 0; $i < 60; ++$i) {
            $subscriber->onKernelRequest($this->exportRequestEvent($clubId));
        }

        try {
            $subscriber->onKernelRequest($this->exportRequestEvent($clubId));
            self::fail('le 61ᵉ export de l\'heure doit être refusé');
        } catch (TooManyRequestsHttpException $e) {
            self::assertStringContainsString('Quota du club', $e->getMessage());
        }

        $planning = self::getContainer()->get('limiter.schedule_pdf_export')->create('club-' . $clubId)->consume(0);
        self::assertSame(10, $planning->getRemainingTokens(), 'l\'export du planning garde tout son budget');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function exportRequestEvent(string $clubId): RequestEvent
    {
        $request = new Request;
        $request->attributes->set('_route', 'api_fixtures_export_pdf');
        $request->attributes->set('_club_id', $clubId);

        return new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function generate(string $token): int
    {
        // L'identifiant n'a pas besoin d'exister : le quota se consomme AVANT le
        // contrôleur (priorité 5, après la résolution du tenant). C'est justement ce qu'on
        // veut d'un cap — il borne l'appel, pas le succès de l'appel.
        return $this->call($token, 'POST', '/api/schedules/00000000-0000-4000-8000-000000000001/generate');
    }

    private function call(string $token, string $method, string $uri): int
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array{0: string, 1: string} token + clubId */
    private function registerClub(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'Q', 'lastName' => 'Quota', 'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        /** @var array{club: array{id: string}} $me */
        $me = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return [$token, $me['club']['id']];
    }

    /** Un second gestionnaire ACTIF du même club, avec son propre JWT. */
    private function addActiveMember(string $clubId): string
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail('quota' . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Collegue');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole('president');
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        $manager = $container->get(JWTTokenManagerInterface::class);
        \assert($manager instanceof JWTTokenManagerInterface);

        return $manager->create($user);
    }
}
