<?php

declare(strict_types=1);

namespace App\Service\Basketball;

/**
 * Canonical basketball sport-category catalog — the single source of truth for
 * both the dev seed (BcclSeeder) and per-club seeding at registration
 * (AuthController::seedNewClub), which used to diverge (18 vs 9 categories,
 * "Senior M" vs "Seniors M", gendered vs not).
 *
 * Categories are AGE brackets only — NOT gendered. Gender is a Team-level field
 * (Homme/Femme/Mixte), so "U15F"/"U15M" collapse to a single "U15" category and
 * the wizard no longer shows a redundant gender that the category already
 * implied. Age (ageMin/ageMax) still drives the engine's age-ascending rule and
 * the JEUNE/SENIOR tags; the U-x tags key off the name token ("U15"), both
 * preserved by these names.
 */
final class CategoryCatalog
{
    /**
     * @return list<array{name: string, ageMin: int|null, ageMax: int|null, sortOrder: int}>
     */
    public static function categories(): array
    {
        return [
            // Ordre d'AFFICHAGE, du plus âgé au plus jeune (retour terrain 2026-07-31,
            // arbitré le 2026-08-01) : un gestionnaire pense ses équipes en partant des
            // seniors. Les deux catégories SANS âge ferment la liste plutôt que de couper
            // l'axe des âges en deux.
            //
            // ⚠ Ce `sortOrder` n'a longtemps servi à RIEN : rien ne triait dessus, ni le
            // serveur ni le front, et les ids étant des UUID v4 l'ordre servi était
            // ALÉATOIRE — différent d'un club à l'autre. C'est `SportCategoryStateProvider`
            // qui lui donne enfin effet ; changer cet ordre ici SANS lui ne se verrait pas.
            ['name' => 'Vétéran', 'ageMin' => 35, 'ageMax' => 99, 'sortOrder' => 0],
            ['name' => 'Senior', 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 1],
            ['name' => 'U21', 'ageMin' => 19, 'ageMax' => 21, 'sortOrder' => 2],
            ['name' => 'U18', 'ageMin' => 16, 'ageMax' => 18, 'sortOrder' => 3],
            ['name' => 'U15', 'ageMin' => 14, 'ageMax' => 15, 'sortOrder' => 4],
            ['name' => 'U13', 'ageMin' => 12, 'ageMax' => 13, 'sortOrder' => 5],
            ['name' => 'U11', 'ageMin' => 10, 'ageMax' => 11, 'sortOrder' => 6],
            ['name' => 'U9', 'ageMin' => 8, 'ageMax' => 9, 'sortOrder' => 7],
            ['name' => 'U7', 'ageMin' => 6, 'ageMax' => 7, 'sortOrder' => 8],
            ['name' => 'U5', 'ageMin' => 3, 'ageMax' => 5, 'sortOrder' => 9],
            ['name' => 'Baby basket', 'ageMin' => null, 'ageMax' => null, 'sortOrder' => 10],
            ['name' => 'Loisir', 'ageMin' => null, 'ageMax' => null, 'sortOrder' => 11],
        ];
    }
}
