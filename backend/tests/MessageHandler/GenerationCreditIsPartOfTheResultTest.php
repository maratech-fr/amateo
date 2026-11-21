<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SubscriptionPlan;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use App\Service\DevScheduleReportWriter;
use App\Service\EngineClient;
use App\Service\OutputCreditLedger;
use App\Service\RequestIdContext;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Service\SeasonResolver;
use App\Tests\BuildsServiceWithDoublesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Throwable;

/**
 * NR d'axe *generation pipeline* (§7.1) — le crédit d'un solve fait partie de son résultat :
 * une version prête a pris UN crédit du club du message, une version qui ne l'est pas n'en a pris
 * aucun, et l'un ne s'écrit jamais sans l'autre (décision fondateur Q16 a : même transaction).
 *
 *  - réussi (COMPLETED) : +1 au compteur du club du MESSAGE, jamais à un voisin ;
 *  - échoué (INFEASIBLE, moteur muet, moteur en panne) : 0 (décision fondateur Q1 a) ;
 *  - le REGISTRE de crédits en panne (une vraie erreur SQL levée par sa connexion, pas un `flush`
 *    de l'ORM) : tout le résultat est annulé — version « échouée » au message générique, créneaux
 *    d'avant intacts, socle ni contexte chargé ni accueil touchés, compteur inchangé, message
 *    acquitté (aucune exception ne sort du handler, l'EntityManager reste ouvert) ;
 *  - deux solves sur deux saisons, compteur à 9 sur 10 : les deux aboutissent, compteur à 11
 *    (décision fondateur Q2 : dépassement accepté, le décompte du solve n'est pas strict) ;
 *  - offre payante, bêta, démonstration : jamais décomptés.
 *
 * Le gestionnaire voit la promesse dans `un-credit-se-prend-quand-la-sortie-est-obtenue.feature` ;
 * ce test porte ce que Behat ne peut pas provoquer (la panne du registre au milieu du résultat).
 * Jumeau sur le rail du placement : {@see PlacementCreditIsPartOfTheResultTest}. La redélivrance
 * d'un COMPLETED qui ne reprend pas un second crédit : {@see RedeliveredGenerationTest}.
 *
 * Handler construit par TYPE ({@see BuildsServiceWithDoublesTrait}) : moteur simulé, hub espion,
 * et, pour la panne, un registre construit sur une connexion dont chaque requête lève l'erreur SQL
 * réelle `division_by_zero` de PostgreSQL.
 */
#[Group('phase1')]
#[Group('integration')]
final class GenerationCreditIsPartOfTheResultTest extends KernelTestCase
{
    use BuildsServiceWithDoublesTrait;
    use TenantGucTrait;

    private const string INTERNAL_ERROR = 'Schedule generation failed unexpectedly. Please regenerate.';

    private const string SENTINEL_TEAM = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private const string SENTINEL_VENUE = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    private EntityManagerInterface $em;

    private int $engineCalls = 0;

    /** @return iterable<string, array{0: array<string, mixed>|null, 1: int}> */
    public static function failedSolves(): iterable
    {
        yield 'impossible pour le solveur' => [['status' => 'infeasible', 'score' => 0, 'slots' => [], 'diagnostics' => []], 200];
        yield 'moteur muet (transport)' => [null, 200];
        yield 'moteur en panne (500)' => [['detail' => 'boom'], 500];
    }

    /** @return iterable<string, array{0: string|null, 1: bool}> */
    public static function neverRestricted(): iterable
    {
        yield 'offre Essentiel réglée' => ['essentiel', false];
        yield 'bêta réglée' => ['beta', false];
        yield 'démonstration' => [null, true];
    }

    /** @return array<string, mixed> */
    private function completed(): array
    {
        return ['status' => 'completed', 'score' => 0, 'slots' => [], 'diagnostics' => []];
    }

