<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plafond de séances par jour et par équipe (1 à 4) :
 *  - `team.max_sessions_per_day` : la valeur de saison, 1 par défaut (le comportement d'avant :
 *    une seule séance par jour) ;
 *  - `team_period_override.max_sessions_per_day` : la valeur d'une période, NULL = celle de la saison.
 *
 * Aucune donnée existante à reprendre. RLS déjà posée sur les deux tables.
 */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team.max_sessions_per_day (1..4, default 1) and team_period_override.max_sessions_per_day (NULL = season).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD max_sessions_per_day SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE team ADD CONSTRAINT chk_team_max_sessions_per_day CHECK (max_sessions_per_day BETWEEN 1 AND 4)');
        $this->addSql('ALTER TABLE team_period_override ADD max_sessions_per_day SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE team_period_override ADD CONSTRAINT chk_team_period_override_max_sessions_per_day CHECK (max_sessions_per_day IS NULL OR max_sessions_per_day BETWEEN 1 AND 4)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_period_override DROP CONSTRAINT chk_team_period_override_max_sessions_per_day');
        $this->addSql('ALTER TABLE team_period_override DROP max_sessions_per_day');
        $this->addSql('ALTER TABLE team DROP CONSTRAINT chk_team_max_sessions_per_day');
        $this->addSql('ALTER TABLE team DROP max_sessions_per_day');
    }
}
