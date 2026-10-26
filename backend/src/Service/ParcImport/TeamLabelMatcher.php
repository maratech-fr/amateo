<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Service\Basketball\VenueLabelNormalizer;

/**
 * Lot correctif import IA (« G2 ») PR1 — service PUR qui APPARIE un libellé d'équipe lu dans une
 * case parc-v2 aux équipes DÉJÀ saisies du club. Le fuzzy d'origine (égalité normalisée ou
 * containment, {@see VenueLabelNormalizer::fuzzyMatches}) ne rattrapait ni
 * « Arhand » (OCR de « Armand »), ni « U15M 1 » (écrit autrement que « U15-1 »), ni un nom coupé par
 * l'OCR. Ce matcher corrige le NOMMAGE sans jamais FILTRER ce qui est trouvé (cadrage G2 D5-D7,
 * consigne fondateur « correcteur jamais filtre »).
 *
 *  - D5 une distance de Levenshtein ≤ 2 à UNE seule équipe → « suggere » (à confirmer), JAMAIS
 *    « apparie » d'office ; une égalité CANONIQUE (« U15M 1 » ≡ « U15-1 ») vise la bonne équipe en
 *    « apparie » ; DEUX équipes dans le seuil → « ambigu » (décoché, cibleId null) ; aucune →
 *    « aCreer » (correcteur jamais filtre : le libellé reste proposé en création) ;
 *  - D6 recollage : deux tokens adjacents d'une case recollés en UN libellé QUAND leur concaténation
 *    s'apparie à une équipe existante ; sinon null (deux équipes distinctes ne fusionnent pas) ;
 *  - D7 découpe « A - B » / « A + B » SEULEMENT si CHAQUE moitié s'apparie à une équipe existante.
 *
 * Service PUR : candidats PASSÉS, aucune DB.
 */
final class TeamLabelMatcher
{
    private const int MAX_DISTANCE = 2;

    /**
     * @param list<array{id: string, nom: string}> $existing
     *
     * @return array{statut: string, cibleId: string|null, cibleNom: string|null, candidats: list<array{id: string, nom: string}>}
     */
    public function match(string $label, array $existing): array
    {
        $key = $this->canonical($label);
        if ('' === $key) {
            return $this->verdict('aCreer', null, null, []);
        }

        $exact = [];
        $fuzzy = [];
        foreach ($existing as $candidate) {
            $ck = $this->canonical($candidate['nom']);
            if ('' === $ck) {
                continue;
            }
            $distance = levenshtein($key, $ck);
            if (0 === $distance) {
                $exact[] = $candidate;
            } elseif ($distance <= self::MAX_DISTANCE) {
                $fuzzy[] = $candidate;
            }
        }

        // PR1 (D5) — le verdict PORTE ses candidats (`{id, nom}`), pour que l'aperçu refondu offre au
        // gestionnaire le groupe « Candidats possibles » du sélecteur de la ligne. Les candidats sont
        // les exacts quand il y en a (une égalité canonique prime), sinon les flous ; « à créer »
        // n'invente aucun candidat (correcteur jamais filtre). Toujours re-listés (`list`) pour que le
        // JSON les sérialise en tableau.
        $matched = [] !== $exact ? $exact : $fuzzy;
        $candidats = array_map(static fn (array $c): array => ['id' => $c['id'], 'nom' => $c['nom']], $matched);

        if (1 === \count($exact)) {
            return $this->verdict('apparie', $exact[0]['id'], $exact[0]['nom'], $candidats);
        }
        if (\count($exact) >= 2) {
            return $this->verdict('ambigu', null, null, $candidats);
        }
        if (1 === \count($fuzzy)) {
            return $this->verdict('suggere', $fuzzy[0]['id'], $fuzzy[0]['nom'], $candidats);
        }
        if (\count($fuzzy) >= 2) {
            return $this->verdict('ambigu', null, null, $candidats);
        }

        return $this->verdict('aCreer', null, null, []);
    }

    /**
     * D7 — « A - B » / « A + B » découpé en ses deux moitiés SEULEMENT si CHACUNE s'apparie à une
     * équipe existante ; sinon null (le libellé reste entier, pas de demi-équipe inventée).
     *
     * @param list<array{id: string, nom: string}> $existing
     *
     * @return array{0: string, 1: string}|null
     */
    public function split(string $label, array $existing): ?array
    {
        $parts = preg_split('/\s*[-+]\s*/', trim($label), 2);
        if (false === $parts || 2 !== \count($parts)) {
            return null;
        }
        $left = trim($parts[0]);
        $right = trim($parts[1]);
        if ('' === $left || '' === $right) {
            return null;
        }
        if ('aCreer' === $this->match($left, $existing)['statut'] || 'aCreer' === $this->match($right, $existing)['statut']) {
            return null;
        }

        return [$left, $right];
    }

    /**
     * D6 — deux tokens adjacents d'une case (nom coupé par l'OCR) recollés en UN libellé, rendu avec
     * son verdict QUAND la concaténation s'apparie à une équipe existante ; sinon null.
     *
     * @param list<string>                         $tokens
     * @param list<array{id: string, nom: string}> $existing
     *
     * @return array{statut: string, cibleId: string|null, cibleNom: string|null, candidats: list<array{id: string, nom: string}>, recolle: string}|null
     */
    public function recombine(array $tokens, array $existing): ?array
    {
        $recolle = trim(implode(' ', array_map('trim', $tokens)));
        if ('' === $recolle) {
            return null;
        }
        $verdict = $this->match($recolle, $existing);
        if ('aCreer' === $verdict['statut']) {
            return null;
        }

        return $verdict + ['recolle' => $recolle];
    }

    /**
     * @param list<array{id: string, nom: string}> $candidats
     *
     * @return array{statut: string, cibleId: string|null, cibleNom: string|null, candidats: list<array{id: string, nom: string}>}
     */
    private function verdict(string $statut, ?string $cibleId, ?string $cibleNom, array $candidats): array
    {
        return ['statut' => $statut, 'cibleId' => $cibleId, 'cibleNom' => $cibleNom, 'candidats' => $candidats];
    }

    /**
     * Clé canonique d'un libellé d'équipe : casse/accents repliés, tout caractère non alphanumérique
     * retiré, PUIS un marqueur de genre « m »/« f » coincé entre deux chiffres effacé (« u15m1 » →
     * « u151 »), pour que « U15M 1 » et « U15-1 » tombent sur la même clé.
     */
    private function canonical(string $label): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
        $lower = mb_strtolower(false === $ascii ? $label : $ascii, 'UTF-8');
        $compact = (string) preg_replace('/[^a-z0-9]+/', '', $lower);

        return (string) preg_replace('/(\d)[mf](\d)/', '$1$2', $compact);
    }
}
