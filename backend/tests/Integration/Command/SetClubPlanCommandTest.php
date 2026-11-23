<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le geste « Offre » du support (`app:clubs:set-plan`) écrit la ligne d'abonnement de UNE saison,
 * et ses deux années sont celles que lisent l'écran et la bascule : « en cours » = l'année de la
 * saison courante du club (sa dernière saison commencée, celle de `/api/me`), ou l'année d'horloge
 * s'il n'a pas de saison ; « suivante » = l'année que la bascule créera,
 * `max(année en cours + 1, année d'horloge + 1)`. L'horloge est celle du club : un club de
 * démonstration épinglé (`simulated_today`) pivote sur cette date, jamais sur le temps réel.
 * Une offre payante exige `--paid-season` ; Découverte le refuse ; un refus n'écrit rien.
 *
 * Le décor vit sur la connexion `admin`, celle de l'écrivain : sous DAMA, une ligne écrite par la
 * connexion propriétaire n'est visible que d'elle, et le club comme ses saisons doivent y être
 * visibles (la commande tourne hors requête, sans locataire posé).
 *
 * L'effet de bout en bout (l'offre servie à l'écran, saison par saison ; la bascule ouverte par la
 * ligne de la saison suivante) : `l-abonnement-se-regle-par-saison.feature`.
 */
#[Group('integration')]
final class SetClubPlanCommandTest extends KernelTestCase
{
    private Connection $admin;

    private CommandTester $tester;

    public function testPaidSeasonCurrentWritesTheSeasonOfTheDemoClock(): void
    {
        // Club démo épinglé au 2028-09-01 (après le pivot du 15 juillet → saison 2028), sans saison :
        // « en cours » = l'année d'horloge 2028 ; l'horloge réelle donnerait une autre année (garde-fou).
        self::assertNotSame(2028, (int) (new DateTimeImmutable)->format('Y'), 'garde-fou : le test perd son sens si le temps réel est déjà 2028');
        $clubId = $this->club(isDemo: true, simulatedToday: '2028-09-01');

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--club' => $clubId, '--plan' => 'complete', '--tier' => 'palier-1', '--paid-season' => 'current']), $this->tester->getDisplay());

        self::assertSame([['season_year' => 2028, 'plan' => 'complete', 'tier' => 'palier-1', 'paid' => true, 'origin' => 'support']], $this->linesOf($clubId), 'current sur l\'horloge 2028 → la seule ligne 2028, réglée');
    }

    public function testPaidSeasonNextTargetsTheFollowingSeason(): void
    {
        $clubId = $this->club(isDemo: true, simulatedToday: '2028-09-01');

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--club' => $clubId, '--plan' => 'complete', '--tier' => 'palier-2', '--paid-season' => 'next']), $this->tester->getDisplay());

        self::assertSame([['season_year' => 2029, 'plan' => 'complete', 'tier' => 'palier-2', 'paid' => true, 'origin' => 'support']], $this->linesOf($clubId), 'next sur l\'horloge 2028 → la seule ligne 2029 : la saison en cours ne reçoit rien');
    }

    /**
     * Lot 8, PR 2 — le palier à la commande, second rempart derrière le schéma de la console
     * (`AdminClubActionTest::testSetPlanValidatesTheClosedArgumentSchemaFailClosed`, qui garde le
     * rail HTTP) : la commande se lance aussi à la main sur la connexion propriétaire. L'offre
     * complète sans palier, un palier sur la Bêta ou sur Découverte, un palier inconnu : refusés,
     * rien n'est écrit. Témoin : la Bêta sans palier s'écrit.
     */
    public function testTheTierIsRequiredForTheCompleteOfferAndRefusedElsewhere(): void
    {
        $clubId = $this->club(isDemo: false, seasonYears: [2026]);

        foreach ([
            'offre complète sans palier' => ['--plan' => 'complete', '--paid-season' => 'current'],
            'palier sur la Bêta' => ['--plan' => 'beta', '--tier' => 'palier-1', '--paid-season' => 'current'],
            'palier sur Découverte' => ['--plan' => 'decouverte', '--tier' => 'palier-1'],
            'palier inconnu' => ['--plan' => 'complete', '--tier' => 'palier-4', '--paid-season' => 'current'],
        ] as $case => $options) {
            self::assertSame(Command::FAILURE, $this->tester->execute(['--club' => $clubId] + $options), $case . ' : refusé');
            self::assertSame([], $this->linesOf($clubId), $case . ' : le refus n\'écrit aucune ligne');
        }

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--club' => $clubId, '--plan' => 'beta', '--paid-season' => 'current']), $this->tester->getDisplay());
        self::assertSame([['season_year' => 2026, 'plan' => 'beta', 'tier' => null, 'paid' => true, 'origin' => 'support']], $this->linesOf($clubId), 'témoin : la Bêta, sans palier, s\'écrit');
    }

