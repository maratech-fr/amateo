<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M15 (retours BCCL 2026-10-27) — une rencontre importée est identifiée par
 * équipe + DIVISION + numéro, plus par équipe + numéro seul (D1/D2) :
 *   - `fixture` gagne `external_division` VARCHAR(180) nullable (le libellé « Division »
 *     du fichier, non nul SSI `external_ref` l'est) ;
 *   - backfill des rencontres FBI déjà en base : `external_division` = nom du championnat
 *     (`competition.name`) — appariement 1:1 mesuré en prod (LINK : 0 rencontre external_ref
 *     sans compétition, backfill sûr) ;
 *   - l'index partiel unique `uniq_fixture_external_ref` est ÉLARGI pour inclure
 *     `external_division` : au 2ᵉ tour l'équipe change de poule/division et FBI réutilise ses
 *     numéros — sans la division, le n°X de la phase 2 réécrivait le n°X de la phase 1.
 *
 * La table est déjà tenant-owned sous FORCE RLS (migration de création) ; ajouter une colonne
 * nullable et re-créer un index partiel ne touche ni les politiques ni les GRANT (même idiome
 * que {@see Version20261027120000}).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5, piège zone).
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fixture: add external_division + backfill from competition.name + widen uniq_fixture_external_ref with external_division (M15 D1).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD external_division VARCHAR(180) DEFAULT NULL');
        $this->addSql('UPDATE fixture f SET external_division = c.name FROM competition c WHERE f.competition_id = c.id AND f.external_ref IS NOT NULL');
        $this->addSql('DROP INDEX uniq_fixture_external_ref');
        $this->addSql('CREATE UNIQUE INDEX uniq_fixture_external_ref ON fixture (club_id, season_id, team_id, external_division, external_ref) WHERE (external_ref IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_fixture_external_ref');
        $this->addSql('CREATE UNIQUE INDEX uniq_fixture_external_ref ON fixture (club_id, season_id, team_id, external_ref) WHERE (external_ref IS NOT NULL)');
        $this->addSql('ALTER TABLE fixture DROP external_division');
    }
}
