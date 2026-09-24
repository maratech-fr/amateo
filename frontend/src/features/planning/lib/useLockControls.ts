import { useCallback, useMemo, useState } from "react";

import type { Slot } from "../api";
import { useLockSlot } from "../queries";

/**
 * Verrous manuels et déverrouillage : les états du panneau/de la lentille et du créneau réservé en
 * attente de confirmation, la mutation de verrou, les verrous manuels, la fermeture du panneau et LE
 * point d'entrée unique de la bascule. Déplacement pur depuis la page (des appels aujourd'hui
 * éparpillés regroupés ici), entrée en paramètre. Retourne la mutation elle-même : l'appel du
 * dialogue de déverrouillage la lit tel quel, caractère pour caractère.
 */
export function useLockControls(slots: Slot[]) {
  // Déverrouiller un créneau né d'une RÉSERVATION de gymnase demande confirmation (F1) : c'est
  // un engagement pris hors de l'app, à ne pas relâcher par inadvertance. On mémorise LE créneau
  // visé (et non un booléen) : le cadenas de la grille (PR 2) peut viser un créneau NON
  // sélectionné, la confirmation doit muter celui-là, pas le sélectionné.
  const [pendingUnlockSlotId, setPendingUnlockSlotId] = useState<string | null>(null);
  // PR 3 — panneau latéral des verrous manuels + lentille (surbrillance de la grille par
  // origine de verrou). Fermer le panneau ÉTEINT la lentille : pas d'état fantôme.
  const [locksPanelOpen, setLocksPanelOpen] = useState(false);
  const [lockLens, setLockLens] = useState(false);
  const lockMutation = useLockSlot();

  // PR 3 — les créneaux verrouillés À LA MAIN (le compteur toolbar + la liste du panneau).
  // SEULS les MANUAL comptent : ni les réservations de gymnase (RESERVATION), ni les verrous
  // d'origine indécidable (UNKNOWN) — c'est le « travail de verrouillage » du gestionnaire.
  const manualLocks = useMemo(() => slots.filter((s) => "MANUAL" === s.lockOrigin), [slots]);
  const closeLocksPanel = useCallback(() => {
    setLocksPanelOpen(false);
    setLockLens(false);
  }, []);

  // F1 (PR 2) — LE point d'entrée UNIQUE de la bascule de verrou, partagé par le panneau de
  // détail ET le cadenas de la grille : la règle RÉSERVATION (déverrouiller → confirmation)
  // s'écrit ainsi une seule fois. MANUAL/UNKNOWN et tout verrouillage mutent directement.
  const requestToggleLock = useCallback(
    (slotId: string) => {
      const slot = slots.find((s) => s.id === slotId);
      if (undefined === slot) {
        return;
      }
      const locked = "NONE" !== slot.lockLevel;
      if (locked && "RESERVATION" === slot.lockOrigin) {
        setPendingUnlockSlotId(slotId);
        return;
      }
      lockMutation.mutate({ id: slotId, lockLevel: locked ? "NONE" : "HARD" });
    },
    [slots, lockMutation],
  );

  return { pendingUnlockSlotId, setPendingUnlockSlotId, locksPanelOpen, setLocksPanelOpen, lockLens, setLockLens, lockMutation, manualLocks, closeLocksPanel, requestToggleLock };
}