    /** Une version prête a pris un crédit, celui du club du message ; le voisin n'a rien perdu. */
    public function testACompletedSolveTakesOneCreditOfTheMessageClub(): void
    {
        [$club, , $schedule] = $this->seedClubWithPendingSchedule(credits: 3);
        [$neighbour] = $this->seedClubWithPendingSchedule(credits: 3);

        $this->runHandler($club->getId(), $schedule->getId(), $this->completed());

        self::assertSame(ScheduleStatus::COMPLETED, $this->statusOf($club->getId(), $schedule->getId()));
        self::assertSame(4, $this->creditsOf($club->getId()), 'une version prête prend un crédit du club du message');
        self::assertSame(3, $this->creditsOf($neighbour->getId()), 'le crédit ne tombe jamais sur un autre club');
    }

    /**
     * La perte voulue (Q16 a) : le registre lève une erreur SQL au moment de prendre le crédit. Rien
     * du résultat ne reste — ni créneaux importés, ni COMPLETED, ni contexte chargé, ni accueil — la
     * version est « échouée » au message générique, le compteur n'a pas bougé, le message est acquitté.
     */
    public function testALedgerFailureLosesTheWholeResultAndTakesNoCredit(): void
    {
        [$club, $season, $schedule] = $this->seedClubWithPendingSchedule(credits: 3, onboardingCompleted: false);
        $sentinelId = $this->seedSentinelSlot($club->getId(), $season->getId(), $schedule->getId());

        try {
            $this->runHandler($club->getId(), $schedule->getId(), $this->completed(), $this->ledgerWhoseSqlFails());
        } catch (Throwable $escaped) {
            self::fail(\sprintf('le message doit être acquitté (aucune exception ne sort du handler) — reçu %s : %s', $escaped::class, $escaped->getMessage()));
        }

        self::assertSame(1, $this->engineCalls, 'témoin : le moteur a bien répondu un résultat valide, c\'est lui qui est perdu');
        self::assertTrue($this->em->isOpen(), 'une erreur SQL du registre ne ferme pas l\'EntityManager');
        self::assertSame(ScheduleStatus::FAILED, $this->statusOf($club->getId(), $schedule->getId()), 'sans son crédit, la version n\'est jamais « prête »');
        self::assertSame([self::INTERNAL_ERROR], $this->diagnosticMessages($club->getId(), $schedule->getId(), 'internal_error'), 'l\'échec porte le message générique, jamais le détail SQL');
        self::assertSame(3, $this->creditsOf($club->getId()), 'aucun crédit pris');

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertInstanceOf(ScheduleSlotTemplate::class, $this->em->getRepository(ScheduleSlotTemplate::class)->find($sentinelId), 'les créneaux d\'avant survivent : l\'import est annulé avec le reste');
        $reloadedSeason = $this->em->getRepository(Season::class)->find($season->getId());
        self::assertInstanceOf(Season::class, $reloadedSeason);
        self::assertNull($reloadedSeason->getLiveContextScheduleId(), 'la version perdue n\'est pas devenue le contexte chargé de la saison');
        $reloadedClub = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertInstanceOf(Club::class, $reloadedClub);
        self::assertFalse($reloadedClub->getOnboardingCompleted(), 'la version perdue ne clôt pas l\'accueil du club');
    }

    /**
     * Q1 a — une génération acceptée qui ne finit pas « réussie » ne coûte rien, quelle qu'en soit la
     * cause : impossible pour le solveur, moteur muet, moteur en panne.
     *
     * @param array<string, mixed>|null $engineBody null = le moteur ne répond pas (transport)
     */
    #[DataProvider('failedSolves')]
    public function testAFailedSolveTakesNoCredit(?array $engineBody, int $httpCode): void
    {
        [$club, , $schedule] = $this->seedClubWithPendingSchedule(credits: 3);

        $this->runHandler($club->getId(), $schedule->getId(), $engineBody, null, $httpCode);

        self::assertSame(ScheduleStatus::FAILED, $this->statusOf($club->getId(), $schedule->getId()), 'témoin : la génération a bien échoué');
        self::assertSame(3, $this->creditsOf($club->getId()), 'une génération échouée laisse le solde intact');
    }

