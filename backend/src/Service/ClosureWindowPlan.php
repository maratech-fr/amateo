<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Le PLAN d'effets d'une modification de fenêtre d'une FERMETURE (prolonger/raccourcir par-delà
 * les vacances), calculé par {@see ClosureWindowPlanner}. IMMUABLE, READ-ONLY.
 *
 * - `effects` : la liste SERVIE au front (aperçu), ORDRE CHRONOLOGIQUE, chaque ligne {kind,
 *   startDate, endDate, label} — `label` en français, dates en clair, aucun identifiant interne.
 * - `summary` : phrase FR courte résumant le geste.
 * - `futurePlansWarning` : des segments à startDate > aujourd'hui naîtraient, que valider/rouvrir
 *   le socle balaierait (inv. 14).
 * - `token` : sha256 canonique de l'état lu ; le PUT le renvoie, un recalcul différent = 409.
 * - Les données d'APPLICATION (consommées par {@see ClosureWindowApplier}, jamais servies) :
 *   `simpleRedate` (une seule fenêtre résultante → le plan « d'un bloc » se contente de rétrécir/
 *   glisser), sinon `convertSegment` (le milieu ré-ancré, plan+version conservés) + `copySegments`
 *   (les runs qui naissent en COPIE validée). `rootEntryId`, `oldStart/oldEnd`, `newStart/newEnd`
 *   en Y-m-d.
 */
final readonly class ClosureWindowPlan
{
    /**
     * @param list<array{kind: string, startDate: string, endDate: string, label: string}> $effects
     * @param array{startDate: string, endDate: string}|null                               $convertSegment
     * @param list<array{startDate: string, endDate: string}>                              $copySegments
     */
    public function __construct(
        public array $effects,
        public string $summary,
        public bool $futurePlansWarning,
        public string $token,
        public bool $simpleRedate,
        public ?array $convertSegment,
        public array $copySegments,
        public string $rootEntryId,
        public string $oldStart,
        public string $oldEnd,
        public string $newStart,
        public string $newEnd,
    ) {}
}
