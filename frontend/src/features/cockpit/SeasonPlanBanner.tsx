import { useState } from "react";
import { useNavigate } from "react-router";

import { useMe } from "@/shared/session/queries";
import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";

import { SeasonSchedulesModal } from "./SeasonSchedulesModal";
import { planRepresentative, visibleSeasonPlans } from "@/features/planning/lib/versions";

import type { CalendarEntry } from "./api";
import { StalenessPill } from "./StalenessPill";
import { useSchedulePlans } from "./queries";
import { seasonPlanCounts } from "./seasonPlannings";

interface SeasonPlanBannerProps {
  schedules: Schedule[];
  /** Whether the plan points at a version (state 3) or not yet (state 2). */
  socleValidated: boolean;
  /** Schedules query still in flight — don't flash "aucun planning principal". */
  loading?: boolean;
  /** Entrées de calendrier — pour exclure une mère découpée des lignes 0 version (B1 F3). */
  entries?: CalendarEntry[];
}

/** Top strip: the season's main plan at a glance + entry points to consult / edit / list all plans. */
export function SeasonPlanBanner({ schedules, socleValidated, loading = false, entries = [] }: SeasonPlanBannerProps) {
  const navigate = useNavigate();
  const { data: me } = useMe();
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const [listOpen, setListOpen] = useState(false);

  // Le planning de la saison TEL QU'IL EST : la version pointée si le gestionnaire
  // en a choisi une, sinon la dernière terminée. Le cockpit s'ouvre dès la première
  // génération (inv. 8/16) alors que le pointeur, lui, attend une validation — ne
  // lire que le pointeur laissait la bannière VIDE en état 2 et après chaque reopen,
  // c'est-à-dire précisément quand le gestionnaire vient regarder son planning.
  const chosen = planRepresentative(visibleSeasonPlans(schedules));
  // Distinct plannings = the season main plan (1) + one per period overlay
  // (versions are navigated inside the planning, not counted here). Counts
  // include OPEN plannings (no finished version) — same rows as the modal —
  // and the subtitle names how many are still in progress so the number never
  // implies a ready secondary schedule (revue #260 round 2).
  // Inclut les plans de période SANS version générée (« en cours ») pour que le
  // compteur colle à « tous les plannings » (retour fondateur 2026-07-19).
  const { data: plans } = useSchedulePlans();
  const { total: planCount, overlays: overlayCount, openOverlays: openOverlayCount } = seasonPlanCounts(schedules, plans ?? [], entries, !loading);
  // P4-173 — la péremption du SOCLE vient du plan SEASON (calendarEntryId === null), servie par le
  // backend ; null (donc pas de pastille) tant qu'aucune version n'est pointée ou fenêtre révolue.
  const seasonStaleness = (plans ?? []).find((p) => null === p.calendarEntryId)?.staleness ?? null;

  // Validated (state 3) → consult the plan. Not yet (state 2) → back to the
  // wizard's generation step to finish/validate it.
  const open = () => {
    if (socleValidated) {
      navigate("/planning");
      return;
    }
    // Même racine que la modale : un mode période persisté ferait générer le
    // plan de PÉRIODE à la place du socle — reset avant d'ouvrir la génération.
    // On purge aussi la sélection planning (bug fondateur 2026-08-19) : une sélection
    // de période laissée ailleurs ne doit pas s'afficher dans l'étape Génération de la SAISON.
    setSelectedScheduleId(null);
    useWizardStore.getState().exitPeriodMode();
    useWizardStore.getState().jumpTo("generate");
    navigate("/wizard");
  };

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-4">
      <div>
        {/* Le plan porte un NOM (ADR-0002 inv. 12) — l'afficher, pas un libellé générique
            (retour fondateur 2026-07-18 : « Planning de la saison » ici, « Planning
            principal » là = pas UX friendly). */}
        <p className="text-sm font-semibold">{me?.seasonPlan?.name ?? "Planning principal"}</p>
        <p className="inline-flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
          {chosen ? (
            <>
              <span>{STATUS_LABELS[chosen.status]}</span>
              <StalenessPill staleness={seasonStaleness} />
              {overlayCount > 0 ? <span>{` · ${overlayCount} planning${overlayCount > 1 ? "s" : ""} secondaire${overlayCount > 1 ? "s" : ""}${openOverlayCount > 0 ? ` (${openOverlayCount} en cours)` : ""}`}</span> : null}
            </>
          ) : loading ? (
            "Chargement…"
          ) : (
            "Aucun planning principal désigné"
          )}
        </p>
      </div>
      <div className="flex flex-wrap gap-2">
        {/* Only "Ouvrir": once inside the planning, the manager decides whether to
            modify (the page has its own reopen/validate controls) — no separate
            Modifier here (user request). */}
        <Button variant="outline" size="sm" onClick={open}>
          Ouvrir
        </Button>
        <Button variant="ghost" size="sm" onClick={() => setListOpen(true)}>
          Tous les plannings ({planCount})
        </Button>
      </div>

      {listOpen ? <SeasonSchedulesModal schedules={schedules} entries={entries} schedulesResolved={!loading} onClose={() => setListOpen(false)} /> : null}
    </div>
  );
}
