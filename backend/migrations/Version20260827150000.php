<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-54 RMM-9 PR-2 — l'annuaire adverse GLOBAL : la table `opponent_directory`
 * cache la localisation d'un adversaire FFBB (salle ou ville), keyée sur son
 * code organisme fédéral, partagée entre clubs.
 *
 * `opponent_directory` est une table de RÉFÉRENCE GLOBALE — AUCUNE colonne
 * club-identifiante (pas de club_id, pas de user_id, pas de compteur, pas de
 * provenance — par conception, OpponentDirectoryShareTest l'assertionne sur le
 * catalogue Postgres). Donc PAS de RLS (patron ffbb_league /
 * shared_competition_deadline) : RlsIsolationTest l'ignore (il n'énumère que les
 * tables portant `club_id`). Keyée sur le code organisme (public, fédéral), sans
 * colonne saison : une salle/ville est indépendante de la saison.
 *
 * GRANT explicite à app_user SANS DELETE (corollaire F-2, patron
 * `shared_competition_deadline`) : sur une table SANS RLS le GRANT est la seule
 * couche DB, on ne donne que le nécessaire — l'upsert exige SELECT+INSERT+UPDATE,
 * aucun chemin de code ne supprime une ligne partagée (table publique-seulement).
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal
 * reste < 4.5 (backend.md).
 */
final class Version20260827150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-54 RMM-9 PR-2: opponent_directory global reference table (opponent locations, no RLS, no DELETE grant).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE opponent_directory (id UUID NOT NULL, ffbb_organisme_code VARCHAR(64) NOT NULL, name VARCHAR(180) NOT NULL, city VARCHAR(180) DEFAULT NULL, postal_code VARCHAR(16) DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, precision VARCHAR(8) NOT NULL, venue_label VARCHAR(180) DEFAULT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_opponent_directory_code ON opponent_directory (ffbb_organisme_code)');

        // Table GLOBALE (aucune donnée club-identifiante) → pas de RLS ; seul le GRANT
        // borne app_user. PAS de DELETE (corollaire F-2) : aucun chemin de code ne
        // supprime une ligne partagée — sur une table SANS RLS, le GRANT est la seule
        // couche DB, on ne donne que le nécessaire (l'upsert exige SELECT+INSERT+UPDATE).
        // ⚠ Le REVOKE est INDISPENSABLE : l'`ALTER DEFAULT PRIVILEGES` de la 20260703120000
        // confère DÉJÀ SELECT+INSERT+UPDATE+DELETE à app_user sur toute table neuve — un GRANT
        // restreint ne RETIRE pas le DELETE conféré par défaut ; il faut le révoquer.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE ON opponent_directory TO ' . $appRole);
            $this->addSql('REVOKE DELETE ON opponent_directory FROM ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE opponent_directory');
    }
}
