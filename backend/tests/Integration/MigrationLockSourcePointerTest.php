<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261010170000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — la migration V1bis ajoute `schedule_plan.lock_source_schedule_id` : colonne UUID NULLABLE,
 * FK vers `schedule(id)` en ON DELETE SET NULL, indexée. Le point LOAD-BEARING est le ON DELETE
 * SET NULL : supprimer la version pointée doit RELÂCHER le pointeur (le repli « dernière COMPLETED »
 * reprend la main), jamais casser le plan ni laisser un pointeur pendouillant.
 *
 * On exerce le VRAI SQL de la migration (via `getSql()`, jamais une copie) en aller-retour sur la
 * base de test (qui porte déjà la colonne, la migration étant appliquée) : `down()` retire colonne/
 * FK/index, `up()` les repose. On vérifie ensuite, sur le schéma réel, que la colonne est nullable,
 * que l'index existe et que la FK est bien déclarée ON DELETE SET NULL (`pg_constraint.confdeltype
 * = 'n'`) vers `schedule`.
 *
 * ⚠ ALTER TABLE exige le propriétaire : connexion `admin` (amateo_owner, hors RLS), NON enveloppée
 * par le rollback DAMA — tout vit dans une transaction OUVERTE ET ANNULÉE ici même (`finally`), rien
 * n'est committé, la base de test partagée finit EXACTEMENT comme la branche l'attend.
 */
#[Group('integration')]
final class MigrationLockSourcePointerTest extends KernelTestCase
{
    public function testThePointerColumnIsNullableWithSetNullForeignKeyAndIndex(): void
    {
        self::bootKernel();
        $admin = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $admin);

        $admin->beginTransaction();

        try {
            // Aller-retour du VRAI SQL : la migration descend puis remonte sans erreur sur le schéma réel.
            $this->runMigration($admin, fn (Version20261010170000 $m) => $m->down(new Schema));
            self::assertFalse(
                $this->columnExists($admin),
                'down() retire bien la colonne lock_source_schedule_id',
            );

            $this->runMigration($admin, fn (Version20261010170000 $m) => $m->up(new Schema));
            self::assertTrue($this->columnExists($admin), 'up() repose la colonne');

            // Colonne UUID NULLABLE (null = aucun pointeur → repli sur la dernière COMPLETED).
            $nullable = $admin->fetchOne(
                'SELECT is_nullable FROM information_schema.columns WHERE table_name = \'schedule_plan\' AND column_name = \'lock_source_schedule_id\'',
            );
            self::assertSame('YES', $nullable, 'le pointeur est nullable');

            // Index présent (batch isLockSource du provider).
            self::assertSame(
                '1',
                (string) $admin->fetchOne('SELECT 1 FROM pg_indexes WHERE indexname = \'idx_schedule_plan_lock_source\''),
                'l\'index du pointeur existe',
            );

            // FK vers `schedule`, règle ON DELETE SET NULL : confdeltype 'n'. C'est l'invariant
            // qui garantit qu'une version supprimée relâche le pointeur plutôt que de pendouiller.
            $fk = $admin->fetchAssociative(
                'SELECT c.confdeltype, t.relname AS target FROM pg_constraint c '
                . 'JOIN pg_class t ON t.oid = c.confrelid '
                . 'WHERE c.conname = \'fk_schedule_plan_lock_source\'',
            );
            self::assertIsArray($fk, 'la FK du pointeur existe');
            self::assertSame('schedule', $fk['target'], 'la FK pointe vers schedule');
            self::assertSame('n', $fk['confdeltype'], 'ON DELETE SET NULL (confdeltype = n)');
        } finally {
            // Jamais de commit : l'aller-retour DDL disparaît, la base reste dans l'état de la branche.
            $admin->rollBack();
        }
    }

    private function columnExists(Connection $admin): bool
    {
        return '1' === (string) $admin->fetchOne(
            'SELECT 1 FROM information_schema.columns WHERE table_name = \'schedule_plan\' AND column_name = \'lock_source_schedule_id\'',
        );
    }

    /**
     * Exécute un sens de la migration sur une instance NEUVE (chaque Version accumule son plan),
     * donc on joue exactement ce que la migration déclare.
     *
     * @param callable(Version20261010170000): void $direction
     */
    private function runMigration(Connection $admin, callable $direction): void
    {
        $migration = new Version20261010170000($admin, new NullLogger);
        $direction($migration);
        foreach ($migration->getSql() as $query) {
            $admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
