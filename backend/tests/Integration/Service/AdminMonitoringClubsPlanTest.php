<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\AdminMonitoringService;
use App\Service\PlanEntitlements;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A1 — le badge de la console SA dit l'offre EFFECTIVE, pas l'offre STOCKÉE. La règle
 * pivot a UNE seule maison ({@see PlanEntitlements::effectivePlanCode}) ; ce
 * test garde que le mapping DBAL d'AdminMonitoringService la relaie sans la re-dériver en
 * SQL : si le service et la règle divergent (p. ex. la comparaison code/effectif inversée),
 * les assertions ci-dessous rougissent.
 *
 * AdminMonitoringService lit par la connexion `admin` (superuser, HORS dama). On sème donc
 * dans une transaction ouverte SUR CETTE connexion partagée — le service voit nos lignes non
 * committées (même session) — et on la `rollBack` au teardown : aucune fuite committée.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminMonitoringClubsPlanTest extends KernelTestCase
{
    private Connection $admin;

    private bool $inTransaction = false;

    private ManagerRegistry $registry;

    /** Préfixe unique : filtre les clubs semés hors du parc de fixtures. */
    private string $tag;

    public function testEffectivePlanMirrorsThePivotRuleNotTheStoredOffer(): void
    {
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $beta = $this->planId('beta');

        // Bêta POSÉE mais saison NON réglée (paidSeasonYear null) → effective Découverte.
        $this->seedClub('unpaid', $beta, null, $year);
        // Bêta POSÉE et saison réglée (paidSeasonYear = année-pivot) → effective Bêta.
        $this->seedClub('paid', $beta, $year, $year);
        // Aucune offre stockée → Découverte, plan null.
        $this->seedClub('noplan', null, null, $year);

        $items = self::getContainer()->get(AdminMonitoringService::class)->clubs(1, 100, $this->tag)['items'];
        $byName = [];
        foreach ($items as $item) {
            $byName[(string) $item['name']] = $item;
        }

        self::assertCount(3, $byName, 'les trois clubs semés doivent remonter');

        $unpaid = $byName[$this->tag . ' unpaid'];
        self::assertSame('beta', $unpaid['plan']['code'], 'l\'offre STOCKÉE reste Bêta');
        self::assertSame('decouverte', $unpaid['effectivePlan']['code'], 'l\'offre EFFECTIVE retombe sur Découverte (saison non réglée)');
        self::assertNull($unpaid['paidSeasonYear']);

        $paid = $byName[$this->tag . ' paid'];
        self::assertSame('beta', $paid['plan']['code']);
        self::assertSame('beta', $paid['effectivePlan']['code'], 'saison réglée → l\'effective est bien Bêta');
        self::assertSame($year, $paid['paidSeasonYear']);

        $noplan = $byName[$this->tag . ' noplan'];
        self::assertNull($noplan['plan'], 'aucune offre stockée → plan null');
        self::assertSame('decouverte', $noplan['effectivePlan']['code']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $this->registry = $registry;
        $connection = $registry->getConnection('admin');
        \assert($connection instanceof Connection);
        $this->admin = $connection;
        $this->admin->beginTransaction();
        $this->inTransaction = true;
        $this->tag = 'A1PLAN' . strtoupper(bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            $this->admin->rollBack();
            $this->inTransaction = false;
        }
        parent::tearDown();
    }

    private function planId(string $code): string
    {
        $id = $this->admin->fetchOne('SELECT id FROM subscription_plan WHERE code = :code', ['code' => $code]);
        \assert(\is_string($id), \sprintf('le plan « %s » doit exister dans les fixtures de test', $code));

        return $id;
    }

    private function seedClub(string $suffix, ?string $planId, ?int $paidSeasonYear, int $year): void
    {
        $clubId = $this->admin->fetchOne('SELECT gen_random_uuid()');
        \assert(\is_string($clubId));
        $name = $this->tag . ' ' . $suffix;

        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO club (id, created_at, updated_at, name, slug, plan_id, paid_season_year, generation_count_season, timezone, locale, onboarding_completed)
            VALUES (:id, NOW(), NOW(), :name, :slug, :plan_id, :paid, 0, 'Europe/Paris', 'fr', TRUE)
            SQL, [
            'id' => $clubId,
            'name' => $name,
            'slug' => strtolower($this->tag . '-' . $suffix),
            'plan_id' => $planId,
            'paid' => $paidSeasonYear,
        ]);

        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO season (id, created_at, updated_at, club_id, name, start_date, end_date, status, transition_data)
            VALUES (gen_random_uuid(), NOW(), NOW(), :club_id, :name, :start, :end, 'ACTIVE', '[]')
            SQL, [
            'club_id' => $clubId,
            'name' => (string) $year,
            'start' => $year . '-08-01',
            'end' => ($year + 1) . '-07-15',
        ]);
    }

    /**
     * PR2 « stats par club » — chaque item porte des COMPTEURS par club (membres actifs /
     * en attente, crédits de sortie consommés, signalements non traités) et l'état
     * d'onboarding, SANS la moindre donnée personnelle. Le « en attente » n'est que
     * `is_active = false AND deactivated_at IS NULL` ({@see \App\Entity\ClubUser} :48-56) :
     * une adhésion DÉSACTIVÉE (deactivated_at posé) n'est ni active ni en attente.
     */
    public function testClubItemsExposeMemberOnboardingCreditAndFeedbackCountsWithoutPii(): void
    {
        $now = new DateTimeImmutable('2026-10-18 12:00:00');
        [$clubId, $seasonId] = $this->seedMonitoredClub('members', $now->modify('-10 days'), $now->modify('-1 day'), 3, false, $now->modify('-20 days'), 47, false, null);
        $this->seedSeasonPlan($clubId, $seasonId, $now->modify('-5 days'), true); // socle EN VIGUEUR
        $this->seedMember($clubId, true, null);
        $this->seedMember($clubId, true, null);
        $this->seedMember($clubId, false, null);                    // en attente
        $this->seedMember($clubId, false, $now->modify('-2 days')); // désactivée : ni active ni en attente
        $this->seedFeedback($clubId, 'untreated');
        $this->seedFeedback($clubId, 'untreated');
        $this->seedFeedback($clubId, 'treated');

        $items = $this->service($now)->clubs(1, 100, $this->tag)['items'];
        self::assertCount(1, $items);
        $item = $items[0];

        self::assertArrayHasKey('members', $item);
        self::assertSame(2, $item['members']['active'], 'deux adhésions actives');
        self::assertSame(1, $item['members']['pending'], 'une seule en attente — la désactivée ne compte pas');

        self::assertArrayHasKey('onboarding', $item);
        self::assertFalse($item['onboarding']['completed']);
        self::assertIsString($item['onboarding']['ffbbTeamsImportedAt'], 'date d\'import des équipes exposée en ISO');
        self::assertTrue($item['onboarding']['socleChosen'], 'socle en vigueur');

        self::assertArrayHasKey('creditsUsed', $item);
        self::assertSame(47, $item['creditsUsed']);

        self::assertArrayHasKey('feedback', $item);
        self::assertSame(2, $item['feedback']['untreated']);

        $this->assertNoPersonalData($item, 'item');
    }

    /**
     * Signal « club qui coince » — raison 1 : onboarding non complété, aucune génération,
     * club né depuis plus de 5 jours. Le seuil est MUET à J+4 (laisser le club démarrer)
     * et SIGNALÉ à J+6 — calculé en PHP via l'horloge injectée.
     */
    public function testOnboardingStallIsSilentUntilTheSixthDayThenFlagged(): void
    {
        $now = new DateTimeImmutable('2026-10-18 12:00:00');
        $this->seedMonitoredClub('j4', $now->modify('-4 days'), $now->modify('-1 day'), 0, false, null, 0, false, null);
        $this->seedMonitoredClub('j6', $now->modify('-6 days'), $now->modify('-1 day'), 0, false, null, 0, false, null);

        $byName = array_column($this->service($now)->clubs(1, 100, $this->tag)['items'], null, 'name');

        $young = $byName[$this->tag . ' j4'];
        self::assertArrayHasKey('stuck', $young);
        self::assertNull($young['stuck'], 'J+4 reste muet — on laisse le club démarrer');

        $stalled = $byName[$this->tag . ' j6'];
        self::assertArrayHasKey('stuck', $stalled);
        self::assertNotNull($stalled['stuck'], 'J+6 sans génération est signalé');
        self::assertCount(1, $stalled['stuck']['reasons']);
        self::assertIsString($stalled['stuck']['reasons'][0]);
        self::assertNotSame('', $stalled['stuck']['reasons'][0], 'la raison est une phrase lisible, pas un code interne');
    }

    /**
     * Signal « club qui coince » — raison 2 : le DERNIER solve est FAILED/INFEASIBLE, lu
     * sur latest_metric SANS la borne de 30 jours. Un échec daté il y a 49 jours (hors
     * fenêtre solveur) signale quand même.
     */
    public function testLatestFailedGenerationFlagsEvenBeyondTheMetricsWindow(): void
    {
        $now = new DateTimeImmutable('2026-10-18 12:00:00');
        [$clubId, $seasonId] = $this->seedMonitoredClub('failed', $now->modify('-30 days'), $now->modify('-1 day'), 4, true, $now->modify('-25 days'), 0, false, null);
        $this->seedSeasonPlan($clubId, $seasonId, $now->modify('-20 days'), true); // socle validé → raison 4 écartée
        $this->seedMetric($clubId, 'FAILED', $now->modify('-49 days'));            // dernier solve : ÉCHEC, hors 30 j

        $item = $this->service($now)->clubs(1, 100, $this->tag)['items'][0];
        self::assertArrayHasKey('stuck', $item);
        self::assertNotNull($item['stuck'], 'un dernier solve en échec signale, même daté hors fenêtre 30 j');
        self::assertCount(1, $item['stuck']['reasons']);
    }

    /**
     * Signal « club qui coince » — raison 3 : aucune activité depuis plus de 14 jours.
     * Muet à J+13, signalé à J+15.
     */
    public function testProlongedSilenceIsSilentAtThirteenDaysThenFlaggedAtFifteen(): void
    {
        $now = new DateTimeImmutable('2026-10-18 12:00:00');
        [$quietId, $quietSeason] = $this->seedMonitoredClub('quiet13', $now->modify('-60 days'), $now->modify('-13 days'), 4, true, $now->modify('-50 days'), 0, false, null);
        $this->seedSeasonPlan($quietId, $quietSeason, $now->modify('-40 days'), true);
        $this->seedMetric($quietId, 'COMPLETED', $now->modify('-13 days'));
        [$silentId, $silentSeason] = $this->seedMonitoredClub('quiet15', $now->modify('-60 days'), $now->modify('-15 days'), 4, true, $now->modify('-50 days'), 0, false, null);
        $this->seedSeasonPlan($silentId, $silentSeason, $now->modify('-40 days'), true);
        $this->seedMetric($silentId, 'COMPLETED', $now->modify('-15 days'));

        $byName = array_column($this->service($now)->clubs(1, 100, $this->tag)['items'], null, 'name');

        $quiet = $byName[$this->tag . ' quiet13'];
        self::assertArrayHasKey('stuck', $quiet);
        self::assertNull($quiet['stuck'], 'J+13 d\'inactivité reste muet (< 14 j)');

        $silent = $byName[$this->tag . ' quiet15'];
        self::assertArrayHasKey('stuck', $silent);
        self::assertNotNull($silent['stuck'], 'au-delà de 14 j sans activité, le club est signalé');
        self::assertCount(1, $silent['stuck']['reasons']);
    }

    /**
     * Signal « club qui coince » — raison 4 : le club a généré (generation_count_season > 0)
     * mais son plan SEASON de la saison courante n'a JAMAIS été validé (first_chosen_at NULL).
     */
    public function testGenerationsWithoutAValidatedSocleAreFlagged(): void
    {
        $now = new DateTimeImmutable('2026-10-18 12:00:00');
        [$clubId, $seasonId] = $this->seedMonitoredClub('nosocle', $now->modify('-30 days'), $now->modify('-1 day'), 5, true, $now->modify('-25 days'), 0, false, null);
        $this->seedSeasonPlan($clubId, $seasonId, null, false); // SEASON jamais validé (first_chosen_at NULL)
        $this->seedMetric($clubId, 'COMPLETED', $now->modify('-2 days'));

        $item = $this->service($now)->clubs(1, 100, $this->tag)['items'][0];
        self::assertArrayHasKey('stuck', $item);
        self::assertNotNull($item['stuck'], 'des générations sans socle validé signalent');
        self::assertCount(1, $item['stuck']['reasons']);
    }

    /** Plusieurs raisons applicables → toutes listées (ici dernier solve en échec ET silence > 14 j). */
    public function testEveryApplicableReasonIsListedTogether(): void
    {
        $now = new DateTimeImmutable('2026-10-18 12:00:00');
        [$clubId, $seasonId] = $this->seedMonitoredClub('double', $now->modify('-60 days'), $now->modify('-20 days'), 4, true, $now->modify('-50 days'), 0, false, null);
        $this->seedSeasonPlan($clubId, $seasonId, $now->modify('-40 days'), true);
        $this->seedMetric($clubId, 'INFEASIBLE', $now->modify('-20 days'));

        $item = $this->service($now)->clubs(1, 100, $this->tag)['items'][0];
        self::assertArrayHasKey('stuck', $item);
        self::assertNotNull($item['stuck']);
        self::assertCount(2, $item['stuck']['reasons'], 'toutes les raisons applicables sont listées');
    }

    /**
     * Un club de démonstration (is_demo) et un club désabonné (unsubscribed_at posé) ne
     * sont JAMAIS signalés, même quand leurs données déclencheraient sinon (dernier solve
     * INFEASIBLE + silence prolongé ici).
     */
    public function testDemoAndUnsubscribedClubsAreNeverFlagged(): void
    {
        $now = new DateTimeImmutable('2026-10-18 12:00:00');
        [$demoId, $demoSeason] = $this->seedMonitoredClub('demo', $now->modify('-60 days'), $now->modify('-20 days'), 4, true, $now->modify('-50 days'), 0, true, null);
        $this->seedSeasonPlan($demoId, $demoSeason, $now->modify('-40 days'), true);
        $this->seedMetric($demoId, 'INFEASIBLE', $now->modify('-20 days'));
        [$unsubId, $unsubSeason] = $this->seedMonitoredClub('unsub', $now->modify('-60 days'), $now->modify('-20 days'), 4, true, $now->modify('-50 days'), 0, false, $now->modify('-3 days'));
        $this->seedSeasonPlan($unsubId, $unsubSeason, $now->modify('-40 days'), true);
        $this->seedMetric($unsubId, 'INFEASIBLE', $now->modify('-20 days'));

        $byName = array_column($this->service($now)->clubs(1, 100, $this->tag)['items'], null, 'name');

        $demo = $byName[$this->tag . ' demo'];
        self::assertArrayHasKey('stuck', $demo);
        self::assertNull($demo['stuck'], 'un club démo n\'est jamais signalé');

        $unsub = $byName[$this->tag . ' unsub'];
        self::assertArrayHasKey('stuck', $unsub);
        self::assertNull($unsub['stuck'], 'un club désabonné n\'est jamais signalé');
    }

    private function service(DateTimeImmutable $now): AdminMonitoringService
    {
        // Patron de AdminDataFreshnessRestoreCheckTest : service construit À LA MAIN avec
        // une MockClock ancrée, mais la MÊME registry (donc la MÊME connexion `admin`
        // transactionnelle) — il voit les lignes semées non committées.
        return new AdminMonitoringService($this->registry, new MockClock($now));
    }

    /**
     * @return array{0: string, 1: string} [clubId, seasonId]
     */
    private function seedMonitoredClub(string $suffix, DateTimeImmutable $createdAt, ?DateTimeImmutable $lastActivityAt, int $generationCount, bool $onboardingCompleted, ?DateTimeImmutable $ffbbImportedAt, int $credits, bool $isDemo, ?DateTimeImmutable $unsubscribedAt): array
    {
        $clubId = $this->admin->fetchOne('SELECT gen_random_uuid()');
        \assert(\is_string($clubId));
        $seasonId = $this->admin->fetchOne('SELECT gen_random_uuid()');
        \assert(\is_string($seasonId));
        $name = $this->tag . ' ' . $suffix;

        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO club (id, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed, output_credits_used, ffbb_teams_imported_at, last_activity_at, is_demo, unsubscribed_at)
            VALUES (:id, :created, NOW(), :name, :slug, :gen, 'Europe/Paris', 'fr', :onboarding, :credits, :ffbb, :activity, :demo, :unsub)
            SQL, [
            'id' => $clubId,
            'created' => $createdAt->format('Y-m-d H:i:sP'),
            'name' => $name,
            'slug' => strtolower($this->tag . '-' . $suffix),
            'gen' => $generationCount,
            'onboarding' => $onboardingCompleted,
            'credits' => $credits,
            'ffbb' => $ffbbImportedAt?->format('Y-m-d H:i:sP'),
            'activity' => $lastActivityAt?->format('Y-m-d H:i:sP'),
            'demo' => $isDemo,
            'unsub' => $unsubscribedAt?->format('Y-m-d H:i:sP'),
        ], [
            'gen' => ParameterType::INTEGER,
            'credits' => ParameterType::INTEGER,
            'onboarding' => ParameterType::BOOLEAN,
            'demo' => ParameterType::BOOLEAN,
        ]);

        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO season (id, created_at, updated_at, club_id, name, start_date, end_date, status, transition_data)
            VALUES (:id, NOW(), NOW(), :club_id, '2026-2027', '2026-08-01', '2027-07-15', 'ACTIVE', '[]')
            SQL, ['id' => $seasonId, 'club_id' => $clubId]);

        return [$clubId, $seasonId];
    }

    private function seedSeasonPlan(string $clubId, string $seasonId, ?DateTimeImmutable $firstChosenAt, bool $chosen): void
    {
        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO schedule_plan (id, created_at, updated_at, club_id, season_id, type, name, start_date, end_date, chosen_schedule_id, first_chosen_at)
            VALUES (gen_random_uuid(), NOW(), NOW(), :club, :season, 'SEASON', 'Saison', '2026-08-01', '2027-07-15', :chosen, :first)
            SQL, [
            'club' => $clubId,
            'season' => $seasonId,
            'chosen' => $chosen ? '11111111-1111-1111-1111-111111111111' : null,
            'first' => $firstChosenAt?->format('Y-m-d H:i:sP'),
        ]);
    }

    private function seedMetric(string $clubId, string $status, DateTimeImmutable $createdAt): void
    {
        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO solver_metrics (id, schedule_id, club_id, status, wall_time_ms, created_at)
            VALUES (gen_random_uuid(), gen_random_uuid(), :club, :status, 1000, :created)
            SQL, ['club' => $clubId, 'status' => $status, 'created' => $createdAt->format('Y-m-d H:i:sP')]);
    }

    private function seedMember(string $clubId, bool $isActive, ?DateTimeImmutable $deactivatedAt): void
    {
        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO club_user (id, created_at, updated_at, club_id, user_id, role, joined_at, is_active, deactivated_at)
            VALUES (gen_random_uuid(), NOW(), NOW(), :club, gen_random_uuid(), 'member', NOW(), :active, :deactivated)
            SQL, [
            'club' => $clubId,
            'active' => $isActive,
            'deactivated' => $deactivatedAt?->format('Y-m-d H:i:sP'),
        ], ['active' => ParameterType::BOOLEAN]);
    }

    private function seedFeedback(string $clubId, string $status): void
    {
        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO feedback (id, club_id, user_id, topic, message, status, created_at)
            VALUES (gen_random_uuid(), :club, gen_random_uuid(), 'bug', 'Message de test', :status, NOW())
            SQL, ['club' => $clubId, 'status' => $status]);
    }

    /**
     * Aucune donnée personnelle ne doit transiter par un item : la console superadmin est
     * cross-tenant, elle agrège des COMPTEURS, jamais des identités.
     *
     * @param mixed $value
     */
    private function assertNoPersonalData($value, string $path): void
    {
        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (\is_string($key)) {
                self::assertNotContains(
                    strtolower($key),
                    ['email', 'userid', 'user_id', 'firstname', 'first_name', 'lastname', 'last_name'],
                    \sprintf('clé personnelle interdite « %s » sous %s', $key, $path),
                );
            }
            $this->assertNoPersonalData($child, $path . '.' . $key);
        }
    }
}
