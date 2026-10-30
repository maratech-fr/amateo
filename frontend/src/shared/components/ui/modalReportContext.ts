import { createContext, useContext } from "react";

/**
 * D14 (PR B « Signaler depuis une modale ») — le CONTEXTE LÉGER qui relie le drapeau « Signaler »
 * de la primitive {@link Modal} au canal de signalement (`FeedbackDialog`). Module NEUTRE, placé dans
 * `shared/components/ui/` exprès : il n'importe RIEN de `shared/feedback/`, ce qui casse le cycle
 * (`FeedbackDialog` importe `Modal` ; si `Modal` importait `FeedbackDialog` directement, cycle). Le
 * PROVIDER qui câble la valeur vit, lui, dans `shared/feedback/ModalReportProvider` et est monté dans
 * le shell applicatif.
 */
export interface ModalReportRequest {
  /** Instantané sérialisé de l'état de la modale (D15), joint comme `modalState`. undefined = contexte de base. */
  modalState?: string;
  /** Libellé lisible de la modale (son `label`), joint comme `screenLabel`. */
  screenLabel?: string;
}

export interface ModalReportContextValue {
  /**
   * Ouvre le canal de signalement avec le contexte de la modale courante. `null` quand aucun provider
   * n'est monté (hors du shell applicatif, p.ex. la console superadmin ou un test de primitive) : le
   * drapeau reste rendu mais inerte.
   */
  openReport: ((request: ModalReportRequest) => void) | null;
}

const ModalReportContext = createContext<ModalReportContextValue>({ openReport: null });

export const ModalReportProviderContext = ModalReportContext.Provider;

export function useModalReport(): ModalReportContextValue {
  return useContext(ModalReportContext);
}

/**
 * Sérialise l'instantané d'état d'une modale en JSON pour le champ `modalState`. Best-effort : une
 * valeur absente ou non sérialisable (cycle) rend `undefined` — le drapeau enverra alors le contexte
 * de base, jamais une exception.
 */
export function serializeModalState(value: unknown): string | undefined {
  if (undefined === value || null === value) {
    return undefined;
  }
  try {
    return JSON.stringify(value);
  } catch {
    return undefined;
  }
}
