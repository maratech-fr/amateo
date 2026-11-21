<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\Club;
use App\Entity\User;

/**
 * Le régime de démonstration, en trois questions — maison unique des lectures des drapeaux
 * `Club::isDemo` et `User::isDemo` dans les gestes du club et du compte. Le club décide de ce
 * qui sort vers un tiers ; le compte garde ses protections.
 *
 * Hors d'ici, volontairement : les traitements de nuit, la console, le cycle de vie de la démo,
 * l'horloge simulée (`ClubClock`) et ce que `/api/me` affiche.
 */
final readonly class DemoRegime
{
    /** Un club de démonstration a des droits pleins : aucune offre ne le bride. */
    public function isUnlimited(Club $club): bool
    {
        return $club->isDemo();
    }

    /**
     * Rien ne part vers un tiers (mail aux coachs, invitation) depuis un club de démonstration,
     * ni depuis un compte de démonstration. Club introuvable : seul le compte décide (fermé par
     * défaut pour un compte de démonstration).
     */
    public function forbidsThirdPartySend(?Club $club, ?User $user): bool
    {
        return (true === $club?->isDemo()) || (true === $user?->isDemo());
    }

    /** Un compte de démonstration ne se modifie pas et ne se supprime pas. */
    public function protectsAccount(User $user): bool
    {
        return $user->isDemo();
    }
}
