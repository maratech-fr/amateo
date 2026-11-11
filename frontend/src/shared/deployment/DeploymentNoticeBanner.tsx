import { Wrench, X } from "lucide-react";
import { useReducer } from "react";

import { Button } from "@/shared/components/ui/button";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";

import { useDeploymentNotice } from "./queries";
import { DEPLOYMENT_TEXTS } from "./texts";

// Début = date longue + heure courte ; retour = l'heure seule si c'est le même jour CALENDAIRE
// local, sinon date longue + heure (une fenêtre qui enjambe minuit).
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

  const startAt = new Date(data.scheduledAt);
  const returnAt = new Date(data.expectedReturnAt);
  const start = startFmt.format(startAt);
  const notice =
    startAt.toDateString() === returnAt.toDateString()
      ? DEPLOYMENT_TEXTS.noticeSameDay(start, timeFmt.format(returnAt))
      : DEPLOYMENT_TEXTS.noticeOtherDay(start, startFmt.format(returnAt));

  return (
    <NoticeBanner tone="warning" role="status" className="mb-4">
      <div className="flex items-center gap-2">
        <Wrench className="size-4 shrink-0 text-warning" aria-hidden="true" />
        <span className="min-w-0 flex-1">{notice}</span>
        <Button type="button" variant="ghost" size="icon-sm" onClick={close} aria-label={DEPLOYMENT_TEXTS.dismiss} className="shrink-0 text-muted-foreground">
          <X className="size-4" />
        </Button>
      </div>
    </NoticeBanner>
  );
}
