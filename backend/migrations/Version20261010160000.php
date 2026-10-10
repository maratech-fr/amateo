<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PR C « vacances : contraintes de saison OFF par défaut » (décision fondateur 2026-10-10) —
 * FIGE l'état effectif des plans de période de VACANCES déjà nés.
 *
 * Le défaut change : dans un plan de période dont l'entrée est HOLIDAY, les contraintes
 * PERMANENTES de saison de portée ÉQUIPE et COACH (scope TEAM/COACH) passent de « héritées
 * par défaut » à « désactivées par défaut ». Pour que les plans DÉJÀ existants ne voient pas
 * leur comportement bouger sous les pieds du gestionnaire, on matérialise leur état effectif
 * AVANT bascule en overrides explicites `is_active = true` : chaque permanente TEAM/COACH du
 * même club+saison, sans override déjà posé sur ce plan, reçoit un override « gardée ».
 *
 * Portée stricte :
 *   - seuls les plans dont l'entrée de calendrier est HOLIDAY (CLOSURE inchangée : elle hérite
 *     déjà tout, défaut non modifié) ;
 *   - seules les contraintes PERMANENTES (`calendar_entry_id IS NULL`) — les datées, posées DANS
 *     un plan, ne sont pas concernées par le défaut ;
 *   - seules les portées TEAM et COACH — FACILITY était DÉJÀ OFF par défaut (état effectif
 *     inchangé, aucun override à poser), et CLUB reste ON (inchangé) ;
 *   - jointure stricte club ET saison entre le plan et la contrainte (aucun RLS en migration) ;
 *   - jamais là où un override existe déjà (le geste explicite du gestionnaire prime).
 *
 * Un override `is_active = true` sur une contrainte TEAM d'une équipe mise EN PAUSE pour la
 * période reste neutre : le sélecteur sort de toute façon la contrainte d'une équipe désactivée
 * (`PeriodConstraintSelector`), override ou non — l'état effectif demeure identique.
 *
 * IDEMPOTENTE : `NOT EXISTS` + `ON CONFLICT (schedule_plan_id, constraint_id) DO NOTHING`
 * (l'unique `uniq_constraint_period_override`). Rejouée, elle n'insère rien de plus.
 *
 * Tourne sur la connexion applicative (amateo_owner) : aucun secret, pur DML.
 */
final class Version20261010160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PR C vacances OFF: freeze existing HOLIDAY period plans by materializing is_active=true overrides for permanent TEAM/COACH season constraints (idempotent).';
    }

    public function up(Schema $schema): void
    {
        // gen_random_uuid() est disponible en PostgreSQL ≥ 13 (fonction interne) et déjà employé
        // par d'autres migrations du dépôt. version=1, created_at/updated_at = now().
        $this->addSql(<<<'SQL'
            INSERT INTO constraint_period_override (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, constraint_id, is_active)
            SELECT gen_random_uuid(), 1, now(), now(), c.club_id, c.season_id, sp.id, c.id, true
            FROM schedule_plan sp
            JOIN calendar_entry ce ON ce.id = sp.calendar_entry_id
            JOIN "constraint" c ON c.club_id = sp.club_id AND c.season_id = sp.season_id
            WHERE sp.calendar_entry_id IS NOT NULL
              AND ce.period_type = 'holiday'
              AND c.calendar_entry_id IS NULL
              AND c.scope IN ('TEAM', 'COACH')
              AND NOT EXISTS (
                  SELECT 1 FROM constraint_period_override o
                  WHERE o.schedule_plan_id = sp.id AND o.constraint_id = c.id
              )
            ON CONFLICT (schedule_plan_id, constraint_id) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Irréversible À DESSEIN : les overrides posés ici ont EXACTEMENT la forme de ceux qu'un
        // gestionnaire crée à la main pour garder une contrainte d'équipe pendant des vacances
        // (`is_active = true`, même plan, même contrainte). Rien dans la ligne ne distingue
        // « posé par la migration » de « posé par un humain » — un DELETE en masse effacerait des
        // choix légitimes. On refuse donc d'annuler plutôt que de détruire une intention.
        $this->throwIrreversibleMigration(
            'Les overrides « gardée » posés par cette migration sont indistinguables de ceux saisis à la main : les retirer effacerait des choix du gestionnaire. Rollback non supporté.',
        );
    }
}
