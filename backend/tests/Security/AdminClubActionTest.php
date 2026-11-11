<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\AdminJob\AdminActionCatalog;
use App\AdminJob\AdminJobDefinition;
use App\AdminJob\AdminJobRunStore;
use App\AdminJob\AdminJobSchedule;
use App\Entity\Club;
use App\Entity\Season;
use App\Entity\SuperAdmin;
use App\Entity\Team;
use App\Enum\AdminJobSource;
use App\Enum\AdminJobStatus;
use App\Enum\SeasonStatus;
use App\Security\TotpService;
use App\Tests\Double\RecordingAdminJobExecutor;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * NR SA4 (sécurité, surface /api/admin — la plus sensible) : les ACTIONS SUPPORT sur
 * un club sont gatées par la session admin + CSRF, bornées au catalogue FERMÉ
 * (AdminActionCatalog) et à un club EXISTANT, et tracées (admin_job_run.arguments
 * porte le --club). Le paramètre clubId est le SEUL argument runtime — jamais de
 * commande ou d'argument libre depuis la requête.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminClubActionTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private string $adminId;

    private string $requestIp;

    /** @var list<string> */
    private array $clubIds = [];

    /** @var list<string> */
    private array $jobRunIds = [];

    public function testActionsAreUnreachableWithoutAnAdminSession(): void
    {
        // Sans aucune session : catalogue et exécution refusés par le firewall admin.
        $this->client->request('GET', '/api/admin/actions');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/admin/clubs/' . Uuid::v4()->toRfc4122() . '/actions/reset-credits');
        self::assertResponseStatusCodeSame(401);
    }

    public function testActionRunIsGatedByCsrfCatalogAndClubExistence(): void
    {
        $clubId = $this->seedClub('Club actions SA4');
        [$secret] = $this->createSuperAdmin('actions@example.test', 'VeryStrongPassword!');
        $csrfToken = $this->authenticate('actions@example.test', 'VeryStrongPassword!', $secret);
        $this->client->disableReboot();
        $executor = self::getContainer()->get(RecordingAdminJobExecutor::class);
        $executor->reset();

        // Le catalogue est servi à l'admin authentifié — fermé, avec le flag dangerous.
        $this->client->request('GET', '/api/admin/actions');
        self::assertResponseIsSuccessful();
        $items = $this->responseBody()['items'];
        $byKey = array_column($items, null, 'key');
        self::assertArrayHasKey('reset-credits', $byKey);
        self::assertFalse($byKey['reset-credits']['dangerous']);
        self::assertTrue($byKey['reset-current-season']['dangerous']);
        self::assertTrue($byKey['purge-old-seasons']['dangerous']);

        // Sans CSRF → 403, rien exécuté.
        $this->client->request('POST', "/api/admin/clubs/{$clubId}/actions/reset-credits");
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $executor->calls);

        // Action hors catalogue → 404, rien exécuté (l'allowlist est totale).
        $this->client->request('POST', "/api/admin/clubs/{$clubId}/actions/drop-database", [], [], ['HTTP_X_CSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $executor->calls);

        // Club inexistant → 404 AVANT toute exécution.
        $this->client->request('POST', '/api/admin/clubs/' . Uuid::v4()->toRfc4122() . '/actions/reset-credits', [], [], ['HTTP_X_CSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $executor->calls);

        // Chemin nominal : l'action part vers l'exécuteur avec le SEUL argument
        // autorisé (--club, validé) et la clé préfixée du catalogue.
        $this->client->request('POST', "/api/admin/clubs/{$clubId}/actions/reset-credits", [], [], ['HTTP_X_CSRF_TOKEN' => $csrfToken]);
        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'reset-credits', 'clubId' => $clubId, 'status' => 'succeeded', 'exitCode' => 0], $this->responseBody());
        self::assertSame([[
            'key' => 'action:reset-credits',
            'superAdminId' => $this->adminId,
            'arguments' => ['--club' => $clubId],
        ]], $executor->calls);

        // L'accès est audité (SA0 fail-closed) — au moins la requête nominale.
        self::assertGreaterThanOrEqual(1, (int) $this->admin()->fetchOne(
            'SELECT COUNT(*) FROM admin_audit_log WHERE super_admin_id = :id AND route = :route',
            ['id' => $this->adminId, 'route' => 'app_adminclubaction_run'],
        ));
    }

    public function testSetPlanValidatesTheClosedArgumentSchemaFailClosed(): void
    {
        // A3 — le rail SA4 gagne des arguments BORNÉS par schéma fermé, validés fail-closed
        // AVANT toute exécution. L'exécuteur ne doit JAMAIS voir une clé/valeur hors schéma.
        $clubId = $this->seedClub('Club offre SA4');
        [$secret] = $this->createSuperAdmin('offre@example.test', 'VeryStrongPassword!');
        $csrfToken = $this->authenticate('offre@example.test', 'VeryStrongPassword!', $secret);
        $this->client->disableReboot();
        $executor = self::getContainer()->get(RecordingAdminJobExecutor::class);
        $executor->reset();

        // Le catalogue tient 6 entrées : une SEULE « Offre » à schéma (l'entrée
        // reset-generation-quota, qui « réussissait » à ne rien débloquer, a été retirée).
        $this->client->request('GET', '/api/admin/actions');
        self::assertResponseIsSuccessful();
        $items = $this->responseBody()['items'];
        self::assertCount(6, $items);
        $byKey = array_column($items, null, 'key');
        self::assertArrayHasKey('set-plan', $byKey);
        self::assertArrayNotHasKey('set-plan-essentiel', $byKey, 'les entrées set-plan-* figées ont disparu');
        // Le schéma d'arguments est servi (les pickers du front en dérivent).
        $setPlan = $byKey['set-plan'];
        self::assertSame('plan', $setPlan['arguments'][0]['key']);
        self::assertTrue($setPlan['arguments'][0]['required']);
        // Grille 20 / 34 / 35+ : cinq offres attribuables, plus de palier « sans-limite ».
        self::assertSame(
            ['decouverte', 'essentiel', 'club', 'grand-club', 'beta'],
            array_column($setPlan['arguments'][0]['choices'], 'value'),
            'le picker d\'offre sert exactement les 5 offres existantes',
        );
        self::assertSame('paidSeason', $setPlan['arguments'][1]['key']);
        self::assertSame(['argument' => 'plan', 'forbiddenValues' => ['decouverte']], $setPlan['arguments'][1]['gate']);

        // Chemin nominal : l'action part avec EXACTEMENT les arguments bornés + --club.
        $this->postAction($clubId, 'set-plan', ['plan' => 'essentiel', 'paidSeason' => 'next'], $csrfToken);
        self::assertResponseIsSuccessful();
        self::assertSame([[
            'key' => 'action:set-plan',
            'superAdminId' => $this->adminId,
            'arguments' => ['--plan' => 'essentiel', '--paid-season' => 'next', '--club' => $clubId],
        ]], $executor->calls);

        // Valeur hors enum → 400, rien exécuté. Le body est PAR AILLEURS complet (saison
        // fournie) : le SEUL défaut est l'enum du plan — ainsi la falsification (désactiver
        // `!$argument->allows($value)` dans AdminActionArgumentSchema) fait rougir CETTE ligne,
        // sans être sauvée par une autre garde (le « required » de paidSeason).
        $executor->reset();
        $this->postAction($clubId, 'set-plan', ['plan' => 'platine', 'paidSeason' => 'next'], $csrfToken);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $executor->calls);

        // L'ancien palier « sans-limite » est désormais hors enum : même refus qu'une offre inventée.
        $this->postAction($clubId, 'set-plan', ['plan' => 'sans-limite', 'paidSeason' => 'next'], $csrfToken);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $executor->calls, 'un plan=sans-limite forgé n\'atteint jamais l\'exécuteur');

        // Clé inconnue → 400, rien exécuté.
        $this->postAction($clubId, 'set-plan', ['plan' => 'essentiel', 'extra' => '--help'], $csrfToken);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $executor->calls);

        // Offre payante (Bêta comprise) SANS saison encaissée → 400 : sans marqueur elle naît expirée.
        $this->postAction($clubId, 'set-plan', ['plan' => 'beta'], $csrfToken);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $executor->calls);

        // Découverte + saison encaissée → 400 : rien à encaisser sur l'offre gratuite.
        $this->postAction($clubId, 'set-plan', ['plan' => 'decouverte', 'paidSeason' => 'next'], $csrfToken);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $executor->calls);

        // Un body sur une action SANS schéma → 400 (l'allowlist reste totale).
        $this->postAction($clubId, 'ffbb-resync', ['plan' => 'essentiel'], $csrfToken);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $executor->calls);
    }

    public function testRunHistoryPersistsTheTargetClubInArguments(): void
    {
        // La trace « quelle action, sur QUEL club » : le store écrit les arguments.
        $store = self::getContainer()->get(AdminJobRunStore::class);
        $clubId = Uuid::v4()->toRfc4122();
        $definition = new AdminJobDefinition('action:reset-credits', 'Reset credits', 'app:clubs:reset-credits', AdminJobSchedule::manual(), ['--club' => $clubId], manualTriggerAllowed: true);

        self::assertTrue($store->tryAcquire($definition->key));
        try {
            $runId = $store->start($definition, AdminJobSource::SUPERADMIN, null);
            $this->jobRunIds[] = $runId;
            $store->finish($runId, AdminJobStatus::SUCCEEDED, 0);
        } finally {
            $store->release($definition->key);
        }

        $arguments = $this->admin()->fetchOne('SELECT arguments FROM admin_job_run WHERE id = :id', ['id' => $runId]);
        self::assertIsString($arguments);
        self::assertSame(['--club' => $clubId], json_decode($arguments, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testFfbbResyncCommandFailsFrankOnUnknownClubOrInvalidCode(): void
    {
        // P2-18 — le refresh support doit ÉCHOUER FRANC quand rien n'a été
        // rafraîchi (club fantôme, ou code FFBB absent/malformé → populate rend
        // false sans toucher le réseau) : un succès silencieux ferait croire au
        // support que la fiche est à jour.
        $clubId = $this->seedClub('Club resync SA4', generationCount: 0); // seedClub ne pose aucun ffbb_club_code
        $application = new Application(self::$kernel);

        self::assertSame(Command::FAILURE, new CommandTester($application->find('app:clubs:ffbb-resync'))->execute(['--club' => $clubId]), 'code FFBB absent → rien à rafraîchir → échec nommé');
        self::assertSame(Command::FAILURE, new CommandTester($application->find('app:clubs:ffbb-resync'))->execute(['--club' => Uuid::v4()->toRfc4122()]), 'club inconnu → échec');
    }

    public function testResetSeasonCommandResolvesTheCurrentSeasonAndDryRunDeletesNothing(): void
    {
        [$clubId, $seasonId] = $this->seedRuntimeClubWithCurrentSeason('Club reset SA4');
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $application = new Application(self::$kernel);
        $dryRun = new CommandTester($application->find('app:clubs:reset-season'));
        $exitCode = $dryRun->execute(['--club' => $clubId, '--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $exitCode, $dryRun->getDisplay());
        self::assertStringContainsString($seasonId, $dryRun->getDisplay(), 'le dry-run annonce la saison courante résolue');
        // Rien supprimé : la ligne Season est intacte (lecture runtime, même porte).
        $em->clear();
        $this->scopeGucToClub($clubId);
        self::assertNotNull($em->getRepository(Season::class)->find($seasonId));

        $unknown = new CommandTester($application->find('app:clubs:reset-season'));
        self::assertSame(Command::FAILURE, $unknown->execute(['--club' => Uuid::v4()->toRfc4122()]));
    }

    public function testResetSeasonCommandActuallyWipesTheSeasonDataKeepingTheSeasonRow(): void
    {
        // Le chemin DESTRUCTIF réel (pas seulement le dry-run) : la structure part,
        // la ligne Season et le club survivent (revue SA4, finding 8).
        [$clubId, $seasonId] = $this->seedRuntimeClubWithCurrentSeason('Club wipe SA4');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(1)
            ->setName('SM1')->setSessionsPerWeek(1)->setIsActive(true);
        $em->persist($team);
        $em->flush();
        $teamId = $team->getId();

        $application = new Application(self::$kernel);
        $wipe = new CommandTester($application->find('app:clubs:reset-season'));
        self::assertSame(Command::SUCCESS, $wipe->execute(['--club' => $clubId]), $wipe->getDisplay());

        $em->clear();
        $this->scopeGucToClub($clubId);
        self::assertNull($em->getRepository(Team::class)->find($teamId), 'la structure de la saison est vidée');
        self::assertNotNull($em->getRepository(Season::class)->find($seasonId), 'la ligne Season survit — le club repart au wizard');
    }

    public function testTheCatalogCommandsExistAndAcceptTheClubOption(): void
    {
        // Contrat catalogue ↔ commandes : chaque entrée nomme une commande RÉELLE qui
        // accepte --club (une typo de catalogue doit rougir ici, pas en prod — finding 9).
        $application = new Application(self::$kernel);
        $catalog = self::getContainer()->get(AdminActionCatalog::class);
        foreach ($catalog->all() as $action) {
            $command = $application->find($action->command);
            self::assertTrue($command->getDefinition()->hasOption('club'), \sprintf('%s doit accepter --club', $action->command));
            foreach (array_keys($action->arguments) as $argument) {
                self::assertTrue($command->getDefinition()->hasOption(ltrim((string) $argument, '-')), \sprintf('%s doit accepter %s', $action->command, $argument));
            }
        }

        // La clé de verrou de purge-old-seasons DOIT rester celle du job planifié :
        // geste manuel et cron balaient les mêmes tables (finding 3, gravé ici).
        $purge = $catalog->find('purge-old-seasons');
        self::assertNotNull($purge);
        self::assertSame('purge-seasons', $purge->lockKey());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        if ([] !== $this->jobRunIds) {
            $this->admin()->executeStatement('DELETE FROM admin_job_run WHERE id IN (:ids)', ['ids' => $this->jobRunIds], ['ids' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->clubIds) {
            $this->admin()->executeStatement('DELETE FROM season WHERE club_id IN (:ids)', ['ids' => $this->clubIds], ['ids' => ArrayParameterType::STRING]);
            $this->admin()->executeStatement('DELETE FROM club WHERE id IN (:ids)', ['ids' => $this->clubIds], ['ids' => ArrayParameterType::STRING]);
        }
        if (isset($this->adminId)) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    /**
     * Seed via l'EM RUNTIME (pas la connexion admin) : les commandes reset-season lisent
     * tout par la porte runtime (club + seasons sous RLS/GUC) — sous le wrapper
     * transactionnel des tests, seule la même connexion voit ses écritures non commitées.
     * La saison créée est COURANTE (contient aujourd'hui, règle SeasonResolver).
     *
     * @return array{0: string, 1: string} clubId, seasonId
     */
    private function seedRuntimeClubWithCurrentSeason(string $name): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $club = (new Club)->setName($name)->setSlug('sa4-' . strtolower(substr(md5(uniqid('', true)), 0, 10)))
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $em->persist($club);
        $em->flush();
        $this->scopeGucToClub($club->getId());
        $season = (new Season)->setClubId($club->getId())->setName('SA4')
            ->setStartDate(new DateTimeImmutable(date('Y') . '-07-16'))
            ->setEndDate(new DateTimeImmutable((date('Y') + 1) . '-07-14'))
            ->setStatus(SeasonStatus::ACTIVE);
        $em->persist($season);
        $em->flush();

        return [$club->getId(), $season->getId()];
    }

    /**
     * POST une action support avec un body JSON (schéma d'arguments A3) et le CSRF.
     *
     * @param array<string, string> $body
     */
    private function postAction(string $clubId, string $key, array $body, string $csrfToken): void
    {
        $this->client->request(
            'POST',
            "/api/admin/clubs/{$clubId}/actions/{$key}",
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $csrfToken, 'CONTENT_TYPE' => 'application/json'],
            json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function seedClub(string $name, int $generationCount = 0): string
    {
        $clubId = Uuid::v4()->toRfc4122();
        $this->clubIds[] = $clubId;
        $this->admin()->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed) VALUES (:id, 1, NOW(), NOW(), :name, :slug, :generations, :timezone, :locale, FALSE)',
            ['id' => $clubId, 'name' => $name, 'slug' => 'sa4-' . strtolower(substr(md5(uniqid('', true)), 0, 8)), 'generations' => $generationCount, 'timezone' => 'Europe/Paris', 'locale' => 'fr'],
        );

        return $clubId;
    }

    /** @return array{0: string} */
    private function createSuperAdmin(string $email, string $password): array
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, $password));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, :enabled, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret(), 'enabled' => true],
            ['enabled' => ParameterType::BOOLEAN],
        );

        return [$secret];
    }

    private function authenticate(string $email, string $password, string $secret): string
    {
        $this->json('POST', '/api/admin/auth/password', ['email' => $email, 'password' => $password]);
        self::assertResponseIsSuccessful();
        $totp = self::getContainer()->get(TotpService::class);
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();
        $csrfToken = $this->responseBody()['csrfToken'] ?? null;
        self::assertIsString($csrfToken);

        return $csrfToken;
    }

    /** @param array<string, mixed> $body */
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
