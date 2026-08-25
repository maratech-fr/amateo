<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RMM-6 (P2-50) — échéances ligue/comité : la colonne club `competition.entry_deadline`
 * (date nullable, hors CRUD, écrite par le seul endpoint bulk) + la table PARTAGÉE
 * `shared_competition_deadline` (défaut communautaire surchargeable).
 *
 * `shared_competition_deadline` est une table de RÉFÉRENCE GLOBALE — AUCUNE colonne
 * club-identifiante (pas de club_id, pas de user_id, pas de compteur — par conception,
 * EntryDeadlineShareTest l'assertionne sur le catalogue Postgres). Donc PAS de RLS
 * (patron ffbb_league / league_match_window) : RlsIsolationTest l'ignore (il n'énumère
 * que les tables portant `club_id`). Elle est keyée sur l'id FFBB de compétition, déjà
 * scopé saison par la fédération — pas de colonne saison. GRANT explicite à app_user
 * (comme match_slot_rotation) ; les ALTER DEFAULT PRIVILEGES la couvriraient de toute
 * façon, mais l'explicite survit à un PG managé.
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal reste
 * < 4.5 (backend.md).
 */
final class Version20260825140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RMM-6: competition.entry_deadline column + shared_competition_deadline reference table (global, no RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition ADD entry_deadline DATE DEFAULT NULL');

        $this->addSql('CREATE TABLE shared_competition_deadline (id UUID NOT NULL, ffbb_competition_id VARCHAR(64) NOT NULL, entry_deadline DATE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_shared_competition_deadline_ffbb ON shared_competition_deadline (ffbb_competition_id)');

        // Table GLOBALE (aucune donnée club-identifiante) → pas de RLS ; seul le GRANT
        // runtime est nécessaire pour que app_user la lise et l'écrive.
        $hasAppUser = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');
        if ($hasAppUser) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON shared_competition_deadline TO app_user');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared_competition_deadline');
        $this->addSql('ALTER TABLE competition DROP entry_deadline');
    }
}
