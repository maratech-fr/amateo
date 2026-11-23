<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le temps de trajet au club : `place_travel_time` relie deux LIEUX du club, sans saison ;
 * `venue_travel_time` (un barème par saison, entre deux gymnases) est supprimée.
 *
 * Conversion ligne à ligne : chaque barème devient le temps entre les lieux de ses deux gymnases,
 * identifiant, minutes et origine recopiés à l'égal, couple rangé « plus petit identifiant de lieu
 * d'abord » (contrôle `place_a_id < place_b_id`). Deux barèmes de saisons différentes entre les mêmes
 * lieux (la saison suivante reprend les lieux) n'en font qu'un : le plus récemment modifié. La
 * migration s'arrête (exception PostgreSQL) si un couple manque à l'appel, barème dont un gymnase
 * n'existe plus compris.
 *
 * Deux clés étrangères COMPOSÉES `(place_a_id, club_id)` et `(place_b_id, club_id)` → `place (id,
 * club_id)`, `ON DELETE CASCADE`, posées à la main (invisibles de Doctrine) : une clé simple laisserait
 * un temps du club A relier un lieu du club B, une vérification de clé étrangère ignorant la RLS ; le
 * temps part avec l'un de ses lieux. Un temps par couple et par club.
 * RLS : patron Version20261125120000 (GRANT, ENABLE/FORCE, `tenant_isolation`, `admin_all`, `readonly_tenant`).
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit la
 * table supprimée (dérogation écrite à la règle « compatible une release en arrière »). Irréversible.
 */
final class Version20261126120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'place_travel_time: un temps de trajet au club entre deux lieux (clés composées avec le club), conversion de venue_travel_time ligne à ligne, venue_travel_time supprimée.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE place_travel_time (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, place_a_id UUID NOT NULL, place_b_id UUID NOT NULL, driving_minutes SMALLINT DEFAULT NULL, walking_minutes SMALLINT DEFAULT NULL, driving_source VARCHAR(10) DEFAULT NULL, walking_source VARCHAR(10) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT chk_place_travel_time_order CHECK (place_a_id < place_b_id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_place_travel_time_couple ON place_travel_time (club_id, place_a_id, place_b_id)');
        $this->addSql('CREATE INDEX idx_place_travel_time_place_a ON place_travel_time (place_a_id)');
        $this->addSql('CREATE INDEX idx_place_travel_time_place_b ON place_travel_time (place_b_id)');
        $this->addSql('ALTER TABLE place_travel_time ADD CONSTRAINT fk_place_travel_time_place_a_club FOREIGN KEY (place_a_id, club_id) REFERENCES place (id, club_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE place_travel_time ADD CONSTRAINT fk_place_travel_time_place_b_club FOREIGN KEY (place_b_id, club_id) REFERENCES place (id, club_id) ON DELETE CASCADE');

        // Un temps par couple de lieux et par club : entre deux barèmes des mêmes lieux, le plus récemment modifié.
        $this->addSql(<<<'SQL'
            INSERT INTO place_travel_time (id, version, created_at, updated_at, club_id, place_a_id, place_b_id, driving_minutes, walking_minutes, driving_source, walking_source)
            SELECT DISTINCT ON (t.club_id, LEAST(va.place_id, vb.place_id), GREATEST(va.place_id, vb.place_id))
                   t.id, t.version, t.created_at, t.updated_at, t.club_id,
                   LEAST(va.place_id, vb.place_id), GREATEST(va.place_id, vb.place_id),
                   t.driving_minutes, t.walking_minutes, t.driving_source, t.walking_source
            FROM venue_travel_time t
            JOIN venue va ON va.id = t.venue_a_id AND va.club_id = t.club_id
            JOIN venue vb ON vb.id = t.venue_b_id AND vb.club_id = t.club_id
            ORDER BY t.club_id, LEAST(va.place_id, vb.place_id), GREATEST(va.place_id, vb.place_id), t.updated_at DESC, t.id
            SQL);
        // Le compte : un temps par couple de lieux distinct ; un barème dont un gymnase n'existe plus compte
        // comme un couple à lui, jamais converti, et arrête donc la migration.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
              expected INT;
              converted INT;
            BEGIN
              SELECT COUNT(*) INTO expected FROM (
                SELECT DISTINCT t.club_id,
                       COALESCE(LEAST(va.place_id, vb.place_id)::text, 'barème ' || t.id::text),
                       COALESCE(GREATEST(va.place_id, vb.place_id)::text, 'barème ' || t.id::text)
                FROM venue_travel_time t
                LEFT JOIN venue va ON va.id = t.venue_a_id AND va.club_id = t.club_id
                LEFT JOIN venue vb ON vb.id = t.venue_b_id AND vb.club_id = t.club_id
              ) couples;
              SELECT COUNT(*) INTO converted FROM place_travel_time;
              IF converted <> expected THEN
                RAISE EXCEPTION 'Conversion des barèmes en temps de trajet : % temps de trajet pour % couples, la migration s''arrête.', converted, expected;
              END IF;
            END
            $$
            SQL);

        $this->addSql('DROP TABLE venue_travel_time');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON place_travel_time TO ' . $appRole);
            $this->addSql('ALTER TABLE public.place_travel_time ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.place_travel_time FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.place_travel_time FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.place_travel_time FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : comme `venue_travel_time` avant elle.
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.place_travel_time TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.place_travel_time FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les barèmes de trajet par saison supprimés ne se recréent pas depuis les temps au club sans risque. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
