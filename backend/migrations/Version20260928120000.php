<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-267 C1 — l'adresse postale d'un gymnase adverse sur la fiche d'un match à l'extérieur.
 *
 * On ajoute trois colonnes NULLABLES sur `opponent_venue_link` (tenant, RLS, club-scoped) :
 * `address` (rue), `postal_code`, `city`. Elles portent le SNAPSHOT fédéral affiché sur la fiche
 * lecture-seule d'un extérieur ({@see App\Service\OpponentTravelProjection}) — renseignées à
 * l'appariement (recherche FFBB / suggestion) et à l'auto-localisation quand la donnée fédérale les
 * porte. Les liens EXISTANTS restent à NULL (pas de rattrapage) : la fiche montre alors le libellé
 * seul, jamais une ligne d'adresse vide.
 *
 * RLS : la table est déjà sous `tenant_isolation` (clé `club_id`) et les GRANT sont au grain TABLE
 * (SELECT/INSERT/UPDATE/DELETE) — l'ajout de colonnes nullables n'exige AUCUN changement de policy
 * ni de grant. Aucune donnée fédérale d'affichage n'entre dans la table PARTAGÉE
 * `opponent_venue_suggestion` : ces colonnes restent propres au lien tenant.
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal reste < 4.5
 * (backend.md).
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'opponent_venue_link.address/postal_code/city (nullables) : snapshot fédéral d\'adresse affiché sur la fiche d\'un match à l\'extérieur ; liens existants laissés à NULL (pas de rattrapage).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE opponent_venue_link ADD address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE opponent_venue_link ADD postal_code VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE opponent_venue_link ADD city VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE opponent_venue_link DROP address');
        $this->addSql('ALTER TABLE opponent_venue_link DROP postal_code');
        $this->addSql('ALTER TABLE opponent_venue_link DROP city');
    }
}
