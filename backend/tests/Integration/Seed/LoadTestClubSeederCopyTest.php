<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seed;

use App\Plans\PlanStates;
use App\Seed\LoadTestClubSeeder;
use App\Seed\LoadTestClubSize;
use App\Tests\BuildsServiceWithDoublesTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse (lot 5, PR 2, ligne 6 du plan) : toute version terminée porte la copie du froid de son plan, graine de charge
 * comprise. Le club de charge semé (`LoadTestClubSeeder`, commande de dev `app:load-test:seed-clubs`) a son planning de
 * saison validé avec sa copie, égale à l'état du plan ; ré-semer le même club n'en écrit pas une seconde et ne lève pas.
 *
 * Jumeau, cité : `Integration/Seed/BcclSeederIdempotenceTest::assertEveryCompletedVersionCarriesItsCopy` (graine BCCL).
 * Même patron que lui : la graine exige la connexion superuser, donc un processus isolé et une transaction à nous,
 * annulée en fin de test (rien ne reste en base). La graine n'est un service que de l'environnement dev : elle est
 * construite ici sur les services du conteneur ({@see BuildsServiceWithDoublesTrait}, aucune doublure).
 */
#[Group('integration')]
final class LoadTestClubSeederCopyTest extends KernelTestCase
{
    use BuildsServiceWithDoublesTrait;

    private Connection $connection;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSeededSeasonVersionCarriesItsCopyAndAReseedKeepsOne(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $seeder = $this->buildWithDoubles(LoadTestClubSeeder::class, []);

        $club = $seeder->run($em, 97, LoadTestClubSize::SMALL);
        $versions = $this->completedVersionsOf($club->getId());
        self::assertNotSame([], $versions, 'témoin : le club de charge a une version de saison terminée');
        self::assertSame([], $this->withoutExactlyOneCopy($versions), 'la version semée porte une copie, une seule');
        $states = self::getContainer()->get(PlanStates::class);
        foreach ($versions as $row) {
            self::assertTrue($states->of($row['id'])->changesTo($states->capture($row['planId']))->isEmpty(), 'la copie est l\'état du plan au moment du seed');
        }

        $seeder->run($em, 97, LoadTestClubSize::SMALL);
        self::assertSame([], $this->withoutExactlyOneCopy($this->completedVersionsOf($club->getId())), 'ré-semer garde une copie par version, une seule');
    }

    protected function setUp(): void
    {
        $adminUrl = $_SERVER['DATABASE_ADMIN_URL'] ?? getenv('DATABASE_ADMIN_URL');
        self::assertNotFalse($adminUrl, 'DATABASE_ADMIN_URL doit être défini pour seeder en superuser');
        $_SERVER['DATABASE_URL'] = $adminUrl;
        $_ENV['DATABASE_URL'] = $adminUrl;
        putenv('DATABASE_URL=' . $adminUrl);

        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('amateo_owner', $this->connection->fetchOne('SELECT current_user'));
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /** @return list<array{id: string, planId: string, copies: int}> */
    private function completedVersionsOf(string $clubId): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (string) $row['id'], 'planId' => (string) $row['schedule_plan_id'], 'copies' => (int) $row['copies']],
            $this->connection->fetchAllAssociative(
                'SELECT s.id, s.schedule_plan_id, (SELECT COUNT(*) FROM schedule_state c WHERE c.schedule_id = s.id) AS copies'
                . ' FROM schedule s WHERE s.club_id = ? AND s.status = \'COMPLETED\' ORDER BY s.id',
                [$clubId],
            ),
        );
    }

    /**
     * @param list<array{id: string, planId: string, copies: int}> $versions
     *
     * @return list<string>
     */
    private function withoutExactlyOneCopy(array $versions): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['id'],
            array_filter($versions, static fn (array $row): bool => 1 !== $row['copies']),
        ));
    }
}
