<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ALIGN-09 — `forcedDays` change de SENS : le wizard l'émet désormais pour « au moins une
 * séance l'un de ces jours » (l'agrégat que le moteur applique, {@see constraints.py}), et NON
 * plus pour « uniquement ces jours-là ».
 *
 * Or `forcedDays` a déjà servi de clé LEGACY (#120) au sens « uniquement » — que le produit
 * exprime aujourd'hui par `allowedDays` (whitelist, ENG-16). Laisser ces lignes héritées en base
 * les réinterpréterait sous la NOUVELLE sémantique : « uniquement le samedi » deviendrait « au
 * moins une séance le samedi », les autres jours rouverts en silence. On les transcrit donc une
 * fois pour toutes vers `allowedDays`, qui porte leur intention d'origine.
 *
 * Deux endroits, le second est celui qu'on oublie :
 * 1. les contraintes VIVES (`constraint.config`) ;
 * 2. les **photos de structure** (`schedule_structure_snapshot.data->'Constraint'`) — sans elles,
 *    « Charger cette version » réinjecte la clé legacy AVEC le nouveau sens : corruption sémantique
 *    d'une version pourtant saine au moment du cliché.
 *
 * Règle de transcription (l'intention AFFICHÉE fait foi) : si `allowedDays` coexiste déjà, on se
 * contente de retirer `forcedDays` (doublon) ; sinon `forcedDays` est RENOMMÉE `allowedDays`.
 *
 * ⚠ Piège SQL (identique à {@see Version20260807190000}) : `config` est de type `json`, pas
 * `jsonb` → casts `::jsonb` explicites ; et l'opérateur `?` (« la clé existe ») est lu par PDO
 * comme un paramètre positionnel → on écrit `jsonb_exists(x, 'clé')`.
 *
 * Idempotente et sûre sur 0..N lignes (en dev, 0 ligne : c'est un filet pour les bases qui ont
 * porté la clé legacy).
 */
final class Version20260823120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ALIGN-09: transcribe legacy DAY forcedDays ("only these days") to allowedDays, in live constraints AND structure snapshots.';
    }

    public function up(Schema $schema): void
    {
        // 1. Les contraintes vives. Borné à DAY : c'est la seule famille où `forcedDays` est une
        //    clé connue (ConstraintConfigValidator). allowedDays présent → drop du doublon ;
        //    sinon rename forcedDays → allowedDays.
        $this->addSql(<<<'SQL'
            UPDATE "constraint"
            SET config = (
                    CASE
                        WHEN jsonb_exists(config::jsonb, 'allowedDays')
                            THEN config::jsonb - 'forcedDays'
                        ELSE jsonb_set(config::jsonb - 'forcedDays', '{allowedDays}', config::jsonb->'forcedDays')
                    END
                )::json,
                updated_at = NOW()
            WHERE family = 'DAY' AND jsonb_exists(config::jsonb, 'forcedDays')
            SQL);

        // 2. Les photos de structure. On reconstruit le tableau `Constraint` en transcrivant le
        //    `config` de chaque élément qui porte `forcedDays` (clé DAY-only), ORDINALITY à l'appui
        //    pour préserver l'ordre du cliché — un restore doit rendre la même structure.
        $this->addSql(<<<'SQL'
            UPDATE schedule_structure_snapshot s
            SET data = jsonb_set(
                    s.data::jsonb,
                    '{Constraint}',
                    COALESCE((
                        SELECT jsonb_agg(
                            CASE
                                WHEN jsonb_exists(e.item->'config', 'forcedDays') AND jsonb_exists(e.item->'config', 'allowedDays')
                                    THEN jsonb_set(e.item, '{config}', (e.item->'config') - 'forcedDays')
                                WHEN jsonb_exists(e.item->'config', 'forcedDays')
                                    THEN jsonb_set(e.item, '{config}', jsonb_set((e.item->'config') - 'forcedDays', '{allowedDays}', e.item->'config'->'forcedDays'))
                                ELSE e.item
                            END ORDER BY e.ord
                        )
                        FROM jsonb_array_elements(s.data::jsonb->'Constraint') WITH ORDINALITY AS e(item, ord)
                    ), '[]'::jsonb)
                )::json,
                updated_at = NOW()
            WHERE jsonb_exists(s.data::jsonb, 'Constraint')
              AND EXISTS (
                  SELECT 1 FROM jsonb_array_elements(s.data::jsonb->'Constraint') c
                  WHERE jsonb_exists(c->'config', 'forcedDays')
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Irréversible : une fois transcrit en `allowedDays`, rien ne distingue plus une whitelist
        // née d'un `forcedDays` legacy d'une whitelist saisie directement — et remettre le sens
        // legacy « uniquement » sous la clé `forcedDays` la réinterpréterait sous la nouvelle
        // sémantique « au moins une ». On n'annule donc pas.
        $this->throwIrreversibleMigration('ALIGN-09: legacy forcedDays→allowedDays transcription cannot be reversed (the original polarity is not recoverable).');
    }
}
