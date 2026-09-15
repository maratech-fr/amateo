<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-54 « adversaire multi-gymnases » PR-1 — le trajet adverse gagne le GRAIN ÉQUIPE.
 *
 * `opponent_travel.opponent_team_key` (VARCHAR(180) nullable) : le libellé de rencontre
 * NORMALISÉ quand la ligne surcharge UNE équipe d'un même organisme adverse ; NULL = la
 * ligne CLUB (le défaut, comportement historique). Une ligne équipe = surcharge pour les
 * rencontres dont le libellé normalisé = ce teamKey ; absence de ligne équipe → défaut du
 * club ; absence de ligne club → annuaire global.
 *
 * L'unicité passe de (club, saison, code) à (club, saison, code, team_key) en
 * `NULLS NOT DISTINCT` (PG 16) : deux lignes CLUB (team_key NULL) du même code restent
 * interdites, tout comme deux lignes de la même équipe. Les lignes existantes gardent leur
 * team_key NULL = lignes CLUB — AUCUNE migration de données.
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal reste < 4.5
 * (backend.md).
 */
final class Version20260915130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-54 adversaire multi-gymnages PR-1: opponent_travel.opponent_team_key + unicité (club, saison, code, team_key) NULLS NOT DISTINCT.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE opponent_travel ADD opponent_team_key VARCHAR(180) DEFAULT NULL');
        $this->addSql('DROP INDEX uniq_opponent_travel_code');
        $this->addSql('CREATE UNIQUE INDEX uniq_opponent_travel_team ON opponent_travel (club_id, season_id, opponent_organisme_code, opponent_team_key) NULLS NOT DISTINCT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_opponent_travel_team');
        $this->addSql('CREATE UNIQUE INDEX uniq_opponent_travel_code ON opponent_travel (club_id, season_id, opponent_organisme_code)');
        $this->addSql('ALTER TABLE opponent_travel DROP COLUMN opponent_team_key');
    }
}
