import { Link2, Loader2, Trash2 } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import { useCalendarEntry, useEntryConflicts, usePeriodAnchor, useSchedulePlanForEntry } from "@/features/cockpit/queries";
import { useTeamLinks } from "@/features/matches/queries";
import { INTENSITY_LABEL } from "@/features/matches/lib/teamLinkLabel";
import { frDateShort } from "@/features/cockpit/lib/date";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { buildTagTeamIds, resolveConstraintTeamIds, targetsTags } from "@/shared/lib/tagTeamIds";
import { formatDuration } from "@/shared/lib/duration";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { Closure } from "@/features/cockpit/api";

import type { Constraint, ConstraintRuleType, Team, TeamPeriodOverride, Venue, VenuePeriodOverride, VenueTrainingSlot } from "../api";
import { DAYS, DURATIONS, durationOptions, hhmm } from "../lib/days";
import { closuresByVenue, closurePeriodLabel } from "../lib/venueClosures";
import { computeDayMaskToggle, manualClosedWeekdays } from "../lib/venueDays";
import type { DayMask } from "../lib/venueDays";
import { mutualisedTeammateLabel } from "../lib/sharedTraining";
import { slotPlacementError } from "../lib/slotOverlap";
import {
  useCreatePeriodConstraintOverride,
  useClearVenuePeriodGrid,
  useClearVenuePeriodMode,
  useCreatePeriodSlot,
  useCreateTeamPeriodOverride,
  useDeleteReservation,
  useDeletePeriodConstraintOverride,
  useDeletePeriodSlot,
  useDeleteTeamPeriodOverride,
  usePeriodConstraintOverrides,
  useDeletionImpact,
  usePeriodSlots,
  usePriorityTiers,
  useReservations,
  useResetVenuePeriodGrid,
  useSetVenuePeriodMode,
  useSharedTrainingBlocks,
  useSharedTrainingGroups,
  useUpdatePeriodSlot,
  useVenuePeriodOverrides,
  useWizardTeamTagAssignments,
  useWizardTeamTags,
  useTeamPeriodOverrides,
  useUpdatePeriodConstraintOverride,
  useUpdateTeamPeriodOverride,
  useWizardConstraints,
  useWizardTeams,
  useWizardVenues,
} from "../queries";
import { SectionCountTitle } from "./StructureSummary";
import { WEEK } from "../lib/weekGrid";
import { CapacitySelect, GroupLabelField, SharedSlotHint } from "./slotFields";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";
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
  // P2-27 — le repère « mutualisée » DOIT figurer aussi en période, sinon il mentirait par
  // omission : les groupes de CETTE période (schedulePlanId concret derrière PeriodAnchorGate).
  const { data: sharedGroups = [] } = useSharedTrainingGroups(schedulePlanId);
  // P2-51 PR-6 — la sous-ligne « Mutualisée avec … » lit AUSSI les blocs de mutualisation (nouveau
  // modèle) de CETTE période, sinon elle mentirait par omission pendant la transition.
  const { data: sharedBlocks = [] } = useSharedTrainingBlocks(schedulePlanId);
  // P2-45 — les passerelles du club+saison (SERVIES par le module matchs) : le repère « passerelle »
  // et la modale Liens. En période elles sont en LECTURE SEULE (structure de saison).
  const { data: teamLinks = [] } = useTeamLinks();
  const [linksTeam, setLinksTeam] = useState<Team | null>(null);
  const nameOf = (id: string): string => teams.find((t) => t.id === id)?.name ?? "?";
  // P2-51 PR-6 — groupe K ET bloc fusionnés, sans doublon (helper pur, testé).
  const mutualiseLabelOf = (teamId: string): string | null => mutualisedTeammateLabel(teamId, sharedGroups, sharedBlocks, nameOf);
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


/**
 * Period-editable venues (#8, PR-B) — même forme que l'éditeur de gymnases de la SAISON
 * (`VenuesEditor`), volontairement : un SÉLECTEUR de gymnase (UNE grille à la fois) et le
 * même geste d'édition de créneau. Réinventer ce panneau en « une carte par gymnase,
 * toutes montées » avait fait ressurgir tout ce que la saison avait déjà résolu — durée
 * figée, pas d'éditeur, mur de milliers de boutons, clavier cassé (revue #8 PR-B). Le
 * gestionnaire voit sa grille — une à la fois, exactement comme en saison.
 *
 * DEUX contrôles, pas trois positions (arbitrage fondateur) :
 *  - un ÉTAT persisté, actif / désactivé, qui ne touche JAMAIS la grille ;
 *  - deux ACTIONS destructives — reprendre la grille du planning principal, ou la vider —
 *    chacune confirmée en annonçant les réservations emportées.
 * « Hériter » n'est pas un état : c'est le défaut, l'absence de ligne. Réactiver ne coûte
 * donc jamais la saisie déjà faite.
 *
 * La grille d'un gymnase DÉSACTIVÉ est gelée dans un <fieldset disabled> — inerte à la
 * souris ET au clavier, contrairement à un pointer-events-none (revue #8 PR-B).
 */
export function PeriodVenues({ calendarEntryId }: { calendarEntryId: string }) {
  const anchor = usePeriodAnchor(calendarEntryId);

  return (
    <PeriodAnchorGate
      anchor={anchor}
      loadingLabel="Chargement des créneaux de la période…"
      errorLabel="Impossible de charger les créneaux de la période."
    >
      {(schedulePlanId) => <PeriodVenuesPanel calendarEntryId={calendarEntryId} schedulePlanId={schedulePlanId} />}
    </PeriodAnchorGate>
  );
}

