<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les équipes d'un plan (lot 4 conception, PR 3a) : une ligne `plan_team` par (plan, équipe), socle compris — participe,
 * séances par semaine, plafond par jour, origine.
 *
 * Dans l'ordre, en une transaction :
 *  1. La table, son unicité (plan, équipe), sa clé composée (plan, club) vers le plan en cascade : une ligne ne désigne
 *     jamais le plan d'un autre club (une vérification de clé étrangère ignore la RLS). Pas de clé vers l'équipe :
 *     « Charger » efface puis réinsère les équipes.
 *  2. Le socle : chaque équipe reçoit les séances et le plafond qu'elle portait.
 *  3. Chaque plan de période : l'exception de l'équipe, champ par champ, sinon la valeur du socle du jour (décision du
 *     fondateur du 2026-11-22 : le plan garde désormais ces valeurs). Un plan de vacances jamais ouvert et sans aucune
 *     version reçoit la pré-sélection de sa naissance (les deux premiers rangs du référentiel, à défaut le meilleur rang
 *     présent) ; avec une version, il garde toutes ses équipes, pour ne pas changer un planning déjà fait.
 *  4. Arrêt si le nombre de lignes n'est pas « équipes × plans » de chaque saison (deux exceptions pour un même couple,
 *     par exemple).
 *  5. Les photos des versions : séances et plafond quittent les lignes `Team` pour une ligne `PlanTeam` du plan de
 *     saison par équipe ; une photo prise avant le plafond donne 1, comme son chargement le donnait.
 *  6. Suppression de `team_period_override`, de `team.sessions_per_week`, `team.max_sessions_per_day` et du drapeau
 *     `schedule_plan.team_selection_initialized`.
 *  7. RLS active et forcée (patron Version20261125120000).
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit les colonnes et la
 * table supprimées (dérogation écrite à la règle « compatible une release en arrière »). Irréversible.
 */