    /**
     * Le cas que l'horloge seule faisait faux : un club resté sur sa saison 2026-2027 (aucune bascule)
     * alors que l'horloge dit 2028. Sa saison en cours est 2026 (celle qu'il lit à l'écran) ; la bascule
     * créera 2029 (`max(2026 + 1, 2028 + 1)`). Régler « en cours » règle 2026, « suivante » règle 2029.
     */
    public function testAClubStillOnItsPastSeasonPaysThatSeasonOrTheOneTheTransitionWillCreate(): void
    {
        $clubId = $this->club(isDemo: true, simulatedToday: '2028-09-01', seasonYears: [2026]);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--club' => $clubId, '--plan' => 'beta', '--paid-season' => 'current']), $this->tester->getDisplay());
        self::assertSame([2026], array_column($this->linesOf($clubId), 'season_year'), '« en cours » = la saison courante du club (2026), pas l\'année d\'horloge (2028)');

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--club' => $clubId, '--plan' => 'beta', '--paid-season' => 'next']), $this->tester->getDisplay());
        self::assertSame([2026, 2029], array_column($this->linesOf($clubId), 'season_year'), '« suivante » = l\'année que la bascule créera (2029), pas 2027');
    }

    public function testDecouvertePlusPaidSeasonIsRejected(): void
    {
        $clubId = $this->club(isDemo: true, simulatedToday: '2028-09-01');

        self::assertSame(Command::FAILURE, $this->tester->execute(['--club' => $clubId, '--plan' => 'decouverte', '--paid-season' => 'current']));
        self::assertStringContainsString('not allowed', $this->tester->getDisplay());
        self::assertSame([], $this->linesOf($clubId), 'le refus n\'écrit aucune ligne');
    }

    /** L'ancienne voie « l'offre seule » n'existe plus : une offre payante sans saison réglée est refusée. */
    public function testAPaidOfferWithoutPaidSeasonIsRefusedAndWritesNothing(): void
    {
        $clubId = $this->club(isDemo: false, seasonYears: [2026]);

        self::assertSame(Command::FAILURE, $this->tester->execute(['--club' => $clubId, '--plan' => 'complete', '--tier' => 'palier-2']));
        self::assertSame([], $this->linesOf($clubId), 'sans --paid-season, aucune ligne : une offre payante se règle toujours pour une saison');
    }

    /** L'ancien palier « sans-limite » n'existe plus : la CLI le refuse comme un code inconnu, rien n'est écrit. */
    public function testTheRetiredSansLimiteOfferIsAnUnknownCode(): void
    {
        $clubId = $this->club(isDemo: false);

        self::assertSame(Command::FAILURE, $this->tester->execute(['--club' => $clubId, '--plan' => 'sans-limite', '--paid-season' => 'current']));
        self::assertStringContainsString('Unknown offer code', $this->tester->getDisplay());
        self::assertSame([], $this->linesOf($clubId), 'le refus n\'écrit aucune ligne');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);
        $this->admin = $connection;
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:clubs:set-plan'));
    }

    /** @param list<int> $seasonYears saisons du club, du 15 juillet au 14 juillet */
    private function club(bool $isDemo, ?string $simulatedToday = null, array $seasonYears = []): string
    {
        $clubId = $this->admin->fetchOne('SELECT gen_random_uuid()::text');
        self::assertIsString($clubId);
        $suffix = bin2hex(random_bytes(4));
        $this->admin->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season, is_demo, simulated_today) VALUES (?, 1, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0, ?, ?)',
            [$clubId, 'Plan ' . $suffix, 'plan-cmd-' . $suffix, $isDemo ? 'true' : 'false', $simulatedToday],
        );
        foreach ($seasonYears as $seasonYear) {
            $this->admin->executeStatement(
                'INSERT INTO season (id, created_at, updated_at, club_id, name, start_date, end_date, status, transition_data) VALUES (gen_random_uuid(), now(), now(), ?, ?, ?, ?, \'active\', \'[]\')',
                [$clubId, \sprintf('%d-%d', $seasonYear, $seasonYear + 1), \sprintf('%d-07-15', $seasonYear), \sprintf('%d-07-14', $seasonYear + 1)],
            );
        }

        return $clubId;
    }

    /** @return list<array{season_year: int, plan: string, tier: ?string, paid: bool, origin: string}> */
    private function linesOf(string $clubId): array
    {
        /** @var list<array{season_year: int|string, plan: string, tier: ?string, paid: bool, origin: string}> $rows */
        $rows = $this->admin->fetchAllAssociative(
            'SELECT cs.season_year, p.code AS plan, t.code AS tier, (cs.paid_at IS NOT NULL) AS paid, cs.origin FROM club_subscription cs JOIN subscription_plan p ON p.id = cs.plan_id LEFT JOIN subscription_plan_tier t ON t.id = cs.tier_id WHERE cs.club_id = ? ORDER BY cs.season_year',
            [$clubId],
        );

        return array_map(static fn (array $row): array => [
            'season_year' => (int) $row['season_year'],
            'plan' => $row['plan'],
            'tier' => $row['tier'],
            'paid' => (bool) $row['paid'],
            'origin' => $row['origin'],
        ], $rows);
    }
}
