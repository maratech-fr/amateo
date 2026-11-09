<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DROP de la table orpheline `venue_unavailability` : plus aucune entité ni lecture depuis la
 * release précédente (les indisponibilités de gymnase sont devenues des fermetures du calendrier).
 *
 * `up()` refuse tant qu'il reste une ligne d'un club HORS démo ou d'un club disparu (pas de FK
 * `club_id` : une ligne orpheline n'est pas une ligne de démo). Seules les lignes de clubs de démo
 * partent avec la table. Mode opératoire du refus : `docs/ops/deploy.md`.
 *
 * `down()` recrée la table VIDE (DDL de Version20260803090000), fermée comme ses sœurs vivantes :
 * GRANT + ENABLE/FORCE + `tenant_isolation`, porte `admin_all`, `readonly_tenant` du rôle lecture
 * seule. Le prédicat tenant est LU sur `team_tag.tenant_isolation` au moment du retour arrière,
 * jamais recopié : il suit le canon vivant ; sans policy de référence, refus avant toute création.
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime la table venue_unavailability, plus lue par l\'application : refusée tant qu\'un club hors démo (ou disparu) y garde une ligne.';
    }

    public function up(Schema $schema): void
    {
        // Exécuté tout de suite (pas addSql) dans la transaction de la migration : aucune ligne ne
        // peut naître entre le comptage et le DROP.
        $this->connection->executeStatement('LOCK TABLE venue_unavailability IN ACCESS EXCLUSIVE MODE');
        $remaining = (int) $this->connection->fetchOne('SELECT count(*) FROM venue_unavailability u LEFT JOIN club c ON c.id = u.club_id WHERE c.id IS NULL OR NOT c.is_demo');

        $this->abortIf(
            $remaining > 0,
            \sprintf(
                '%d indisponibilité(s) de gymnase d\'un club hors démo (ou d\'un club disparu) restent dans venue_unavailability : re-saisissez-les comme fermetures du calendrier, supprimez ces lignes, puis relancez la migration.',
                $remaining,
            ),
        );

        $this->addSql('DROP TABLE venue_unavailability');
    }

    public function down(Schema $schema): void
    {
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');
        $this->abortIf(
            !\is_string($appRole),
            'Aucun rôle applicatif trouvé : impossible de recréer venue_unavailability sous RLS.',
        );
        \assert(\is_string($appRole));

        $predicate = $this->connection->fetchOne('SELECT pg_get_expr(polqual, polrelid) FROM pg_policy WHERE polname = \'tenant_isolation\' AND polrelid = \'public.team_tag\'::regclass');
        $this->abortIf(
            !\is_string($predicate) || '' === $predicate,
            'Policy tenant_isolation de team_tag introuvable : le prédicat tenant de référence manque, la table venue_unavailability ne peut pas être recréée fermée.',
        );
        \assert(\is_string($predicate));

        $this->addSql('CREATE TABLE venue_unavailability (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, label VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_venue_unavailability_club_season ON venue_unavailability (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_venue_unavailability_venue ON venue_unavailability (venue_id)');

        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON venue_unavailability TO ' . $appRole);
        $this->addSql('ALTER TABLE public.venue_unavailability ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE public.venue_unavailability FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation ON public.venue_unavailability FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
            $predicate,
            $predicate,
        ));

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.venue_unavailability FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.venue_unavailability TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.venue_unavailability FOR SELECT TO amateo_read USING (%s)',
                $predicate,
            ));
        }
    }
}
