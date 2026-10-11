<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot A « l'export ne ment plus » (décision fondateur 2026-10-11), axe planning lifecycle —
 * LE LIBELLÉ DE CRÉNEAU APPARTIENT AU PLANNING DE SAISON.
 *
 * Le libellé de groupe (« CEC3 ») n'a de sens que dans la grille de SAISON. Jusqu'ici la copie
 * prise à la naissance d'un plan de période reportait le libellé du modèle, et un libellé pouvait
 * même être saisi directement sur un créneau de période : l'écran et l'export montraient alors un
 * nom de groupe que personne n'avait voulu pour cette période. La copie ne recopie plus le libellé
 * (SchedulePlanProvisioner::copySeasonalSlotRows émet NULL) et l'API le refuse sur un créneau de
 * période (VenueTrainingSlotStateProcessor). Cette migration FIGE les données déjà nées : tout
 * créneau rattaché à un plan (schedule_plan_id IS NOT NULL) perd son libellé.
 *
 * Tourne sur la connexion applicative (amateo_owner) : aucun RLS en migration, pur DML sur tous
 * les clubs. Idempotente par nature (rejouée, elle remet NULL sur des colonnes déjà NULL).
 */
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot A: group_label belongs to the season grid — strip it from every slot attached to a schedule plan (period slots).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE venue_training_slot SET group_label = NULL WHERE schedule_plan_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // No-op VOULU : l'effacement est irréversible. Les libellés portés par les créneaux de
        // période n'étaient pas conservés ailleurs ; rien ne permet de les reconstruire. On ne
        // feint pas un rollback qui ne restaurerait rien — on ne touche à rien.
    }
}
