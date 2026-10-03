<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ALIGN-18 (décision fondateur) — « on ne verrouille que les créneaux » : le cran `LOCK` a été
 * retiré des CONTRAINTES. Il n'est plus une valeur de l'enum `ConstraintRuleType` ({@see
 * App\Enum\ConstraintRuleType}) ; une écriture `LOCK` est désormais refusée 422. Le moteur
 * traitait déjà un `LOCK` TIME/DAY/FACILITY exactement comme un `HARD` (mapping silencieux,
 * désormais supprimé) : le recalage des données legacy est donc une CONVERSION FIDÈLE vers HARD,
 * jamais une perte de sens.
 *
 * ⚠ OBLIGATOIRE : la colonne `constraint.rule_type` est mappée par Doctrine en `enumType`
 * (`ConstraintRuleType`). Sans ce recalage, toute hydratation d'une ligne résiduelle `LOCK`
 * lèverait un `ValueError` (« not a valid backing value ») — un 500 silencieux sur une donnée
 * pourtant écrite avant le retrait du cran.
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5,
 * aucun changement de schéma). RLS sans objet : les migrations tournent sous `amateo_owner`
 * (BYPASSRLS), le recalage est global. WHERE ciblé → idempotente et rejouable (un second passage
 * ne trouve plus de `rule_type = 'LOCK'`).
 */
final class Version20261003090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ALIGN-18: recale les contraintes legacy rule_type=LOCK en HARD (le cran « verrouillé » est retiré des contraintes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE "constraint" SET rule_type = \'HARD\' WHERE rule_type = \'LOCK\'');
    }

    public function down(Schema $schema): void
    {
        // No-op JUSTIFIÉ : le reverse est à la fois impossible et indésirable. Impossible, car
        // après conversion une règle MIGRÉE (LOCK→HARD) et une règle HARD SAISIE sont
        // indistinguables (le cran LOCK ne portait aucune sémantique propre, le moteur le
        // traitait comme HARD) ; indésirable, car `LOCK` n'est plus une valeur de l'enum —
        // réécrire des lignes `LOCK` ferait lever un ValueError à l'hydratation. En dev/test le
        // round-trip up→down→up reste jouable (down ne touche rien, up est idempotent). En
        // production, on restaure la sauvegarde antérieure à la migration, jamais ce down().
    }
}
