<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-271 — la semaine type passe à deux valeurs (A/B) et le club porte son propre
 * réglage d'affichage « modèle de week-end sur deux semaines ».
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5),
 * idempotente, RLS inchangée (elle tourne sur la connexion propriétaire). L'ORDRE
 * des étapes est SIGNIFIANT et ne doit pas bouger :
 *   (a) `club.weekend_alternates` BOOLEAN NOT NULL DEFAULT FALSE ;
 *   (b) on ALLUME le réglage pour tout club qui possédait DÉJÀ un créneau idéal tagué
 *       A ou B — la trace que le gestionnaire se servait de l'alternance. DOIT passer
 *       AVANT (c) : une fois les `ALL` convertis en `A`, la distinction est perdue et
 *       tous les clubs sembleraient utiliser l'alternance ;
 *   (c) les créneaux restés `ALL` (aucune alternance) retombent en `A` ;
 *   (d) le défaut de la colonne `week` passe de `ALL` à `A`.
 */
final class Version20260930100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-271: club.weekend_alternates flag + team_match_habit.week collapses ALL to A (two-week A/B model).';
    }

    public function up(Schema $schema): void
    {
        // (a) Nouveau réglage d'affichage du club — faux par défaut.
        $this->addSql('ALTER TABLE club ADD COLUMN IF NOT EXISTS weekend_alternates BOOLEAN NOT NULL DEFAULT FALSE');

        // (b) AVANT toute conversion : allumer le réglage pour les clubs qui utilisaient
        //     déjà l'alternance (au moins un créneau idéal tagué A ou B).
        $this->addSql(<<<'SQL'
            UPDATE club SET weekend_alternates = TRUE
            WHERE EXISTS (
                SELECT 1 FROM team_match_habit h
                WHERE h.club_id = club.id AND h.week IN ('A', 'B')
            )
            SQL);

        // (c) Les créneaux sans alternance (« ALL ») retombent sur la semaine A.
        $this->addSql('UPDATE team_match_habit SET week = \'A\' WHERE week = \'ALL\'');

        // (d) Le défaut de la colonne suit le nouveau modèle.
        $this->addSql('ALTER TABLE team_match_habit ALTER COLUMN week SET DEFAULT \'A\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_match_habit ALTER COLUMN week SET DEFAULT \'ALL\'');
        $this->addSql('ALTER TABLE club DROP COLUMN IF EXISTS weekend_alternates');
    }
}
