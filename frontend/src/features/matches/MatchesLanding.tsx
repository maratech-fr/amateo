import { useEffect, useState } from "react";
import { Navigate, useSearchParams } from "react-router";

import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { CalendarPage } from "./CalendarPage";
import { openConflictCount } from "./lib/conflictResolution";
import { useConflicts } from "./queries";
import { useMatchesStore } from "./store";

/**
 * UXS-07 — la ROUTE D'ATTERRISSAGE du module matchs (index de `/matchs`). L'index
 * était inconditionnellement le Calendrier ; le fondateur veut atterrir sur
 * **Conflits s'il y a des conflits à traiter**, sur le **Calendrier sinon**. La
 * décision vit ICI, jamais dans `MatchesLayout` — le layout garde sa nav intacte
 * (l'ordre des six onglets reste octet pour octet), et cette route rend elle-même
 * `<CalendarPage/>` dans le cas nominal.
 *
 * Contrat :
 *  1. **Latch au premier rendu** : on ne DÉCIDE que si la décision n'est pas déjà
 *     prise dans la session ET qu'aucun paramètre d'URL ne fait foi. Figé par `useRef`
 *     — ne se rejoue pas quand le compte arrive ou que la décision se marque.
 *  2. On lit `useConflicts()` — le MÊME cache que le badge « Conflits · N » du layout
 *     (déjà monté au-dessus), donc zéro requête nouvelle — et `openConflictCount`, la
 *     MÊME définition de « à traiter » que le badge.
 *  3. Pendant l'attente du compte : `FullPageSpinner`. On n'affiche JAMAIS le
 *     Calendrier avant de savoir — c'est le saut d'écran que la décision exclut.
 *  4. Compte connu : `> 0` → Conflits (`replace`) ; sinon → `<CalendarPage/>`.
 *  5. 🔴 Requête en ÉCHEC → on ouvre le CALENDRIER (« fail-open ») : un module qui
 *     n'ouvre rien parce qu'un compte de conflits est en erreur serait pire que le
 *     défaut corrigé. Même doctrine que le badge (jamais « Conflits · 0 » sur données
 *     absentes).
 *  6. 🔴 URL avec paramètres ⇒ décision SAUTÉE : un lien profond fait foi (le cockpit
 *     envoie `/matchs?fbi=1` ; « Voir la semaine » / « Replacer » naviguent avec des
 *     paramètres). Sans cette garde, ces parcours rebondiraient sur Conflits.
 */
export function MatchesLanding() {
  const [searchParams] = useSearchParams();
  const decided = useMatchesStore((s) => s.landingDecided);
  const markLandingDecided = useMatchesStore((s) => s.markLandingDecided);

  // Latch : capturé au PREMIER rendu via l'initialiseur `useState` (exécuté une seule
  // fois, jamais rejoué), gelé ensuite — le compte qui arrive et la décision qui se
  // marque ne le recalculent pas. On ne décide que sur une session vierge ET sans lien
  // profond (un paramètre d'URL fait foi). Le setter est volontairement ignoré.
  const [deciding] = useState(() => !decided && "" === searchParams.toString());

  const conflicts = useConflicts();
  // « Résolu » = on n'attend plus le compte : soit on ne décide pas, soit le compte est
  // arrivé (succès) ou la requête a échoué. Tant que non résolu → spinner.
  const resolved = !deciding || !conflicts.isPending;

  useEffect(() => {
    if (resolved) {
      markLandingDecided();
    }
  }, [resolved, markLandingDecided]);

  if (!resolved) {
    return <FullPageSpinner />;
  }

  // Le compte « à traiter » : 0 en ÉCHEC (fail-open, `!conflicts.isError`), sinon le même
  // décompte que le badge. Le `deciding` gate le RENVOI, pas ce calcul : hors décision (déjà
  // vu / lien profond) on rend le Calendrier sans jamais rediriger, même s'il y a des conflits.
  const openConflicts = !conflicts.isError && undefined !== conflicts.data ? openConflictCount(conflicts.data.conflicts) : 0;

  if (deciding && openConflicts > 0) {
    return <Navigate to="/matchs/conflits" replace />;
  }
  return <CalendarPage />;
}
