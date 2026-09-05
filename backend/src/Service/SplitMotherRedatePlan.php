<?php

declare(strict_types=1);

namespace App\Service;

/**
 * D3 v2 (P4-174) — le PLAN d'effets du re-datage d'une indisponibilité DÉCOUPÉE (mère CLOSURE à
 * ≥ 1 enfant, sans plan-bloc), calculé par {@see SplitMotherRedatePlanner}. IMMUABLE.
 *
 * - `effects` : la liste SERVIE au front (aperçu), en français, sans identifiant interne — chaque
 *   ligne porte son `kind` (keep/shift/absorb/vanish/birth/holiday_takes_over) et son `label` prêt
 *   à afficher.
 * - `token` : sha256 canonique de l'état lu (mère + ancienne/nouvelle fenêtre + par enfant trié). Le
 *   front le renvoie au PUT ; un recalcul différent = la période a bougé depuis l'aperçu → 409.
 * - Les trois listes d'APPLICATION (consommées par le processor, jamais servies) : quels enfants
 *   glissent (et vers où), quels enfants disparaissent (absorption + disparition, supprimés en
 *   cascade), quels segments naissent (enfant + plan neufs vides).
 */
final readonly class SplitMotherRedatePlan
{
    /**
     * @param list<array{kind: string, label: string}>                 $effects   aperçu servi (ordre chronologique)
     * @param list<array{childId: string, start: string, end: string}> $shifts    enfants à re-dater vers une nouvelle fenêtre
     * @param list<string>                                             $deletions ids des enfants supprimés en cascade (absorption + disparition)
     * @param list<array{start: string, end: string}>                  $births    segments neufs → enfant + plan vides
     */
    public function __construct(
        public array $effects,
        public string $token,
        public array $shifts,
        public array $deletions,
        public array $births,
    ) {}
}