final class Version20261128120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'Les équipes d\'un plan : plan_team (socle compris, exceptions de période converties, pré-sélection des vacances jamais ouvertes), photos converties ; team_period_override, team.sessions_per_week, team.max_sessions_per_day et schedule_plan.team_selection_initialized supprimés.';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        // 1. La table.
        $this->addSql('CREATE TABLE plan_team (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID NOT NULL, team_id UUID NOT NULL, participates BOOLEAN NOT NULL, sessions_per_week INT NOT NULL, max_sessions_per_day SMALLINT NOT NULL, source VARCHAR(30) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_plan_team ON plan_team (schedule_plan_id, team_id)');
        $this->addSql('CREATE INDEX idx_plan_team_season ON plan_team (season_id)');
        $this->addSql('CREATE INDEX idx_plan_team_team ON plan_team (team_id)');
        $this->addSql('ALTER TABLE plan_team ADD CONSTRAINT fk_plan_team_schedule_plan FOREIGN KEY (schedule_plan_id, club_id) REFERENCES schedule_plan (id, club_id) ON DELETE CASCADE');

        // 2. Le socle : les valeurs de l'équipe.
        $this->addSql(<<<'SQL'
            INSERT INTO plan_team (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, team_id, participates, sessions_per_week, max_sessions_per_day, source)
            SELECT gen_random_uuid(), 1, now(), now(), p.club_id, p.season_id, p.id, t.id, true, t.sessions_per_week, t.max_sessions_per_day, NULL
            FROM schedule_plan p
            JOIN team t ON t.season_id = p.season_id
            WHERE p.type = 'SEASON'
            SQL);

        // 3. Les plans de période : l'exception champ par champ, sinon le socle du jour ; la pré-sélection d'un plan de
        //    vacances jamais ouvert et sans version.
        $this->addSql(<<<'SQL'
            INSERT INTO plan_team (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, team_id, participates, sessions_per_week, max_sessions_per_day, source)
            SELECT gen_random_uuid(), 1, now(), now(), p.club_id, p.season_id, p.id, t.id,
              COALESCE(o.is_active, CASE
                WHEN p.type <> 'HOLIDAY' OR p.team_selection_initialized OR EXISTS (SELECT 1 FROM schedule s WHERE s.schedule_plan_id = p.id) THEN true
                WHEN EXISTS (SELECT 1 FROM team t2 WHERE t2.season_id = t.season_id AND t2.priority_tier_id IN (SELECT pt.id FROM priority_tier pt ORDER BY pt.id LIMIT 2))
                  THEN t.priority_tier_id IN (SELECT pt.id FROM priority_tier pt ORDER BY pt.id LIMIT 2)
                ELSE t.priority_tier_id = (SELECT MIN(t3.priority_tier_id) FROM team t3 WHERE t3.season_id = t.season_id)
              END),
              COALESCE(o.sessions_per_week, t.sessions_per_week),
              COALESCE(o.max_sessions_per_day, t.max_sessions_per_day),
              o.source
            FROM schedule_plan p
            JOIN team t ON t.season_id = p.season_id
            LEFT JOIN team_period_override o ON o.schedule_plan_id = p.id AND o.team_id = t.id
            WHERE p.type <> 'SEASON'
            SQL);

        // 4. Une ligne par (plan, équipe), ni plus ni moins.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
              written INT;
              expected INT;
            BEGIN
              SELECT COUNT(*) INTO written FROM plan_team;
              SELECT COUNT(*) INTO expected FROM schedule_plan p JOIN team t ON t.season_id = p.season_id;
              IF written <> expected THEN
                RAISE EXCEPTION 'Les équipes d''un plan : % lignes pour % couples (plan, équipe), la conversion s''annule.', written, expected;
              END IF;
            END
            $$
            SQL);

        // 5. Les photos : une ligne `PlanTeam` du plan de saison par ligne `Team`, qui perd séances et plafond.
        $this->addSql(<<<'SQL'
            UPDATE schedule_structure_snapshot snap SET data = (
              snap.data::jsonb || jsonb_build_object(
                'Team', COALESCE((
                  SELECT jsonb_agg(e.elem - 'sessionsPerWeek' - 'maxSessionsPerDay' ORDER BY e.ord)
                  FROM jsonb_array_elements(snap.data::jsonb -> 'Team') WITH ORDINALITY AS e(elem, ord)
                ), '[]'::jsonb),
                'PlanTeam', COALESCE((
                  SELECT jsonb_agg(jsonb_build_object(
                    'teamId', e.elem -> 'id',
                    'schedulePlanId', p.id,
                    'participates', true,
                    'sessionsPerWeek', CASE WHEN jsonb_typeof(e.elem -> 'sessionsPerWeek') = 'number' THEN e.elem -> 'sessionsPerWeek' ELSE '2'::jsonb END,
                    'maxSessionsPerDay', CASE WHEN jsonb_typeof(e.elem -> 'maxSessionsPerDay') = 'number' THEN e.elem -> 'maxSessionsPerDay' ELSE '1'::jsonb END
                  ) ORDER BY e.ord)
                  FROM jsonb_array_elements(snap.data::jsonb -> 'Team') WITH ORDINALITY AS e(elem, ord)
                ), '[]'::jsonb)
              )
            )::json
            FROM schedule_plan p
            WHERE p.season_id = snap.season_id AND p.type = 'SEASON' AND jsonb_typeof(snap.data::jsonb -> 'Team') = 'array'
            SQL);

        // 6. Ce que les lignes remplacent.
        $this->addSql('DROP TABLE team_period_override');
        $this->addSql('ALTER TABLE team DROP COLUMN sessions_per_week, DROP COLUMN max_sessions_per_day');
        $this->addSql('ALTER TABLE schedule_plan DROP COLUMN team_selection_initialized');

        // 7. RLS.
        $this->addSql('ALTER TABLE public.plan_team ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE public.plan_team FORCE ROW LEVEL SECURITY');
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON plan_team TO ' . $appRole);
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.plan_team FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'')) {
            $this->addSql('CREATE POLICY admin_all ON public.plan_team FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'')) {
            $this->addSql('GRANT SELECT ON public.plan_team TO amateo_read');
            $this->addSql(\sprintf('CREATE POLICY readonly_tenant ON public.plan_team FOR SELECT TO amateo_read USING (%s)', self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les exceptions d\'équipes, les séances de l\'équipe et le drapeau de pré-sélection ne se recréent pas sans risque depuis les lignes des plans. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
