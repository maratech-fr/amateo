<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BCK-11 — `team_tag_assignment` rejoint le régime tenant : colonne `club_id` + RLS.
 *
 * C'était le SEUL objet lié à un tenant sans `club_id`, donc le seul sans backstop
 * base de données : son isolation reposait entièrement sur le filtre Doctrine de
 * saison. Ça tenait — mais une lecture hors filtre (worker, commande, requête
 * forgée) voyait toutes les assignations de tous les clubs, là où la même erreur
 * sur n'importe quelle autre table est arrêtée par PostgreSQL.
 *
 * Trois temps dans la MÊME migration : colonne nullable → **backfill depuis
 * `team.club_id`** (la table a déjà des lignes) → `SET NOT NULL`, puis le patron
 * RLS habituel (GRANT + ENABLE/FORCE + policy `tenant_isolation`).
 *
 * ⚠ NON rétro-compatible une release en arrière, et c'est ASSUMÉ (décision
 * fondateur 2026-08-07) : la convention de `docs/ops/deploy.md` §Règle d'écriture
 * des migrations protège une production qui n'existe pas encore — on est en V0,
 * développement pur. L'ancien code insérerait sans `club_id` et violerait le
 * NOT NULL pendant les quelques secondes de bascule. Le jour où il y a des
 * clients, ce genre de changement se fait en deux releases (colonne nullable +
 * écrivains, puis NOT NULL + RLS).
 */
final class Version20260807170000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'BCK-11: team_tag_assignment gains club_id (backfilled) and comes under RLS.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_tag_assignment ADD club_id UUID DEFAULT NULL');
        // Backfill : l'équipe EST la source du club — une assignation ne peut pas
        // appartenir à un autre club que celui de son équipe.
        $this->addSql('UPDATE team_tag_assignment a SET club_id = t.club_id FROM team t WHERE t.id = a.team_id');
        // Ceinture : une assignation dont l'équipe a disparu n'a plus de tenant
        // possible et ne peut pas passer le NOT NULL. Elle est déjà orpheline —
        // la supprimer est le seul état cohérent (et ne perd rien de réel).
        $this->addSql('DELETE FROM team_tag_assignment WHERE club_id IS NULL');
        $this->addSql('ALTER TABLE team_tag_assignment ALTER COLUMN club_id SET NOT NULL');
        $this->addSql('CREATE INDEX idx_team_tag_assignment_club ON team_tag_assignment (club_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON team_tag_assignment TO ' . $appRole);
            $this->addSql('ALTER TABLE public.team_tag_assignment ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.team_tag_assignment FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.team_tag_assignment FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.team_tag_assignment');
            $this->addSql('ALTER TABLE public.team_tag_assignment NO FORCE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.team_tag_assignment DISABLE ROW LEVEL SECURITY');
        }
        $this->addSql('DROP INDEX IF EXISTS idx_team_tag_assignment_club');
        $this->addSql('ALTER TABLE team_tag_assignment DROP COLUMN club_id');
    }
}
