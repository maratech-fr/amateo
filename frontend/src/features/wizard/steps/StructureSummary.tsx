import { Lock } from "lucide-react";
import type { ReactNode } from "react";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

import type { Team } from "../api";
import { coachMeta, orderedCoaches } from "../lib/ranking";
import { coachTeamNames } from "../lib/summary";
import { usePriorityTiers, useWizardCoachPlayers, useWizardCoaches, useWizardTeamCoaches, useWizardTeams } from "../queries";

/** One item per row — shared by the recap and the period read-only views. */
export function SummaryRow({ label, meta, action, className }: { label: ReactNode; meta?: ReactNode; action?: ReactNode; className?: string }) {
  return (
    <div className={cn("flex items-center justify-between gap-4 border-b border-border/60 py-1.5 text-sm last:border-0", className)}>
      <span className="text-foreground">{label}</span>
      <span className="flex shrink-0 items-center gap-2">
        {meta ? <span className="text-xs text-muted-foreground">{meta}</span> : null}
        {/* P4-44 — le récap devient ACTIONNABLE pour le seul cas où l'écran « Réserver »
            ne peut rien : une réservation hors grille n'a aucune case où s'afficher
            là-bas (la grille boucle sur les créneaux), donc c'est ICI ou nulle part. */}
        {action}
      </span>
    </div>
  );
}

/** Section header: label + a count badge, fed to AccordionSection's `title`. */
export function SectionCountTitle({ label, count }: { label: string; count: number }) {
  return (
    <span className="flex flex-1 items-center gap-2">
      <span>{label}</span>
      <span className="rounded-full bg-muted px-2 text-xs font-normal text-muted-foreground">{count}</span>
    </span>
  );
}

const emptyDash = <p className="py-1.5 text-sm text-muted-foreground">—</p>;

/**
 * Teams grouped by priority tier, one collapsible accordion per tier, tiers in
 * ranking order (S→D), teams within a tier ranked. Shared by the recap and the
 * period read-only teams view so both read the same way.
 */
export function TeamTierAccordion({ teams, renderRow, defaultOpen = true }: { teams: Team[]; renderRow: (team: Team) => ReactNode; defaultOpen?: boolean }) {
  const { data: tiers = [] } = usePriorityTiers();
  const groups = groupTeamsByTier(teams, tiers);
  if (0 === groups.length) {
    return emptyDash;
  }
  return (
    <div className="flex flex-col gap-1.5">
      {groups.map((g) => (
        <AccordionSection key={g.tier?.id ?? "orphan"} defaultOpen={defaultOpen} title={<SectionCountTitle label={tierGroupLabel(g.tier)} count={g.teams.length} />}>
          {g.teams.map(renderRow)}
        </AccordionSection>
      ))}
    </div>
  );
}

/** The "inherited, read-only for this period" banner shown atop each readonly view. */
function LockedBanner({ title }: { title: string }) {
  return (
    <p className="flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
      <Lock className="size-4 shrink-0" />
      {title} — hérité du planning de saison, en lecture seule pour cette période.
    </p>
  );
}

// ---------------------------------------------------------------------------
// Period read-only views (wizard "period" mode): inherited from the base plan.
// ---------------------------------------------------------------------------

export function ReadonlyCoaches() {
  const { data: coaches = [] } = useWizardCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const { data: teams = [] } = useWizardTeams();

  const coachPlayerIds = new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId));
  const teamName = new Map(teams.map((t) => [t.id, t.name]));

  return (
    <div className="space-y-3">
      <LockedBanner title="Coachs" />
      {0 === coaches.length ? (
        <EmptyHint>Aucun coach.</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-1 rounded-md border border-border">
          {orderedCoaches(coaches, coachPlayerIds).map(({ coach: c }) => {
            const teamsOf = coachTeamNames(c.id, teamCoaches, teamName);
            const meta = coachMeta(c.isEmployee, coachPlayerIds.has(c.id), c.gender);
            return (
              <li key={c.id} className="flex items-center justify-between gap-3 border-b border-border/60 px-3 py-1.5 text-sm last:border-0">
                <span>{`${c.firstName} ${c.lastName}`.trim() + (teamsOf.length > 0 ? ` (${teamsOf.join(", ")})` : "")}</span>
                {meta ? <span className="shrink-0 text-xs text-muted-foreground">{meta}</span> : null}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
