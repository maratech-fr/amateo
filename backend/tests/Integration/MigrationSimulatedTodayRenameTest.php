<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261002090000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * NR — le renommage `club.demo_today` → `club.simulated_today` est un RENAME COLUMN
 * sans perte : la date posée (la démo BCCL vit à une date simulée) survit dans les
 * DEUX sens.
 *
 * Le schéma de la base de test porte DÉJÀ `simulated_today` (la migration est
 * appliquée). On exerce donc le VRAI SQL de la migration (via `getSql()`, jamais une
 * copie) en aller-retour : `down()` revient à `demo_today`, `up()` repart à
 * `simulated_today`. La valeur posée doit se relire à l'identique après chaque sens,
 * ce qui prouve que le rename ne reconstruit ni ne vide la colonne.
 *
 * ⚠ Un ALTER TABLE exige le propriétaire : on passe par la connexion `admin`
 * (amateo_owner, hors RLS — comme les migrations réelles), PAS la connexion applicative.
 * Comme cette connexion n'est PAS enveloppée par le rollback dama, tout vit dans une
 * transaction OUVERTE ET ANNULÉE ici même (`finally`) : rien n'est committé, la base de
 * test partagée finit EXACTEMENT comme la branche l'attend (schéma `simulated_today`).
 */
#[Group('integration')]
final class MigrationSimulatedTodayRenameTest extends KernelTestCase
{
    public function testThePinnedDateSurvivesTheRenameInBothDirections(): void
    {
        self::bootKernel();
        $admin = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $admin);

        $admin->beginTransaction();

        try {
            $clubId = $this->seedClub($admin, '2026-12-15');

            $this->runMigration($admin, fn (Version20261002090000 $m) => $m->down(new Schema));
            self::assertSame(
                '2026-12-15',
                $admin->fetchOne('SELECT demo_today FROM club WHERE id = ?', [$clubId]),
                'la date posée survit au retour vers demo_today',
            );

            $this->runMigration($admin, fn (Version20261002090000 $m) => $m->up(new Schema));
            self::assertSame(
                '2026-12-15',
                $admin->fetchOne('SELECT simulated_today FROM club WHERE id = ?', [$clubId]),
                'la date posée survit au renommage vers simulated_today',
            );
        } finally {
            // Jamais de commit : l'aller-retour DDL et le club jetable disparaissent.
            $admin->rollBack();
        }
    }

    /**
     * Exécute un sens de la migration sur une instance NEUVE (chaque Version accumule
     * son plan), donc on joue exactement ce que la migration déclare.
     *
     * @param callable(Version20261002090000): void $direction
     */
    private function runMigration(Connection $admin, callable $direction): void
    {
        $migration = new Version20261002090000($admin, new NullLogger);
        $direction($migration);
        foreach ($migration->getSql() as $query) {
            $admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function seedClub(Connection $admin, string $simulatedToday): string
    {
        $id = Uuid::v4()->toRfc4122();
        $admin->insert('club', [
            'id' => $id,
            'created_at' => '2026-01-01 00:00:00+00',
            'updated_at' => '2026-01-01 00:00:00+00',
            'name' => 'Migration test',
            'slug' => 'migration-' . bin2hex(random_bytes(4)),
            'generation_count_season' => 0,
            'timezone' => 'Europe/Paris',
            'locale' => 'fr',
            'onboarding_completed' => true,
            'is_demo' => true,
            'simulated_today' => $simulatedToday,
        ], [
            'onboarding_completed' => ParameterType::BOOLEAN,
            'is_demo' => ParameterType::BOOLEAN,
        ]);

        return $id;
    }
}
