import { AlertTriangle } from "lucide-react";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { frDateSlashed } from "@/shared/lib/date";

import { useMe } from "./queries";
import { RequestManagerRoleButton } from "./RequestManagerRoleButton";
import { SESSION_TEXTS } from "./texts";

/** Date (JJ/MM/AAAA) d'un horodatage RFC 3339 ou d'une date Y-m-d servis par le serveur. */
function day(value: string): string {
  return frDateSlashed(value.slice(0, 10));
}

/**
 * Le club n'a plus de gestionnaire (`me.club.closingOn`, servi par le serveur) : chaque écran dit
 * la date d'effacement. Rien quand `closingOn` est null ou que la session n'est pas chargée.
 *
 * Chantier Membre (PR 3) — le bandeau porte le geste de reprise : un Membre demande à devenir
 * gestionnaire (la demande part à l'adresse officielle du club). L'état affiché est celui que le
 * serveur calcule (`me.managerRequest`) : aucune demande → le bouton ; transmise → la date ;
 * expirée → renvoyable ; refusée → la date de renouvellement, sans bouton.
 */
export function ClubClosingBanner() {
  const { data: me } = useMe();
  const closingOn = me?.club?.closingOn;
  if (!closingOn) {
    return null;
  }
  const managerRequest = me.managerRequest ?? null;
  const canAsk = "active" === me.membershipStatus && (null === managerRequest || "expired" === managerRequest.status);

  let status: string | null = null;
  if ("club_pending" === managerRequest?.status) {
    // Adresse officielle inconnue : rien n'est parti au club — le bandeau ne l'affirme jamais.
    status = false === managerRequest.clubEmailKnown ? SESSION_TEXTS.managerRequestNoOfficialAddress : SESSION_TEXTS.repriseRequestSent(day(managerRequest.since));
  } else if ("expired" === managerRequest?.status) {
    status = SESSION_TEXTS.managerRequestExpired(day(managerRequest.since));
  } else if ("refused" === managerRequest?.status) {
    status = SESSION_TEXTS.managerRequestRefused(day(managerRequest.since), day(managerRequest.until ?? managerRequest.since));
  }

  return (
    <NoticeBanner tone="warning" role="status" icon={<AlertTriangle className="size-4" />} message={SESSION_TEXTS.clubClosing(frDateSlashed(closingOn))} className="mb-4">
      {null === status && !canAsk ? null : (
        <div className="flex flex-wrap items-center justify-between gap-2 pl-6">
          {null === status ? <span /> : <p>{status}</p>}
          {canAsk ? <RequestManagerRoleButton /> : null}
        </div>
      )}
    </NoticeBanner>
  );
}
