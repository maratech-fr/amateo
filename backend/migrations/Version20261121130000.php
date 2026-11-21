<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le jour de match enregistré d'une équipe passe du comptage de 0 (lundi) à 6 (dimanche) au comptage
 * de 1 (lundi) à 7 (dimanche), celui de tous les autres jours de la semaine et du moteur.
 *
 * Deux endroits, le second est celui qu'on oublie :
 * 1. la table `team` ;
 * 2. les photos de structure (`schedule_structure_snapshot.data`, clé `Team[].matchDay`) : sans elles,
 *    « Charger » une version d'avant la migration réécrirait un samedi (5) qui se lirait vendredi.
 *    L'ordre des équipes de la photo est gardé (ORDINALITY) ; une photo sans jour de match numérique
 *    n'est pas réécrite.
 *
 * `schedule.snapshot_data` (la demande déjà envoyée au moteur) comptait déjà de 1 à 7 : non touché.
 * Aucun DDL : l'ancien code lit toujours la colonne (il ajouterait 1 à une valeur convertie ; la mesure
 * en production valait 0 ligne renseignée).
 */
final class Version20261121130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team.match_day et Team[].matchDay des photos de structure : comptage de 1 (lundi) à 7 (dimanche)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE team SET match_day = match_day + 1 WHERE match_day IS NOT NULL');
        $this->addSql($this->shiftPhotos('+ 1'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE team SET match_day = match_day - 1 WHERE match_day IS NOT NULL');
        $this->addSql($this->shiftPhotos('- 1'));
    }

    /** @param '+ 1'|'- 1' $shift */
    private function shiftPhotos(string $shift): string
    {
        return <<<SQL
            UPDATE schedule_structure_snapshot s
            SET data = jsonb_set(
                    s.data::jsonb,
                    '{Team}',
                    COALESCE((
                        SELECT jsonb_agg(
                            CASE WHEN jsonb_typeof(e.item->'matchDay') = 'number'
                                 THEN jsonb_set(e.item, '{matchDay}', to_jsonb((e.item->>'matchDay')::int {$shift}))
                                 ELSE e.item
                            END ORDER BY e.ord
                        )
                        FROM jsonb_array_elements(s.data::jsonb->'Team') WITH ORDINALITY AS e(item, ord)
                    ), '[]'::jsonb)
                )::json,
                updated_at = NOW()
            WHERE jsonb_typeof(s.data::jsonb->'Team') = 'array'
              AND EXISTS (
                  SELECT 1 FROM jsonb_array_elements(s.data::jsonb->'Team') t
                  WHERE jsonb_typeof(t->'matchDay') = 'number'
              )
            SQL;
    }
}