function PeriodVenuesPanel({ calendarEntryId, schedulePlanId }: { calendarEntryId: string; schedulePlanId: string }) {
  const { data: venues = [] } = useWizardVenues();
  const periodSlotsQuery = usePeriodSlots(schedulePlanId);
  const overridesQuery = useVenuePeriodOverrides(schedulePlanId);
  const conflictsQuery = useEntryConflicts(calendarEntryId);
  const conflicts = conflictsQuery.data;

  const [selectedId, setSelectedId] = useState("");
  const [editingSlot, setEditingSlot] = useState<VenueTrainingSlot | null>(null);
  // Durée du PROCHAIN créneau posé au clic (P4-43). L'état vit ICI et non dans
  // `PeriodVenuePanel` : ce dernier est remonté par `key={selected.id}`, si bien qu'y
  // loger la durée la remettrait à 90 min à chaque changement de gymnase. La saison la
  // conserve (état dans `VenuesEditor`) — la période fait pareil.
  const [posingDuration, setPosingDuration] = useState(90);

  // P4-1 — la grille EST ces deux requêtes : un échec coercé en `[]` afficherait
  // « 0 créneau », indistinguable d'une période délibérément vidée. Le gestionnaire
  // re-dessine sa semaine → doublons de créneaux, ou valide une période qu'il croit
  // sans entraînement. L'échec passe donc avant tout rendu de grille.
  // `readState` plutôt que les drapeaux bruts : un refetch d'arrière-plan raté
  // laisse la donnée en cache intacte — céder la place à une erreur détruirait un
  // écran qui fonctionne. Seul un échec SANS rien à montrer s'affiche.
  if (readLoading(periodSlotsQuery) || readLoading(overridesQuery) || readLoading(conflictsQuery)) {
    // Les conflits font partie du chargement : sans eux, la grille se rend avec ZÉRO
    // fermeture affichée — tout paraît permis, et le gestionnaire pose des créneaux dans un
    // gymnase fermé (le vide crédible, version fermetures).
    return <p className="text-xs text-muted-foreground">Chargement de la grille de la période…</p>;
  }
  // Les conflits portent l'interdit « ce gymnase est fermé cette période » : les
  // avaler en `[]` rendrait une grille où tout paraît permis.
  if (readFailed(conflictsQuery)) {
    return (
      <LoadErrorHint onRetry={() => void conflictsQuery.refetch()}>
        Impossible de vérifier les fermetures de gymnase sur cette période.
      </LoadErrorHint>
    );
  }
  if (readFailed(periodSlotsQuery) || readFailed(overridesQuery)) {
    return (
      <LoadErrorHint
        onRetry={() => {
          void periodSlotsQuery.refetch();
          void overridesQuery.refetch();
        }}
      >
        Impossible de charger la grille de la période.
      </LoadErrorHint>
    );
  }
  if (0 === venues.length) {
    return <EmptyHint>Aucun gymnase.</EmptyHint>;
  }

  const periodSlots = periodSlotsQuery.data ?? [];
  const overrides = overridesQuery.data ?? [];
  const selected = (selectedId ? venues.find((v) => v.id === selectedId) : null) ?? venues[0];
  const override = overrides.find((o) => o.venueId === selected.id) ?? null;
  // D3 (P2-22) — `venueIds` reste l'INDEX des gymnases concernés ; le DÉTAIL (jours fermés,
  // dates, titre) vient de `closures`, au grain JOUR. Le front ne dérive rien : `weekdays` est
  // calculé serveur.
  const closed = new Set(conflicts?.venueIds ?? []);
  // P2-37 D6 — les gymnases ENTIÈREMENT fermés sur la fenêtre, tels que le SERVEUR les
  // calcule (`fullyClosedVenueIds`). Indisponibilité TOTALE : le front la LIT, il ne
  // redérive pas « toutes les dates fermées » (règle d'or). Sur un tel gymnase, l'interrupteur
  // Désactiver/Réactiver laisse place à la RAISON — le serveur refuserait le geste (D2).
  const fullyClosed = new Set(conflicts?.fullyClosedVenueIds ?? []);
  const closuresByV = closuresByVenue(conflicts?.closures ?? []);
  const closureLabelsFor = (venueId: string): string[] => (closuresByV.get(venueId) ?? []).map(closurePeriodLabel);
  // Indispo INFORMATIVE (2026-08-18) — l'état effectif jour par jour, servi AVEC provenance
  // (`effectiveClosedWeekdays`). Le front le LIT ; il ne recompose jamais incident × masque.
  const effectiveClosedWeekdays = conflicts?.effectiveClosedWeekdays;
  const manualDaysFor = (venueId: string): number[] => manualClosedWeekdays(effectiveClosedWeekdays, venueId);
  // P2-43 volet (ii) — les gymnases DÉSACTIVÉS (mode DISABLED), tels que le SERVEUR les calcule.
  const disabledSet = new Set(conflicts?.disabledVenueIds ?? []);
  // L'état effectif que le SÉLECTEUR doit annoncer, par priorité : désactivé (mode) > indisponible
  // toute la période (fermé total) > fermé {jours} (partiel, masque OU indispo) > rien (ouvert).
  // Lu de l'état SERVI, jamais recomposé — un gymnase désactivé cessait sinon d'exister à l'écran.
  const pickerStateLabel = (venueId: string): string => {
    if (disabledSet.has(venueId)) {
      return "désactivé";
    }
    if (fullyClosed.has(venueId)) {
      return "indisponible toute la période";
    }
    const days = Object.keys(effectiveClosedWeekdays?.[venueId] ?? {}).map(Number).sort((a, b) => a - b);
    return days.length > 0 ? `fermé ${days.map((d) => DAY_LABELS_LONG[d].toLowerCase()).join(", ")}` : "";
  };
  const venueSlots = periodSlots.filter((s) => s.venueId === selected.id);
  // Le bandeau rappelle TOUT ce qui ne sert pas : indisponibilités déclarées ET jours décochés
  // à la main (décision C) — sans quoi un jour fermé d'un clic passait inaperçu hors du gymnase
  // sélectionné.
  const venuesToRemind = venues.filter((v) => closed.has(v.id) || manualDaysFor(v.id).length > 0);

  return (
    <div>
      <p className="mb-3 text-sm text-muted-foreground">
        Ces créneaux sont repris de votre planning principal à l’ouverture de la période. Les modifier ici ne change que cette période — cliquez dans la grille pour poser un créneau, un créneau pour l’ajuster.
      </p>

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <label className="text-sm font-medium" htmlFor="period-venue-picker">
          Gymnase :
        </label>
        <VenueSelect
          id="period-venue-picker"
          aria-label="Gymnase"
          className="h-9"
          wrapperClassName="w-60"
          // P2-43 volet (ii) — chaque option porte son état effectif (désactivé / indisponible
          // toute la période / fermé {jours} / rien), plus seulement la fermeture totale.
          venues={venues.map((v) => {
            const state = pickerStateLabel(v.id);
            return { id: v.id, name: "" === state ? v.name : `${v.name} — ${state}`, color: v.color };
          })}
          value={selected.id}
          onChange={(e) => {
            setSelectedId(e.target.value);
            setEditingSlot(null); // ne jamais laisser l'éditeur ouvert sur un créneau d'un autre gymnase
          }}
        />
        <VenueSwatch color={selected.color ?? "transparent"} className="size-4 border border-input" />
      </div>

      {/* Indicateur d'ensemble : le badge par gymnase ne montre que le gymnase choisi, or
          un gestionnaire doit voir d'un coup TOUT ce qui ne sert pas (revue #8 PR-B round 2 ;
          décision C 2026-08-18) — les indisponibilités déclarées ET les jours décochés à la
          main — sans quoi il croit un jour/gymnase utilisable et ne comprend pas le résultat. */}
      {venuesToRemind.length > 0 ? (
        <div role="alert" className="mb-3 space-y-1 text-sm text-destructive">
          {venuesToRemind.map((v) => {
            const parts: string[] = [];
            if (closed.has(v.id)) {
              parts.push(closureLabelsFor(v.id).join(" · ") || "Indispo cette période");
            }
            const manual = manualDaysFor(v.id);
            if (manual.length > 0) {
              parts.push(`jours décochés à la main : ${manual.map((d) => DAY_LABELS_LONG[d].toLowerCase()).join(", ")}`);
            }
            return (
              <p key={v.id}>
                <span className="font-medium">{v.name}</span> : {parts.join(" — ")}
              </p>
            );
          })}
        </div>
      ) : null}

      <PeriodVenuePanel
        key={selected.id}
        venue={selected}
        schedulePlanId={schedulePlanId}
        slots={venueSlots}
        override={override}
        closures={closuresByV.get(selected.id) ?? []}
        fullyClosed={fullyClosed.has(selected.id)}
        effectiveClosed={effectiveClosedWeekdays?.[selected.id] ?? {}}
        syncing={overridesQuery.isFetching}
        editingSlot={editingSlot}
        onEditSlot={setEditingSlot}
        posingDuration={posingDuration}
        onPosingDuration={setPosingDuration}
      />
    </div>
  );
}

