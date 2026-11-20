<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Enum\ClubRole;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le rôle qui gère est déclaré des deux côtés de la frontière, sur DEUX valeurs seulement.
 *
 * Le backend en est le juge (`ClubRole::isManager()`, lu par `ManagementAccessGuard`) ; le
 * frontend en garde un miroir d'affichage (`MANAGER_ROLE` de `shared/lib/roles.ts`, lu par
 * `isManagementRole`) pour ne pas OFFRIR un geste voué au 403. Depuis la fermeture de la colonne
 * `role` (admin, member), plus aucune liste de rôles de gestion n'existe : un seul rôle gère.
 *
 * ⚠ **Le sens dangereux est l'AJOUT.** Un rôle de gestion ajouté côté serveur et oublié
 * côté frontend : les écrans continuent de **masquer** une capacité que le backend autorise.
 * La fonctionnalité existe, elle est invisible, et **rien ne le signale** — aucune erreur, le
 * bouton n'est simplement pas là. Le retrait, lui, est bruyant : l'écran offre un geste que
 * l'API refuse, et le 403 se voit.
 *
 * Jumeau côté écran : `frontend/src/shared/lib/coachName.test.ts` (« rôles de gestion ») prouve
 * que `isManagementRole` ne rend vrai que pour ce rôle.
 */
#[Group('contract')]
final class ManagementRolesMatchBackendTest extends TestCase
{
    private const string FRONT_ROLES = __DIR__ . '/../../../frontend/src/shared/lib/roles.ts';

    public function testTheFrontendManagerRoleIsTheOnlyBackendManagerRole(): void
    {
        $backend = array_values(array_map(
            static fn (ClubRole $role): string => $role->value,
            array_filter(ClubRole::cases(), static fn (ClubRole $role): bool => $role->isManager()),
        ));

        $source = file_get_contents(self::FRONT_ROLES);
        self::assertIsString($source, \sprintf('Illisible : %s', self::FRONT_ROLES));

        $found = preg_match('/export const MANAGER_ROLE\b[^=]*=\s*"([a-z_]+)"/', $source, $matches);
        self::assertSame(1, $found, 'Le rôle de gestion (MANAGER_ROLE) a disparu du frontend — si sa forme a changé, mettre ce test à jour plutôt que de le retirer.');

        self::assertSame($backend, [$matches[1]], \sprintf(
            "Le miroir frontend du rôle de gestion a dérivé du backend.\n"
            . "Backend (ClubRole::isManager) : %s\nFrontend (MANAGER_ROLE) : %s\n\n"
            . "Un rôle ajouté côté serveur et oublié côté écran fait MASQUER une capacité que\n"
            . 'l\'API autorise : la fonctionnalité existe, elle est invisible, et rien ne le dit.',
            implode(', ', $backend),
            $matches[1],
        ));
    }

    /**
     * P1-1 (PR C) — les rôles ASSIGNABLES sont déclarés des deux côtés : l'enum PHP
     * `ClubRole` (juge : toute écriture de rôle s'y valide, 422 sinon) et la liste
     * `ASSIGNABLE_ROLES` du frontend (ce qu'un écran d'approbation/changement PROPOSE).
     * Les deux doivent coïncider DANS LES DEUX SENS : un rôle assignable ajouté côté
     * serveur mais oublié à l'écran est invisible ; un rôle proposé à l'écran mais
     * absent de l'enum part au 422.
     */
    public function testTheFrontendAssignableRolesMirrorTheClubRoleEnum(): void
    {
        $enum = array_map(static fn (ClubRole $role): string => $role->value, ClubRole::cases());
        sort($enum);

        $source = file_get_contents(self::FRONT_ROLES);
        self::assertIsString($source, \sprintf('Illisible : %s', self::FRONT_ROLES));

        $found = preg_match('/ASSIGNABLE_ROLES = \[(.*?)\]/s', $source, $matches);
        self::assertSame(1, $found, 'La liste ASSIGNABLE_ROLES a disparu du frontend — si elle a changé de forme, mettre ce test à jour plutôt que de le retirer.');

        preg_match_all('/"([a-z_]+)"/', $matches[1], $front);
        $frontRoles = $front[1];
        sort($frontRoles);

        self::assertSame($enum, $frontRoles, \sprintf(
            "Les rôles assignables du frontend ont dérivé de l'enum ClubRole.\n"
            . "Backend (ClubRole) : %s\nFrontend (ASSIGNABLE_ROLES) : %s",
            implode(', ', $enum),
            implode(', ', $frontRoles),
        ));
    }
}
