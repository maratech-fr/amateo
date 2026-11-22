<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La personne au club : `person` porte le nom, le mail, le téléphone et le genre d'un coach, une fois,
 * au club ; `coach` devient le rôle d'une saison et pointe sa fiche par `person_id`.
 *
 * Conversion : une fiche par ligne de coach, SANS fusion (deux coachs homonymes donnent deux fiches),
 * identifiants de `coach` inchangés. Les colonnes d'identité quittent `coach`. Les photos de versions
 * (`schedule_structure_snapshot.data`) perdent l'identité et gagnent `personId` ; la ligne d'un coach qui
 * n'existe plus en sort. La migration s'arrête (exception PostgreSQL) si une fiche manque à l'appel.
 *
 * Clé étrangère COMPOSÉE `(person_id, club_id)` → `person (id, club_id)`, `ON DELETE RESTRICT`, posée à la
 * main (invisible de Doctrine) : une clé simple laisserait un coach du club A désigner la fiche du club B,
 * une vérification de clé étrangère ignorant la RLS. Au plus un rôle coach par personne et par saison.
 * RLS : patron Version20261110120000 (GRANT, ENABLE/FORCE, `tenant_isolation`, `admin_all`, `readonly_tenant`).
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit les
 * colonnes supprimées (dérogation écrite à la règle « compatible une release en arrière »). Irréversible.
 */
final class Version20261122140000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'person: une fiche de personne au club par coach (sans fusion), coach.person_id (clé composée avec le club), colonnes d\'identité retirées de coach, photos nettoyées.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE person (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, gender VARCHAR(12) DEFAULT \'UNSPECIFIED\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_person_club ON person (club_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_person_id_club ON person (id, club_id)');

        // Une fiche par ligne de coach : l'identifiant de la fiche est tiré sur la ligne du coach, puis la
        // fiche est écrite depuis cette ligne (jamais regroupée par nom).
        $this->addSql('ALTER TABLE coach ADD person_id UUID DEFAULT NULL');
        $this->addSql('UPDATE coach SET person_id = gen_random_uuid()');
        $this->addSql('INSERT INTO person (id, version, created_at, updated_at, club_id, first_name, last_name, email, phone, gender) SELECT person_id, 1, created_at, updated_at, club_id, first_name, last_name, email, phone, gender FROM coach');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              IF (SELECT COUNT(*) FROM person) <> (SELECT COUNT(*) FROM coach)
                 OR EXISTS (SELECT 1 FROM coach c WHERE NOT EXISTS (SELECT 1 FROM person p WHERE p.id = c.person_id AND p.club_id = c.club_id)) THEN
                RAISE EXCEPTION 'Conversion des coachs en personnes : % fiches pour % coachs, la migration s''arrête.',
                  (SELECT COUNT(*) FROM person), (SELECT COUNT(*) FROM coach);
              END IF;
            END
            $$
            SQL);

        $this->addSql('ALTER TABLE coach ALTER person_id SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_coach_season_person ON coach (season_id, person_id)');
        $this->addSql('ALTER TABLE coach ADD CONSTRAINT fk_coach_person_club FOREIGN KEY (person_id, club_id) REFERENCES person (id, club_id) ON DELETE RESTRICT');

        // Photos : la ligne d'un coach qui existe encore (dans le club de la photo) garde ses options de
        // planning, perd l'identité et gagne l'identifiant de sa fiche ; celle d'un coach disparu sort.
        $this->addSql(<<<'SQL'
            UPDATE schedule_structure_snapshot s
            SET data = jsonb_set(
              s.data::jsonb,
              '{Coach}',
              COALESCE((
                SELECT jsonb_agg((e.elem - 'firstName' - 'lastName' - 'email' - 'phone' - 'gender') || jsonb_build_object('personId', c.person_id::text) ORDER BY e.ord)
                FROM jsonb_array_elements(s.data::jsonb -> 'Coach') WITH ORDINALITY AS e(elem, ord)
                JOIN coach c ON c.id::text = e.elem ->> 'id' AND c.club_id = s.club_id
              ), '[]'::jsonb)
            )::json
            WHERE jsonb_typeof(s.data::jsonb -> 'Coach') = 'array'
            SQL);

        $this->addSql('ALTER TABLE coach DROP first_name, DROP last_name, DROP email, DROP phone, DROP gender');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON person TO ' . $appRole);
            $this->addSql('ALTER TABLE public.person ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.person FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.person FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.person FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : comme `coach` avant elle (aucun secret, l'identité était déjà lisible).
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.person TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.person FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigration(
            'Les colonnes d\'identité de coach supprimées ne se recréent pas depuis les fiches sans risque. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
