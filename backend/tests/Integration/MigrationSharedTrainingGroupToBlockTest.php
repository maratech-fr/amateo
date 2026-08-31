<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260831130000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * NR — la migration de retrait P2-51 PR-7 CONVERTIT avant de supprimer : chaque
 * `shared_training_group` (+ ses membres) devient un `shared_training_block` À L'IDENTIQUE (mêmes
 * id, équipes, `commonSessions`, portée socle/période) AVANT le `DROP` des tables K. C'est ce qui
 * laisse les bases locales du fondateur (`amateo_dev`, `amateo_local`) garder leurs mutualisations
 * sans reseed.
 *
 * On exécute le VRAI SQL de la migration (`getSql()`, jamais une copie). La migration DROP des
 * tables : ça exige le propriétaire — on passe donc par la connexion `admin` (amateo_owner,
 * superuser), exactement comme les migrations en production. Les tables K ayant DÉJÀ été supprimées
 * par la migration sur la base de test, on les recrée ici pour rejouer la conversion, puis on nettoie
 * (la connexion admin n'est pas dans la transaction DAMA du test).
 */
#[Group('integration')]
final class MigrationSharedTrainingGroupToBlockTest extends KernelTestCase
{
    private const string CLUB = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string SEASON = 'aaaaaaaa-0000-4000-8000-000000000002';

    private Connection $admin;

    public function testGroupsAreConvertedToBlocksIdenticallyThenTheKTablesAreDropped(): void
    {
        $planId = Uuid::v4()->toRfc4122();
        $socleGroupId = Uuid::v4()->toRfc4122();
        $periodGroupId = Uuid::v4()->toRfc4122();
        $t1 = Uuid::v4()->toRfc4122();
        $t2 = Uuid::v4()->toRfc4122();
        $t3 = Uuid::v4()->toRfc4122();

        // Un groupe SOCLE {t1, t2} K=3 (schedule_plan_id NULL) et un groupe de PÉRIODE {t2, t3} K=2.
        $this->insertGroup($socleGroupId, null, 3, [$t1, $t2]);
        $this->insertGroup($periodGroupId, $planId, 2, [$t2, $t3]);

        // Le VRAI SQL de la migration (2 INSERT de conversion + 2 DROP), joué sous le propriétaire.
        $migration = new Version20260831130000($this->admin, new NullLogger);
        $migration->up(new Schema);
        foreach ($migration->getSql() as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        // Chaque groupe est devenu un bloc À L'IDENTIQUE : même id, même commonSessions, même portée.
        $socleBlock = $this->admin->fetchAssociative('SELECT club_id, season_id, schedule_plan_id, common_sessions FROM shared_training_block WHERE id = ?', [$socleGroupId]);
        self::assertIsArray($socleBlock, 'le groupe socle est converti en bloc, id repris');
        self::assertSame(self::CLUB, (string) $socleBlock['club_id']);
        self::assertSame(self::SEASON, (string) $socleBlock['season_id']);
        self::assertNull($socleBlock['schedule_plan_id'], 'portée SOCLE préservée (schedule_plan_id NULL)');
        self::assertSame(3, (int) $socleBlock['common_sessions'], 'commonSessions préservé');
        self::assertSame($this->sorted([$t1, $t2]), $this->blockMembers($socleGroupId), 'mêmes équipes membres, id repris');

        $periodBlock = $this->admin->fetchAssociative('SELECT schedule_plan_id, common_sessions FROM shared_training_block WHERE id = ?', [$periodGroupId]);
        self::assertIsArray($periodBlock, 'le groupe de période est converti en bloc');
        self::assertSame($planId, (string) $periodBlock['schedule_plan_id'], 'portée PÉRIODE préservée (schedule_plan_id = planId)');
        self::assertSame(2, (int) $periodBlock['common_sessions']);
        self::assertSame($this->sorted([$t2, $t3]), $this->blockMembers($periodGroupId), 'la multi-appartenance de t2 survit (deux blocs)');

        // Les tables K ont disparu — plus qu'UNE notion de mutualisation.
        self::assertNull($this->admin->fetchOne('SELECT to_regclass(\'public.shared_training_group\')'), 'la table shared_training_group est supprimée');
        self::assertNull($this->admin->fetchOne('SELECT to_regclass(\'public.shared_training_group_team\')'), 'la table shared_training_group_team est supprimée');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = self::getContainer()->get('doctrine')->getConnection('admin');

        // Les tables K ont été supprimées par la migration sur la base de test : on les recrée pour
        // rejouer la conversion (schéma de Version20260817120000/130000, colonne pour colonne).
        $this->dropKTables();
        $this->admin->executeStatement('CREATE TABLE shared_training_group (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID DEFAULT NULL, common_sessions INT NOT NULL, PRIMARY KEY (id))');
        $this->admin->executeStatement('CREATE TABLE shared_training_group_team (id UUID NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID DEFAULT NULL, group_id UUID NOT NULL, team_id UUID NOT NULL, PRIMARY KEY (id))');
    }

    protected function tearDown(): void
    {
        // La connexion admin n'est pas dans la transaction DAMA : on nettoie à la main. Les tables K
        // recréées finissent supprimées (état de production) ; les blocs convertis de CE club partent.
        $this->dropKTables();
        $this->admin->executeStatement('DELETE FROM shared_training_block_team WHERE club_id = ?', [self::CLUB]);
        $this->admin->executeStatement('DELETE FROM shared_training_block WHERE club_id = ?', [self::CLUB]);
        parent::tearDown();
    }

    private function dropKTables(): void
    {
        $this->admin->executeStatement('DROP TABLE IF EXISTS shared_training_group_team');
        $this->admin->executeStatement('DROP TABLE IF EXISTS shared_training_group');
    }

    /**
     * @param list<string> $teamIds
     */
    private function insertGroup(string $groupId, ?string $planId, int $commonSessions, array $teamIds): void
    {
        $this->admin->executeStatement(
            'INSERT INTO shared_training_group (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, common_sessions) VALUES (?, 1, NOW(), NOW(), ?, ?, ?, ?)',
            [$groupId, self::CLUB, self::SEASON, $planId, $commonSessions],
        );
        foreach ($teamIds as $teamId) {
            $this->admin->executeStatement(
                'INSERT INTO shared_training_group_team (id, club_id, season_id, schedule_plan_id, group_id, team_id) VALUES (?, ?, ?, ?, ?, ?)',
                [Uuid::v4()->toRfc4122(), self::CLUB, self::SEASON, $planId, $groupId, $teamId],
            );
        }
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function blockMembers(string $blockId): array
    {
        return array_map(
            static fn (mixed $id): string => (string) $id,
            $this->admin->fetchFirstColumn('SELECT team_id FROM shared_training_block_team WHERE block_id = ? ORDER BY team_id', [$blockId]),
        );
    }
}
