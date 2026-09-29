<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-272 ② — la fonction SQL `league_window_suggestions(uuid)` : la « tendance
 * dominante » des plages de match d'une INSTANCE fédérale (comité / ligue /
 * fédération selon le niveau de la plage), lue à travers la frontière tenant sans
 * jamais rendre de donnée club-identifiante. PREMIÈRE fonction `SECURITY DEFINER`
 * du dépôt.
 *
 * POURQUOI SECURITY DEFINER : la fonction doit agréger `club_league_window` (FORCE
 * RLS) de TOUS les clubs pairs. `amateo_app` en est incapable (RLS le borne à son
 * club). Exécutée comme son propriétaire (`amateo_owner`, qui porte la policy
 * `admin_all` — et bypasse la RLS sur un Postgres managé), elle voit tout, mais ne
 * rend QUE l'agrégat : (catégorie, niveau, genre, jour, ensemble canonique trié de
 * plages, NOMBRE de clubs) — jamais LESQUELS. Durcissement (revue sécurité) :
 * `SET search_path = pg_catalog, public, pg_temp` (pg_temp EN DERNIER, reco PostgreSQL
 * pour SECURITY DEFINER — sinon une session pourrait ombrer club/season/club_league_window
 * par des tables temporaires) + tables qualifiées `public.…` + `REVOKE … FROM PUBLIC` +
 * `GRANT EXECUTE` au seul rôle applicatif = surface d'appel close.
 *
 * Règle de la tendance (décision fondateur 2026-09-29) : un ensemble de plages
 * identiques est retenu s'il est saisi par ≥ 3 clubs de l'instance ET par plus de
 * la moitié des clubs de l'instance ayant saisi cette combinaison ; sinon rien.
 * Saisons `active` des AUTRES clubs seulement ; demandeur exclu. Instance dérivée
 * du `ffbb_club_code` du demandeur LUI-MÊME (aucun paramètre de ligue fourni par
 * l'appelant) : ligue = 3 lettres, comité = 4 chiffres suivants ; un code illisible
 * (`me` vide) → aucun résultat.
 *
 * Fonction en `sql` STABLE (lecture pure). Détection du rôle applicatif identique à
 * `Version20260928130000`. Migration écrite à la main (`make migration-diff`
 * inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-272 ②: fonction SECURITY DEFINER league_window_suggestions (tendance des plages par instance fédérale).';
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

        // Surface d'appel close : PUBLIC perd l'EXECUTE que CREATE FUNCTION lui
        // confère par défaut ; seul le rôle applicatif (et le propriétaire) l'ont.
        // Détection du rôle identique à Version20260928130000.
        $this->addSql('REVOKE ALL ON FUNCTION league_window_suggestions(uuid) FROM PUBLIC');
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT EXECUTE ON FUNCTION league_window_suggestions(uuid) TO ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION IF EXISTS league_window_suggestions(uuid)');
    }
}
