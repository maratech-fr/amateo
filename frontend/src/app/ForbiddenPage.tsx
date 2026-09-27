import { type ReactNode, useState } from "react";
import { useNavigate } from "react-router";

import { FeedbackDialog } from "@/shared/feedback/FeedbackDialog";
import { SystemScreen } from "@/shared/components/ui/system-screen";

/**
 * P5-14 — le 403. Rendu par `RouteErrorBoundary` quand une route `throw`e une
 * `Response` 403 (idiome react-router). PAS de câblage page par page : une seule
 * porte.
 *
 * ⚑ Constat honnête : aucun chemin UI actuel ne produit un 403 pleine page — le
 * front masque les gestes de gestion en amont (`shared/lib/roles.ts:16-19`) et le
 * 403 saison s'auto-guérit (`client.ts:108-113`). Le fondateur a tranché : on livre
 * la porte quand même, pour le jour où un Membre atteint une route de gestion.
 *
 * « Demander l'accès » ne mène nulle part côté produit aujourd'hui → branché sur le
 * `FeedbackDialog` contextuel, comme « Contacter le support » (arbitrage fondateur).
 */
export function ForbiddenPage(): ReactNode {
  const navigate = useNavigate();
  const [accessOpen, setAccessOpen] = useState(false);

  return (
    <>
      <SystemScreen
        title="Vestiaire réservé aux licenciés."
        primaryAction={{ label: "Retour à l'accueil", onClick: () => void navigate("/") }}
        secondaryAction={{ label: "Demander l'accès", onClick: () => setAccessOpen(true) }}
      >
        Votre compte n'a pas les droits pour cette page. Si vous pensez que c'est une erreur d'arbitrage, demandez à un responsable du club de vérifier votre rôle.
      </SystemScreen>
      {accessOpen ? <FeedbackDialog variant="contextual" onClose={() => setAccessOpen(false)} /> : null}
    </>
  );
}