function PeriodVenuePanel({
  venue,
  schedulePlanId,
  slots,
  override,
  closures,
  fullyClosed,
  effectiveClosed,
  syncing,
  editingSlot,
  onEditSlot,
  posingDuration,
  onPosingDuration,
}: {
  venue: Venue;
  schedulePlanId: string;
  slots: VenueTrainingSlot[];
  override: VenuePeriodOverride | null;
  closures: Closure[];
  /** Indispo INFORMATIVE (2026-08-18) — gymnase entièrement fermé sur la fenêtre (donnée serveur).
   *  Le geste est désormais ACCEPTÉ ; la raison reste affichée en information, jamais à sa place. */
  fullyClosed: boolean;
  /** L'état effectif jour par jour du gymnase, servi AVEC provenance : `{ jour ISO → provenance }`.
   *  Seuls les jours EFFECTIVEMENT fermés y figurent. Composé SERVEUR — jamais recomposé ici. */
  effectiveClosed: Record<string, "manual" | "default-incident">;
  syncing: boolean;
  editingSlot: VenueTrainingSlot | null;
  onEditSlot: (slot: VenueTrainingSlot | null) => void;
  posingDuration: number;
  onPosingDuration: (minutes: number) => void;
}) {
  const [pending, setPending] = useState<"reset" | "clear" | null>(null);
  const { data: reservations = [] } = useReservations(schedulePlanId);
  const createSlot = useCreatePeriodSlot(schedulePlanId);
  const deleteSlot = useDeletePeriodSlot(schedulePlanId);
  const setMode = useSetVenuePeriodMode(schedulePlanId);
  const clearMode = useClearVenuePeriodMode(schedulePlanId);
  const resetGrid = useResetVenuePeriodGrid(schedulePlanId);
  const clearGrid = useClearVenuePeriodGrid(schedulePlanId);

  const isDisabled = "DISABLED" === override?.mode;
  // `syncing` ferme la fenêtre entre le succès d'une mutation et le refetch des overrides :
  // sans lui, un double-clic « Désactiver » repartait en POST create (override encore null
  // dans le cache) → 422 (revue #8 PR-B). Le bouton reste inerte tant que l'état n'est pas
  // à jour.
  const modeBusy = setMode.isPending || clearMode.isPending || syncing;
  const gridBusy = resetGrid.isPending || clearGrid.isPending;
  const reservationCount = reservations.filter((r) => r.venueId === venue.id).length;
  // #8 round 2 finding #6 — un créneau sur un jour que la grille ne montre pas resterait
  // INVISIBLE tout en étant SERVI au solveur. On le rend visible et supprimable plutôt que
  // de le laisser agir en silence.
  //
  // ⚠ Le cas d'origine était le DIMANCHE, que la grille s'arrêtant au samedi ne pouvait pas
  // afficher. P4-37 a traité la cause : les sept jours sont désormais rendus, et ce filet
  // ne rattrape plus qu'un jour ABERRANT (donnée importée, dérive). On le garde pour ça —
  // il coûte une ligne et son absence rendrait le créneau muet.
  const offGridSlots = slots.filter((sl) => !WEEK.some((d) => d.n === sl.dayOfWeek));

  // Le masque manuel STOCKÉ (la ressource que l'écran édite). Le front l'écrit, mais ne
  // recompose jamais l'état effectif — celui-ci vient du serveur (`effectiveClosed`).
  const dayMask: DayMask = override?.dayOverrides ?? {};
  const hasIncident = closures.length > 0;
  const hasOverride = null !== override;
  const maskHasEntries = Object.keys(dayMask).length > 0;

  // Basculer la coche d'UN jour : écrire l'intention (OPEN/CLOSED) dans le masque SPARSE, et
  // supprimer la ligne dès que mode ET masque redeviennent vides (retour au défaut « hériter »).
  const toggleDay = (weekday: number, currentlyClosed: boolean) => {
    const nextMask = computeDayMaskToggle(dayMask, weekday, currentlyClosed);
    const mode = override?.mode ?? null;
    const maskEmpty = 0 === Object.keys(nextMask).length;
    if (null === mode && maskEmpty) {
      if (null !== override) {
        clearMode.mutate(override.id);
      }
      return;
    }
    setMode.mutate({ venueId: venue.id, mode, dayOverrides: maskEmpty ? null : nextMask, existingId: override?.id });
  };

  // Geste gymnase entier — rouvrir les 7 jours malgré l'indisponibilité (masque OPEN×7), mode
  // courant préservé (le serveur REMPLACE mode + masque au PUT, on envoie donc l'état complet).
  const reactivateDespite = () => {
    const allOpen: DayMask = { 1: "OPEN", 2: "OPEN", 3: "OPEN", 4: "OPEN", 5: "OPEN", 6: "OPEN", 7: "OPEN" };
    setMode.mutate({ venueId: venue.id, mode: override?.mode ?? null, dayOverrides: allOpen, existingId: override?.id });
  };

  // « Revenir au défaut » : supprimer la ligne (mode ET masque effacés) — sans ligne = hériter.
  const backToDefault = () => {
    if (null !== override) {
      clearMode.mutate(override.id);
    }
  };

  const toggleActive = () => {
    if (isDisabled && null !== override) {
      // Réactiver : garder le masque DORMANT (coches conservées). Masque vide → retirer la ligne
      // (le backend ne touche pas la grille depuis DISABLED) ; masque présent → PUT mode nul +
      // masque, jamais un DELETE qui effacerait les coches conservées.
      if (maskHasEntries) {
        setMode.mutate({ venueId: venue.id, mode: null, dayOverrides: dayMask, existingId: override.id });
      } else {
        clearMode.mutate(override.id);
      }
      return;
    }
    // Désactiver : poser le mode en PRÉSERVANT le masque (le PUT remplace tout côté serveur).
    setMode.mutate({ venueId: venue.id, mode: "DISABLED", dayOverrides: maskHasEntries ? dayMask : null, existingId: override?.id });
  };

  return (
    <section aria-label={`Gymnase ${venue.name}`} className="rounded-lg border border-border bg-card p-3">
      <header className="mb-2 flex flex-wrap items-center gap-2">
        <span className={cn("font-medium", isDisabled && "text-muted-foreground line-through")}>{venue.name}</span>
        {isDisabled ? <span className="rounded bg-muted px-1.5 py-0.5 text-xs font-semibold text-muted-foreground">Désactivé cette période</span> : null}
        {/* Indispo INFORMATIVE (2026-08-18) — la raison reste affichée en INFORMATION (badge par
            fermeture, grain jour), qu'elle soit partielle ou totale. Elle ne remplace plus
            l'interrupteur : le serveur accepte désormais le geste. */}
        {closures.map((c) => (
          <span key={c.constraintId} className="rounded bg-destructive/10 px-1.5 py-0.5 text-xs font-semibold text-destructive">
            {closurePeriodLabel(c)}
          </span>
        ))}
        <span className="text-xs text-muted-foreground">
          {slots.length} créneau{slots.length > 1 ? "x" : ""}
        </span>
        <Button type="button" size="sm" variant="outline" className="ml-auto" disabled={modeBusy} onClick={toggleActive}>
          {isDisabled ? "Réactiver" : "Désactiver"}
        </Button>
      </header>

      {fullyClosed && !isDisabled ? (
        <p className="mb-2 text-xs text-muted-foreground">
          Ce gymnase est indisponible sur toute la fenêtre (indisponibilité déclarée) — aucune séance n'y sera placée. Vous pouvez rouvrir des jours ci-dessous, ou le préparer pour la suite.
        </p>
      ) : isDisabled ? (
        <p className="mb-2 text-xs text-muted-foreground">Ce gymnase ne sera pas utilisé pour cette période. Sa grille est conservée telle quelle — réactivez-le pour la modifier.</p>
      ) : 0 === slots.length ? (
        <p role="alert" className="mb-2 text-sm text-destructive">Aucun créneau : aucune équipe ne pourra s’entraîner dans ce gymnase tant que vous n’en aurez pas posé.</p>
      ) : null}

      {/* Rangée de coches JOUR (indispo INFORMATIVE, 2026-08-18) : la coche du plan fait foi, jour
          par jour. L'état est LU de `effectiveClosed` (composé SERVEUR, avec provenance) ; le clic
          écrit OPEN/CLOSED dans le masque manuel, ou RETIRE l'entrée au retour au défaut. Sous
          DISABLED la rangée est GELÉE (fieldset) et montre le masque DORMANT — coches conservées. */}
      <fieldset disabled={isDisabled} className={cn("mb-3 min-w-0 border-0 p-0", isDisabled && "opacity-50")}>
        <p className="mb-1 text-xs font-medium text-muted-foreground">Jours ouverts cette période</p>
        <div className="flex flex-wrap gap-x-3 gap-y-1">
          {WEEK.map((d) => {
            // Sous DISABLED, l'état effectif servi exclut le gymnase : on rend alors le masque
            // dormant. Sinon, `effectiveClosed` (serveur) fait foi.
            const closedDay = isDisabled ? "CLOSED" === dayMask[d.n] : undefined !== effectiveClosed[d.n];
            const provenance = effectiveClosed[d.n];
            const reopened = "OPEN" === dayMask[d.n];
            const firstClosure = closures[0];
            const datesSuffix = firstClosure ? ` (du ${frDateShort(firstClosure.startDate)} au ${frDateShort(firstClosure.endDate)})` : "";
            const title = closedDay
              ? "manual" === provenance
                ? "fermé — décoché manuellement"
                : `fermé — indisponibilité déclarée${datesSuffix}`
              : reopened
                ? "ouvert — réactivé malgré l'indisponibilité"
                : "ouvert";
            return (
              <label key={d.n} className="flex items-center gap-1 text-xs text-muted-foreground" title={title}>
                <input
                  type="checkbox"
                  checked={!closedDay}
                  disabled={modeBusy}
                  onChange={() => toggleDay(d.n, closedDay)}
                  aria-label={`${DAY_LABELS_LONG[d.n]} — ${venue.name}`}
                  title={title}
                />
                <span>{d.label}</span>
              </label>
            );
          })}
        </div>
        {isDisabled ? (
          <p className="mt-1 text-xs italic text-muted-foreground">Grille et coches conservées — réactivez le gymnase pour les retrouver.</p>
        ) : (
          <p className="mt-1 text-xs text-muted-foreground">Décocher un jour le ferme sur toutes les semaines de la période ; le recocher le rouvre partout.</p>
        )}
        {!isDisabled && (hasIncident || hasOverride) ? (
          <div className="mt-2 flex flex-wrap items-center gap-2">
            {hasIncident ? (
              <Button type="button" size="sm" variant="outline" disabled={modeBusy} onClick={reactivateDespite}>
                Réactiver malgré l&apos;indisponibilité
              </Button>
            ) : null}
            {hasOverride ? (
              <Button type="button" size="sm" variant="ghost" disabled={modeBusy} onClick={backToDefault}>
                Revenir au défaut
              </Button>
            ) : null}
          </div>
        ) : null}
      </fieldset>

      {/* fieldset disabled : gèle la grille à la souris ET au clavier quand le gymnase est
          désactivé — ses boutons sortent de l'ordre de tabulation et ne s'activent pas.
          ⚠ La barre « À poser » est DEDANS, pas au-dessus : le geste qu'elle règle et la
          grille où il s'exerce ne font qu'un. Laissée dehors, son libellé et son indice
          « cliquez la grille » restaient en pleine opacité au-dessus d'une grille gelée —
          une instruction de cliquer là où le clic est impossible (revue #351). */}
      <fieldset disabled={isDisabled} className={cn("min-w-0 border-0 p-0", isDisabled && "opacity-50")}>
        {/* Barre « À poser » — même forme et même rôle qu'en saison (`VenuesStep`) : la durée
            du PROCHAIN créneau posé au clic. Elle manquait ici, et la pose était figée à
            90 min : un créneau de 2h se posait en deux gestes (poser, puis rouvrir
            l'éditeur), et une pose tardive était refusée pour une durée que le gestionnaire
            n'avait pas choisie (P4-43). La capacité, elle, reste réglée par créneau dans
            l'éditeur — un créneau neuf vaut toujours 1, comme en saison. */}
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <span className="text-xs text-muted-foreground">À poser :</span>
          <Select aria-label="Durée à poser" className="h-9 w-24" value={posingDuration} onChange={(e) => onPosingDuration(Number(e.target.value))}>
            {DURATIONS.map((d) => (
              <option key={d} value={d}>
                {formatDuration(d)}
              </option>
            ))}
          </Select>
          <span className="text-xs text-muted-foreground">— cliquez la grille pour ajouter un créneau</span>
        </div>

        <VenueAvailabilityGrid
          venue={venue}
          slots={slots}
          closures={closures}
          selectedSlotId={editingSlot?.id ?? null}
          onAdd={(dayOfWeek, startTime) => {
            // Un créneau peut finir APRÈS la dernière ligne de la grille — un créneau du
            // soir (22:00–23:30) est légitime, et l'y interdire rendait la période plus
            // stricte que la saison (revue #8 PR-B round 2). La seule borne est MINUIT
            // (P4-37), et son message par défaut nomme désormais la durée : le gestionnaire
            // a la barre « À poser » sous les yeux pour la corriger.
            const invalid = slotPlacementError(slots, dayOfWeek, startTime, posingDuration);
            if (null !== invalid) {
              toast.error(invalid);
              return;
            }
            createSlot.mutate({ venueId: venue.id, dayOfWeek, startTime, durationMinutes: posingDuration, capacity: 1 });
          }}
          onSelect={(slot) => onEditSlot(slot)}
        />
      </fieldset>

      {offGridSlots.length > 0 && !isDisabled ? (
        <div className="mt-2 rounded-md border border-destructive/40 bg-destructive/5 p-2">
          <p role="alert" className="mb-1 text-xs font-medium text-destructive">
            Créneau(x) sur un jour non affichable — servi au système mais invisible sur la grille. Supprimez-le pour ne pas planifier ce jour-là.
          </p>
          <ul className="flex flex-col gap-1">
            {offGridSlots.map((sl) => (
              <li key={sl.id} className="flex items-center justify-between gap-2 text-xs">
                <span>
                  {DAY_LABELS_LONG[sl.dayOfWeek] ?? `jour ${sl.dayOfWeek}`} {hhmm(sl.startTime)} ({sl.durationMinutes} min)
                </span>
                <Button type="button" size="sm" variant="destructive" disabled={deleteSlot.isPending} onClick={() => deleteSlot.mutate(sl.id)}>
                  <Trash2 className="size-4" />
                  Supprimer
                </Button>
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      <div className="mt-2 flex flex-wrap items-center gap-2">
        <Button type="button" size="sm" variant="outline" disabled={isDisabled || gridBusy} onClick={() => setPending("reset")}>
          Reprendre la grille du planning principal
        </Button>
        <Button type="button" size="sm" variant="outline" disabled={isDisabled || gridBusy || 0 === slots.length} onClick={() => setPending("clear")}>
          Vider la grille
        </Button>
      </div>

      {null !== editingSlot && editingSlot.venueId === venue.id && !isDisabled ? (
        <PeriodSlotEditor
          key={editingSlot.id}
          slot={editingSlot}
          schedulePlanId={schedulePlanId}
          canSplit={venue.canSplit}
          otherSlots={slots.filter((s) => s.id !== editingSlot.id)}
          reservationsHere={reservations.filter((r) => r.venueId === venue.id && r.dayOfWeek === editingSlot.dayOfWeek && hhmm(r.startTime) === hhmm(editingSlot.startTime))}
          // P2-43 volet (i) — l'état effectif du gymnase (jour → provenance) + ses fermetures :
          // l'éditeur DIT qu'un créneau tombe un jour fermé (poser reste permis).
          effectiveClosed={effectiveClosed}
          closures={closures}
          onClose={() => onEditSlot(null)}
        />
      ) : null}

      <ConfirmDialog
        open={null !== pending}
        destructive
        title={"reset" === pending ? `Reprendre la grille de ${venue.name} ?` : `Vider la grille de ${venue.name} ?`}
        description={
          "reset" === pending
            ? `Les créneaux de ${venue.name} pour cette période seront remplacés par ceux de votre planning principal.${reservationCount > 0 ? ` ${reservationCount} réservation${reservationCount > 1 ? "s" : ""} sur ce gymnase ser${reservationCount > 1 ? "ont" : "a"} supprimée${reservationCount > 1 ? "s" : ""}.` : ""}`
            : `Tous les créneaux de ${venue.name} pour cette période seront supprimés, à ressaisir.${reservationCount > 0 ? ` ${reservationCount} réservation${reservationCount > 1 ? "s" : ""} sur ce gymnase part${reservationCount > 1 ? "iront" : "ira"} avec eux.` : ""}`
        }
        confirmLabel={"reset" === pending ? "Reprendre" : "Vider"}
        onConfirm={() => {
          // Actions atomiques et idempotentes des deux côtés : jamais un PUT de mode, dont
          // l'idempotence rendrait « vider » un no-op quand le mode ne change pas.
          if ("reset" === pending) {
            resetGrid.mutate(venue.id, { onSuccess: () => toast.success("Grille reprise du planning principal") });
          } else {
            clearGrid.mutate(venue.id, { onSuccess: () => toast.success("Grille vidée") });
          }
          onEditSlot(null); // l'éditeur pouvait viser un créneau qu'on vient de détruire
          setPending(null);
        }}
        onCancel={() => setPending(null)}
      />
    </section>
  );
}

/**
 * Édite un créneau de la période — jour, heure, DURÉE, capacité (gymnase divisible) — ou
 * le supprime. Miroir de `SlotEditor` (saison), câblé sur les hooks de la COUCHE période :
 * mêmes gestes, écriture sur le plan et jamais sur le socle.
 *
 * DÉPLACER un créneau (changer jour/heure) laisse les réservations épinglées à l'ANCIENNE
 * position orphelines — et un épinglage orphelin BLOQUE la génération (OrphanPinGuard). On
 * ne le fait donc jamais en silence : si le créneau porte des réservations et que sa
 * position change, on confirme en annonçant qu'elles seront retirées, puis on déplace ET
 * on les supprime (pas d'orphelin laissé derrière — revue #8 PR-B round 2).
 */
function PeriodSlotEditor({
  slot,
  schedulePlanId,
  canSplit,
  otherSlots,
  reservationsHere,
  effectiveClosed,
  closures,
  onClose,
}: {
  slot: VenueTrainingSlot;
  schedulePlanId: string;
  canSplit: boolean;
  otherSlots: VenueTrainingSlot[];
  reservationsHere: { id: string }[];
  /** P2-43 volet (i) — l'état effectif jour par jour du gymnase (jour ISO → provenance), SERVI. */
  effectiveClosed: Record<string, "manual" | "default-incident">;
  /** Les fermetures déclarées du gymnase (pour dater la cause « indisponibilité déclarée »). */
  closures: Closure[];
  onClose: () => void;
}) {
  const update = useUpdatePeriodSlot(schedulePlanId);
  const del = useDeletePeriodSlot(schedulePlanId);
  const delReservation = useDeleteReservation();
  const [day, setDay] = useState(slot.dayOfWeek);
  const [time, setTime] = useState(hhmm(slot.startTime));
  const [duration, setDuration] = useState(slot.durationMinutes);
  const [capacity, setCapacity] = useState(slot.capacity);
  const [groupLabel, setGroupLabel] = useState(slot.groupLabel ?? "");
  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [confirmMove, setConfirmMove] = useState(false);

  const moved = day !== slot.dayOfWeek || time !== hhmm(slot.startTime);
  const slotImpact = useDeletionImpact("slot", confirmDelete ? slot.id : null);
  // Toujours dérivé du cache, et c'est légitime : il sert à la confirmation de DÉPLACEMENT
  // (un autre geste, qui ne détruit pas en cascade), pas à l'annonce de suppression.
  const reservationCount = reservationsHere.length;

  // P2-43 volet (i) — le JOUR édité est-il fermé (état effectif SERVI) ? On ne bloque pas (décision
  // fondateur : poser reste permis) — on le DIT. La provenance choisit la cause (présentation) ; la
  // décision « ce jour est fermé » vient du serveur (présence de la clé).
  const closedProvenance = effectiveClosed[day];
  const firstClosure = closures[0];
  const closedCause =
    "manual" === closedProvenance
      ? "décoché manuellement"
      : `indisponibilité déclarée${firstClosure ? ` du ${frDateShort(firstClosure.startDate)} au ${frDateShort(firstClosure.endDate)}` : ""}`;

  const doSave = () => {
    const effCapacity = canSplit ? capacity : 1;
    // La période n'est pas plus stricte que la saison : un créneau du soir (22:00–23:30)
    // reste légitime, la seule borne est MINUIT — posée en amont par `slotPlacementError`
    // dans `save`, pas ici. Ne referme qu'au succès : une écriture rejetée garde l'éditeur
    // ouvert plutôt que de disparaître en donnant l'illusion d'être enregistrée.
    // Libellé de groupe : envoyé seulement sur un créneau partageable (≥ 2), sinon null (P2-17).
    update.mutate(
      { id: slot.id, body: { venueId: slot.venueId, dayOfWeek: day, startTime: time, durationMinutes: duration, capacity: effCapacity, groupLabel: effCapacity >= 2 ? groupLabel : null } },
      {
        onSuccess: () => {
          // Le déplacement a orpheliné les réservations de l'ancienne position : on les
          // retire pour ne pas bloquer la génération (elles ont été annoncées).
          for (const r of reservationsHere) {
            delReservation.mutate(r.id);
          }
          onClose();
        },
      },
    );
  };

  const save = () => {
    const invalid = slotPlacementError(otherSlots, day, time, duration);
    if (null !== invalid) {
      setError(invalid);
      return;
    }
    // Déplacer un créneau réservé retire ses réservations : on le confirme d'abord.
    if (moved && reservationCount > 0) {
      setConfirmMove(true);
      return;
    }
    doSave();
  };

  return (
    <Modal
      label="Modifier le créneau"
      title="Modifier le créneau"
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>
            <Trash2 className="size-4" />
            Supprimer
          </Button>
          <Button onClick={save} disabled={update.isPending}>
            Enregistrer
          </Button>
        </>
      }
    >
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="text-xs text-muted-foreground">
          Jour
          <Select aria-label="Jour" className="mt-0.5 h-9 w-28" value={day} onChange={(e) => (setDay(Number(e.target.value)), setError(null))}>
            {/* Idem `VenuesStep` : ce select filtrait le dimanche pour son compte, alors
                que la grille le rend désormais (P4-37). Un créneau du dimanche s'y ouvrait
                sur un champ vide. */}
            {WEEK.map((d) => (
              <option key={d.n} value={d.n}>
                {d.label}
              </option>
            ))}
          </Select>
        </label>
        <label className="text-xs text-muted-foreground">
          Début
          <Input aria-label="Début" type="time" className="mt-0.5 h-9 w-28" value={time} onChange={(e) => (setTime(e.target.value), setError(null))} />
        </label>
        <label className="text-xs text-muted-foreground">
          Durée
          <Select aria-label="Durée" className="mt-0.5 h-9 w-28" value={duration} onChange={(e) => (setDuration(Number(e.target.value)), setError(null))}>
            {durationOptions(duration, slot.durationMinutes).map((d) => (
              <option key={d} value={d}>
                {formatDuration(d)}
              </option>
            ))}
          </Select>
        </label>
        {canSplit ? (
          <div className="text-xs text-muted-foreground">
            {/* CapacitySelect porte son propre aria-label="Capacité". */}
            <span>Capacité</span>
            <CapacitySelect value={capacity} onChange={setCapacity} canSplit={canSplit} className="mt-0.5 block h-9 w-52" />
          </div>
        ) : null}
      </div>

      {/* P2-43 volet (i) — le jour édité est fermé : on le DIT, sans rien bloquer (poser reste permis). */}
      {undefined !== closedProvenance ? (
        <p role="note" className="mt-3 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
          Créneau inactif — le {DAY_LABELS_LONG[day]?.toLowerCase() ?? `jour ${day}`} est fermé ({closedCause}). Le poser reste possible ; il ne servira pas tant que ce jour reste fermé.
        </p>
      ) : null}

      {canSplit ? <SharedSlotHint capacity={capacity} /> : null}
      {canSplit ? <GroupLabelField capacity={capacity} value={groupLabel} onChange={setGroupLabel} /> : null}

      {null !== error ? (
        <p role="alert" className="mt-3 text-sm text-destructive">
          {error}
        </p>
      ) : null}

      {/* affectsPeriodPlans NON posé : supprimer un créneau de PÉRIODE ne touche QUE cette
          période, pas « les plannings de période » — l'exagérer trompe (invariant n°4,
          revue #8 PR-B round 2). */}
      <DeleteConfirm
        open={confirmDelete}
        entityName={`créneau ${DAYS.find((d) => d.n === slot.dayOfWeek)?.label ?? ""} ${hhmm(slot.startTime)}`.trim()}
        // P4-108 : compté par le serveur, borné à la COUCHE de ce créneau — un créneau de
        // période n'emporte jamais l'épinglage du planning principal.
        impact={slotImpact.data ?? undefined}
        impactLoading={slotImpact.isPending && confirmDelete}
        impactFailed={slotImpact.isError}
        onConfirm={() => {
          del.mutate(slot.id, { onSuccess: onClose });
          setConfirmDelete(false);
        }}
        onCancel={() => setConfirmDelete(false)}
      />

      <ConfirmDialog
        open={confirmMove}
        destructive
        title="Déplacer ce créneau ?"
        description={`Ce créneau porte ${reservationCount} réservation${reservationCount > 1 ? "s" : ""} d'équipe. Le déplacer ${reservationCount > 1 ? "les retirera" : "la retirera"} — il faudra ${reservationCount > 1 ? "les reposer" : "la reposer"} sur le nouveau créneau.`}
        confirmLabel="Déplacer"
        onConfirm={() => {
          setConfirmMove(false);
          doSave();
        }}
        onCancel={() => setConfirmMove(false)}
      />
    </Modal>
  );
}

const DAY_LABELS_LONG: Record<number, string> = { 1: "Lundi", 2: "Mardi", 3: "Mercredi", 4: "Jeudi", 5: "Vendredi", 6: "Samedi", 7: "Dimanche" };

/** Libellés gestionnaire (jamais l'enum brut à l'écran). */
const RULE_LABEL: Record<ConstraintRuleType, string> = {
  HARD: "Obligatoire",
  PREFERRED: "Préféré",
  BONUS: "Bonus",
  LOCK: "Verrouillé",
};

/** L'onglet-famille où une contrainte héritée se range. (FACILITY_CAPACITY, qui
 * n'avait pas d'onglet propre et se rangeait sous Gymnase, a été RETIRÉE le
 * 2026-08-08 : aucun chemin UI ne la créait — la capacité se règle par créneau.) */
const familyTabOf = (family: Constraint["family"]): Constraint["family"] => family;

/**
 * Period-editable constraints: the club's PERMANENT constraints are inherited into the
 * overlay (read-only), each toggle-able for the window via a sparse ConstraintPeriodOverride
 * that DEVIATES from the smart default. No row = the default applies; the base plan and the
 * Constraint's own isActive are never touched.
 *
 * - Fermeture (closure): default = keep every constraint (B3+F2 — unchanged).
 * - Reprise (holiday): default FOLLOWS the team selection — CLUB/COACH kept, a TEAM
 *   constraint kept only if its team reprend (not deactivated), FACILITY dropped.
 *
 * Rendered only for overlay-generating periods (closure | holiday), on a CONFIRMED entry
 * (never during its load window, so the wrong default can't flash).
 *
 * `family` (fondateur 2026-07-24) : rendue DANS l'onglet-famille de ConstraintsStep, la
 * section ne montre que les héritées de CET onglet — plus d'écran à part au-dessus. Sans
 * `family` (tests/usages historiques), liste complète inchangée. Un onglet sans héritée
 * ne rend RIEN (pas de section vide répétée par onglet) — mais uniquement une fois les
 * requêtes RÉSOLUES et SANS erreur : masquer pendant le chargement ferait clignoter le
 * cadre, et masquer sur erreur laisserait croire qu'aucune contrainte n'est héritée
 * (P4-1 : le panneau reste visible sur erreur, cf. plus bas).
 *
 * ⚠️ Le composant DOIT rester monté tant que le gestionnaire travaille la période :
 * `inflight` (et les onSettled par mutation) sérialisent les écritures d'override, et un
 * démontage en cours d'écriture les perd — d'où le rendu conditionnel de l'appelant, qui
 * doit dépendre de l'onglet actif SANS démonter (voir ConstraintsStep).
 */
export function PeriodConstraints({ calendarEntryId, family }: { calendarEntryId: string; family?: Constraint["family"] }) {
  const anchor = usePeriodAnchor(calendarEntryId);

  return (
    <PeriodAnchorGate
      anchor={anchor}
      loadingLabel="Chargement des contraintes de la période…"
      errorLabel="Impossible de charger la période : les bascules ne seraient pas enregistrées."
    >
      {(schedulePlanId) => <PeriodConstraintsPanel calendarEntryId={calendarEntryId} family={family} schedulePlanId={schedulePlanId} />}
    </PeriodAnchorGate>
  );
}

function PeriodConstraintsPanel({
  calendarEntryId,
  family,
  schedulePlanId,
}: {
  calendarEntryId: string;
  family?: Constraint["family"];
  schedulePlanId: string;
}) {
  const { data: entry } = useCalendarEntry(calendarEntryId);
  // Inv. 5 (lot C2) : les bascules de contraintes pendent au PLAN, pas au déclencheur.
  // L'ancre est CERTAINE ici — le panneau ne s'affiche que derrière `PeriodAnchorGate`
  // (P4-20 : avant, les cases restaient cliquables et chaque bascule bailait en silence).
  const isClosure = "closure" === entry?.periodType;
  const isReprise = "holiday" === entry?.periodType;
  const isOverlay = isClosure || isReprise;
  const { data: constraints = [], isLoading, isError: constraintsError } = useWizardConstraints(); // permanent (base) constraints
  const { data: teams = [] } = useWizardTeams();
  const { data: tags = [], isLoading: tagsLoading, isError: tagsError } = useWizardTeamTags();
  const { data: tagAssignments = [], isLoading: tagAssignmentsLoading, isError: tagAssignmentsError } = useWizardTeamTagAssignments();
  // Off an overlay period, null disables both queries (no wasted fetch).
  const { data: overrides = [], isLoading: overridesLoading, isError: overridesError } = usePeriodConstraintOverrides(isOverlay ? schedulePlanId : null);
  // Needed for BOTH period types: a TEAM constraint of a deactivated team is non-applicable
  // and dropped from the payload server-side — the checklist must mirror that. On a fetch
  // error we can't tell which teams are paused, so a TEAM constraint's applicability is
  // UNKNOWN → its toggle is disabled (non-TEAM scopes are unaffected and stay usable).
  const { data: teamOverrides = [], isLoading: teamOverridesLoading, isError: teamOverridesError } = useTeamPeriodOverrides(isOverlay ? schedulePlanId : null);
  const create = useCreatePeriodConstraintOverride(schedulePlanId);
  const update = useUpdatePeriodConstraintOverride(schedulePlanId);
  const del = useDeletePeriodConstraintOverride(schedulePlanId);
  // Optimistic intended-active state per constraint while its write is in flight (the
  // sparse row only lands after the refetch). Also serializes toggles: each of create/update/
  // del is a SINGLE shared observer, so firing the same hook for a second constraint before
  // the first settles would rebind it and drop the first's onSettled — one write at a time
  // keeps every onSettled reliable.
  const [inflight, setInflight] = useState<Map<string, boolean>>(new Map());
  const deactivatedTeamIds = useMemo(() => new Set(teamOverrides.filter((o) => !o.isActive).map((o) => o.teamId)), [teamOverrides]);
  const activeTeamIds = useMemo(() => new Set(teams.filter((t) => !deactivatedTeamIds.has(t.id)).map((t) => t.id)), [teams, deactivatedTeamIds]);
  // Résolution tag→équipes : FOYER PARTAGÉ avec le panneau planning (P4-88). Le filtre
  // « équipes actives cette période » ci-dessous est un raffinement d'overlay posé
  // PAR-DESSUS, pas une seconde résolution.
  const tagTeamIdsByName = useMemo(() => buildTagTeamIds(tags, tagAssignments), [tags, tagAssignments]);
  const activeTagTeamIdsByName = useMemo(() => {
    const byTag = new Map<string, Set<string>>();
    for (const [tagName, teamIds] of tagTeamIdsByName) {
      byTag.set(tagName, new Set([...teamIds].filter((teamId) => activeTeamIds.has(teamId))));
    }
    return byTag;
  }, [activeTeamIds, tagTeamIdsByName]);
  const activeTeamIdList = useMemo(() => [...activeTeamIds], [activeTeamIds]);
  const needsTagResolution = constraints.some((c) => targetsTags(c.config));
  const tagResolutionReady = !needsTagResolution || (!tagsLoading && !tagAssignmentsLoading && !tagsError && !tagAssignmentsError);
  // ⚠️ MIROIR DÉCLARÉ (régime 2, P4-88). Deux branchements sur les valeurs d'un enum de
  // contrainte, reflétant le serveur :
  //  - `defaultKept` (scope) reflète `ScheduleConstraintBuilder::inheritedPermanents` (prédicat
  //    reprise) — FACILITY tombe, TEAM garde si l'équipe reprend, CLUB/COACH gardés ;
  //  - `hidden` (CLUB ciblant un/des tag(s), sans AUCUNE équipe active résolue) reflète
  //    l'expansion du payload — P2-29 : « (∩ targetTags) − (∪ excludeTags) » sur les équipes
  //    actives, via le foyer partagé `resolveConstraintTeamIds`.
  // La résolution tag→équipes (`buildTagTeamIds` / `resolveConstraintTeamIds`, foyer partagé) est
  // pinnée mécaniquement par `TagTeamIdsMirrorParityTest`. Ce module figure au registre
  // `FrontRederivationRegistryTest`.
  const defaultKept = (c: Constraint): boolean => {
    if (isClosure) {
      return true; // fermeture: everything kept by default (B3+F2 unchanged)
    }
    switch (c.scope) {
      case "FACILITY":
        return false;
      case "TEAM":
        return !deactivatedTeamIds.has(c.scopeTargetId ?? "");
      default:
        return true; // CLUB, COACH
    }
  };
  // A TEAM constraint whose team is paused can't ship (server-side buildForOverlay drops it) —
  // show it struck & disabled, never toggle-able, so the checklist matches the payload.
  const notApplicable = (c: Constraint): boolean => "TEAM" === c.scope && deactivatedTeamIds.has(c.scopeTargetId ?? "");
  const overrideOf = new Map(overrides.map((o) => [o.constraintId, o]));
  // Les équipes ACTIVES que la contrainte vise réellement : « (∩ targetTags) − (∪ excludeTags) »
  // posé sur la carte des tags RESTREINTE aux équipes actives (le raffinement d'overlay, P2-29).
  const activeResolvedTeamIds = (c: Constraint): string[] => resolveConstraintTeamIds(c.config, activeTagTeamIdsByName, activeTeamIdList);
  const hidden = (c: Constraint): boolean => tagResolutionReady && "CLUB" === c.scope && targetsTags(c.config) && 0 === activeResolvedTeamIds(c).length;
  const shown = (c: Constraint): boolean => !hidden(c) && (undefined === family || familyTabOf(c.family) === family);
  const activeOf = (c: Constraint): boolean => (notApplicable(c) ? false : inflight.has(c.id) ? (inflight.get(c.id) as boolean) : (overrideOf.get(c.id)?.isActive ?? defaultKept(c)));
  const mutating = inflight.size > 0;
  // Toggle = upsert-or-delete-to-default (mirrors the team override): back to the default
  // drops the row (stays sparse); a deviation upserts (create, or PUT if a row exists).
  // A TEAM constraint whose applicability can't be determined (team overrides failed to load)
  // is locked — non-TEAM scopes don't depend on the team selection and stay usable.
  const teamStateUnknown = (c: Constraint): boolean => teamOverridesError && "TEAM" === c.scope;
  const toggle = (c: Constraint, desired: boolean) => {
    // overridesError: without the override list we can't tell create-vs-update, so a toggle
    // would POST an existing row → 422. Render read-only until it reloads (P4-1 query-error UX).
    if (mutating || notApplicable(c) || overridesError || teamStateUnknown(c)) {
      // a write is settling, the constraint can't ship / is unknown, or overrides are
      // unavailable. L'ancre, elle, n'est plus à tester : ce panneau ne s'affiche que
      // derrière `PeriodAnchorGate`, donc `schedulePlanId` est un `string` certain.
      return;
    }
    const settle = () =>
      setInflight((m) => {
        const next = new Map(m);
        next.delete(c.id);
        return next;
      });
    setInflight((m) => new Map(m).set(c.id, desired));
    const existing = overrideOf.get(c.id);
    if (desired === defaultKept(c)) {
      if (undefined === existing) {
        settle();
        return;
      }
      del.mutate(existing.id, { onSettled: settle });
      return;
    }
    if (undefined !== existing) {
      update.mutate({ id: existing.id, body: { schedulePlanId, constraintId: c.id, isActive: desired } }, { onSettled: settle });
      return;
    }
    create.mutate({ schedulePlanId, constraintId: c.id, isActive: desired }, { onSettled: settle });
  };

  if (!isOverlay) {
    return null;
  }
  const visible = constraints.filter(shown);
  // DEUX niveaux de chargement, à ne pas confondre (revue #284 round 2) :
  // - PRÉSENCE de la section : ce qui décide QUELLES contraintes s'affichent — la requête
  //   des contraintes de base, plus la résolution des tags dont `hidden` dépend. Les
  //   overrides n'en font PAS partie : ils ne pilotent que l'ÉTAT des cases. Les y inclure
  //   faisait disparaître puis réapparaître la section quand le plan se résolvait (les
  //   requêtes d'override, désactivées tant que planId est null, rapportent isLoading=false
  //   avant de basculer à true) — un saut de mise en page pire que le flash corrigé.
  // - CORPS : attend en plus les overrides, sinon les cases s'afficheraient au mauvais état.
  const presenceSettled = !isLoading && tagResolutionReady;
  const bodyLoading = !presenceSettled || overridesLoading || teamOverridesLoading;
  // Dans un onglet, on ne rend RIEN tant qu'il n'y a rien à montrer (l'EmptyHint global n'a
  // de sens que pour la liste complète) : rien pendant que la présence se décide — sinon le
  // cadre titré apparaît puis disparaît — et rien si l'onglet est vide une fois décidée.
  // Une fois la section rendue, elle ne peut plus disparaître : `visible` ne dépend que de
  // requêtes déjà résolues. SUR ERREUR des contraintes, on rend malgré tout (P4-1) : une
  // liste vide par échec n'est pas « rien à hériter ».
  if (undefined !== family && !constraintsError && (!presenceSettled || 0 === visible.length)) {
    return null;
  }

  return (
    <div className="mb-4 space-y-2 rounded-lg border border-border bg-card p-3">
      <p className="text-sm font-medium">Contraintes du planning principal</p>
      <p className="text-xs text-muted-foreground">Cochez celles à garder pendant cette période — le planning principal n'est pas modifié.</p>
      {/* Le corps attend les requêtes qui pilotent l'ÉTAT des cases (overrides de la période —
          sinon un toggle 422 sur une ligne existante — et overrides d'équipes, qui donnent le
          défaut reprise et le barré non-applicable). Sur ERREUR des contraintes on le dit
          explicitement : afficher « Aucune contrainte permanente » serait le mensonge exact
          que le panneau doit éviter — le gestionnaire validerait la période en croyant que
          rien n'est hérité (revue #284 round 2). */}
      {constraintsError ? (
        <p className="text-xs text-destructive">
          Impossible de charger les contraintes du planning principal. Elles restent appliquées selon leur réglage actuel — rechargez la page pour les ajuster.
        </p>
      ) : bodyLoading ? null : 0 === visible.length ? (
        <EmptyHint>Aucune contrainte permanente.</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-1">
          {visible.map((c) => {
            const active = activeOf(c);
            const naf = notApplicable(c);
            const tagUnknown = "CLUB" === c.scope && targetsTags(c.config) && !tagResolutionReady;
            return (
              <li key={c.id} className="flex items-center justify-between gap-3 border-b border-border/60 py-1.5 text-sm last:border-0">
                <label className="flex items-center gap-2">
                  <input type="checkbox" checked={active} disabled={mutating || naf || overridesError || teamStateUnknown(c) || tagUnknown} onChange={(e) => toggle(c, e.target.checked)} aria-label={`${c.name} appliquée cette période`} />
                  <span className={cn(!active && "text-muted-foreground line-through")}>{c.name}</span>
                  {naf ? <span className="text-xs text-muted-foreground">(équipe en pause)</span> : null}
                </label>
                <span className="shrink-0 text-xs text-muted-foreground">{RULE_LABEL[c.ruleType]}</span>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
