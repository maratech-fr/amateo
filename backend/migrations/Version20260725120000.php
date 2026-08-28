<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Doléances coachs (feature #10, lot C1).
 *
 * `coach_wish` — un souhait de coach par (période mère de vacances, équipe, semaine) :
 * nombre de créneaux voulus, jours indisponibles, commentaire, coche « traité ». Jamais
 * une contrainte (aucun effet solveur). Ancrée à l'entrée MÈRE (`calendar_entry_id`) + le
 * lundi (`week_start`) — robuste à la découpe en semaines.
 *
 * RLS FORCE comme toute table club_id (RlsIsolationTest la découvre dynamiquement).
 */
final class Version20260725120000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'coach wishes: coach_wish (+RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE coach_wish (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, calendar_entry_id UUID NOT NULL, week_start DATE NOT NULL, team_id UUID NOT NULL, coach_id UUID DEFAULT NULL, slots_wanted SMALLINT DEFAULT 0 NOT NULL, unavailable_days JSON NOT NULL, comment TEXT DEFAULT NULL, done BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_coach_wish ON coach_wish (calendar_entry_id, team_id, week_start)');
        $this->addSql('CREATE INDEX idx_coach_wish_entry ON coach_wish (calendar_entry_id)');

        // RLS: FORCE + policy tenant_isolation adossée au GUC app.club_id.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON coach_wish TO ' . $appRole);
            $this->addSql('ALTER TABLE public.coach_wish ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.coach_wish FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.coach_wish FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte ses index, sa policy et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE coach_wish');
    }
}
