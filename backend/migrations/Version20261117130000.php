<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Occupations de gymnase — `venue_occupancy` : un intervalle nommé où le gymnase n'est pas au club
 * (« École », mardi 14:00-16:15), défini comme un créneau (jour, début, durée) et rangé dans la même
 * grille (`schedule_plan_id` nul = saison, posé = copie d'un plan de période). Information seulement :
 * aucun lecteur de la grille de créneaux ne la voit.
 *
 * Clé étrangère `schedule_plan_id → schedule_plan(id) ON DELETE CASCADE` posée validée d'un seul
 * geste (table vide) : les occupations d'une période partent avec son plan. AUCUNE clé étrangère sur
 * `venue_id` : une occupation dont le gymnase est absent de la version chargée dort et revient avec lui.
 *
 * RLS : patron Version20261103140000 — GRANT + ENABLE/FORCE + `tenant_isolation`, porte
 * `admin_all`, policy `readonly_tenant` du rôle lecture seule, posés à la création.
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261117130000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'venue_occupancy: named intervals when a venue is not the club\'s (tenant, RLS + admin door + read-only policy, cascade with its period plan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET lock_timeout = \'5s\'');
        $this->addSql('CREATE TABLE venue_occupancy (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE NOT NULL, duration_minutes INT NOT NULL, label VARCHAR(40) NOT NULL, schedule_plan_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_venue_occupancy_club_venue ON venue_occupancy (club_id, venue_id)');
        $this->addSql('CREATE INDEX idx_venue_occupancy_schedule_plan ON venue_occupancy (schedule_plan_id)');
        $this->addSql('ALTER TABLE venue_occupancy ADD CONSTRAINT fk_venue_occupancy_schedule_plan FOREIGN KEY (schedule_plan_id) REFERENCES schedule_plan (id) ON DELETE CASCADE');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON venue_occupancy TO ' . $appRole);
            $this->addSql('ALTER TABLE public.venue_occupancy ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.venue_occupancy FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.venue_occupancy FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.venue_occupancy FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : aucun secret (gymnase, horaire, motif).
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.venue_occupancy TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.venue_occupancy FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS readonly_tenant ON public.venue_occupancy');
        $this->addSql('DROP POLICY IF EXISTS admin_all ON public.venue_occupancy');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.venue_occupancy');
        $this->addSql('DROP TABLE venue_occupancy');
    }
}
