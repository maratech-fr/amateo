<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une seule offre payante, à trois paliers : la ligne Essentiel devient l'offre complète (même id,
 * code `complete`, nom « Offre complète ») ; `subscription_plan_tier` porte ses trois paliers
 * (`palier-1` 20 équipes, `palier-2` 34, `palier-3` illimité = 0), sans prix posé (`price_cents`,
 * posé à la main en production) ; `club_subscription.tier_id` désigne le palier d'une ligne.
 *
 * Conversion, sans changer le plafond d'aucun club : une ligne Essentiel passe au palier 1, une
 * ligne Club à l'offre complète au palier 2, une ligne Grand club à l'offre complète au palier 3 ;
 * saison, règlement et origine ne bougent pas. Découverte et Bêta restent sans palier. Les offres
 * Club et Grand club partent ; le plafond quitte l'offre (`subscription_plan.max_teams`).
 *
 * Accès : la table des paliers n'a pas de `club_id` (référentiel de plateforme, pas de RLS) ;
 * lisible par le rôle de lecture, à côté de `subscription_plan`. Le rôle applicatif LIT les deux
 * catalogues et n'en écrit aucun (INSERT, UPDATE, DELETE, TRUNCATE retirés).
 *
 * Intégrité, en base : le palier d'une ligne appartient à son offre (clé composite) et une offre
 * qui porte des paliers exige un palier (déclencheur).
 *
 * Part dans la mise en production unique du lot conception, site fermé. Irréversible.
 */
final class Version20261124120000 extends AbstractMigration
{
    private const string COMPLETE_PLAN = '00000000-0000-4000-8000-000000000002';
    private const string CLUB_PLAN = '00000000-0000-4000-8000-000000000003';
    private const string GRAND_CLUB_PLAN = '00000000-0000-4000-8000-000000000004';

    /** Les trois paliers : [id, code, position, plafond (0 = illimité)]. */
    private const array TIERS = [
        ['00000000-0000-4000-8000-000000000101', 'palier-1', 1, 20],
        ['00000000-0000-4000-8000-000000000102', 'palier-2', 2, 34],
        ['00000000-0000-4000-8000-000000000103', 'palier-3', 3, 0],
    ];

    public function getDescription(): string
    {
        return 'One paid offer with three tiers: Essentiel becomes the complete offer, subscription_plan_tier (20 / 34 / unlimited), club_subscription.tier_id; Club and Grand club lines move to their tier, both offers and subscription_plan.max_teams dropped.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscription_plan_tier (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, plan_id UUID NOT NULL, code VARCHAR(60) NOT NULL, position INT NOT NULL, max_teams INT NOT NULL, price_cents INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscription_plan_tier_code ON subscription_plan_tier (code)');
        $this->addSql('CREATE INDEX idx_subscription_plan_tier_plan ON subscription_plan_tier (plan_id)');
        $this->addSql('ALTER TABLE subscription_plan_tier ADD CONSTRAINT fk_subscription_plan_tier_plan FOREIGN KEY (plan_id) REFERENCES subscription_plan (id) ON DELETE RESTRICT');

        foreach (self::TIERS as [$id, $code, $position, $maxTeams]) {
            $this->addSql(
                'INSERT INTO subscription_plan_tier (id, created_at, updated_at, plan_id, code, position, max_teams, price_cents) VALUES (:id, NOW(), NOW(), :plan, :code, :position, :maxTeams, NULL)',
                ['id' => $id, 'plan' => self::COMPLETE_PLAN, 'code' => $code, 'position' => $position, 'maxTeams' => $maxTeams],
            );
        }

        $this->addSql('ALTER TABLE club_subscription ADD tier_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_club_subscription_tier ON club_subscription (tier_id)');
        $this->addSql('ALTER TABLE club_subscription ADD CONSTRAINT fk_club_subscription_tier FOREIGN KEY (tier_id) REFERENCES subscription_plan_tier (id) ON DELETE RESTRICT');

        // Chaque ligne payante au palier qui porte SON plafond d'avant, sur l'offre complète.
        foreach ([[self::COMPLETE_PLAN, 'palier-1'], [self::CLUB_PLAN, 'palier-2'], [self::GRAND_CLUB_PLAN, 'palier-3']] as [$planBefore, $tier]) {
            $this->addSql(
                'UPDATE club_subscription SET plan_id = :complete, tier_id = (SELECT id FROM subscription_plan_tier WHERE code = :tier) WHERE plan_id = :before',
                ['complete' => self::COMPLETE_PLAN, 'tier' => $tier, 'before' => $planBefore],
            );
        }

        $this->addSql(
            'UPDATE subscription_plan SET code = \'complete\', name = \'Offre complète\', updated_at = NOW() WHERE id = :complete',
            ['complete' => self::COMPLETE_PLAN],
        );
        $this->addSql('DELETE FROM subscription_plan WHERE id IN (:club, :grandClub)', ['club' => self::CLUB_PLAN, 'grandClub' => self::GRAND_CLUB_PLAN]);
        $this->addSql('ALTER TABLE subscription_plan DROP max_teams');

        // Le palier d'une ligne appartient à SON offre (clé composite : vérifiée à l'insertion, à la
        // modification du palier ET au changement d'offre) ; une offre qui porte des paliers exige un
        // palier (déclencheur : une contrainte CHECK ne lit pas une autre table). Posés après la
        // conversion, qui les respecte.
        $this->addSql('ALTER TABLE subscription_plan_tier ADD CONSTRAINT uniq_subscription_plan_tier_of_plan UNIQUE (id, plan_id)');
        $this->addSql('ALTER TABLE club_subscription ADD CONSTRAINT fk_club_subscription_tier_of_plan FOREIGN KEY (tier_id, plan_id) REFERENCES subscription_plan_tier (id, plan_id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION club_subscription_tier_required() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp
            AS $fn$
            BEGIN
                IF NEW.tier_id IS NULL AND EXISTS (SELECT 1 FROM subscription_plan_tier WHERE plan_id = NEW.plan_id) THEN
                    RAISE EXCEPTION 'club_subscription: the offer % has tiers, the line needs one of them', NEW.plan_id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $fn$
            SQL);
        $this->addSql('CREATE TRIGGER trg_club_subscription_tier_required BEFORE INSERT OR UPDATE OF plan_id, tier_id ON club_subscription FOR EACH ROW EXECUTE FUNCTION club_subscription_tier_required()');

        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'')) {
            $this->addSql('GRANT SELECT ON public.subscription_plan_tier TO amateo_read');
        }

        // Les deux catalogues (offres, paliers) sont lus par tous les clubs : le rôle applicatif les
        // LIT seulement. L'`ALTER DEFAULT PRIVILEGES` de Version20260703120000 lui confère l'écriture,
        // un GRANT restreint ne la retire pas (patron du REVOKE de Version20261123120000). Seule la
        // connexion propriétaire (migrations, support) les écrit.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            foreach (['subscription_plan', 'subscription_plan_tier'] as $table) {
                $this->addSql(\sprintf('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON public.%s FROM %s', $table, $appRole));
                $this->addSql(\sprintf('GRANT SELECT ON public.%s TO %s', $table, $appRole));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les offres Club et Grand club ne se recréent pas depuis les paliers sans réécrire les lignes. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
