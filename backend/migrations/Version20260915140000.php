<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-54 « adversaire multi-gymnases » PR-2 — les SUGGESTIONS partagées de gymnases
 * par club adverse : la table `opponent_venue_suggestion` collectionne, keyée sur le
 * code organisme fédéral PUBLIC, les gymnases connus d'un adversaire (vus dans le
 * calendrier fédéral = `FFBB_API`, ou choisis par des clubs = `MANUAL`) avec un COMPTE
 * de clubs — « un compte, jamais un qui ».
 *
 * Table de RÉFÉRENCE GLOBALE — AUCUNE colonne club-identifiante (pas de club_id, pas de
 * user_id, pas de provenance — par conception, OpponentVenueSuggestionShareTest
 * l'assertionne sur le catalogue Postgres). Donc PAS de RLS (patron `opponent_directory`
 * / `shared_competition_deadline`) : RlsIsolationTest l'ignore (il n'énumère que les
 * tables portant `club_id`). Sans colonne saison : un gymnase d'organisme est
 * indépendant de la saison.
 *
 * Unicité en DEUX index partiels disjoints (le `numero` fédéral de salle n'existe QUE
 * sur les lignes MANUAL — le hit rencontre ne le porte pas, sondé 2026-09-15) :
 *   - `(code, venue_external_ref)` WHERE ref IS NOT NULL     → les lignes MANUAL ;
 *   - `(code, lower(venue_label))` WHERE ref IS NULL         → les lignes FFBB_API.
 *
 * GRANT explicite à app_user SANS DELETE (corollaire F-2, patron `opponent_directory`) :
 * sur une table SANS RLS le GRANT est la seule couche DB ; le runtime upsert/incrémente/
 * décrémente (SELECT+INSERT+UPDATE), aucun chemin de code ne SUPPRIME une suggestion
 * (A2 : une ligne à 0 reste).
 *
 * ⚠ PAS de backfill (revue sécurité 2026-09-15) : le plan A5 agrégeait les
 * `override_venue_label`/coordonnées de `opponent_travel` — or ce sont des valeurs
 * SAISIES par des clubs, interdites dans le partagé (« jamais de texte club »). Le
 * partagé ne reçoit que des données FÉDÉRALES re-résolues côté serveur au moment du
 * choix ({@see App\Service\Geo\OpponentTravelResolver::accountManualChoice}). Les
 * compteurs se reconstruisent organiquement au prochain choix de chaque club — pas de
 * ligne « compte sans libellé » à traîner (`venue_label` reste NOT NULL).
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal < 4.5.
 */
final class Version20260915140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-54 adversaire multi-gymnases PR-2: opponent_venue_suggestion global reference table (shared venue suggestions per opponent, no RLS, no DELETE grant; no backfill — shared rows carry FEDERAL data only).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE opponent_venue_suggestion ('
            . 'id UUID NOT NULL, '
            . 'ffbb_organisme_code VARCHAR(64) NOT NULL, '
            . 'venue_external_ref VARCHAR(64) DEFAULT NULL, '
            . 'venue_label VARCHAR(180) NOT NULL, '
            . 'city VARCHAR(180) DEFAULT NULL, '
            . 'postal_code VARCHAR(16) DEFAULT NULL, '
            . 'latitude DOUBLE PRECISION DEFAULT NULL, '
            . 'longitude DOUBLE PRECISION DEFAULT NULL, '
            . 'source VARCHAR(8) NOT NULL, '
            . 'chosen_by_count INT NOT NULL DEFAULT 0, '
            . 'last_chosen_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, '
            . 'created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, '
            . 'updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'CONSTRAINT ck_opponent_venue_suggestion_count_non_negative CHECK (chosen_by_count >= 0)'
            . ')',
        );
        // MANUAL : keyée sur le numéro de salle fédéral (présent seulement là).
        $this->addSql('CREATE UNIQUE INDEX uniq_opponent_venue_suggestion_ref ON opponent_venue_suggestion (ffbb_organisme_code, venue_external_ref) WHERE venue_external_ref IS NOT NULL');
        // FFBB_API : sans ref (le hit rencontre ne porte pas le numero) → dédup par libellé.
        $this->addSql('CREATE UNIQUE INDEX uniq_opponent_venue_suggestion_label ON opponent_venue_suggestion (ffbb_organisme_code, lower(venue_label)) WHERE venue_external_ref IS NULL');

        // Table GLOBALE (aucune donnée club-identifiante) → pas de RLS ; seul le GRANT
        // borne app_user. PAS de DELETE (corollaire F-2) : aucun chemin de code ne
        // supprime une suggestion — le runtime upsert/incrémente/décrémente seulement.
        // ⚠ Le REVOKE est INDISPENSABLE : l'`ALTER DEFAULT PRIVILEGES` de la 20260703120000
        // confère DÉJÀ SELECT+INSERT+UPDATE+DELETE à app_user sur toute table neuve — un GRANT
        // restreint ne RETIRE pas le DELETE conféré par défaut ; il faut le révoquer.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE ON opponent_venue_suggestion TO ' . $appRole);
            $this->addSql('REVOKE DELETE ON opponent_venue_suggestion FROM ' . $appRole);
        }

        // PAS de backfill : les override_venue_label/coords de opponent_travel sont
        // SAISIS par des clubs (interdits dans le partagé). Les compteurs se
        // reconstruisent au prochain choix, avec un libellé FÉDÉRAL re-résolu serveur.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE opponent_venue_suggestion');
    }
}
