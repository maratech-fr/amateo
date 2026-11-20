<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Access\AccessDeclarationResolver;
use App\Access\ClubFunction;
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
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;
use UnitEnum;

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
     * `/regenerate` et `/fill` (le comblement d'une période, P2-44) déclenchent un solve tout
     * autant — et ce sont ELLES que le work-loop emprunte, donc l'usage réel. Un cap posé sur la
     * seule première route se contourne sans même le vouloir. (« Charger une version »,
     * `/regenerate-from`, ne lance aucun calcul : hors du seau, décision fondateur Q13.)
     */
    public function testRegenerateRoutesDrawFromTheSameBudget(): void
    {
        [$token] = $this->registerClub('QD');

        for ($i = 0; $i < self::GENERATION_LIMIT; ++$i) {
            $this->generate($token);
        }

        self::assertSame(429, $this->call($token, 'POST', '/api/schedules/00000000-0000-4000-8000-000000000001/regenerate'), '/regenerate doit puiser dans le même seau que /generate');
        self::assertSame(429, $this->call($token, 'POST', '/api/schedules/00000000-0000-4000-8000-000000000001/fill'), '/fill (comblement de période) puise dans le même seau : c\'est un 4e déclencheur de solve');
    }

    /**
     * Q13 — « Charger une version » (`/regenerate-from`) restaure une structure sans lancer de calcul :
     * elle n'entame jamais le plafond de 5 générations par heure du club, même appelée plus de 5 fois.
     */
    public function testLoadingAVersionNeverDrawsFromTheGenerationBudget(): void
    {
        [$token, $clubId] = $this->registerClub('QL');

        for ($i = 0; $i < self::GENERATION_LIMIT + 1; ++$i) {
            self::assertNotSame(429, $this->call($token, 'POST', '/api/schedules/00000000-0000-4000-8000-000000000001/regenerate-from'), 'charger une version n\'est jamais refusé par le plafond de génération');
        }

        self::assertSame(self::GENERATION_LIMIT, $this->remainingTokens('limiter.schedule_generation', $clubId), 'le seau de génération du club est intact après des chargements');
    }

    /**
     * Toute fonction de club déclarée comme une sortie qui mobilise une ressource partagée — un solve
     * (génération, régénération, comblement), un placement de matchs, un rendu PDF (planning, matchs)
     * — puise dans un seau du club : le seau vide, elle répond 429. Lu sur les DÉCLARATIONS du routeur,
     * pas sur une liste à la main : une sortie déclarée demain ne peut pas échapper au plafond. Le
     * tableur (rendu en mémoire, sans worker) n'a pas de seau, à dessein.
     */
    public function testEveryDeclaredSolveOrRenderDrawsFromAClubBucket(): void
    {
        $buckets = [
            'SOLVE' => 'limiter.schedule_generation',
            'MATCH_PLACEMENT' => 'limiter.match_placement',
            'PLANNING_PDF' => 'limiter.schedule_pdf_export',
            'MATCHES_PDF' => 'limiter.fixtures_pdf_export',
        ];
        $declared = $this->routesDeclaringAnOutput();
        foreach (['generate_schedule', 'api_schedule_regenerate', 'api_schedule_fill', 'api_fixtures_place', 'export_pdf', 'api_fixtures_export_pdf'] as $witness) {
            self::assertArrayHasKey($witness, $declared, \sprintf('témoin : %s se déclare comme une sortie', $witness));
        }

        $escaping = [];
        foreach ($declared as $name => [$path, $output]) {
            if (!isset($buckets[$output])) {
                continue;
            }
            [$token, $clubId] = $this->registerClub('QS');
            $this->emptyBucket($buckets[$output], $clubId);
            $status = $this->call($token, 'POST', $path);
            if (429 !== $status) {
                $escaping[] = \sprintf('%s (%s, sortie %s) : seau %s vide, répond %d', $name, $path, $output, $buckets[$output], $status);
            }
        }

        self::assertSame([], $escaping, "Sorties déclarées qui échappent au plafond du club :\n  - " . implode("\n  - ", $escaping));
    }

    /**
     * E9 — un club Découverte À SEC qui a aussi atteint son plafond horaire reçoit d'abord le 429
     * « réessayez » (le plafond se juge avant le contrôle central), puis, la fenêtre passée, le 403
     * des crédits. Avant le lot 2, les deux écouteurs tiraient à la même priorité : l'ordre n'était
     * pas garanti.
     */
    public function testAnExhaustedClubAtItsHourlyCapIsToldToWaitThenToUpgrade(): void
    {
        [$token, $clubId] = $this->registerClub('QE');
        $this->exhaustCredits($clubId);
        $this->emptyBucket('limiter.schedule_generation', $clubId);

        self::assertSame(429, $this->generate($token), 'plafond atteint ET pool vide : le plafond répond le premier');

        $limiter = self::getContainer()->get('limiter.schedule_generation');
        \assert($limiter instanceof RateLimiterFactory);
        $limiter->create('club-' . $clubId)->reset();

        self::assertSame(403, $this->generate($token), 'la fenêtre passée, le pool vide répond');
        self::assertStringContainsString('gratuites', (string) $this->client->getResponse()->getContent(), 'le 403 est celui des crédits');
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
     * La borne de production du placement automatique des matchs : 20 demandes par heure et par
     * club (un run mobilise le solveur jusqu'à 45 min), seau à part de la génération ; 200 en dev,
     * où Behat et e2e rejouent le placement sur le club de démonstration. Le câblage (une demande
     * = une unité, refus = 429) : `PlacementQuotaTest`.
     */
    public function testTheMatchPlacementCapIsTheOneWeDecided(): void
    {
        $config = Yaml::parseFile(__DIR__ . '/../../config/packages/rate_limiter.yaml');
        self::assertIsArray($config);

        $placement = $config['framework']['rate_limiter']['match_placement'] ?? null;
        self::assertIsArray($placement, 'Le cap du placement des matchs a disparu de rate_limiter.yaml.');
        self::assertSame('sliding_window', $placement['policy'] ?? null);
        self::assertSame(20, $placement['limit'] ?? null, 'Le budget de placement d\'un club a changé sans que personne ne le décide ici.');
        self::assertSame('1 hour', $placement['interval'] ?? null);

        $dev = $config['when@dev']['framework']['rate_limiter']['match_placement'] ?? null;
        self::assertIsArray($dev, 'Behat et e2e jouent le placement en dev sur le club de démonstration : le cap de prod les ferait tomber en 429.');
        self::assertSame(200, $dev['limit'] ?? null);
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

    /**
     * Les routes POST `^/api/` dont la fonction de club déclare une sortie, avec un chemin jouable
     * (chaque paramètre remplacé par un UUID bidon : le plafond se compte avant le contrôleur).
     *
     * @return array<string, array{0: string, 1: string}> nom de route → [chemin, nom de la sortie]
     */
    private function routesDeclaringAnOutput(): array
    {
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $resolver = new AccessDeclarationResolver;
        $out = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/') || !\in_array('POST', $route->getMethods(), true)) {
                continue;
            }
            $path = (string) preg_replace('/\{[^}]+\}/', '00000000-0000-4000-8000-000000000001', str_replace('.{_format}', '', $route->getPath()));
            $request = Request::create($path, 'POST');
            $request->attributes->add($route->getDefaults());
            $request->attributes->set('_route', $name);
            $declaration = $resolver->for($request);
            if (!$declaration instanceof ClubFunction) {
                continue;
            }
            $output = get_object_vars($declaration)['output'] ?? null;
            if ($output instanceof UnitEnum) {
                $out[$name] = [$path, $output->name];
            }
        }

        return $out;
    }

    private function emptyBucket(string $limiterId, string $clubId): void
    {
        $limiter = self::getContainer()->get($limiterId);
        \assert($limiter instanceof RateLimiterFactory);
        $bucket = $limiter->create('club-' . $clubId);
        $bucket->consume($bucket->consume(0)->getRemainingTokens());
    }

    private function remainingTokens(string $limiterId, string $clubId): int
    {
        $limiter = self::getContainer()->get($limiterId);
        \assert($limiter instanceof RateLimiterFactory);

        return $limiter->create('club-' . $clubId)->consume(0)->getRemainingTokens();
    }

    /** Le pool Découverte du club inscrit (10 crédits) est entièrement utilisé. */
    private function exhaustCredits(string $clubId): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->getConnection()->executeStatement('UPDATE club SET output_credits_used = 10 WHERE id = :id', ['id' => $clubId]);
        $em->clear();
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
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        $manager = $container->get(JWTTokenManagerInterface::class);
        \assert($manager instanceof JWTTokenManagerInterface);

        return $manager->create($user);
    }
}