    /**
     * Q2 — deux solves sur deux saisons, compteur à 9 sur 10 : les deux versions aboutissent et le
     * compteur passe à 11. Le décompte du solve n'est pas strict : aucun résultat calculé n'est rendu
     * « échoué : crédits épuisés ».
     */
    public function testTwoSolvesOnTwoSeasonsBothCompleteAndMayOverrunThePool(): void
    {
        [$club, , $current] = $this->seedClubWithPendingSchedule(credits: 9);
        $next = $this->seedPendingScheduleInANewSeason($club->getId());

        $this->runHandler($club->getId(), $current->getId(), $this->completed());
        $this->runHandler($club->getId(), $next->getId(), $this->completed());

        self::assertSame(ScheduleStatus::COMPLETED, $this->statusOf($club->getId(), $current->getId()));
        self::assertSame(ScheduleStatus::COMPLETED, $this->statusOf($club->getId(), $next->getId()), 'le second solve aboutit malgré le pool franchi');
        self::assertSame(11, $this->creditsOf($club->getId()), 'chaque version prête prend son crédit, le pool peut être dépassé');
    }

    /** Une offre payante, la bêta et la démonstration ne sont jamais décomptées, même au succès. */
    #[DataProvider('neverRestricted')]
    public function testAnUnrestrictedClubIsNeverCounted(?string $planCode, bool $isDemo): void
    {
        [$club, , $schedule] = $this->seedClubWithPendingSchedule(credits: 50, planCode: $planCode, isDemo: $isDemo);

        $this->runHandler($club->getId(), $schedule->getId(), $this->completed());

        self::assertSame(ScheduleStatus::COMPLETED, $this->statusOf($club->getId(), $schedule->getId()), 'témoin : la version est prête');
        self::assertSame(50, $this->creditsOf($club->getId()), 'un club non bridé n\'est jamais décompté');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->engineCalls = 0;
    }

    /** @param array<string, mixed>|null $engineBody null = le moteur ne répond pas (erreur de transport) */
    private function runHandler(string $clubId, string $scheduleId, ?array $engineBody, ?OutputCreditLedger $ledger = null, int $httpCode = 200): void
    {
        $http = new MockHttpClient(function () use ($engineBody, $httpCode): MockResponse {
            ++$this->engineCalls;

            return null === $engineBody
                ? new MockResponse('', ['error' => 'moteur injoignable (simulé)'])
                : new MockResponse(json_encode($engineBody, \JSON_THROW_ON_ERROR), ['http_code' => $httpCode, 'response_headers' => ['content-type' => 'application/json']]);
        });
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $doubles = [
            EntityManagerInterface::class => $this->em,
            EngineClient::class => new EngineClient($http, new RequestIdContext),
            ScheduleProgressPublisher::class => new ScheduleProgressPublisher($hub),
            DevScheduleReportWriter::class => null,
            LoggerInterface::class => new NullLogger,
        ];
        if ($ledger instanceof OutputCreditLedger) {
            $doubles[OutputCreditLedger::class] = $ledger;
        }

        $handler = $this->buildWithDoubles(GenerateScheduleHandler::class, $doubles);
        $handler(new GenerateScheduleMessage(scheduleId: $scheduleId, clubId: $clubId));
    }

    /**
     * Le registre de crédits réel, construit sur une connexion dont CHAQUE requête lève une vraie
     * erreur SQL de PostgreSQL (`division_by_zero`, jouée sur la connexion de test) : la panne vient
     * du registre, pas d'un `flush` de l'ORM.
     */
    private function ledgerWhoseSqlFails(): OutputCreditLedger
    {
        $real = $this->em->getConnection();
        $fail = static function () use ($real): never {
            $real->executeStatement('SELECT 1 / 0');

            throw new LogicException('inatteignable : la division par zéro a levé');
        };
        $connection = $this->createMock(Connection::class);
        foreach (['executeStatement', 'executeQuery', 'fetchOne', 'fetchAssociative', 'fetchAllAssociative', 'fetchFirstColumn'] as $method) {
            $connection->method($method)->willReturnCallback($fail);
        }

        return $this->buildWithDoubles(OutputCreditLedger::class, [Connection::class => $connection, EntityManagerInterface::class => $this->em]);
    }

