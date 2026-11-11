<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Offres : grille payante 20 / 34 / 35+. Club monte à 34 équipes, Grand club devient
 * illimité (0 = illimité), le palier `sans-limite` disparaît.
 *
 * Ordre obligatoire : les clubs `sans-limite` passent Grand club (saison réglée INTACTE)
 * AVANT la suppression de la ligne — la FK `club.plan_id` est ON DELETE SET NULL, et
 * plan_id NULL = Découverte : supprimer d'abord renverrait ces clubs au gratuit en silence.
 * Pour la même raison, sans Grand club en base la migration s'arrête (le déploiement aussi).
 *
 * down() réinsère `sans-limite` et remet Club 30 / Grand club 50, sans redéplacer les clubs :
 * un ex-`sans-limite` reste Grand club, désormais plafonné à 50.
 */
final class Version20261111120000 extends AbstractMigration
{
    private const string CLUB_PLAN = '00000000-0000-4000-8000-000000000003';
    private const string GRAND_CLUB_PLAN = '00000000-0000-4000-8000-000000000004';
    private const string SANS_LIMITE_PLAN = '00000000-0000-4000-8000-000000000005';

    public function getDescription(): string
    {
        return 'Offres : grille 20 / 34 / 35+ — Club 34, Grand club illimité, sans-limite retiré et ses clubs passés Grand club';
    }

    public function up(Schema $schema): void
    {
        $grandClub = $this->connection->fetchOne(
            'SELECT id FROM subscription_plan WHERE id = :grandClub AND code = :code',
            ['grandClub' => self::GRAND_CLUB_PLAN, 'code' => 'grand-club'],
        );
        $this->abortIf(false === $grandClub, 'Offre Grand club absente : les clubs « sans-limite » retomberaient sur Découverte. Migration refusée.');

        $this->addSql('UPDATE subscription_plan SET max_teams = 34 WHERE id = :club', ['club' => self::CLUB_PLAN]);
        $this->addSql('UPDATE subscription_plan SET max_teams = 0 WHERE id = :grandClub', ['grandClub' => self::GRAND_CLUB_PLAN]);
        $this->addSql(
            'UPDATE club SET plan_id = :grandClub WHERE plan_id = :sansLimite',
            ['grandClub' => self::GRAND_CLUB_PLAN, 'sansLimite' => self::SANS_LIMITE_PLAN],
        );
        $this->addSql('DELETE FROM subscription_plan WHERE id = :sansLimite', ['sansLimite' => self::SANS_LIMITE_PLAN]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO subscription_plan '
            . '(id, version, created_at, updated_at, code, name, max_teams, max_venues, max_generations, features) '
            . 'VALUES (:id, 1, NOW(), NOW(), :code, :name, 0, 0, 0, :features) '
            . 'ON CONFLICT (id) DO NOTHING',
            ['id' => self::SANS_LIMITE_PLAN, 'code' => 'sans-limite', 'name' => 'Sans limite', 'features' => '[]'],
        );
        $this->addSql('UPDATE subscription_plan SET max_teams = 30 WHERE id = :club', ['club' => self::CLUB_PLAN]);
        $this->addSql('UPDATE subscription_plan SET max_teams = 50 WHERE id = :grandClub', ['grandClub' => self::GRAND_CLUB_PLAN]);
    }
}
