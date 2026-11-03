import { AlertTriangle } from "lucide-react";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { frDateSlashed } from "@/shared/lib/date";

import { useMe } from "./queries";
import { SESSION_TEXTS } from "./texts";

/**
 * Le club n'a plus de gestionnaire (`me.club.closingOn`, servi par le serveur) : chaque écran dit
 * la date d'effacement. Information seule, sans bouton — rien quand `closingOn` est null ou que la
 * session n'est pas chargée.
 */
export function ClubClosingBanner() {
  const { data: me } = useMe();
  const closingOn = me?.club?.closingOn;
  if (!closingOn) {
    return null;
  }
  return <NoticeBanner tone="warning" role="status" icon={<AlertTriangle className="size-4" />} message={SESSION_TEXTS.clubClosing(frDateSlashed(closingOn))} className="mb-4" />;
}
