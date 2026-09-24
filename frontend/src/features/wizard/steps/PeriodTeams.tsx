import { Link2, Loader2 } from "lucide-react";
import { useEffect, useState } from "react";

import { useCalendarEntry, usePeriodAnchor, useSchedulePlanForEntry } from "@/features/cockpit/queries";
import { useTeamLinks } from "@/features/matches/queries";
import { INTENSITY_LABEL } from "@/features/matches/lib/teamLinkLabel";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { Team, TeamPeriodOverride } from "../api";
import { mutualisedTeammateLabel } from "../lib/sharedTraining";
import {
  useCreateTeamPeriodOverride,
  useDeleteTeamPeriodOverride,
  usePriorityTiers,
  useSharedTrainingBlocks,
  useTeamPeriodOverrides,
  useUpdateTeamPeriodOverride,
  useWizardTeams,
} from "../queries";
import { SectionCountTitle } from "./StructureSummary";
import { claimPeriodSeed, periodSeedWasClaimed } from "./periodSeed";
import { PeriodAnchorGate } from "./PeriodAnchorGate";
import { TeamLinksModal } from "./TeamLinksModal";

const fieldClass = "h-8 rounded-md border border-input bg-background px-2 text-sm";

// In-session guard against re-seeding between firing the Fanion-only seed and the
// plan query reflecting teamSelectionInitialized (the DURABLE, reload-proof signal).
// Module-level so it survives a step remount (WizardLayout unmounts inactive steps).
// Claimed ONCE and never un-claimed (un-claiming on a partial failure would re-run the
// seed against a still-empty cache and double-write): a failed seed is best-effort, the
// manager completes it with the "Fanion seul" ramp.
/**
 * Period-editable teams (F1): the club roster is READ-ONLY (grouped by rank), but
 * each team can be toggled off for the period and given a period-specific number
 * of sessions. A sparse TeamPeriodOverride backs each change; the base plan and
 * the Team's seasonal fields are never touched. Default on a fresh period: only
 * the top tier (Fanion) trains — the resumption ramp starts there.
 */
export function PeriodTeams({ calendarEntryId }: { calendarEntryId: string }) {
  const anchor = usePeriodAnchor(calendarEntryId);

  return (
    <PeriodAnchorGate
      anchor={anchor}
      loadingLabel="Chargement des équipes de la période…"
      errorLabel="Impossible de charger la période : les modifications ne seraient pas enregistrées."
    >
      {(schedulePlanId) => <PeriodTeamsPanel calendarEntryId={calendarEntryId} schedulePlanId={schedulePlanId} />}
    </PeriodAnchorGate>
  );
}

