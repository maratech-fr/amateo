<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les deux rôles d'une adhésion club : Gestionnaire (écrit tout) et Membre (lit tout).
 * La colonne `role` de `club_user` et de `club_invitation` est FERMÉE à ces deux valeurs
 * (contrainte CHECK, migration Version20261120120000 : les anciennes valeurs `owner`,
 * `editor`, `viewer` y ont été converties). Toute écriture de rôle se valide contre cet
 * enum — une valeur hors liste est un 422, et la base la refuse de toute façon.
 *
 * Miroir d'affichage côté écran : `MANAGER_ROLE` de `frontend/src/shared/lib/roles.ts`
 * (parité : `ManagementRolesMatchBackendTest`).
 */
enum ClubRole: string
{
    /** Ce rôle ouvre-t-il les écritures de gestion ? */
    public function isManager(): bool
    {
        return self::MANAGER === $this;
    }
    case MANAGER = 'admin';
    case MEMBER = 'member';
}
