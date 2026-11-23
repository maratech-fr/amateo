import { AlertTriangle } from "lucide-react";
import { Link } from "react-router";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { useMe } from "@/shared/session/queries";

import { CREDITS_TEXTS } from "./texts";

/**
 * Au-dessus de son palier, le club n'a plus de sorties : chaque écran, pour chaque membre, affiche
 * le message que le serveur sert (`me.club.entitlements.teamCapMessage`), jamais recalculé ici, et
 * mène aux offres. Rien quand le serveur ne sert aucun message. Les boutons de sortie restent
 * actifs : le refus se dit au clic.
 */
export function TeamCapBanner() {
  const { data: me } = useMe();
  const message = me?.club?.entitlements?.teamCapMessage;
  if (!message) {
    return null;
  }

  return (
    <NoticeBanner tone="destructive" role="alert" className="mb-4">
      <div className="flex items-center gap-2">
        <AlertTriangle className="size-4 shrink-0 text-destructive" aria-hidden="true" />
        <span className="min-w-0 flex-1">{message}</span>
        <Link to="/club" className="shrink-0 font-medium underline underline-offset-2 hover:opacity-80">
          {CREDITS_TEXTS.offersLink}
        </Link>
      </div>
    </NoticeBanner>
  );
}
