<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les règles appliquées par une période (lot 4 conception, PR 4) : une ligne `plan_constraint` par (plan de période,
 * contrainte permanente de sa saison) — « s'applique ». Aucune ligne pour le plan de saison ni pour une contrainte
 * datée (elle appartient à sa période).
 *
 * Dans l'ordre, en une transaction :
 *  1. La table et sa clé composée (plan, club) vers le plan en cascade : une ligne ne désigne jamais le plan d'un
 *     autre club (une vérification de clé étrangère ignore la RLS). Pas de clé vers la contrainte : « Charger » efface
 *     puis réinsère les contraintes permanentes.
 *  2. Chaque couple (plan de période, contrainte permanente) : sa coche si elle existe, sinon le défaut de la nature
 *     du plan (fermeture : tout s'applique ; vacances : seules les règles de tout le club). Une coche dont la
 *     contrainte n'existe plus (ou est datée) ne donne aucune ligne.
 *  3. Arrêt si le nombre de lignes n'est pas « contraintes permanentes × plans de période » de chaque saison (deux
 *     coches pour un même couple, par exemple). L'unicité (plan, contrainte) n'est posée qu'ensuite, pour que ce compte
 *     parle le premier.
 *  4. Suppression de `constraint_period_override`.
 *  5. RLS active et forcée (patron Version20261128120000).
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit la table
 * supprimée (dérogation écrite à la règle « compatible une release en arrière »). Irréversible.
 */
final class Version20261130120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'Les règles appliquées par une période : plan_constraint (une ligne par plan de période et contrainte permanente, coches converties) ; constraint_period_override supprimée.';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        // 1. La table.
        $this->addSql('CREATE TABLE plan_constraint (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID NOT NULL, constraint_id UUID NOT NULL, applies BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_constraint_season ON plan_constraint (season_id)');
        $this->addSql('CREATE INDEX idx_plan_constraint_constraint ON plan_constraint (constraint_id)');
        $this->addSql('ALTER TABLE plan_constraint ADD CONSTRAINT fk_plan_constraint_schedule_plan FOREIGN KEY (schedule_plan_id, club_id) REFERENCES schedule_plan (id, club_id) ON DELETE CASCADE');

        // 2. Chaque couple (plan de période, contrainte permanente) : la coche, sinon le défaut de la nature.
        $this->addSql(<<<'SQL'
            INSERT INTO plan_constraint (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, constraint_id, applies)
            SELECT gen_random_uuid(), 1, now(), now(), p.club_id, p.season_id, p.id, c.id,
              COALESCE(o.is_active, p.type = 'CLOSURE' OR c.scope = 'CLUB')
            FROM schedule_plan p
            JOIN "constraint" c ON c.season_id = p.season_id AND c.calendar_entry_id IS NULL
            LEFT JOIN constraint_period_override o ON o.schedule_plan_id = p.id AND o.constraint_id = c.id
            WHERE p.type <> 'SEASON'
            SQL);

        // 3. Une ligne par (plan de période, contrainte permanente), ni plus ni moins ; puis l'unicité.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
              written INT;
              expected INT;
            BEGIN
              SELECT COUNT(*) INTO written FROM plan_constraint;
              SELECT COUNT(*) INTO expected FROM schedule_plan p JOIN "constraint" c ON c.season_id = p.season_id AND c.calendar_entry_id IS NULL WHERE p.type <> 'SEASON';
              IF written <> expected THEN
                RAISE EXCEPTION 'Les règles appliquées par une période : % lignes pour % couples (plan de période, contrainte permanente), la conversion s''annule.', written, expected;
              END IF;
            END
            $$
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_plan_constraint ON plan_constraint (schedule_plan_id, constraint_id)');

        // 4. Ce que les lignes remplacent.
        $this->addSql('DROP TABLE constraint_period_override');

        // 5. RLS.
        $this->addSql('ALTER TABLE public.plan_constraint ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE public.plan_constraint FORCE ROW LEVEL SECURITY');
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON plan_constraint TO ' . $appRole);
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.plan_constraint FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'')) {
            $this->addSql('CREATE POLICY admin_all ON public.plan_constraint FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'')) {
            $this->addSql('GRANT SELECT ON public.plan_constraint TO amateo_read');
            $this->addSql(\sprintf('CREATE POLICY readonly_tenant ON public.plan_constraint FOR SELECT TO amateo_read USING (%s)', self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les coches de règle d\'une période ne se recréent pas sans risque depuis les lignes des plans. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
