import { type ReactNode, useState } from "react";
import { useNavigate } from "react-router";

import { FeedbackDialog } from "@/shared/feedback/FeedbackDialog";
import { SystemScreen } from "@/shared/components/ui/system-screen";

export interface NotFoundPageProps {
  /** Destination du geste principal (défaut : l'accueil). L'admin pointe sa console. */
  homeTo?: string;
  homeLabel?: string;
  /** Le geste « Contacter le support » (canal de signalement du club) — absent côté admin. */
  showSupport?: boolean;
}

/**
 * P5-14 — la 404. Sert le catch-all applicatif (sous AppLayout) ET le catch-all
 * admin (« Retour à la console »).
 *
 * ⚠⚠ DOCTRINE DE SÉCURITÉ, NON NÉGOCIABLE — cette même page sert au REFUS TENANT :
 * une ressource d'un AUTRE club rend 404, jamais 403 (un 403 confirmerait son
 * existence). Sa copie doit donc rester MUETTE sur les droits. Ne JAMAIS ajouter
 * « vous n'avez pas accès » / « permission » / « rôle » ici : ce serait transformer
 * la 404 en oracle d'existence — c'est exactement l'« amélioration » qu'une
 * relecture bien intentionnée ajouterait. Le 403, lui, a sa propre page.
 */
export function NotFoundPage({ homeTo = "/", homeLabel = "Retour à l'accueil", showSupport = true }: NotFoundPageProps): ReactNode {
  const navigate = useNavigate();
  const [supportOpen, setSupportOpen] = useState(false);

  return (
    <>
      <SystemScreen
        title="Ce créneau n'existe pas."
        primaryAction={{ label: homeLabel, onClick: () => void navigate(homeTo) }}
        secondaryAction={showSupport ? { label: "Contacter le support", onClick: () => setSupportOpen(true) } : undefined}
      >
        Vous cherchez une page qui n'a jamais été réservée — ou qui a été déplacée pendant que vous aviez le dos tourné. Ça arrive aux meilleurs plannings.
      </SystemScreen>
      {supportOpen ? <FeedbackDialog variant="contextual" onClose={() => setSupportOpen(false)} /> : null}
    </>
  );
}
