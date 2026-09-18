<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D1 (décision fondateur) — « préférer ce gymnase » est TOUJOURS une préférence
 * (PREFERRED) ; l'obligatoire, c'est « impose » (forcedVenueId). Les données legacy
 * portent des règles FACILITY « préfère » (config `preferredVenueId`) épinglées HARD ou
 * LOCK — le sens exact d'« impose ». Cette migration les convertit en « impose » :
 *   - clé de config `preferredVenueId` → `forcedVenueId` (même gymnase, mode « impose ») ;
 *   - libellé « préfère » → « impose ».
 *
 * La colonne `constraint.config` est de type `json` (pas `jsonb`) : les opérateurs `?`,
 * `-` et `||` exigent un cast `::jsonb`, puis un retour `::json`.
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5,
 * aucun changement de schéma). RLS sans objet : les migrations tournent sous `amateo_owner`
 * (BYPASSRLS), le recalage est global. WHERE ciblé → idempotente et rejouable (un second
 * passage ne trouve plus de `preferredVenueId` HARD/LOCK).
 *
 * ⚠ down() : reverse LOSSY, GARDÉ par `abortIf` (dev/test seulement, un docblock n'est pas un
 * contrôle). Après conversion, une règle migrée et une règle « impose » SAISIE À LA MAIN sont
 * INDISTINGUABLES (toutes deux `forcedVenueId` HARD, nom « impose ») — c'est le comportement
 * voulu par D1. Le reverse rétablirait « préfère » pour TOUTES ces règles : il sert le round-trip
 * de dev/test (up→prev→up), et se REFUSE partout ailleurs. En production, on restaure la
 * sauvegarde antérieure à la migration, jamais ce down().
 */
final class Version20260918200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D1: migrate legacy FACILITY HARD|LOCK « préfère » (preferredVenueId) rules into « impose » (forcedVenueId).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE "constraint" SET '
            . 'config = ((config::jsonb - \'preferredVenueId\') || jsonb_build_object(\'forcedVenueId\', config::jsonb->\'preferredVenueId\'))::json, '
            . 'name = replace(name, \'préfère\', \'impose\') '
            . 'WHERE family = \'FACILITY\' AND rule_type IN (\'HARD\', \'LOCK\') AND jsonb_exists(config::jsonb, \'preferredVenueId\')',
        );
    }

    public function down(Schema $schema): void
    {
        // ⚠ Reverse LOSSY, refusé hors dev/test (un docblock n'est pas un contrôle) : après
        // conversion, un « impose » MIGRÉ et un « impose » SAISI sont indistinguables, donc ce
        // rollback rétablirait « préfère » pour LES DEUX. Patron du dépôt (abortIf) : autorisé
        // en dev/test (round-trip up→prev→up jouable), refusé partout ailleurs — en prod, on
        // restaure la sauvegarde pré-migration, pas ce down().
        $this->abortIf(
            !\in_array($_SERVER['APP_ENV'] ?? 'prod', ['dev', 'test'], true),
            'Rollback lossy refusé : cette migration ne se rejoue à l\'envers qu\'en dev/test. '
            . 'Après conversion, les « impose » migrés et les « impose » saisis à la main sont '
            . 'indistinguables — ce down() les rétablirait TOUS en « préfère ». En production, '
            . 'restaurez la sauvegarde antérieure à la migration.',
        );
        // Reverse approximatif : rétablit « préfère » pour toute FACILITY HARD|LOCK « impose »
        // (forcedVenueId, nom « impose »), migrée OU saisie.
        $this->addSql(
            'UPDATE "constraint" SET '
            . 'config = ((config::jsonb - \'forcedVenueId\') || jsonb_build_object(\'preferredVenueId\', config::jsonb->\'forcedVenueId\'))::json, '
            . 'name = replace(name, \'impose\', \'préfère\') '
            . 'WHERE family = \'FACILITY\' AND rule_type IN (\'HARD\', \'LOCK\') AND jsonb_exists(config::jsonb, \'forcedVenueId\') AND name LIKE \'%impose%\'',
        );
    }
}
