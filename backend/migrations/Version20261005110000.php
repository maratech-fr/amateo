<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\LeagueWindowSuggestionService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BCK-36 — durcissement de `league_window_suggestions(uuid)` (lot robustesse, audit
 * 2026-10-03). Deux correctifs dans la MÊME redéfinition (décision fondateur : oui,
 * même migration) :
 *
 *  (1) **Les clubs de DÉMONSTRATION n'entrent jamais dans les pairs** — un `AND NOT
 *      c.is_demo` dans la CTE `peers`. Sans lui, un club vendeur (ARA9999999 et ses
 *      clones de démo) comptait dans la « tendance dominante » d'une instance fédérale
 *      servie à de vrais clubs : du bruit, et une fuite de l'existence des démos.
 *  (2) **`p_requesting_club` est LIÉ au tenant courant** — la CTE `me` exige
 *      `id = NULLIF(current_setting('app.club_id', true), '')::uuid`, EXACTEMENT le
 *      prédicat des policies RLS du dépôt (fail-closed, patron le plus proche). Un appel
 *      avec un club ≠ GUC (ou sans GUC posé) rend `me` vide → `scoped` vide → zéro ligne :
 *      la fonction `SECURITY DEFINER` (qui bypasse la RLS) ne peut plus agréger
 *      l'instance d'un club ARBITRAIRE, seulement celle du club du GUC. L'appelant
 *      ({@see LeagueWindowSuggestionService}) tourne dans une requête HTTP
 *      où le listener tenant a déjà posé `app.club_id` = club courant — le prédicat passe.
 *
 * Le reste est INCHANGÉ (règle de la tendance ≥ 3 ET majorité stricte, groupement par
 * niveau, saisons actives, exclusion du demandeur). `CREATE OR REPLACE` conserve les
 * privilèges ; on re-pose REVOKE/GRANT par sûreté (base fraîche rejouant toutes les
 * migrations). Migration écrite à la main (`make migration-diff` inopérant tant que
 * doctrine/dbal < 4.5).
 */
final class Version20261005110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BCK-36: league_window_suggestions exclut les clubs démo des pairs et lie p_requesting_club au GUC tenant (fail-closed RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION league_window_suggestions(p_requesting_club uuid)
            RETURNS TABLE (
                category text,
                level text,
                gender text,
                day_of_week smallint,
                windows jsonb,
                club_count integer
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $fn$
                WITH me AS (
                    SELECT upper(substring(ffbb_club_code FROM 1 FOR 3)) AS ligue,
                           substring(ffbb_club_code FROM 4 FOR 4) AS comite
                    FROM public.club
                    WHERE id = p_requesting_club
                      -- BCK-36 : le demandeur DOIT être le club du tenant courant (prédicat RLS
                      -- du dépôt). GUC absent / club ≠ GUC ⇒ me vide ⇒ aucun résultat (fail-closed).
                      AND id = NULLIF(current_setting('app.club_id', true), '')::uuid
                      AND ffbb_club_code ~ '^[A-Za-z]{3}[0-9]{4}'
                ),
                peers AS (
                    SELECT c.id,
                           upper(substring(c.ffbb_club_code FROM 1 FOR 3)) AS ligue,
                           substring(c.ffbb_club_code FROM 4 FOR 4) AS comite
                    FROM public.club c
                    WHERE c.id <> p_requesting_club
                      -- BCK-36 : les clubs de démonstration ne comptent jamais dans la tendance.
                      AND NOT c.is_demo
                      AND c.ffbb_club_code ~ '^[A-Za-z]{3}[0-9]{4}'
                ),
                entries AS (
                    SELECT w.club_id, w.category, w.level, w.gender, w.day_of_week,
                           p.ligue, p.comite,
                           jsonb_agg(
                               jsonb_build_object(
                                   'kickoffMin', to_char(w.kickoff_min, 'HH24:MI'),
                                   'kickoffMax', to_char(w.kickoff_max, 'HH24:MI')
                               )
                               ORDER BY w.kickoff_min, w.kickoff_max
                           ) AS windows
                    FROM public.club_league_window w
                    JOIN public.season s ON s.id = w.season_id AND s.status = 'active'
                    JOIN peers p ON p.id = w.club_id
                    GROUP BY w.club_id, w.category, w.level, w.gender, w.day_of_week, p.ligue, p.comite
                ),
                scoped AS (
                    SELECT e.category, e.level, e.gender, e.day_of_week, e.windows
                    FROM entries e, me
                    WHERE CASE
                        WHEN e.level = 'REGIONAL' THEN e.ligue = me.ligue
                        WHEN e.level IN ('NATIONAL', 'ELITE') THEN true
                        ELSE e.ligue = me.ligue AND e.comite = me.comite
                    END
                ),
                tallies AS (
                    SELECT category, level, gender, day_of_week, windows,
                           count(*) AS c,
                           sum(count(*)) OVER (PARTITION BY category, level, gender, day_of_week) AS total
                    FROM scoped
                    GROUP BY category, level, gender, day_of_week, windows
                )
                SELECT category, level, gender, day_of_week, windows, c::int
                FROM tallies
                WHERE c >= 3 AND c * 2 > total;
            $fn$;
            SQL);

        // Surface d'appel close (idempotent — `CREATE OR REPLACE` garde déjà les privilèges) :
        // PUBLIC n'a pas l'EXECUTE, seul le rôle applicatif l'a. Détection identique à l'origine.
        $this->addSql('REVOKE ALL ON FUNCTION league_window_suggestions(uuid) FROM PUBLIC');
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT EXECUTE ON FUNCTION league_window_suggestions(uuid) TO ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        // Retour à la définition P4-272 ② (sans l'exclusion démo ni la liaison GUC).
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION league_window_suggestions(p_requesting_club uuid)
            RETURNS TABLE (
                category text,
                level text,
                gender text,
                day_of_week smallint,
                windows jsonb,
                club_count integer
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $fn$
                WITH me AS (
                    SELECT upper(substring(ffbb_club_code FROM 1 FOR 3)) AS ligue,
                           substring(ffbb_club_code FROM 4 FOR 4) AS comite
                    FROM public.club
                    WHERE id = p_requesting_club
                      AND ffbb_club_code ~ '^[A-Za-z]{3}[0-9]{4}'
                ),
                peers AS (
                    SELECT c.id,
                           upper(substring(c.ffbb_club_code FROM 1 FOR 3)) AS ligue,
                           substring(c.ffbb_club_code FROM 4 FOR 4) AS comite
                    FROM public.club c
                    WHERE c.id <> p_requesting_club
                      AND c.ffbb_club_code ~ '^[A-Za-z]{3}[0-9]{4}'
                ),
                entries AS (
                    SELECT w.club_id, w.category, w.level, w.gender, w.day_of_week,
                           p.ligue, p.comite,
                           jsonb_agg(
                               jsonb_build_object(
                                   'kickoffMin', to_char(w.kickoff_min, 'HH24:MI'),
                                   'kickoffMax', to_char(w.kickoff_max, 'HH24:MI')
                               )
                               ORDER BY w.kickoff_min, w.kickoff_max
                           ) AS windows
                    FROM public.club_league_window w
                    JOIN public.season s ON s.id = w.season_id AND s.status = 'active'
                    JOIN peers p ON p.id = w.club_id
                    GROUP BY w.club_id, w.category, w.level, w.gender, w.day_of_week, p.ligue, p.comite
                ),
                scoped AS (
                    SELECT e.category, e.level, e.gender, e.day_of_week, e.windows
                    FROM entries e, me
                    WHERE CASE
                        WHEN e.level = 'REGIONAL' THEN e.ligue = me.ligue
                        WHEN e.level IN ('NATIONAL', 'ELITE') THEN true
                        ELSE e.ligue = me.ligue AND e.comite = me.comite
                    END
                ),
                tallies AS (
                    SELECT category, level, gender, day_of_week, windows,
                           count(*) AS c,
                           sum(count(*)) OVER (PARTITION BY category, level, gender, day_of_week) AS total
                    FROM scoped
                    GROUP BY category, level, gender, day_of_week, windows
                )
                SELECT category, level, gender, day_of_week, windows, c::int
                FROM tallies
                WHERE c >= 3 AND c * 2 > total;
            $fn$;
            SQL);
        $this->addSql('REVOKE ALL ON FUNCTION league_window_suggestions(uuid) FROM PUBLIC');
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT EXECUTE ON FUNCTION league_window_suggestions(uuid) TO ' . $appRole);
        }
    }
}
