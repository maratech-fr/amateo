import { type ReactNode, useState } from "react";

import { FeedbackDialog } from "@/shared/feedback/FeedbackDialog";
import { SystemScreen } from "@/shared/components/ui/system-screen";

/**
 * P5-14 — le hors-ligne PLEINE PAGE. Sert les cas où il n'y a RIEN derrière un
 * bandeau : échec de chunk (RouteErrorBoundary) ou échec de /api/me au boot
 * (AuthGuard). ⚠ Ce n'est PAS le bandeau hors-ligne d'usage normal (hors lot).
 *
 * `onRetry` diffère selon l'appelant (rejouer la navigation, refetch de /api/me) —
 * d'où l'injection. Rendu SOUS providers dans les deux cas, donc « Contacter le
 * support » peut ouvrir le `FeedbackDialog`.
 */
export function OfflineScreen({ onRetry }: { onRetry: () => void }): ReactNode {
  const [supportOpen, setSupportOpen] = useState(false);

  return (
    <>
      <SystemScreen
        title="Pas de réseau dans ce gymnase."
        primaryAction={{ label: "Réessayer", onClick: onRetry }}
        secondaryAction={{ label: "Contacter le support", onClick: () => setSupportOpen(true) }}
      >
        Le chargement a échoué faute de connexion. Classique en salle : deux barres, trois murs de béton. Reconnectez-vous au réseau, puis réessayez — le reste de l'application reste utilisable.
      </SystemScreen>
      {supportOpen ? <FeedbackDialog variant="contextual" onClose={() => setSupportOpen(false)} /> : null}
    </>
  );
}
