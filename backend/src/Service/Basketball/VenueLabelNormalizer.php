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
     * Retire le suffixe « (n) » que la FFBB accole en fin de libellé d'équipe pour
     * distinguer deux engagements homonymes (« AL CALUIRE ET CUIRE - 3 (6) » →
     * « AL CALUIRE ET CUIRE - 3 »). Foyer UNIQUE du nettoyage (P4-199) — appliqué au
     * libellé BRUT à l'import (parseFile + canal API) et rejoué par la migration de
     * données sur les libellés déjà stockés. Un « (6) » en MILIEU de chaîne est
     * intact (l'ancre `$` ne mord qu'en fin) ; une chaîne sans suffixe revient
     * telle quelle. Idempotent (un seul suffixe possible en fin).
     */
    public function stripTeamNumberSuffix(string $label): string
    {
        return (string) preg_replace('/\s+\(\d+\)\s*$/u', '', $label);
    }

    /**
     * Retire le suffixe d'ÉQUIPE « - n » que la FFBB accole en fin de libellé pour
     * distinguer les engagements d'un même organisme (« BASKET BALL 5EME - 2 » →
     * « BASKET BALL 5EME », le nom d'organisme nu). Tiret court ou cadratin, espaces
     * optionnels de part et d'autre, entier ; ancré en fin (`$`), donc un « - 3 » en
     * MILIEU de chaîne est intact. Un seul suffixe possible → idempotent. Distinct de
     * {@see stripTeamNumberSuffix} (le « (n) » homonyme, entre parenthèses) : les deux
     * se composent pour retomber sur le nom d'organisme.
     *
     * Sert au RAPPROCHEMENT au nom d'un adversaire multi-équipes (P2-54, la relance
     * sans suffixe de {@see OpponentLocationResolver::resolveOrganismeByName}) : le
     * LIBELLÉ de la rencontre, lui, reste toujours intact — ce nettoyage ne sert qu'à
     * chercher l'organisme.
     */
    public function stripTrailingTeamNumber(string $label): string
    {
        return (string) preg_replace('/\s*[-–]\s*\d+\s*$/u', '', $label);
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
