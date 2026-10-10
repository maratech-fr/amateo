<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-63 volet B — `coach_wish.keep_season_slots` : le coach souhaite GARDER ses créneaux
 * habituels (ceux du planning de saison) pour cette semaine de vacances.
 *
 * Booléen NU (défaut false) : la page publique n'expose JAMAIS les horaires de saison, juste
 * ce drapeau. Transmis et AFFICHÉ (pastille côté gestionnaire) ; la contrainte qui l'honore
 * est créée au transfert vers le planning (étape ultérieure), pas ici — aucun effet solveur.
 *
 * AUCUNE policy ni GRANT à poser : `coach_wish` porte déjà `tenant_isolation FOR ALL`
 * (couvre toute colonne de la ligne) et un `GRANT SELECT` de table ENTIÈRE à `amateo_read`
 * (patron catalogue, Version20260930090000) — une colonne nouvelle en hérite sans action.
 * `keep_season_slots` ne matche aucun motif de secret (ReadOnlyRoleTest).
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261010100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-63 B: coach_wish.keep_season_slots (boolean, default false; covered by existing FOR ALL policy and table-wide read grant).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_wish ADD keep_season_slots BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_wish DROP keep_season_slots');
    }
}
