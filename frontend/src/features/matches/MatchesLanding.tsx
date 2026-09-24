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
 *  1. **On fige l'ISSUE, pas seulement l'entrée en décision.** `outcome`
 *     (`null` → `"conflicts" | "calendar"`) est posé UNE fois, puis ne bouge plus. C'est le
 *     bug corrigé (le flaky de `matches.spec.ts`) : l'ancien code gelait bien l'ENTRÉE en
 *     décision, mais RÉÉVALUAIT la condition de renvoi à CHAQUE rendu. Un conflit né APRÈS
 *     un atterrissage sans conflit — l'utilisateur saisit une rencontre, `invalidateFixtures`
 *     (`queries.ts`) rafraîchit le radar, le compte passe de 0 à N — rejouait alors le
 *     `<Navigate>` et ÉJECTAIT l'utilisateur du Calendrier en plein travail. Désormais un
 *     conflit né ensuite se voit dans le badge « Conflits · N » du layout ; il ne DÉPLACE
 *     jamais l'utilisateur.
 *  2. **Initialisation** : `"calendar"` d'emblée quand il n'y a RIEN à décider — décision
 *     déjà prise dans la session (`decided`) OU lien profond (un paramètre d'URL fait foi :
 *     le cockpit envoie `/matchs?fbi=1` ; « Voir la semaine » / « Replacer » naviguent avec
 *     des paramètres — sans cette garde ces parcours rebondiraient sur Conflits). Sinon
 *     `null` : on attend le compte pour trancher.
 *  3. **Résolution** : `outcome === null && !isPending` ⇒ on POSE l'issue PENDANT le rendu
 *     (patron React documenté « adjusting state during render » : idempotent, donc sûr sous
 *     StrictMode — surtout PAS dans un `useRef`, que le double rendu ferait basculer). On lit
 *     `useConflicts()` — le MÊME cache que le badge « Conflits · N » du layout (déjà monté
 *     au-dessus), donc zéro requête nouvelle — et `openConflictCount`, la MÊME définition de
 *     « à traiter » que le badge.
 *  4. Pendant l'attente (`outcome === null`) : `FullPageSpinner`. On n'affiche JAMAIS le
 *     Calendrier avant de savoir — c'est le saut d'écran que la décision exclut.
 *  5. 🔴 Requête en ÉCHEC → issue `"calendar"` (« fail-open », `!conflicts.isError`) : un
 *     module qui n'ouvre rien parce qu'un compte de conflits est en erreur serait pire que le
 *     défaut corrigé. Même doctrine que le badge (jamais « Conflits · 0 » sur données
 *     absentes).
 */
export function MatchesLanding() {
  const [searchParams] = useSearchParams();
  const decided = useMatchesStore((s) => s.landingDecided);
  const markLandingDecided = useMatchesStore((s) => s.markLandingDecided);

  // L'ISSUE de l'atterrissage, figée dès qu'elle est posée. Posée d'emblée à "calendar"
  // quand il n'y a rien à décider (décision déjà prise dans la session, ou lien profond qui
  // fait foi) ; sinon `null` = on attend le compte de conflits.
  const [outcome, setOutcome] = useState<"conflicts" | "calendar" | null>(() =>
    decided || "" !== searchParams.toString() ? "calendar" : null,
  );

  const conflicts = useConflicts();

  // Compte connu (succès OU échec) et issue pas encore posée → on tranche PENDANT le rendu et
  // on la GÈLE. Le compte « à traiter » : 0 en ÉCHEC (fail-open, `!conflicts.isError`) ou
  // données absentes, sinon le même décompte que le badge. Une fois l'issue posée, ce bloc ne
  // se rejoue plus (garde `null === outcome`) : un conflit né plus tard ne peut plus rediriger.
  if (null === outcome && !conflicts.isPending) {
    const openConflicts = !conflicts.isError && undefined !== conflicts.data ? openConflictCount(conflicts.data.conflicts) : 0;
    setOutcome(openConflicts > 0 ? "conflicts" : "calendar");
  }

  useEffect(() => {
    if (null !== outcome) {
      markLandingDecided();
    }
  }, [outcome, markLandingDecided]);

  if (null === outcome) {
    return <FullPageSpinner />;
  }
  if ("conflicts" === outcome) {
    return <Navigate to="/matchs/conflits" replace />;
  }
  return <CalendarPage />;
}
