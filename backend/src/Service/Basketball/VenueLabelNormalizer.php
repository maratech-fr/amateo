<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Service\FbiFixtureImporter;

/**
 * The ONE home of FBI/FFBB label normalization and the fuzzy salle compare
 * (P4-187a D1). Case/accents/spacing drift are folded to a stable key so an
 * imported « Salle » label, a stored venue name and a confirmed alias all
 * compare on the same ground — the federation's labels are not under our control.
 *
 * Stateless. {@see FbiFixtureImporter} (normalizeLabel / containsClub
 * / venueMatches) delegates here so the import channel and the alias resolver
 * ({@see VenueAliasResolver}) can never drift apart on what « same label » means.
 *
 * Exemples figés (VenueLabelNormalizerTest) : « GYMNASE MATEO » → « gymnase mateo »
 * (accents et casse repliés) ; « MATEO » → « mateo » (clé différente, jamais
 * confondue) ; les tirets deviennent des séparateurs (« DESPARMET-RUELLO » →
 * « desparmet ruello »).
 */
final class VenueLabelNormalizer
{
    /**
     * Casse/accents/ponctuation repliés : minuscule ASCII, tout ce qui n'est pas
     * alphanumérique devient une espace, espaces multiples réduits, bords rognés.
     */
    public function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = mb_strtolower(false === $ascii ? $value : $ascii, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $lower)));
    }

    /**
     * Whole-word containment (space-padded), NOT raw substring: the ALREADY
     * NORMALIZED needle must appear as a full word of the (normalized here)
     * haystack — « bc test » matches « bc test 1 » but never « bc testville ».
     */
    public function containsWord(string $haystack, string $normalizedNeedle): bool
    {
        return str_contains(' ' . $this->normalize($haystack) . ' ', ' ' . $normalizedNeedle . ' ');
    }

    /**
     * Fuzzy salle match (D13): normalized equality OR whole-word containment
     * either direction. Degrades to « match » when a side is empty (nothing to
     * compare → never a divergence).
     */
    public function fuzzyMatches(string $a, string $b): bool
    {
        $na = $this->normalize($a);
        $nb = $this->normalize($b);
        if ('' === $na || '' === $nb) {
            return true;
        }

        return $na === $nb || $this->containsWord($b, $na) || $this->containsWord($a, $nb);
    }
}
