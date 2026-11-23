<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le socle a son plan (lot 4 conception, PR 2) : chaque ligne de planning du socle porte l'identifiant du
 * plan de saison de sa saison ; « plan nul » ne veut plus dire « socle ».
 *
 * Dans l'ordre, en une transaction :
 *  1. Refus si une ligne des six familles porte déjà l'identifiant d'un plan de saison (attendu : zéro) —
 *     le socle et ce plan ne se distingueraient plus. Rien n'est converti.
 *  2. Chaque saison sans plan de saison (donnée d'avant les plans) en reçoit un, au club de la saison.
 *  3. Grille, occupations, réservations, groupes mutualisés et leurs membres, règles de bien-être : chaque
 *     ligne à plan nul reçoit le plan de saison de sa saison ; une ligne de période garde le sien. Arrêt si
 *     une ligne reste sans plan (saison disparue) ou désigne un plan qui n'existe plus.
 *  4. `schedule_plan_id` obligatoire sur les six tables ; une clé vers le plan en cascade, ajoutée aux trois
 *     qui n'en avaient pas. Unicité (id, club_id) sur le plan, cible des clés composées à venir.
 *  5. La version chargée du socle (l'étoile) passe de la saison à son plan : recopiée sur
 *     `schedule_plan.lock_source_schedule_id`, puis `season.live_context_schedule_id` est supprimée.
 *  6. Les photos des versions : une ligne de grille ou de réservation sans plan reçoit le plan de saison de
 *     la saison de la photo ; rien d'autre ne change (l'ordre des clés d'un objet, si : réécriture jsonb).
 *  7. Décision du fondateur (2026-11-22) : un plan de période né sans copie des règles de bien-être les
 *     reçoit, aux valeurs de la saison du moment (le défaut sinon), puis ne suit plus la saison.
 *     Les clés et leur défaut sont figés ici, comme `ImplicitRuleSettingRepository::materializeForPlan`
 *     les écrit aujourd'hui.
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit
 * `season.live_context_schedule_id` et cherche le socle à plan nul (dérogation écrite à la règle
 * « compatible une release en arrière »). Irréversible.
 */
final class Version20261127120000 extends AbstractMigration
{
    /** Les six familles dont « plan nul » voulait dire « socle ». */
    private const array SOCLE_TABLES = ['venue_training_slot', 'venue_occupancy', 'reservation', 'shared_training_block', 'shared_training_block_team', 'implicit_rule_setting'];

    /** Celles qui n'avaient pas encore de clé vers le plan. */
    private const array TABLES_WITHOUT_PLAN_KEY = ['shared_training_block', 'shared_training_block_team', 'implicit_rule_setting'];

    public function getDescription(): string
    {
        return 'Le socle a son plan : plan de saison posé sur les lignes à plan nul des six familles, NOT NULL et clé en cascade, étoile du socle recopiée sur le plan (season.live_context_schedule_id supprimée), photos converties, règles de bien-être des plans de période anciens matérialisées.';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        // 1. Refus : une ligne porte déjà l'identifiant d'un plan de saison.
        $this->addSql(\sprintf(<<<'SQL'
            DO $$
            DECLARE
              carried INT;
            BEGIN
              SELECT COUNT(*) INTO carried FROM (%s) rows_on_season_plan;
              IF carried > 0 THEN
                RAISE EXCEPTION 'Le socle a son plan : %% ligne(s) portent déjà l''identifiant d''un plan de saison, la conversion s''annule.', carried;
              END IF;
            END
            $$
            SQL, implode(' UNION ALL ', array_map(
            static fn (string $table): string => \sprintf('SELECT 1 FROM %s t JOIN schedule_plan p ON p.id = t.schedule_plan_id WHERE p.type = \'SEASON\'', $table),
            self::SOCLE_TABLES,
        ))));

        // 2. Le plan de saison manquant, au club de la saison (même nom que `SchedulePlanProvisioner`).
        $this->addSql(<<<'SQL'
            INSERT INTO schedule_plan (id, version, created_at, updated_at, club_id, season_id, type, name, start_date, end_date, last_version_number, team_selection_initialized)
            SELECT gen_random_uuid(), 1, now(), now(), s.club_id, s.id, 'SEASON', 'Planning de la saison ' || s.name, s.start_date, s.end_date, 0, false
            FROM season s
            WHERE NOT EXISTS (SELECT 1 FROM schedule_plan p WHERE p.season_id = s.id AND p.type = 'SEASON')
            SQL);

        // 3. Le plan de saison sur chaque ligne du socle.
        foreach (self::SOCLE_TABLES as $table) {
            $this->addSql(\sprintf(
                'UPDATE %s t SET schedule_plan_id = p.id FROM schedule_plan p WHERE t.schedule_plan_id IS NULL AND p.season_id = t.season_id AND p.type = \'SEASON\'',
                $table,
            ));
        }
        $this->addSql(\sprintf(
            <<<'SQL'
                DO $$
                DECLARE
                  unplanned INT;
                  orphaned INT;
                BEGIN
                  SELECT COUNT(*) INTO unplanned FROM (%s) rows_without_plan;
                  IF unplanned > 0 THEN
                    RAISE EXCEPTION 'Le socle a son plan : %% ligne(s) restent sans plan (saison introuvable), la conversion s''arrête.', unplanned;
                  END IF;
                  SELECT COUNT(*) INTO orphaned FROM (%s) rows_on_missing_plan;
                  IF orphaned > 0 THEN
                    RAISE EXCEPTION 'Le socle a son plan : %% ligne(s) désignent un plan qui n''existe plus, la conversion s''arrête.', orphaned;
                  END IF;
                END
                $$
                SQL,
            implode(' UNION ALL ', array_map(static fn (string $table): string => \sprintf('SELECT 1 FROM %s WHERE schedule_plan_id IS NULL', $table), self::SOCLE_TABLES)),
            implode(' UNION ALL ', array_map(static fn (string $table): string => \sprintf('SELECT 1 FROM %s t WHERE NOT EXISTS (SELECT 1 FROM schedule_plan p WHERE p.id = t.schedule_plan_id)', $table), self::SOCLE_TABLES)),
        ));

        // 4. Colonne obligatoire, clé en cascade vers le plan, unicité (id, club_id) du plan.
        foreach (self::SOCLE_TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER COLUMN schedule_plan_id SET NOT NULL', $table));
        }
        foreach (self::TABLES_WITHOUT_PLAN_KEY as $table) {
            $this->addSql(\sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT fk_%1$s_schedule_plan FOREIGN KEY (schedule_plan_id) REFERENCES schedule_plan (id) ON DELETE CASCADE',
                $table,
            ));
        }
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_plan_id_club ON schedule_plan (id, club_id)');

        // 5. L'étoile du socle sur son plan : un seul pointeur de version chargée.
        $this->addSql(<<<'SQL'
            UPDATE schedule_plan p SET lock_source_schedule_id = s.live_context_schedule_id
            FROM season s
            WHERE p.season_id = s.id AND p.type = 'SEASON'
            SQL);
        $this->addSql('ALTER TABLE season DROP COLUMN live_context_schedule_id');

        // 6. Les photos : grille et réservations du socle sur le plan de saison de la saison de la photo.
        $this->addSql(<<<'SQL'
            UPDATE schedule_structure_snapshot snap SET data = (
                SELECT COALESCE(jsonb_object_agg(family.key, CASE
                    WHEN family.key IN ('VenueTrainingSlot', 'Reservation') AND jsonb_typeof(family.value) = 'array' THEN (
                        SELECT COALESCE(jsonb_agg(CASE
                            WHEN jsonb_typeof(row_data.value) = 'object' AND COALESCE(row_data.value -> 'schedulePlanId', 'null'::jsonb) = 'null'::jsonb
                                THEN row_data.value || jsonb_build_object('schedulePlanId', p.id)
                            ELSE row_data.value
                        END ORDER BY row_data.position), '[]'::jsonb)
                        FROM jsonb_array_elements(family.value) WITH ORDINALITY AS row_data(value, position)
                    )
                    ELSE family.value
                END), '{}'::jsonb)
                FROM jsonb_each(snap.data::jsonb) AS family
            )::json
            FROM schedule_plan p
            WHERE p.season_id = snap.season_id AND p.type = 'SEASON'
            SQL);

        // 7. Les règles de bien-être d'un plan de période qui n'en porte aucune : celles de la saison du moment.
        $this->addSql(<<<'SQL'
            INSERT INTO implicit_rule_setting (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, rule_key, intensity, params)
            SELECT gen_random_uuid(), 1, now(), now(), p.club_id, p.season_id, p.id, k.rule_key, COALESCE(s.intensity, k.default_intensity), s.params
            FROM schedule_plan p
            CROSS JOIN (VALUES ('coachRestDay', 'HARD'), ('salarieDistribution', 'HARD'), ('maxConsecutiveSessions', 'HARD'), ('ageAscending', 'HARD'), ('maxConsecutiveDays', 'OFF')) AS k(rule_key, default_intensity)
            JOIN schedule_plan sp ON sp.season_id = p.season_id AND sp.type = 'SEASON'
            LEFT JOIN implicit_rule_setting s ON s.club_id = p.club_id AND s.season_id = p.season_id AND s.schedule_plan_id = sp.id AND s.rule_key = k.rule_key
            WHERE p.type <> 'SEASON'
              AND NOT EXISTS (SELECT 1 FROM implicit_rule_setting e WHERE e.schedule_plan_id = p.id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Le socle à plan nul et le pointeur de la saison ne se recréent pas sans risque depuis le plan de saison. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
