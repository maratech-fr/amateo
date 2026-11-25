<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La copie du froid d'une version (lot 5 conception, PR 2) : table `schedule_state`, une ligne par version.
 *
 * En une transaction :
 *  1. La table, son unicité sur la version et sa clé vers la version en cascade : la copie part avec sa version.
 *  2. RLS active et forcée (patron Version20261130120000).
 *
 * Elle ne copie rien : la capture des versions validées ou chargées est la commande de conversion
 * (`app:plans:capture-version-states`), jouée une fois par la procédure manuelle du lot, site fermé. Son heure
 * d'exécution (`doctrine_migration_versions.executed_at`) sépare les versions « nées avant » (capturées par la
 * commande) des versions nées après (copiées à leur naissance, jamais réparées). Irréversible.
 */
final class Version20261201120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'La copie du froid d\'une version : table schedule_state (une ligne par version, en cascade avec elle, RLS forcée).';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        // 1. La table.
        $this->addSql('CREATE TABLE schedule_state (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_id UUID NOT NULL, data JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_state_schedule ON schedule_state (schedule_id)');
        $this->addSql('CREATE INDEX idx_schedule_state_club_season ON schedule_state (club_id, season_id)');
        $this->addSql('ALTER TABLE schedule_state ADD CONSTRAINT fk_schedule_state_schedule FOREIGN KEY (schedule_id) REFERENCES schedule (id) ON DELETE CASCADE');

        // 2. RLS.
        $this->addSql('ALTER TABLE public.schedule_state ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE public.schedule_state FORCE ROW LEVEL SECURITY');
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON schedule_state TO ' . $appRole);
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.schedule_state FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'')) {
            $this->addSql('CREATE POLICY admin_all ON public.schedule_state FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'')) {
            $this->addSql('GRANT SELECT ON public.schedule_state TO amateo_read');
            $this->addSql(\sprintf('CREATE POLICY readonly_tenant ON public.schedule_state FOR SELECT TO amateo_read USING (%s)', self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les copies des versions ne se recréent pas en arrière : retour arrière par la sauvegarde d\'avant migration.',
        );
    }
}
