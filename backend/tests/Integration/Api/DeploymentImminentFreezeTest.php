<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Controller\DeploymentNoticeController;
use App\Controller\FillPeriodPlanController;
use App\Controller\GenerateScheduleController;
use App\Controller\PlaceMatchesController;
use App\Controller\RegenerateController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * NR (axe *generation pipeline*, §7.1, P4-350) : QUAND UNE MISE EN PRODUCTION EST IMMINENTE, LES
 * QUATRE GUICHETS QUI LANCENT UN SOLVE REFUSENT.
 *
 * Promesse (D5) : dès que l'heure H d'un déploiement ACTIF (`scheduled`|`dispatched`) est atteinte,
 * les quatre portes qui enfilent un run moteur — générer ({@see GenerateScheduleController}),
 * régénérer ({@see RegenerateController}), combler un plan de période ({@see FillPeriodPlanController})
 * et placer des matchs ({@see PlaceMatchesController}) — refusent 409 avec le message EXACT
 * « Une mise en production est imminente — réessayez après la maintenance. », SANS rien enfiler
 * (aucun passage en PENDING, aucune V+1, aucun run de placement) et SANS fuiter la moindre cuisine
 * de déploiement (ni la version, ni l'acteur, ni « GitHub » — un gestionnaire de club n'a pas à la
 * connaître, patron {@see DeploymentNoticeController}).
 *
 * Contrôle (non-régression) : un déploiement FUTUR (H pas encore atteinte) ou ANNULÉ ne gèle RIEN —
 * les guichets se comportent EXACTEMENT comme sans déploiement (génération nominale → 202).
 *
 * Contrat FIXÉ pour NEO : le gel est porté par un service `App\Service\DeploymentGate::isFrozen()`
 * (`scheduled`|`dispatched` ET `scheduled_at <= now`, lu par la connexion APPLICATIVE — la table
 * `scheduled_deployment` est GLOBALE, sans RLS), appelé dans CHAQUE guichet IMMÉDIATEMENT après la
 * garde management (SEC-07) et AVANT toute validation propre à la ressource (lookup/état/socle) —
 * ainsi le refus précède tout, uniforme sur les quatre portes. Le message de refus est une
 * CONSTANTE serveur, jamais interpolée avec un détail du déploiement.
 *
 * ⚠ Falsification PAR GUICHET (piège de la « garde dupliquée », `.claude/rules/backend.md`) : les
 * quatre portes sont quatre chemins d'écriture DISTINCTS. Retirer l'appel au gate dans UN seul
 * guichet doit faire rougir SA méthode de test (et elle seule), jamais laisser le NR vert — d'où
 * une méthode frozen par guichet.
 */
