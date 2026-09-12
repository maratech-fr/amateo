<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-199 — nettoyage des libellés d'équipe déjà stockés : la FFBB accole un
 * suffixe « (n) » en fin de libellé pour distinguer deux engagements homonymes
 * (« AL CALUIRE ET CUIRE - 3 (6) »). Depuis P4-199 l'import le retire à la lecture
 * (foyer unique {@see App\Service\Basketball\VenueLabelNormalizer::stripTeamNumberSuffix}) ;
 * cette migration rejoue le même retrait sur les données EXISTANTES :
 *   - `fixture.opponent_label` (le libellé adverse importé),
 *   - `competition.fbi_team_label` (la clé de rapprochement division↔équipe).
 *
 * Le retrait est ancré en FIN de chaîne (`$`) — un « (6) » en milieu de libellé
 * est intact — et gardé par un WHERE qui ne touche que les lignes suffixées :
 * idempotent et rejouable (un second passage ne trouve plus rien).
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que
 * doctrine/dbal < 4.5, aucun changement de schéma de toute façon). RLS sans objet :
 * les migrations tournent sous `amateo_owner` (BYPASSRLS) — le nettoyage est global.
 */
final class Version20260912120000 extends AbstractMigration
{
    /** Le motif POSIX (ARE PostgreSQL) : espaces + « (chiffres) » collés en fin de chaîne. */
    private const SUFFIX_PATTERN = '\\s+\\(\\d+\\)\\s*$';

    public function getDescription(): string
    {
        return 'P4-199: strip the FFBB « (n) » team-number suffix from stored fixture.opponent_label and competition.fbi_team_label.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(\sprintf(
            'UPDATE fixture SET opponent_label = regexp_replace(opponent_label, \'%s\', \'\') WHERE opponent_label ~ \'%s\'',
            self::SUFFIX_PATTERN,
            self::SUFFIX_PATTERN,
        ));
        $this->addSql(\sprintf(
            'UPDATE competition SET fbi_team_label = regexp_replace(fbi_team_label, \'%s\', \'\') WHERE fbi_team_label IS NOT NULL AND fbi_team_label ~ \'%s\'',
            self::SUFFIX_PATTERN,
            self::SUFFIX_PATTERN,
        ));
    }

    public function down(Schema $schema): void
    {
        // Irréversible : le suffixe retiré n'est pas reconstituable (on ne sait plus
        // quel numéro était accolé). Aucun schéma à défaire — no-op assumé.
    }
}
