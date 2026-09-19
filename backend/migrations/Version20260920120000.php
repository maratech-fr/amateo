<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * C4 — `club_travel_cache` : cache de temps de trajet AU NIVEAU CLUB (jamais par
 * saison). Un trajet routier est une CONSTANTE (deux coordonnées arrondies + un
 * profil), donc une valeur déjà calculée n'est jamais recalculée. Clé unique
 * (club, profil, origine, destination). Tenant / RLS (les coordonnées croisées
 * trahissent le siège d'un club — même raison qu'`opponent_travel`).
 *
 * RLS : patron opponent_travel/conflict_resolution — GRANT + ENABLE/FORCE + policy
 * `tenant_isolation` (rôle app) + porte admin `admin_all` (amateo_owner).
 * RlsIsolationTest découvre la table automatiquement (elle porte club_id).
 *
 * SEED one-shot (avant l'armement RLS ; les migrations tournent en `amateo_owner`
 * qui contourne la RLS des tables SOURCES) : on rétro-alimente le cache depuis les
 * trajets DÉJÀ calculés — (1) `opponent_travel.travel_minutes` (siège actuel du club
 * → lieu adverse effectif, override sinon annuaire), (2) `venue_travel_time`
 * conduite/marche entre les deux gymnases → profils car/pedestrian, DANS LES DEUX
 * SENS (le cache est directionnel, la matrice non). `ON CONFLICT DO NOTHING` : jamais
 * d'écrasement, deux sources qui convergent sur la même clé fusionnent.
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal
 * reste < 4.5 (backend.md).
 */
final class Version20260920120000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    /**
     * Les instructions du seed one-shot, isolées pour être REJOUÉES à l'identique par
     * le test de seed (`ClubTravelCacheSeedTest`) — la migration et son test partagent
     * exactement ce SQL, donc aucune dérive. Idempotentes (ON CONFLICT DO NOTHING).
     *
     * @return list<string>
     */
    public static function seedStatements(): array
    {
        // (1) Trajets adverses déjà calculés : siège ACTUEL du club → lieu effectif
        //     (override épinglé sinon annuaire fédéral). Direction unique (club → lieu).
        $statements = [
            'INSERT INTO club_travel_cache (id, club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon, minutes, resolved_at)
             SELECT gen_random_uuid(), ot.club_id, \'car\',
                    round(c.latitude::numeric, 5), round(c.longitude::numeric, 5),
                    round(COALESCE(ot.override_latitude, od.latitude)::numeric, 5),
                    round(COALESCE(ot.override_longitude, od.longitude)::numeric, 5),
                    ot.travel_minutes, now()
             FROM opponent_travel ot
             JOIN club c ON c.id = ot.club_id
             LEFT JOIN opponent_directory od ON od.ffbb_organisme_code = ot.opponent_organisme_code
             WHERE ot.travel_minutes IS NOT NULL
               AND c.latitude IS NOT NULL AND c.longitude IS NOT NULL
               AND COALESCE(ot.override_latitude, od.latitude) IS NOT NULL
               AND COALESCE(ot.override_longitude, od.longitude) IS NOT NULL
             ON CONFLICT (club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon) DO NOTHING',
        ];

        // (2) Matrice des gymnases (conduite → car, marche → pedestrian), les DEUX SENS.
        foreach ([['driving_minutes', 'car'], ['walking_minutes', 'pedestrian']] as [$column, $profile]) {
            $statements[] = "INSERT INTO club_travel_cache (id, club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon, minutes, resolved_at)
                 SELECT gen_random_uuid(), s.club_id, '{$profile}', s.o_lat, s.o_lon, s.d_lat, s.d_lon, s.mins, now()
                 FROM (
                     SELECT vtt.club_id,
                            round(va.latitude::numeric, 5) AS o_lat, round(va.longitude::numeric, 5) AS o_lon,
                            round(vb.latitude::numeric, 5) AS d_lat, round(vb.longitude::numeric, 5) AS d_lon,
                            vtt.{$column} AS mins
                     FROM venue_travel_time vtt
                     JOIN venue va ON va.id = vtt.venue_a_id
                     JOIN venue vb ON vb.id = vtt.venue_b_id
                     WHERE vtt.{$column} IS NOT NULL
                       AND va.latitude IS NOT NULL AND va.longitude IS NOT NULL
                       AND vb.latitude IS NOT NULL AND vb.longitude IS NOT NULL
                     UNION ALL
                     SELECT vtt.club_id,
                            round(vb.latitude::numeric, 5), round(vb.longitude::numeric, 5),
                            round(va.latitude::numeric, 5), round(va.longitude::numeric, 5),
                            vtt.{$column}
                     FROM venue_travel_time vtt
                     JOIN venue va ON va.id = vtt.venue_a_id
                     JOIN venue vb ON vb.id = vtt.venue_b_id
                     WHERE vtt.{$column} IS NOT NULL
                       AND va.latitude IS NOT NULL AND va.longitude IS NOT NULL
                       AND vb.latitude IS NOT NULL AND vb.longitude IS NOT NULL
                 ) s
                 ON CONFLICT (club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon) DO NOTHING";
        }

        return $statements;
    }

    public function getDescription(): string
    {
        return 'C4: club_travel_cache (cache de trajets club-scoped, tenant, RLS) + seed one-shot depuis opponent_travel et venue_travel_time.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE club_travel_cache (id UUID NOT NULL, club_id UUID NOT NULL, profile VARCHAR(16) NOT NULL, origin_lat NUMERIC(9, 5) NOT NULL, origin_lon NUMERIC(9, 5) NOT NULL, dest_lat NUMERIC(9, 5) NOT NULL, dest_lon NUMERIC(9, 5) NOT NULL, minutes SMALLINT NOT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_club_travel_cache_key ON club_travel_cache (club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon)');
        $this->addSql('CREATE INDEX idx_club_travel_cache_club ON club_travel_cache (club_id)');

        // ── SEED one-shot (avant RLS ; owner contourne la RLS des sources) ──────────
        // Les instructions vivent dans self::seedStatements() — partagées verbatim avec le
        // test de seed (aucune dérive possible entre la migration et ce qu'il vérifie).
        foreach (self::seedStatements() as $sql) {
            $this->addSql($sql);
        }

        // ── RLS (patron) ────────────────────────────────────────────────────────────
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON club_travel_cache TO ' . $appRole);
            $this->addSql('ALTER TABLE public.club_travel_cache ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.club_travel_cache FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.club_travel_cache FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin : le rôle propriétaire garde l'accès sous FORCE RLS (RlsIsolationTest
        // exige exactement 1 admin_all par table FORCE).
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.club_travel_cache FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.club_travel_cache');
        $this->addSql('DROP TABLE club_travel_cache');
    }
}
