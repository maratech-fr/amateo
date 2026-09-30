<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trajets (décision fondateur 2026-09-30) — deux volets d'une même feature :
 *
 *  (LOT B) Le levier « Trajet entre gymnases » gagne DEUX réglages club+saison, en plus de son
 *          intensité : `tolerance_minutes` (battement toléré, retranché du barème pour l'écart
 *          exigé) et `default_minutes` (barème d'un couple sans temps). Défaut 20 pour TOUS les
 *          clubs — une ligne absente applique le défaut sans exister (le résolveur retombe dessus).
 *
 *  (LOT A) Le mode NON VÉHICULÉ de la matrice devient le VÉLO/la trottinette (plus la marche). Les
 *          temps AUTO déjà calculés étaient des DURÉES piétonnes ; on les re-dérive en temps à vélo
 *          par la même loi que l'autofill (distance ≈ minutes piétonnes × 83 m/min, puis
 *          /250 m/min + 5 min de marge ≈ `CEIL(minutes × 3 / 10) + 5`). Les MANUAL ne sont JAMAIS
 *          touchés (colonne source). Et on PURGE le cache IGN piéton (`profile = 'pedestrian'`), qui
 *          contenait des durées : « Recalculer » ré-obtiendra les distances exactes. Le nom
 *          technique `walking_*` est conservé, seul le SENS change.
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5). Les ajouts de
 * colonne sont idempotents (IF NOT EXISTS) ; la re-dérivation AUTO est un one-shot (Doctrine ne
 * rejoue pas une migration déjà appliquée). down() retire les colonnes ; la conversion des minutes
 * AUTO est LOSSY et n'est pas restaurée (un « Recalculer » les régénère).
 */
final class Version20260930110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trajets: venue_travel_rule_setting.tolerance_minutes/default_minutes (def 20) + AUTO walking→bike re-derivation + pedestrian cache purge.';
    }

    public function up(Schema $schema): void
    {
        // (LOT B) Réglages club+saison — défaut 20 pour tous.
        $this->addSql('ALTER TABLE venue_travel_rule_setting ADD COLUMN IF NOT EXISTS tolerance_minutes INT NOT NULL DEFAULT 20');
        $this->addSql('ALTER TABLE venue_travel_rule_setting ADD COLUMN IF NOT EXISTS default_minutes INT NOT NULL DEFAULT 20');

        // (LOT A) Re-dérivation des temps AUTO du mode non véhiculé (durée piétonne → temps à vélo).
        //         Les MANUAL sont préservés (filtre sur la colonne source). Borné au smallint.
        $this->addSql(<<<'SQL'
            UPDATE venue_travel_time
            SET walking_minutes = CEIL(walking_minutes * 3 / 10.0) + 5
            WHERE walking_source = 'AUTO' AND walking_minutes IS NOT NULL
            SQL);

        // (LOT A) Purge du cache IGN piéton : il tenait des DURÉES, plus des distances de vélo.
        $this->addSql('DELETE FROM club_travel_cache WHERE profile = \'pedestrian\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_travel_rule_setting DROP COLUMN IF EXISTS default_minutes');
        $this->addSql('ALTER TABLE venue_travel_rule_setting DROP COLUMN IF EXISTS tolerance_minutes');
        // La conversion AUTO walking→vélo et la purge de cache ne se dé-migrent pas (lossy) :
        // un « Recalculer » régénère les valeurs exactes.
    }
}
