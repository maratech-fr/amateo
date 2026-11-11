import { useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";

import { useMe } from "@/shared/session/queries";
import { applyServerToday, todayISO } from "@/shared/lib/clock";

/**
 * P4-16/P2-4 — cale le « aujourd'hui » du front sur celui du SERVEUR pour un club
 * à horloge simulée (`me.club.simulatedToday`), pour que l'écran et le serveur
 * disent la même date.
 *
 * Monté dans AppLayout, comme le thème club. L'application (ou le relâchement) ne
 * change `todayISO()` que pour les rendus SUIVANTS : quand la date effective bouge,
 * on invalide le cache des requêtes — les écrans déjà montés (radar, calendrier)
 * se recalculent au lieu de garder des cartes datées de l'ancien « aujourd'hui ».
 * Un club à l'heure réelle a `simulatedToday` null : `applyServerToday(null)`
 * relâche, et comme la date effective ne bouge pas, aucune invalidation — zéro coût.
 */
export function useApplySimulatedClock(): void {
  const { data: me } = useMe();
  const queryClient = useQueryClient();
  const simulatedToday = me?.club?.simulatedToday ?? null;

  useEffect(() => {
    const before = todayISO();
    applyServerToday(simulatedToday);
    if (todayISO() !== before) {
      void queryClient.invalidateQueries();
    }
  }, [simulatedToday, queryClient]);
}

/**
 * La console superadmin vit à la date RÉELLE : venu d'un club démo daté (horloge simulée posée
 * par AppLayout), entrer sous `/admin` relâche l'override au montage — sinon « Il y a N jours »
 * se compterait depuis la date simulée. Le `?today=` de dev garde la priorité
 * (`applyServerToday`). Revenir dans l'app remonte AppLayout, dont `useApplySimulatedClock`
 * ré-applique la date du club. Monté dans `AdminShell`.
 */
export function useReleaseSimulatedClock(): void {
  useEffect(() => {
    applyServerToday(null);
  }, []);
}
