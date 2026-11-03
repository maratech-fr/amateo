import type { ReactNode } from "react";

import { useCanManage } from "./queries";

/**
 * Un geste d'ÉCRITURE réservé aux gestionnaires : rendu tel quel pour un gestionnaire, MASQUÉ
 * (absent du DOM, jamais grisé) pour un Membre. Affichage seul — le serveur refuse de toute façon
 * (403) ; on évite seulement de proposer un geste voué à l'échec.
 */
export function ManagerOnly({ children }: { children: ReactNode }): ReactNode {
  return useCanManage() ? children : null;
}