#[Group('phase1')]
#[Group('integration')]
final class DeploymentImminentFreezeTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /** Le message de refus EXACT — zéro interpolation, zéro oracle de déploiement. */
    private const string FREEZE_MESSAGE = 'Une mise en production est imminente — réessayez après la maintenance.';

    /** Version du déploiement seedé : distinctive, pour prouver qu'elle ne FUIT jamais dans le refus. */
    private const string SECRET_VERSION = 'v9.9.9-secret-tag';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $user;

    private string $token;

    /** L'acteur SA0 seedé dans le déploiement — son id ne doit JAMAIS ressortir dans le refus. */
    private string $deploymentActorId = '';

    /** Générer pendant le gel → 409 message exact, et la version NE PASSE PAS en PENDING (rien enfilé). */
    public function testAnImminentDeploymentFreezesGeneration(): void
    {
        $this->seedDeployment('scheduled', '-2 minutes');
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/generate');

        $this->assertFrozen();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame(
            ScheduleStatus::COMPLETED->value,
            $this->em->getConnection()->fetchOne('SELECT status FROM schedule WHERE id = :id', ['id' => $version->getId()]),
            'le gel refuse AVANT tout enfilage : la version reste COMPLETED, jamais PENDING',
        );
    }

    /** Régénérer pendant le gel → 409 message exact, et AUCUNE nouvelle version n'est créée. */
    public function testAnImminentDeploymentFreezesRegeneration(): void
    {
        $this->seedDeployment('scheduled', '-2 minutes');
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $before = $this->countSeasonVersions();

        $this->request('POST', '/api/schedules/' . $version->getId() . '/regenerate');

        $this->assertFrozen();
        self::assertSame($before, $this->countSeasonVersions(), 'le gel refuse AVANT la V+1 : aucune version créée');
    }

    /** Combler un plan de période pendant le gel → 409 message exact (le refus de gel précède la garde « période »). */
    public function testAnImminentDeploymentFreezesPeriodPlanFill(): void
    {
        $this->seedDeployment('scheduled', '-2 minutes');
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $before = $this->countSeasonVersions();

        $this->request('POST', '/api/schedules/' . $version->getId() . '/fill');

        $this->assertFrozen();
        self::assertSame($before, $this->countSeasonVersions(), 'le gel refuse AVANT la V+1 de comblement');
    }

    /** Placer des matchs pendant le gel → 409 message exact, et AUCUN run de placement n'est créé. */
    public function testAnImminentDeploymentFreezesMatchPlacement(): void
    {
        $this->seedDeployment('scheduled', '-2 minutes');

        $this->request('POST', '/api/fixtures/place');

        $this->assertFrozen();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM match_placement_run WHERE club_id = :club', ['club' => $this->club->getId()]),
            'le gel refuse AVANT l\'enfilage : aucun run de placement',
        );
    }

    /**
     * Le refus de gel ne dit RIEN du déploiement : message EXACT, et jamais la version, l'acteur
     * ou « GitHub » (patron DeploymentNoticeController — le club n'a pas à connaître la cuisine).
     */
    public function testTheFreezeRefusalLeaksNoDeploymentDetail(): void
    {
        $this->seedDeployment('scheduled', '-2 minutes');
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/generate');

        self::assertResponseStatusCodeSame(409);
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertSame(self::FREEZE_MESSAGE, $this->responseError(), 'le message de refus est la constante EXACTE');
        self::assertStringNotContainsString(self::SECRET_VERSION, $raw, 'la version déployée ne fuite pas');
        self::assertStringNotContainsString($this->deploymentActorId, $raw, 'l\'acteur SA0 ne fuite pas');
        self::assertStringNotContainsStringIgnoringCase('github', $raw, '« GitHub » ne fuite pas');
    }

    /** Non-régression : un déploiement FUTUR (H pas atteinte) ne gèle pas — la génération nominale survit (202). */
    public function testAFutureDeploymentDoesNotFreezeTheGuichets(): void
    {
        $this->seedDeployment('scheduled', '+2 hours');
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/generate');

        self::assertResponseStatusCodeSame(202, 'avant H, aucun gel : la génération passe comme sans déploiement');
    }

    /** Non-régression : un déploiement ANNULÉ ne gèle jamais, même son heure passée — génération nominale (202). */
    public function testACancelledDeploymentDoesNotFreezeTheGuichets(): void
    {
        $this->seedDeployment('cancelled', '-2 minutes');
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/generate');

        self::assertResponseStatusCodeSame(202, 'un déploiement annulé ne gèle aucun guichet');
    }

    /**
     * SÉCURITÉ (horloge simulée, liste fermée §7) — LE GEL SUIT L'HORLOGE RÉELLE, JAMAIS CELLE D'UN
     * CLUB DÉMO. Un gestionnaire d'un club DÉMO daté LOIN dans le futur (`simulated_today` +30 j) ne
     * doit PAS être gelé par un déploiement encore futur en temps RÉEL (+2 j) : la mise en prod est
     * un fait PLATEFORME en temps réel, pas une date de club. Sans l'horloge réelle, `isFrozen()`
     * comparerait la date SIMULÉE (+30 j) au `scheduled_at` réel (+2 j) → gel à tort.
     */
    public function testADemoClubFutureSimulatedClockNeverFreezesAgainstAFutureRealDeployment(): void
    {
        $this->seedDeployment('scheduled', '+2 days'); // pas encore à H en temps RÉEL
        [$token, $versionId] = $this->bootDemoClub(new DateTimeImmutable('+30 days'));

        $this->requestAs($token, 'POST', '/api/schedules/' . $versionId . '/generate');

        self::assertResponseStatusCodeSame(202, 'le gel suit l\'horloge RÉELLE : l\'horloge simulée future d\'un club démo ne gèle pas un déploiement encore futur');
    }

    /**
     * SÉCURITÉ (horloge simulée) — symétrique : un club DÉMO daté LOIN dans le passé
     * (`simulated_today` −30 j) EST tout de même gelé par un déploiement DÉJÀ à H en temps RÉEL
     * (−2 min). L'horloge simulée passée ne doit pas « déverrouiller » un gel réel.
     */
    public function testADemoClubPastSimulatedClockIsStillFrozenByADueRealDeployment(): void
    {
        $this->seedDeployment('scheduled', '-2 minutes'); // DÉJÀ à H en temps RÉEL
        [$token, $versionId] = $this->bootDemoClub(new DateTimeImmutable('-30 days'));

        $this->requestAs($token, 'POST', '/api/schedules/' . $versionId . '/generate');

        self::assertResponseStatusCodeSame(409, 'le gel réel tient même pour un club démo daté dans le passé');
        self::assertSame(self::FREEZE_MESSAGE, $this->responseError());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        // Table GLOBALE partagée entre méthodes (index unique partiel sur l'actif) : base propre.
        $this->em->getConnection()->executeStatement('DELETE FROM scheduled_deployment');
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Club gel-déploiement')
            ->setSlug('cgd-' . $uid)
            ->setTimezone('Europe/Paris')
            ->setLocale('fr')
            ->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('cgd-' . $uid . '@test.com');
        $this->user->setFirstName('Gel');
        $this->user->setLastName('Deploy');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $this->em->persist((new ClubUser)
            ->setClubId($this->club->getId())
            ->setUserId($this->user->getId())
            ->setRole('admin')
            ->setIsActive(true));

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season = (new Season)
            ->setClubId($this->club->getId())
            ->setName((string) $year)
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))
            ->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->season->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
        $this->provisionSeasonPlan($this->season);

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DELETE FROM scheduled_deployment');
        }
        parent::tearDown();
    }

    /** Un déploiement GLOBAL seedé par SQL direct (table sans RLS) : statut + heure H relative à maintenant. */
    private function seedDeployment(string $status, string $scheduledAtOffset): void
    {
        $this->deploymentActorId = Uuid::v4()->toRfc4122();
        $scheduledAt = new DateTimeImmutable($scheduledAtOffset);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO scheduled_deployment (id, version, scheduled_at, expected_return_at, status, created_by, created_at, updated_at)'
            . ' VALUES (:id, :version, :scheduled, :ret, :status, :by, NOW(), NOW())',
            [
                'id' => Uuid::v4()->toRfc4122(),
                'version' => self::SECRET_VERSION,
                'scheduled' => $scheduledAt->format('Y-m-d H:i:sP'),
                'ret' => $scheduledAt->modify('+60 minutes')->format('Y-m-d H:i:sP'),
                'status' => $status,
                'by' => $this->deploymentActorId,
            ],
        );
    }

    private function assertFrozen(): void
    {
        self::assertResponseStatusCodeSame(409, 'le guichet gèle quand une mise en production est imminente');
        self::assertSame(self::FREEZE_MESSAGE, $this->responseError());
    }

    private function responseError(): string
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($body) && \is_string($body['error'] ?? null) ? $body['error'] : '';
    }

    private function seedSeasonVersion(ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setName('V ' . $status->value)
            ->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function countSeasonVersions(): int
    {
        $this->scopeGucToClub($this->club->getId());

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule WHERE club_id = :club AND season_id = :season',
            ['club' => $this->club->getId(), 'season' => $this->season->getId()],
        );
    }

    private function request(string $method, string $uri): void
    {
        $this->requestAs($this->token, $method, $uri);
    }

    private function requestAs(string $token, string $method, string $uri): void
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
    }

    /**
     * Un club DÉMONSTRATION à horloge simulée + son gestionnaire + une version de travail COMPLETED,
     * décor jetable (rollback DAMA). Renvoie [token JWT, id de la version].
     *
     * @return array{0: string, 1: string}
     */
    private function bootDemoClub(DateTimeImmutable $simulatedToday): array
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        // CHECK `simulated_today IS NULL OR is_demo` : is_demo AVANT (même INSERT) la date simulée.
        $club = (new Club)
            ->setName('Club démo gel')
            ->setSlug('cdgd-' . $uid)
            ->setTimezone('Europe/Paris')
            ->setLocale('fr')
            ->setOnboardingCompleted(true)
            ->setIsDemo(true)
            ->setSimulatedToday($simulatedToday);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('cdgd-' . $uid . '@test.com');
        $user->setFirstName('Demo');
        $user->setLastName('Clock');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)
            ->setClubId($club->getId())
            ->setUserId($user->getId())
            ->setRole('admin')
            ->setIsActive(true));

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = (new Season)
            ->setClubId($club->getId())
            ->setName((string) $year)
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))
            ->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->provisionSeasonPlan($season);

        $version = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('V démo')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version);
        $this->em->flush();

        $token = $container->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $version->getId()];
    }
}
