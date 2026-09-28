<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Derives a club's FFBB league (région) from its ffbbClubCode, mirroring
 * SchoolZoneResolver (dép. → zone scolaire). The FFBB code's 3-letter prefix
 * already encodes the league (ARA = Auvergne-Rhône-Alpes → AURA, GES = Grand
 * Est…), so we key on that prefix directly rather than re-deriving from the
 * department.
 *
 * A code with a readable 3-letter prefix ALWAYS names a league (founder ruling
 * 2026-09-29: « toute valeur de 3 lettres est une ligue », outre-mer included):
 * a catalogued prefix maps to its internal catalog key (ARA → AURA), an
 * uncatalogued-but-readable one returns the FFBB prefix itself (GUY → GUY,
 * a real league we simply do not catalog) rather than null. Only an UNREADABLE
 * code (no 3-letter prefix — a test/garbage code like `A11Y…`) returns null.
 *
 * Compat ① : `LeagueMatchWindowRepository::effectiveLeague` still maps any
 * uncatalogued league (GUY as much as null) to the federation default AURA for
 * the copy/seed, so day-one placement behaviour is unchanged. The value is
 * stored on Club.league and never overwritten if already set.
 */
final class LeagueResolver
{
    /** FFBB 3-letter prefix → internal league key (extend as leagues are catalogued). */
    public const PREFIX_LEAGUE = [
        'ARA' => 'AURA',   // Auvergne-Rhône-Alpes
        'GES' => 'GEST',   // Grand Est
        'BFC' => 'BOFC',   // Bourgogne-Franche-Comté
        'NAQ' => 'NOAQ',   // Nouvelle-Aquitaine
        'OCC' => 'OCCI',   // Occitanie
        'IDF' => 'IDF',    // Île-de-France
        'HDF' => 'HDF',    // Hauts-de-France
        'NOR' => 'NORM',   // Normandie
        'PDL' => 'PDLL',   // Pays de la Loire
        'BRE' => 'BRET',   // Bretagne
        'CVL' => 'CVDL',   // Centre-Val de Loire
        'PCA' => 'PACA',   // Provence-Alpes-Côte d'Azur
        'COR' => 'CORS',   // Corse
    ];

    public function resolveFromFfbbCode(?string $ffbbCode): ?string
    {
        $prefix = $this->extractPrefix($ffbbCode);
        if (null === $prefix) {
            return null;
        }

        // Readable prefix → a league. Catalogued → its catalog key; otherwise the
        // prefix itself (a real, uncatalogued league — never null).
        return self::PREFIX_LEAGUE[$prefix] ?? $prefix;
    }

    /** The leading 3 letters of the FFBB code (the league prefix), or null. */
    public function extractPrefix(?string $ffbbCode): ?string
    {
        if (null === $ffbbCode || '' === $ffbbCode) {
            return null;
        }

        $code = strtoupper(trim($ffbbCode));
        if (1 === preg_match('/^([A-Z]{3})/', $code, $m)) {
            return $m[1];
        }

        return null;
    }
}