    /** @return list<string> */
    private function diagnosticMessages(string $clubId, string $scheduleId, string $type): array
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();

        return array_map(
            static fn (ScheduleDiagnostic $d): string => $d->getMessage(),
            $this->em->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $scheduleId, 'type' => $type]),
        );
    }

    private function statusOf(string $clubId, string $scheduleId): ?ScheduleStatus
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $schedule = $this->em->getRepository(Schedule::class)->find($scheduleId);
        $this->clearGuc();

        return $schedule instanceof Schedule ? $schedule->getStatus() : null;
    }

    private function creditsOf(string $clubId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT output_credits_used FROM club WHERE id = :id', ['id' => $clubId]);
    }

    private function seedSentinelSlot(string $clubId, string $seasonId, string $scheduleId): string
    {
        $this->scopeGucToClub($clubId);
        $sentinel = new ScheduleSlotTemplate;
        $sentinel->setClubId($clubId);
        $sentinel->setSeasonId($seasonId);
        $sentinel->setScheduleId($scheduleId);
        $sentinel->setTeamId(self::SENTINEL_TEAM);
        $sentinel->setVenueId(self::SENTINEL_VENUE);
        $sentinel->setDayOfWeek(3);
        $sentinel->setStartTime(new DateTimeImmutable('19:30'));
        $sentinel->setDurationMinutes(90);
        $sentinel->setLockLevel(LockLevel::NONE);
        $this->em->persist($sentinel);
        $this->em->flush();
        $this->em->clear();
        $this->clearGuc();

        return $sentinel->getId();
    }

    /**
     * Un club (Découverte non démo par défaut : `plan_id` NULL), sa saison courante et une version de
     * saison PENDING liée à son plan, comme la laissent les trois guichets avant la mise en file.
     *
     * @return array{0: Club, 1: Season, 2: Schedule}
     */
    private function seedClubWithPendingSchedule(int $credits, ?string $planCode = null, bool $isDemo = false, bool $onboardingCompleted = true): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Credit Club ' . $uid);
        $club->setSlug('credit-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted($onboardingCompleted);
        $club->setIsDemo($isDemo);
        $club->setOutputCreditsUsed($credits);
        $this->em->persist($club);
        $this->em->flush();

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        if (null !== $planCode) {
            $plan = $this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => $planCode]);
            self::assertInstanceOf(SubscriptionPlan::class, $plan, \sprintf('l\'offre « %s » est seedée', $planCode));
            $club->setPlanId($plan->getId());
            $club->setPaidSeasonYear($year);
            $this->em->flush();
        }

        $season = $this->seedSeason($club->getId(), $year);
        $schedule = $this->seedPendingSchedule($club->getId(), $season);
        $this->em->clear();
        $this->clearGuc();

        return [$club, $season, $schedule];
    }

    private function seedPendingScheduleInANewSeason(string $clubId): Schedule
    {
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1;
        $season = $this->seedSeason($clubId, $year, SeasonStatus::DRAFT);
        $schedule = $this->seedPendingSchedule($clubId, $season);
        $this->em->clear();
        $this->clearGuc();

        return $schedule;
    }

    private function seedSeason(string $clubId, int $year, SeasonStatus $status = SeasonStatus::ACTIVE): Season
    {
        $this->scopeGucToClub($clubId);
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName($year . '-' . ($year + 1));
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus($status);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function seedPendingSchedule(string $clubId, Season $season): Schedule
    {
        $this->scopeGucToClub($clubId);
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Credit schedule');
        $schedule->setStatus(ScheduleStatus::PENDING);
        $schedule->setSchedulePlanId($provisioner->ensureSeasonPlanId($season->getId()));
        $this->em->persist($schedule);
        $this->em->flush();
        $provisioner->linkSchedule($schedule);
        $schedule->setStatus(ScheduleStatus::PENDING);
        $this->em->flush();

        return $schedule;
    }
}
