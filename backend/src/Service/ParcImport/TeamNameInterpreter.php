<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Service\Basketball\CategoryCatalog;

/**
 * Lot correctif import IA (« G2 ») PR1 — service PUR qui DÉDUIT, du seul NOM d'une équipe écrit dans
 * une case parc-v2, son GENRE (M / F / MIXTE) et sa CATÉGORIE d'âge. En parc-v2 le chatbot ne rend
 * plus de section `equipes` structurée : l'appli reconstruit chaque équipe à partir du libellé lu
 * dans la case (cadrage G2 D1). Ce service est le cœur de cette déduction.
 *
 * Règles :
 *  - un token d'âge FFBB « U<n> » suivi d'un marqueur de genre « F »/« M » → catégorie « U<n> »,
 *    genre déduit du marqueur (« U18F3 » → U18 / F) ; sans marqueur → genre NULL (« U13 » → U13 / null) ;
 *  - un préfixe senior « SM »/« SF » → catégorie seniors, genre M / F (« SM2 » → Senior / M) ;
 *  - un libellé senior/vétéran sans marqueur de genre (« Vétérans ») → genre NULL ;
 *  - tout le reste (activité, section : « Section J.Macé », « Basket Santé ») → « À classer », genre NULL —
 *    un repère explicite que le gestionnaire reclasse, jamais une catégorie NI un genre devinés.
 *
 * Refonte PR2 (§0 point 1 AMENDÉ, « plus MIXTE ») : on ne DEVINE jamais un genre absent et on ne le
 * replie JAMAIS en MIXTE d'office — le genre est désormais OBLIGATOIRE, tranché à l'import.
 *
 * La catégorie rendue est un libellé HUMAIN : le nom CANONIQUE du {@see CategoryCatalog} quand le
 * token y correspond (« Senior », « U18 »), sinon le token brut (« U8 » hors catalogue) ou le repère
 * « À classer ». Service PUR : aucune DB, aucun appariement aux entités du club (ça, c'est
 * {@see TeamLabelMatcher}).
 */
final class TeamNameInterpreter
{
    /** Repère explicite d'une équipe dont l'âge/le genre ne se déduisent pas sans risque de mentir. */
    public const string UNFILED = 'À classer';

    /**
     * @return array{genre: 'M'|'F'|null, categorie: string}
     */
    public function interpret(string $name): array
    {
        $trimmed = trim($name);

        // Jeunes FFBB : « U<n> » éventuellement suivi d'un marqueur de genre puis d'un numéro.
        if (1 === preg_match('/^U\s*(\d{1,2})\s*([MFmf])?/u', $trimmed, $m)) {
            $age = 'U' . $m[1];
            $marker = isset($m[2]) ? strtoupper($m[2]) : null;

            return [
                'genre' => 'M' === $marker ? 'M' : ('F' === $marker ? 'F' : null),
                'categorie' => $this->catalogNameFor($age) ?? $age,
            ];
        }

        // Seniors gendrés : « SM<n> » / « SF<n> ».
        if (1 === preg_match('/^S\s*([MF])\s*\d*$/u', $trimmed, $m)) {
            return ['genre' => $m[1], 'categorie' => $this->catalogNameFor('Senior') ?? 'Senior'];
        }

        // Libellé senior / vétéran sans marqueur de genre → genre NULL (plus MIXTE d'office).
        if (1 === preg_match('/^(s[ée]nior|v[ée]t[ée]ran)/ui', $this->fold($trimmed))) {
            $isVeteran = str_starts_with($this->fold($trimmed), 'veteran');
            $category = $isVeteran ? 'Vétéran' : 'Senior';

            return ['genre' => null, 'categorie' => $this->catalogNameFor($category) ?? $category];
        }

        return ['genre' => null, 'categorie' => self::UNFILED];
    }

    /**
     * Le nom CANONIQUE du catalogue pour un token (casse/accents repliés), ou null s'il n'y figure
     * pas (« U8 », absent du catalogue, revient au token brut chez l'appelant).
     */
    private function catalogNameFor(string $token): ?string
    {
        $key = $this->fold($token);
        foreach (CategoryCatalog::categories() as $category) {
            if ($this->fold($category['name']) === $key) {
                return $category['name'];
            }
        }

        return null;
    }

    private function fold(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = mb_strtolower(false === $ascii ? $value : $ascii, 'UTF-8');

        return trim((string) preg_replace('/[^a-z0-9]+/', '', $lower));
    }
}
