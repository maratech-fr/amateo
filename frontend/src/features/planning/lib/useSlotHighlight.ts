import { useCallback, useState } from "react";

import type { MoveViolation, Slot } from "../api";
import { violationHighlightSlotIds } from "./violationHighlight";

/**
 * Le CARREFOUR du surlignage de la grille (P4-255 PR 2). Cet état était écrit par PLUSIEURS
 * sujets à la fois (déplacement simple/groupe refusé, placement refusé, essai à blanc refusé,
 * clic diagnostic, et chaque geste réussi qui l'efface) : ce n'est donc pas un déplacement
 * verbatim mais une DÉCISION DE CONCEPTION — le hook devient le propriétaire unique de l'état,
 * et n'expose que des INTENTIONS nommées (aucun setter).
 *
 * Trois intentions, ni plus ni moins :
 *  - `highlightViolations(violations)` — surligne les créneaux de l'équipe nommée en conflit
 *    (présentation pure, via {@link violationHighlightSlotIds}). Dépend de `slots`, donc son
 *    identité est INSTABLE — ASSUMÉ.
 *    ⚠ INTERDICTION : `highlightViolations` ne doit JAMAIS descendre dans le tableau de
 *    dépendances d'un enfant (elle change à chaque changement de `slots`). Seules les DEUX
 *    intentions stables ci-dessous descendent dans le JSX.
 *  - `highlightSlots(ids)` — pose un surlignage explicite (clic diagnostic) et amène le PREMIER
 *    créneau à l'écran. `useCallback([])` → identité STABLE.
 *  - `clearHighlight()` — efface. `useCallback([])` → identité STABLE.
 *
 * 🔴 Pourquoi la stabilité est un CONTRAT, pas un confort : `DiagnosticsPanel` efface via
 * `onHighlight(new Set())` (= `highlightSlots`) et place `onHighlight` dans le tableau de
 * dépendances d'un de ses effets (« setter stable côté PlanningPage »). Une identité qui
 * changerait à chaque rendu y créerait une BOUCLE d'effet. Gardé par `useSlotHighlight.test.ts`.
 *
 * `slots` est un paramètre individuel unique (jamais un objet d'options qui changerait d'identité
 * à chaque rendu). Aucun setter n'est exposé.
 */
export function useSlotHighlight(slots: Slot[]) {
  const [highlightSlotIds, setHighlightSlotIds] = useState<Set<string>>(new Set());

  const highlightViolations = useCallback(
    (violations: MoveViolation[]) => setHighlightSlotIds(violationHighlightSlotIds(violations, slots)),
    [slots],
  );

  // Le chemin SURLIGNAGE (clic diagnostic) amène le PREMIER créneau à l'écran (retour fondateur
  // 2026-08-15 : « ça illumine mais je dois chercher pour le trouver »). Même recette qu'openSlot —
  // rAF pour laisser React peindre avant de scroller, `scrollIntoView` optionnel (absent en jsdom).
  // Un clic qui ÉTEINT le surlignage (set vide) ne scrolle pas.
  const highlightSlots = useCallback((slotIds: Set<string>) => {
    setHighlightSlotIds(slotIds);
    const [first] = slotIds;
    if (undefined !== first) {
      requestAnimationFrame(() => document.querySelector(`[data-slot-id="${first}"]`)?.scrollIntoView?.({ block: "center", inline: "center", behavior: "smooth" }));
    }
  }, []);

  const clearHighlight = useCallback(() => setHighlightSlotIds(new Set()), []);

  return { highlightSlotIds, highlightViolations, highlightSlots, clearHighlight };
}
