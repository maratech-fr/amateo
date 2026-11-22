<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le lieu au club : `place` porte l'adresse et la position d'un gymnase, une fois, au club ; `venue`
 * devient le gymnase d'une saison et pointe son lieu par `place_id`.
 *
 * Conversion : un lieu par ligne de gymnase, SANS fusion (deux gymnases à la même adresse donnent deux
 * lieux), identifiants de `venue` inchangés, adresse et position recopiées à l'égal. Les colonnes
 * d'adresse et de position quittent `venue`. Les photos de versions (`schedule_structure_snapshot.data`)
 * perdent l'adresse et la position et gagnent `placeId` ; la ligne d'un gymnase qui n'existe plus reçoit
 * un lieu créé depuis l'adresse et la position de la photo (« Charger » la ramène sans rien perdre). La
 * migration s'arrête (exception PostgreSQL) si un lieu manque à l'appel.
 *
 * Clé étrangère COMPOSÉE `(place_id, club_id)` → `place (id, club_id)`, `ON DELETE RESTRICT`, posée à la
 * main (invisible de Doctrine) : une clé simple laisserait un gymnase du club A désigner le lieu du club B,
 * une vérification de clé étrangère ignorant la RLS. Au plus un gymnase par lieu et par saison.
 * RLS : patron Version20261122140000 (GRANT, ENABLE/FORCE, `tenant_isolation`, `admin_all`, `readonly_tenant`).
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit les
 * colonnes supprimées (dérogation écrite à la règle « compatible une release en arrière »). Irréversible.
 */
final class Version20261125120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    /** Le lieu d'une ligne de photo dont le gymnase n'existe plus : un identifiant tiré de la photo et de la ligne. */
    private const string ORPHAN_PHOTO_PLACE_ID = 'md5(s.id::text || \':\' || (e.elem ->> \'id\'))::uuid';

    public function getDescription(): string
    {
        return 'place: un lieu au club par gymnase (sans fusion), venue.place_id (clé composée avec le club), adresse et position retirées de venue, photos nettoyées.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE place (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, address VARCHAR(255) DEFAULT NULL, latitude NUMERIC(10, 7) DEFAULT NULL, longitude NUMERIC(10, 7) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_place_club ON place (club_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_place_id_club ON place (id, club_id)');

        // Un lieu par ligne de gymnase : l'identifiant du lieu est tiré sur la ligne du gymnase, puis le
        // lieu est écrit depuis cette ligne (jamais regroupé par adresse).
        $this->addSql('ALTER TABLE venue ADD place_id UUID DEFAULT NULL');
        $this->addSql('UPDATE venue SET place_id = gen_random_uuid()');
        $this->addSql('INSERT INTO place (id, version, created_at, updated_at, club_id, address, latitude, longitude) SELECT place_id, 1, created_at, updated_at, club_id, address, latitude, longitude FROM venue');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              IF (SELECT COUNT(*) FROM place) <> (SELECT COUNT(*) FROM venue)
                 OR EXISTS (SELECT 1 FROM venue v WHERE NOT EXISTS (SELECT 1 FROM place p WHERE p.id = v.place_id AND p.club_id = v.club_id)) THEN
                RAISE EXCEPTION 'Conversion des gymnases en lieux : % lieux pour % gymnases, la migration s''arrête.',
                  (SELECT COUNT(*) FROM place), (SELECT COUNT(*) FROM venue);
              END IF;
            END
            $$
            SQL);

        $this->addSql('ALTER TABLE venue ALTER place_id SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_venue_season_place ON venue (season_id, place_id)');
        $this->addSql('ALTER TABLE venue ADD CONSTRAINT fk_venue_place_club FOREIGN KEY (place_id, club_id) REFERENCES place (id, club_id) ON DELETE RESTRICT');

        // Photos : la ligne d'un gymnase qui n'existe plus (dans le club de la photo) reçoit un lieu créé
        // depuis l'adresse et la position que la photo portait : « Charger » ramène le gymnase et ses
        // créneaux, adresse comprise.
        $this->addSql(\sprintf(
            <<<'SQL'
                INSERT INTO place (id, version, created_at, updated_at, club_id, address, latitude, longitude)
                SELECT DISTINCT ON (%1$s) %1$s, 1, now(), now(), s.club_id, e.elem ->> 'address', (e.elem ->> 'latitude')::numeric, (e.elem ->> 'longitude')::numeric
                FROM schedule_structure_snapshot s
                CROSS JOIN LATERAL jsonb_array_elements(s.data::jsonb -> 'Venue') AS e(elem)
                WHERE jsonb_typeof(s.data::jsonb -> 'Venue') = 'array'
                  AND NOT EXISTS (SELECT 1 FROM venue v WHERE v.id::text = e.elem ->> 'id' AND v.club_id = s.club_id)
                SQL,
            self::ORPHAN_PHOTO_PLACE_ID,
        ));

        // Photos : chaque ligne de gymnase perd l'adresse et la position et gagne l'identifiant de son lieu
        // (celui du gymnase s'il existe, sinon celui créé ci-dessus) ; nom et numéro fédéral restent.
        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE schedule_structure_snapshot s
                SET data = jsonb_set(
                  s.data::jsonb,
                  '{Venue}',
                  COALESCE((
                    SELECT jsonb_agg((e.elem - 'address' - 'latitude' - 'longitude') || jsonb_build_object('placeId', COALESCE(v.place_id, %s)::text) ORDER BY e.ord)
                    FROM jsonb_array_elements(s.data::jsonb -> 'Venue') WITH ORDINALITY AS e(elem, ord)
                    LEFT JOIN venue v ON v.id::text = e.elem ->> 'id' AND v.club_id = s.club_id
                  ), '[]'::jsonb)
                )::json
                WHERE jsonb_typeof(s.data::jsonb -> 'Venue') = 'array'
                SQL,
            self::ORPHAN_PHOTO_PLACE_ID,
        ));

        $this->addSql('ALTER TABLE venue DROP address, DROP latitude, DROP longitude');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON place TO ' . $appRole);
            $this->addSql('ALTER TABLE public.place ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.place FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.place FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.place FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : comme `venue` avant elle (aucun secret, l'adresse était déjà lisible).
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.place TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.place FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigration(
            'Les colonnes d\'adresse et de position de venue supprimées ne se recréent pas depuis les lieux sans risque. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
