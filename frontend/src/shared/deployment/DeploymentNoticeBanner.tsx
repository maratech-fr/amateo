import { Wrench, X } from "lucide-react";
import { useReducer } from "react";

import { Button } from "@/shared/components/ui/button";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { PRODUCT_NAME } from "@/shared/lib/product";

import { useDeploymentNotice } from "./queries";

// Début = date longue + heure courte ; retour = juste l'heure (même jour, par construction).
// Fuseau LOCAL (pas de timeZone forcé) : le gestionnaire lit l'heure de SON navigateur.
const startFmt = new Intl.DateTimeFormat("fr-FR", { dateStyle: "long", timeStyle: "short" });
const timeFmt = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit" });

// Clé de session liée à l'instant PROGRAMMÉ : un « Masquer » tait l'annonce de CETTE mise en
// production pour la session, mais une NOUVELLE programmation (autre `scheduledAt`) revient.
const DISMISS_PREFIX = "deployment-notice-dismissed:";

function isDismissed(scheduledAt: string): boolean {
  try {
    return "1" === sessionStorage.getItem(DISMISS_PREFIX + scheduledAt);
  } catch {
    return false;
  }
}

/**
 * Bandeau d'annonce d'une mise en production programmée — monté une fois pour tout l'espace
 * authentifié (près de `CreditsBanner`). Se décide depuis `useDeploymentNotice` : rien tant qu'aucune
 * mise en production n'est programmée (ou que la lecture a échoué, `data` indéfini). Fermable par
 * session (patron `CreditsBanner`), avec réapparition pour une nouvelle programmation.
 */
export function DeploymentNoticeBanner() {
  // Forcer le re-render à la fermeture : l'état « masqué » vit en sessionStorage, pas en state.
  const [, bump] = useReducer((n: number) => n + 1, 0);
  const { data } = useDeploymentNotice();

  if (!data || isDismissed(data.scheduledAt)) {
    return null;
  }

  const close = () => {
    try {
      sessionStorage.setItem(DISMISS_PREFIX + data.scheduledAt, "1");
    } catch {
      // sessionStorage indisponible (mode privé) : on ne masque pas en dur, pas grave.
    }
    bump();
  };

  const start = startFmt.format(new Date(data.scheduledAt));
  const until = timeFmt.format(new Date(data.expectedReturnAt));

  return (
    <NoticeBanner tone="warning" role="status" className="mb-4">
      <div className="flex items-center gap-2">
        <Wrench className="size-4 shrink-0 text-warning" aria-hidden="true" />
        <span className="min-w-0 flex-1">
          Maintenance prévue le {start} : {PRODUCT_NAME} sera indisponible jusqu'à {until} environ.
        </span>
        <Button type="button" variant="ghost" size="icon-sm" onClick={close} aria-label="Masquer l'annonce de maintenance" className="shrink-0 text-muted-foreground">
          <X className="size-4" />
        </Button>
      </div>
    </NoticeBanner>
  );
}
