<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Basketball\VenueLabelNormalizer;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-54 « adversaire multi-gymnases », amendement 2026-09-20 — le gymnase d'un
 * adversaire se rattache au CLUB adverse et au LIBELLÉ de salle du fichier, jamais à
 * l'équipe ni à la saison. Naissance de `opponent_venue_link` (tenant, RLS, club-scoped
 * SANS saison) et MORT de `opponent_travel` (le trajet est désormais servi depuis
 * `club_travel_cache`, une CONSTANTE, jamais dupliquée par ligne ni par saison).
 *
 * Grain `(club, code organisme, libellé FBI normalisé)` : une rencontre AWAY retrouve
 * SON lien par le libellé de SA salle. Le lien ne porte PAS de trajet — seulement le
 * snapshot fédéral du gymnase (ref/label/lat/long) et la source (AUTO|MANUAL).
 *
 * MIGRATION DE DONNÉES (postUp, avant le DROP) : chaque ligne `opponent_travel` portant
 * un override géolocalisé (MANUAL et AUTO) devient un lien par libellé FBI DISTINCT des
 * fixtures AWAY du club pour ce code — normalisation via {@see VenueLabelNormalizer}
 * instancié directement (service PUR, sans dépendance ; ⚠ si un jour il gagne une
 * dépendance de conteneur, ce `new` dérive du foyer runtime et cette migration devra
 * figer sa propre copie de la règle). Les MANUAL sont traités d'abord, l'unicité tranche
 * en leur faveur (ON CONFLICT DO NOTHING). Les lignes AUTO club sans override (lieu de
 * l'annuaire, jamais épinglé) ne portent aucun gymnase à copier — l'auto-localisateur
 * les repose au prochain import.
 *
 * RLS : patron opponent_travel/club_travel_cache — GRANT + ENABLE/FORCE + policy
 * `tenant_isolation` (rôle app) + porte admin `admin_all`. RlsIsolationTest découvre la
 * table automatiquement (elle porte club_id).
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal reste
 * < 4.5 (backend.md).
 */
final class Version20260920140000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P2-54 adversaire multi-gymnases (amendement): opponent_venue_link (tenant, RLS, club-scoped sans saison) + migration de données depuis opponent_travel, puis DROP opponent_travel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE opponent_venue_link (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, opponent_organisme_code VARCHAR(64) NOT NULL, fbi_label VARCHAR(180) NOT NULL, fbi_label_norm VARCHAR(180) NOT NULL, venue_external_ref VARCHAR(64) DEFAULT NULL, venue_label VARCHAR(180) NOT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, source VARCHAR(10) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_opponent_venue_link ON opponent_venue_link (club_id, opponent_organisme_code, fbi_label_norm)');
        $this->addSql('CREATE INDEX idx_opponent_venue_link_club_code ON opponent_venue_link (club_id, opponent_organisme_code)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON opponent_venue_link TO ' . $appRole);
            $this->addSql('ALTER TABLE public.opponent_venue_link ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.opponent_venue_link FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.opponent_venue_link FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin (§6) : le rôle propriétaire garde l'accès sous FORCE RLS. RlsIsolationTest
        // exige exactement 1 admin_all par table FORCE.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.opponent_venue_link FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    /**
     * Migration de données PUIS mort d'`opponent_travel`. En postUp (après le CREATE + RLS
     * ci-dessus) : la connexion des migrations tourne sous `amateo_owner`, qui traverse la
     * RLS (porte admin) — lecture cross-club des sources, écriture des liens sans GUC.
     */
    public function postUp(Schema $schema): void
    {
        $normalizer = new VenueLabelNormalizer;

        // Fixtures AWAY porteuses d'un code fédéral ET d'un libellé de salle : la seule
        // source des libellés à apparier. Indexées `club|code`.
        /** @var list<array{club_id: string, opponent_organisme_code: string, opponent_label: string, fbi_venue_label: string}> $fixtures */
        $fixtures = $this->connection->fetchAllAssociative(
            'SELECT club_id, opponent_organisme_code, opponent_label, fbi_venue_label
             FROM fixture
             WHERE home_away = \'AWAY\'
               AND opponent_organisme_code IS NOT NULL AND opponent_organisme_code <> \'\'
               AND fbi_venue_label IS NOT NULL AND btrim(fbi_venue_label) <> \'\'',
        );
        /** @var array<string, list<array{opponentLabelNorm: string, fbiLabel: string}>> $fixturesByClubCode */
        $fixturesByClubCode = [];
        foreach ($fixtures as $fixture) {
            $key = $fixture['club_id'] . '|' . $fixture['opponent_organisme_code'];
            $fixturesByClubCode[$key][] = [
                'opponentLabelNorm' => $normalizer->normalize(trim($fixture['opponent_label'])),
                'fbiLabel' => trim($fixture['fbi_venue_label']),
            ];
        }

        // Overrides géolocalisés d'opponent_travel — MANUAL d'abord (l'unicité tranche en
        // leur faveur via ON CONFLICT DO NOTHING).
        /** @var list<array{club_id: string, opponent_organisme_code: string, opponent_team_key: ?string, override_venue_external_ref: ?string, override_venue_label: ?string, override_latitude: float, override_longitude: float, source: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT club_id, opponent_organisme_code, opponent_team_key,
                    override_venue_external_ref, override_venue_label, override_latitude, override_longitude, source
             FROM opponent_travel
             WHERE override_latitude IS NOT NULL AND override_longitude IS NOT NULL
             ORDER BY (source = \'MANUAL\') DESC',
        );

        foreach ($rows as $row) {
            $candidates = $fixturesByClubCode[$row['club_id'] . '|' . $row['opponent_organisme_code']] ?? [];
            $teamKey = $row['opponent_team_key'];
            $venueLabel = null !== $row['override_venue_label'] && '' !== trim($row['override_venue_label'])
                ? trim($row['override_venue_label'])
                : null;

            foreach ($candidates as $candidate) {
                // Une ligne ÉQUIPE ne gouvernait que les rencontres de ce teamKey (libellé
                // de rencontre normalisé) ; une ligne CLUB (teamKey NULL) toutes.
                if (null !== $teamKey && $candidate['opponentLabelNorm'] !== $teamKey) {
                    continue;
                }
                $norm = $normalizer->normalize($candidate['fbiLabel']);
                if ('' === $norm) {
                    continue;
                }
                $this->connection->executeStatement(
                    'INSERT INTO opponent_venue_link
                        (id, version, created_at, updated_at, club_id, opponent_organisme_code, fbi_label, fbi_label_norm, venue_external_ref, venue_label, latitude, longitude, source)
                     VALUES (gen_random_uuid(), 1, now(), now(), :club, :code, :fbiLabel, :fbiLabelNorm, :ref, :venueLabel, :lat, :lon, :source)
                     ON CONFLICT (club_id, opponent_organisme_code, fbi_label_norm) DO NOTHING',
                    [
                        'club' => $row['club_id'],
                        'code' => mb_substr($row['opponent_organisme_code'], 0, 64),
                        'fbiLabel' => mb_substr($candidate['fbiLabel'], 0, 180),
                        'fbiLabelNorm' => mb_substr($norm, 0, 180),
                        'ref' => null === $row['override_venue_external_ref'] ? null : mb_substr($row['override_venue_external_ref'], 0, 64),
                        'venueLabel' => mb_substr($venueLabel ?? $candidate['fbiLabel'], 0, 180),
                        'lat' => $row['override_latitude'],
                        'lon' => $row['override_longitude'],
                        'source' => 'MANUAL' === $row['source'] ? 'MANUAL' : 'AUTO',
                    ],
                );
            }
        }

        // ── Mort d'opponent_travel (le trajet vit dans club_travel_cache) ────────────────
        $this->connection->executeStatement('DROP POLICY IF EXISTS tenant_isolation ON public.opponent_travel');
        $this->connection->executeStatement('DROP POLICY IF EXISTS admin_all ON public.opponent_travel');
        $this->connection->executeStatement('DROP TABLE IF EXISTS opponent_travel');
    }

    public function down(Schema $schema): void
    {
        // Recrée opponent_travel (schéma des migrations 20260828120000 + 20260915130000) —
        // sans restaurer les données (une down de refactor de données ne les recompose pas).
        $this->addSql('CREATE TABLE opponent_travel (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, opponent_organisme_code VARCHAR(64) NOT NULL, opponent_team_key VARCHAR(180) DEFAULT NULL, travel_minutes SMALLINT DEFAULT NULL, source VARCHAR(10) NOT NULL, override_venue_external_ref VARCHAR(64) DEFAULT NULL, override_venue_label VARCHAR(180) DEFAULT NULL, override_latitude DOUBLE PRECISION DEFAULT NULL, override_longitude DOUBLE PRECISION DEFAULT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_opponent_travel_team ON opponent_travel (club_id, season_id, opponent_organisme_code, opponent_team_key) NULLS NOT DISTINCT');
        $this->addSql('CREATE INDEX idx_opponent_travel_club_season ON opponent_travel (club_id, season_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON opponent_travel TO ' . $appRole);
            $this->addSql('ALTER TABLE public.opponent_travel ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.opponent_travel FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.opponent_travel FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.opponent_travel FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.opponent_venue_link');
        $this->addSql('DROP POLICY IF EXISTS admin_all ON public.opponent_venue_link');
        $this->addSql('DROP TABLE opponent_venue_link');
    }
}