function PeriodTeamsPanel({ calendarEntryId, schedulePlanId }: { calendarEntryId: string; schedulePlanId: string }) {
  const { data: teams = [] } = useWizardTeams();
  const { data: tiers = [] } = usePriorityTiers();
  // Le TYPE de période gouverne le défaut d'équipes (E3) : reprise = Fanion + importantes,
  // fermeture = tout le club (structure verrouillée). Lu comme PeriodConstraints le fait.
  const { data: entry } = useCalendarEntry(calendarEntryId);
  const isClosure = "closure" === entry?.periodType;
  // Le PLAN entier est nécessaire ici (le garde de seed lit teamSelectionInitialized) ;
  // `usePeriodAnchor` fournit l'ancre ET son état — ne pas re-dériver un `?? null` nu.
  const { data: plan, isLoading: planLoading } = useSchedulePlanForEntry(calendarEntryId);
  const overridesQueryState = useTeamPeriodOverrides(schedulePlanId);
  const { data: overrides = [], isLoading, refetch: retryOverrides } = overridesQueryState;
  const overridesFailed = readFailed(overridesQueryState);
  const create = useCreateTeamPeriodOverride(schedulePlanId);
  const update = useUpdateTeamPeriodOverride(schedulePlanId);
  const del = useDeleteTeamPeriodOverride(schedulePlanId);
  const [busy, setBusy] = useState(false);
  // P2-51 — le repère « mutualisée » DOIT figurer aussi en période, sinon il mentirait par
  // omission : les blocs de mutualisation de CETTE période (schedulePlanId derrière PeriodAnchorGate).
  const { data: sharedBlocks = [] } = useSharedTrainingBlocks(schedulePlanId);
  // P2-45 — les passerelles du club+saison (SERVIES par le module matchs) : le repère « passerelle »
  // et la modale Liens. En période elles sont en LECTURE SEULE (structure de saison).
  const { data: teamLinks = [] } = useTeamLinks();
  const [linksTeam, setLinksTeam] = useState<Team | null>(null);
  const nameOf = (id: string): string => teams.find((t) => t.id === id)?.name ?? "?";
  // P2-51 — la sous-ligne « Mutualisée avec … » tirée des blocs (helper pur, testé).
  const mutualiseLabelOf = (teamId: string): string | null => mutualisedTeammateLabel(teamId, sharedBlocks, nameOf);
  // P2-45 — le repère « passerelle », intensité comprise (lue telle quelle du lien, jamais recalculée).
  const bridgeLabelOf = (teamId: string): string | null => {
    const parts = teamLinks
      .filter((l) => l.teamAId === teamId || l.teamBId === teamId)
      .map((l) => `${teams.find((t) => t.id === (l.teamAId === teamId ? l.teamBId : l.teamAId))?.name ?? "?"} (${INTENSITY_LABEL[l.trainingIntensity]})`);
    return parts.length > 0 ? `Passerelle avec ${parts.join(", ")}` : null;
  };
  const linksLabelOf = (teamId: string): string | null => {
    const combined = [mutualiseLabelOf(teamId), bridgeLabelOf(teamId)].filter((x): x is string => null !== x);
    return combined.length > 0 ? combined.join(" · ") : null;
  };

  const groups = groupTeamsByTier(teams, tiers);
  const topTierId = groups[0]?.tier?.id ?? null;
  const overrideOf = new Map<string, TeamPeriodOverride>(overrides.map((o) => [o.teamId, o]));

  const isActive = (t: Team): boolean => overrideOf.get(t.id)?.isActive ?? true;
  const sessionsOf = (t: Team): number => overrideOf.get(t.id)?.sessionsPerWeek ?? t.sessionsPerWeek;

  // Upsert the override, or DELETE it when the state is back to the seasonal default
  // (active + seasonal session count) — keeps the table sparse. Returns the mutation
  // promise so batch actions (seed / ramp) can await the whole set.
  /**
   * Rend `null` quand il n'y a RIEN à écrire (l'état voulu est déjà celui de la saison,
   * sans override) — à distinguer d'une écriture réussie : `Promise.allSettled` ne voit
   * aucune différence entre « zéro requête » et « toutes réussies », et c'est ainsi que
   * « Sélection appliquée » se confirmait sur zéro ligne écrite.
   */
  const upsertAsync = (t: Team, next: { isActive: boolean; sessions: number | null }): Promise<unknown> | null => {
    const existing = overrideOf.get(t.id);
    const backToSeasonal = next.isActive && (null === next.sessions || next.sessions === t.sessionsPerWeek);
    if (backToSeasonal) {
      return existing ? del.mutateAsync(existing.id) : null;
    }
    const body = { schedulePlanId, teamId: t.id, isActive: next.isActive, sessionsPerWeek: next.sessions };
    return existing ? update.mutateAsync({ id: existing.id, body }) : create.mutateAsync(body);
  };
  // Fire-and-forget for single edits (checkbox/session). Swallow the rejection: the
  // global MutationCache already toasts it — this only avoids an unhandledrejection.
  const upsert = (t: Team, next: { isActive: boolean; sessions: number | null }) => void upsertAsync(t, next)?.catch(() => {});

  // Default team selection on a FRESH period (no overrides yet, not already seeded this
  // session), ONCE — best-effort. The DEPTH depends on the period type (E3) :
  //  - reprise (holiday) : Fanion + importantes reprennent (tiers S+A) ; le reste démarre
  //    en pause, coché au fil de la montée en charge.
  //  - fermeture (closure) : structure verrouillée → tout le club reste actif, rien n'est
  //    désactivé d'office (le gestionnaire décoche à la main une équipe loisir au besoin).
  // Controls stay disabled (busy) until the async writes settle so a first click can't
  // race them. The key is claimed BEFORE firing and never un-claimed: un-claiming on a
  // partial failure re-runs the effect against a still-empty override cache and
  // double-writes the teams that already succeeded. On a failure the global toast
  // informs; the manager applies a ramp preset to complete.
  //
  // The DURABLE signal is plan.teamSelectionInitialized (server-set on the first
  // override, survives reload); seededPeriods only guards the in-session window
  // between firing the seed and the plan query reflecting the flag. ADR-0002 lot C:
  // the flag lives on the plan — the RESPONSE — not on the calendar event.
  useEffect(() => {
    // `false !== plan?.teamSelectionInitialized` bails on undefined AND on null:
    // seed ONLY when the plan loaded and says explicitly "jamais configuré".
    //
    // Les cas de sortie sont voulus, et aucun n'est le cas nominal. `undefined` (plan OU
    // entry) = fetch en erreur/en vol : ne pas seeder une période inconnue (elle pourrait
    // être déjà configurée, son GET ayant simplement raté) — seeder à tort désactiverait
    // des équipes que le gestionnaire vient d'activer, et sans le type on ne connaît pas la
    // profondeur du seed. `null` (plan) = aucun plan : inatteignable pour une closure/holiday
    // depuis que le geste est atomique (l'entrée et son plan naissent ensemble, ou pas du
    // tout), et le mode période ne s'ouvre que sur ces deux types. Un null ici est donc une
    // anomalie, pas un état neuf : ne rien faire est le choix conservateur.
    // `overridesFailed` fait partie de la garde : sur une query en ERREUR, `overrides`
    // vaut `[]` et `isLoading` est faux — le seed passait donc toutes ses conditions et
    // désactivait la moitié du club EN ARRIÈRE-PLAN, pendant que l'écran affichait
    // « Impossible de charger la sélection ». Le gestionnaire ne voyait rien, et
    // retrouvait une sélection qu'il n'avait pas faite (ou générait sans le savoir).
    if (isLoading || overridesFailed || planLoading || undefined === entry || 0 === teams.length || overrides.length > 0 || null === topTierId || false !== plan?.teamSelectionInitialized || periodSeedWasClaimed(calendarEntryId)) {
      return;
    }
    claimPeriodSeed(calendarEntryId);
    // Fermeture (structure verrouillée) : tout le club reste actif → aucun retrait d'office.
    // Reprise : garder les 2 premiers RANGS (S+A) par RANG et non par occupation — un rang vide
    // (pas de Fanion) ne doit pas promouvoir un rang inférieur par un slice() positionnel des
    // groupes. Les rangs sont ordonnés par id (1=S…5=D). GARDE-FOU : un club SANS aucune équipe
    // en S/A (petit club tout en loisir) ne doit pas voir TOUT le monde désactivé — on retombe
    // alors sur le meilleur rang réellement présent (topTierId) : la reprise n'est jamais vide.
    const topRankIds = new Set([...tiers].sort((a, b) => a.id - b.id).slice(0, 2).map((t) => t.id));
    const keptByRank = teams.filter((t) => topRankIds.has(t.priorityTierId));
    const keepIds = new Set((keptByRank.length > 0 ? keptByRank : teams.filter((t) => t.priorityTierId === topTierId)).map((t) => t.id));
    const toDeactivate = isClosure ? [] : teams.filter((t) => !keepIds.has(t.id));
    if (0 === toDeactivate.length) {
      return;
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect -- one-shot: gate the controls while the async default-selection writes settle
    setBusy(true);
    void Promise.allSettled(toDeactivate.map((t) => create.mutateAsync({ schedulePlanId: plan.id, teamId: t.id, isActive: false }))).finally(() => setBusy(false));
  }, [isLoading, overridesFailed, planLoading, plan, entry, isClosure, teams, tiers, overrides, topTierId, calendarEntryId, create]);

  const toggle = (t: Team, value: boolean) => upsert(t, { isActive: value, sessions: overrideOf.get(t.id)?.sessionsPerWeek ?? null });
  const setSessions = (t: Team, raw: number) => {
    // Ignore an emptied / out-of-range field client-side (Number("") === 0; the field
    // caps at 7 but paste/typing can exceed it) rather than fire a doomed 422.
    if (!Number.isInteger(raw) || raw < 1 || raw > 7) {
      return;
    }
    upsert(t, { isActive: isActive(t), sessions: raw === t.sessionsPerWeek ? null : raw });
  };

  // Ramp presets: activate every team up to (and including) a tier group index —
  // awaited as a batch, controls disabled meanwhile.
  const rampTo = async (upToIndex: number) => {
    if (busy) {
      return;
    }
    setBusy(true);
    try {
      const writes = groups
        .flatMap((g, i) => g.teams.map((t) => upsertAsync(t, { isActive: i <= upToIndex, sessions: overrideOf.get(t.id)?.sessionsPerWeek ?? null })))
        .filter((w): w is Promise<unknown> => null !== w);
      const results = await Promise.allSettled(writes);
      // On ne confirme QUE si des lignes ont réellement été écrites, toutes sans échec.
      // Sans le premier test, zéro requête (overrides pas encore chargés → tout le monde
      // « déjà au défaut saison ») donnait zéro rejet, donc « Sélection appliquée » sur
      // une période intouchée. Un succès qui ment est pire qu'une erreur.
      if (0 === writes.length) {
        // Rien à écrire D'APRÈS LA CARTE LOCALE. Elle peut être périmée (un refetch
        // d'invalidation raté après le seed laisse un [] pré-seed en cache) : on ne
        // dit donc pas « appliquée », on constate — et on RESYNCHRONISE pour que la
        // carte re-reflète le serveur au prochain geste.
        toast.success("Aucune modification à enregistrer");
        void retryOverrides();
      } else if (!results.some((r) => "rejected" === r.status)) {
        toast.success("Sélection appliquée");
      }
    } finally {
      setBusy(false);
    }
  };

  // P4-1 — un GET d'overrides en échec rendait des cases « par défaut » crédibles :
  // le gestionnaire re-cochait une sélection déjà posée. L'échec passe avant le vide.
  // Sans la carte des overrides, `isActive()`/`sessionsOf()` retombent sur les
  // valeurs de SAISON : une équipe mise en pause s'affiche cochée, et un clic
  // calcule son écriture contre un état faux (POST sur une ligne existante → 422,
  // ou DELETE jamais envoyé → équipe qui reste en pause en croyant l'inverse).
  // Le panneau voisin (`PeriodConstraintsPanel`) garde déjà exactement ça.
  if (readLoading(overridesQueryState)) {
    return <p className="text-xs text-muted-foreground">Chargement de la sélection d’équipes…</p>;
  }
  if (readFailed(overridesQueryState)) {
    return (
      <LoadErrorHint onRetry={() => void retryOverrides()}>Impossible de charger la sélection d’équipes de la période.</LoadErrorHint>
    );
  }
  if (0 === groups.length) {
    return <EmptyHint>Aucune équipe.</EmptyHint>;
  }

  return (
    <div className="space-y-3" aria-busy={busy}>
      <p className="text-sm text-muted-foreground">
        {isClosure ? (
          <>
            Tout le club reste actif sur cette période — <strong>décochez</strong> les équipes qui ne s'entraînent pas (ex. loisir).
          </>
        ) : (
          <>
            Choisissez qui reprend sur cette période. Par défaut le <strong>Fanion</strong> et les équipes <strong>importantes</strong> reprennent — cochez les autres au fur et à mesure de la montée en charge.
          </>
        )}
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm" variant="outline" disabled={busy} onClick={() => void rampTo(0)}>
          Fanion seul
        </Button>
        {groups.length > 2 ? (
          <Button size="sm" variant="outline" disabled={busy} onClick={() => void rampTo(1)}>
            + importantes
          </Button>
        ) : null}
        <Button size="sm" variant="outline" disabled={busy} onClick={() => void rampTo(groups.length - 1)}>
          Tout le club
        </Button>
        {busy ? (
          <span className="flex items-center gap-1 text-xs text-muted-foreground">
            <Loader2 className="size-3 animate-spin" />
            Application…
          </span>
        ) : null}
      </div>

      <div className="flex flex-col gap-1.5">
        {groups.map((g) => (
          <AccordionSection key={g.tier?.id ?? "orphan"} defaultOpen title={<SectionCountTitle label={tierGroupLabel(g.tier)} count={g.teams.length} />}>
            {g.teams.map((t) => {
              const active = isActive(t);
              return (
                <div key={t.id} className="flex items-center justify-between gap-3 border-b border-border/60 py-1.5 text-sm last:border-0">
                  <label className="flex items-center gap-2">
                    <input type="checkbox" checked={active} disabled={busy} onChange={(e) => toggle(t, e.target.checked)} aria-label={`${t.name} active cette période`} />
                    <span className={cn(!active && "text-muted-foreground line-through")}>{t.name}</span>
                    {linksLabelOf(t.id) ? <span className="text-xs italic text-muted-foreground">· {linksLabelOf(t.id)}</span> : null}
                  </label>
                  <div className="flex items-center gap-2">
                    <label className={cn("flex items-center gap-1 text-xs text-muted-foreground", !active && "opacity-50")}>
                      séances
                      <input
                        type="number"
                        min={1}
                        max={7}
                        className={cn(fieldClass, "w-14")}
                        value={sessionsOf(t)}
                        disabled={!active || busy}
                        onChange={(e) => setSessions(t, Number(e.target.value))}
                        aria-label={`Séances de ${t.name} cette période`}
                      />
                    </label>
                    {/* P2-45 — l'affordance « Liens » (même geste qu'en saison) : passerelles (lecture
                        seule ici) + mutualisation (éditable, ancrée au plan de la période). */}
                    <Button size="icon" variant="ghost" className="size-8" aria-label={`Liens de ${t.name}`} onClick={() => setLinksTeam(t)}>
                      <Link2 className="size-4" />
                    </Button>
                  </div>
                </div>
              );
            })}
          </AccordionSection>
        ))}
      </div>

      {/* P2-45 — la modale Liens d'une équipe de la période. Passerelles en LECTURE SEULE (la saison
          seule les déclare) ; mutualisation ancrée au PLAN de la période (schedulePlanId). Les
          équipes en pause ne sont pas offertes comme candidates à la mutualisation. */}
      {null !== linksTeam ? (
        <TeamLinksModal
          team={linksTeam}
          teams={teams}
          tiers={tiers}
          schedulePlanId={schedulePlanId}
          pausedTeamIds={new Set(teams.filter((t) => !isActive(t)).map((t) => t.id))}
          readOnlyLinks
          onClose={() => setLinksTeam(null)}
        />
      ) : null}
    </div>
  );
}
