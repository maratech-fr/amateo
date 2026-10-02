import { CalendarPlus } from "lucide-react";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { useMe } from "@/shared/session/queries";
import { useTransitionUiStore } from "@/shared/stores/transitionUiStore";
import { useTodayDate } from "@/shared/lib/clock";

import { frDayMonth, localIso, seasonPrepWindow } from "./seasonTransition";
import { isManagementRole } from "@/shared/lib/roles";

/**
 * Permanent anticipation banner (transition P2-PR2): from May 15 until the
 * current season's real end (fallback July-15 pivot for a dormant club), while
 * NO season N+1 exists, nudge the manager to prepare the next season — window
 * shared with the SeasonSelector via seasonPrepWindow. Entirely derived from
 * /api/me (no endpoint); the CTA opens the
 * existing "Préparer la saison suivante" confirm via the shared store. The
 * e-mail cron (app:seasons:remind-transition) is the out-of-app twin.
 * Not dismissible by design — the user asked for a permanent on-screen nudge.
 */
export function SeasonTransitionBanner({ today }: { today?: Date } = {}) {
  const { data: me } = useMe();
  const openConfirm = useTransitionUiStore((s) => s.openConfirm);
  // Date du jour RÉACTIVE : l'horloge simulée serveur arrive après le premier rendu (via
  // `useApplySimulatedClock`), et sans abonnement la bannière restait à la date réelle en
  // prod. Le prop `today` (tests) prime quand il est fourni.
  const reactiveToday = useTodayDate();
  const effectiveToday = today ?? reactiveToday;

  const seasons = me?.seasons ?? [];
  const current = seasons.find((s) => s.isCurrent);
  // Preparing a season is a management action (the endpoint 403s otherwise) —
  // never nag members who cannot act on the nudge.
  const isManagement = isManagementRole(me?.role);
  if (undefined === current || !isManagement) {
    return null;
  }

  // Fenêtre PARTAGÉE avec le sélecteur (revue D : logique unique, plus de
  // divergence). Ancrée sur AUJOURD'HUI (nudge un club dormant avant chaque pivot) ;
  // bannière = à partir du 15 mai. La deadline affichée est la borne réelle (fin de
  // saison), plus le 15 juillet codé en dur (revue D F2).
  const { inWindow, successorExists, deadline } = seasonPrepWindow(localIso(effectiveToday), seasons, "05-15");
  // La bannière (nag) se masque hors fenêtre ET quand un successeur existe déjà.
  if (!inWindow || successorExists) {
    return null;
  }

  return (
    <NoticeBanner tone="accent" role="status" className="mb-4">
      <div className="flex items-center gap-2">
        <CalendarPlus className="size-4 shrink-0 text-accent" />
        <span className="min-w-0 flex-1">
          La saison <span className="font-medium">{current.name}</span> se termine — préparez la saison suivante avant le {frDayMonth(deadline)}.
        </span>
        <button type="button" className="shrink-0 font-medium text-accent hover:underline" onClick={openConfirm}>
          Préparer la saison suivante
        </button>
      </div>
    </NoticeBanner>
  );
}
