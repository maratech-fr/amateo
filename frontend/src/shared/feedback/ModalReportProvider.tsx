import { type ReactNode, useMemo, useState } from "react";

import { ModalReportProviderContext, type ModalReportRequest } from "@/shared/components/ui/modalReportContext";

import { FeedbackDialog } from "./FeedbackDialog";

/**
 * D14 (PR B « Signaler depuis une modale ») — le PROVIDER du canal de signalement contextuel des
 * modales. Monté dans le shell applicatif (AppLayout), il câble `openReport` du contexte neutre
 * {@link ModalReportProviderContext} : chaque drapeau « Signaler » d'une {@link Modal} descendante
 * l'appelle avec l'instantané de SON état, et le provider ouvre `FeedbackDialog` en variante
 * contextuelle PAR-DESSUS la modale courante.
 *
 * Il vit dans `shared/feedback/` (il connaît `FeedbackDialog`) pendant que le contexte reste neutre
 * dans `shared/components/ui/` : aucun cycle d'import.
 */
export function ModalReportProvider({ children }: { children: ReactNode }) {
  const [request, setRequest] = useState<ModalReportRequest | null>(null);
  const value = useMemo(() => ({ openReport: (next: ModalReportRequest) => setRequest(next) }), []);

  return (
    <ModalReportProviderContext value={value}>
      {children}
      {null !== request ? (
        <FeedbackDialog variant="contextual" modalState={request.modalState} screenLabel={request.screenLabel} onClose={() => setRequest(null)} />
      ) : null}
    </ModalReportProviderContext>
  );
}
