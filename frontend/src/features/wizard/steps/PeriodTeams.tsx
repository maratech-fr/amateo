import { Link2, X } from "lucide-react";

import { Spinner } from "@/shared/components/ui/spinner";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { useState } from "react";

import { useCalendarEntry, usePeriodAnchor } from "@/features/cockpit/queries";
import { useTeamLinks } from "@/features/matches/queries";
import { INTENSITY_LABEL } from "@/features/matches/lib/teamLinkLabel";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { CtcMark } from "@/shared/components/ui/ctc-mark";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { ReadOnlyFieldset } from "@/shared/components/ui/read-only-fieldset";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { StatusPill } from "@/shared/components/ui/badge";
import { cn } from "@/shared/lib/utils";
import { ManagerOnly } from "@/shared/session/ManagerOnly";
import { useCanManage } from "@/shared/session/queries";
import { toast } from "@/shared/stores/toastStore";

import type { Team, TeamPeriodOverride } from "../api";
import { effectiveMaxPerDay, periodOverrideBody } from "../lib/periodOverride";
import { mutualisedTeammateLabel } from "../lib/sharedTraining";
import {
  useCreateTeamPeriodOverride,
  useDeleteConstraint,
  useDeleteTeamPeriodOverride,
  usePriorityTiers,
  useSharedTrainingBlocks,
  useTeamPeriodOverrides,
  useUpdateTeamPeriodOverride,
  useWizardConstraints,
  useWizardTeams,
} from "../queries";
import { SectionCountTitle } from "./StructureSummary";
import { PeriodAnchorGate } from "./PeriodAnchorGate";
import { DailyCapMarker } from "./TeamDailyCapPanel";
import { TeamLinksModal } from "./TeamLinksModal";

/**
 * Period-editable teams (F1): the club roster is READ-ONLY (grouped by rank), but
 * each team can be toggled off for the period and given a period-specific number
 * of sessions. Each team has its line in the period's plan (served as a sparse override when it
 * differs from the season); the season's values are never touched. The default selection of a
 * holiday plan (Fanion + importantes) is laid by the SERVER when the plan is born: opening this
 * step writes nothing.
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
  // Le TYPE de période : une fermeture garde tout le club (structure verrouillée). Lu comme PeriodConstraints le fait.
  const { data: entry } = useCalendarEntry(calendarEntryId);
  const isClosure = "closure" === entry?.periodType;
  const overridesQueryState = useTeamPeriodOverrides(schedulePlanId);
  const { data: overrides = [], refetch: retryOverrides } = overridesQueryState;
  const create = useCreateTeamPeriodOverride(schedulePlanId);
  const update = useUpdateTeamPeriodOverride(schedulePlanId);
  const del = useDeleteTeamPeriodOverride(schedulePlanId);
  const [busy, setBusy] = useState(false);
  // Un Membre lit la sélection : aucun réglage.
  const canManage = useCanManage();
  // P2-51 — le repère « mutualisée » DOIT figurer aussi en période, sinon il mentirait par
  // omission : les blocs de mutualisation de CETTE période (schedulePlanId derrière PeriodAnchorGate).
  const { data: sharedBlocks = [] } = useSharedTrainingBlocks(schedulePlanId);
  // P2-45 — les passerelles du club+saison (SERVIES par le module matchs) : le repère « passerelle »
  // et la modale Liens. En période elles sont en LECTURE SEULE (structure de saison).
  const { data: teamLinks = [] } = useTeamLinks();
  const [linksTeam, setLinksTeam] = useState<Team | null>(null);
  // PR 6 (lot 8, décision 9 A) — « Garder ses créneaux de saison » se VOIT et s'ENLÈVE ici. La
  // lecture vient du SERVEUR (les contraintes datées de CETTE entrée de période, même hook que
  // l'étape Contraintes) : le front ne re-dérive rien, il affiche ce que le transfert a posé.
  // Une seule contrainte SEASON_SLOTS ACTIVE par équipe (le transfert efface/recrée) → map directe.
  const { data: datedConstraints = [] } = useWizardConstraints(calendarEntryId);
  const deleteConstraint = useDeleteConstraint();
  const [removeSeasonSlots, setRemoveSeasonSlots] = useState<{ constraintId: string; teamName: string } | null>(null);
  const seasonSlotConstraintOf = new Map<string, string>(
    datedConstraints
      .filter((c) => "SEASON_SLOTS" === c.family && c.isActive && null !== c.scopeTargetId)
      .map((c) => [c.scopeTargetId as string, c.id]),
  );
  const nameOf = (id: string): string => teams.find((t) => t.id === id)?.name ?? "?";
  // P2-45 — le repère « passerelle », intensité comprise (lue telle quelle du lien, jamais recalculée).
  const bridgeLabelOf = (teamId: string): string | null => {
    const parts = teamLinks
      .filter((l) => l.teamAId === teamId || l.teamBId === teamId)
      .map((l) => `${teams.find((t) => t.id === (l.teamAId === teamId ? l.teamBId : l.teamAId))?.name ?? "?"} (${INTENSITY_LABEL[l.trainingIntensity]})`);
    return parts.length > 0 ? `Passerelle avec ${parts.join(", ")}` : null;
  };

  const groups = groupTeamsByTier(teams, tiers);
  const overrideOf = new Map<string, TeamPeriodOverride>(overrides.map((o) => [o.teamId, o]));

  const isActive = (t: Team): boolean => overrideOf.get(t.id)?.isActive ?? true;
  const sessionsOf = (t: Team): number => overrideOf.get(t.id)?.sessionsPerWeek ?? t.sessionsPerWeek;

  // P2-51 + #11 — la sous-ligne « Mutualisée : N séance(s) sur M … » tirée des blocs (helper pur,
  // testé). En PÉRIODE, le total M suit l'override de séances de la période (`sessionsOf`), pas la
  // valeur de saison — distinction réelle gardée par le jumeau de ce test côté période.
  const mutualiseLabelOf = (t: Team): string | null => mutualisedTeammateLabel(t.id, sharedBlocks, nameOf, sessionsOf(t));
  const linksLabelOf = (t: Team): string | null => {
    const combined = [mutualiseLabelOf(t), bridgeLabelOf(t.id)].filter((x): x is string => null !== x);
    return combined.length > 0 ? combined.join(" · ") : null;
  };

  // Upsert the override, or DELETE it when the state is back to the seasonal default
  // (active + seasonal session count + seasonal daily cap) — keeps the table sparse. The body is
  // built by `periodOverrideBody` (full replacement: a gesture on one field never drops the others,
  // e.g. un/re-checking a team keeps its period daily cap). Returns the mutation promise so batch
  // actions (seed / ramp) can await the whole set.
  /**
   * Rend `null` quand il n'y a RIEN à écrire (l'état voulu est déjà celui de la saison,
   * sans override) — à distinguer d'une écriture réussie : `Promise.allSettled` ne voit
   * aucune différence entre « zéro requête » et « toutes réussies », et c'est ainsi que
   * « Sélection appliquée » se confirmait sur zéro ligne écrite.
   */
  const upsertAsync = (t: Team, patch: { isActive?: boolean; sessionsPerWeek?: number | null }): Promise<unknown> | null => {
    const existing = overrideOf.get(t.id);
    const body = periodOverrideBody(schedulePlanId, t, existing, patch);
    if (null === body) {
      return existing ? del.mutateAsync(existing.id) : null;
    }
    return existing ? update.mutateAsync({ id: existing.id, body }) : create.mutateAsync(body);
  };
  // Fire-and-forget for single edits (checkbox/session). Swallow the rejection: the
  // global MutationCache already toasts it — this only avoids an unhandledrejection.
  const upsert = (t: Team, patch: { isActive?: boolean; sessionsPerWeek?: number | null }) => void upsertAsync(t, patch)?.catch(() => {});

  const toggle = (t: Team, value: boolean) => upsert(t, { isActive: value });
  const setSessions = (t: Team, raw: number) => {
    // Ignore an emptied / out-of-range field client-side (Number("") === 0; the field
    // caps at 7 but paste/typing can exceed it) rather than fire a doomed 422.
    if (!Number.isInteger(raw) || raw < 1 || raw > 7) {
      return;
    }
    upsert(t, { sessionsPerWeek: raw });
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
        .flatMap((g, i) => g.teams.map((t) => upsertAsync(t, { isActive: i <= upToIndex })))
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
      <ManagerOnly>
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
            <Spinner className="size-3" />
            Application…
          </span>
        ) : null}
      </div>
      </ManagerOnly>

      <div className="flex flex-col gap-1.5">
        {groups.map((g) => (
          <AccordionSection key={g.tier?.id ?? "orphan"} defaultOpen title={<SectionCountTitle label={tierGroupLabel(g.tier)} count={g.teams.length} />}>
            {g.teams.map((t) => {
              const active = isActive(t);
              return (
                <div key={t.id} className="flex items-center justify-between gap-3 border-b border-border/60 py-1.5 text-sm last:border-0">
                  <div className="flex min-w-0 flex-wrap items-center gap-2">
                    <label className="flex items-center gap-2">
                      <input type="checkbox" checked={active} disabled={busy || !canManage} onChange={(e) => toggle(t, e.target.checked)} aria-label={`${t.name} active cette période`} />
                      <span className={cn("flex items-center", !active && "text-muted-foreground line-through")}>
                        {true === t.isCtc ? <CtcMark partner={t.ctcPartner} /> : null}
                        {t.name}
                      </span>
                    </label>
                    <DailyCapMarker max={effectiveMaxPerDay(t, overrideOf.get(t.id))} />
                    {linksLabelOf(t) ? <span className="text-xs italic text-muted-foreground">· {linksLabelOf(t)}</span> : null}
                    {/* P2-63 PR 5 (F) — provenance « via doléances », SEULEMENT pour la valeur
                        'coach_wish' (une équipe activée par la mutualisation porte 'mutualisation'). */}
                    {"coach_wish" === overrideOf.get(t.id)?.source ? <StatusPill variant="accent">via doléances</StatusPill> : null}
                    {/* PR 6 (lot 8) — « garde ses créneaux de saison » : pastille + retrait. Variante
                        neutre (distincte de l'accent « via doléances », qui peut coexister sur la ligne) ;
                        pas de bouton d'ajout ici — la contrainte naît du transfert seul. */}
                    {seasonSlotConstraintOf.has(t.id) ? (
                      <span className="inline-flex items-center gap-1">
                        <StatusPill variant="neutral">garde ses créneaux de saison</StatusPill>
                        <ManagerOnly>
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          disabled={busy}
                          aria-label={`Ne plus garder les créneaux de saison de ${t.name}`}
                          onClick={() => setRemoveSeasonSlots({ constraintId: seasonSlotConstraintOf.get(t.id) as string, teamName: t.name })}
                        >
                          <X className="size-3.5" />
                        </Button>
                        </ManagerOnly>
                      </span>
                    ) : null}
                  </div>
                  <div className="flex items-center gap-2">
                    <ReadOnlyFieldset readOnly={!canManage}>
                    <label className={cn("flex items-center gap-1 text-xs text-muted-foreground", !active && "opacity-50")}>
                      séances
                      <Input
                        type="number"
                        min={1}
                        max={7}
                        compact
                        className="w-14"
                        value={sessionsOf(t)}
                        disabled={!active || busy}
                        onChange={(e) => setSessions(t, Number(e.target.value))}
                        aria-label={`Séances de ${t.name} cette période`}
                      />
                    </label>
                    </ReadOnlyFieldset>
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
          équipes en pause ne sont pas offertes comme candidates à la mutualisation. D4 — l'onglet
          « Entente (CTC) », lui, reste ÉDITABLE même ici : il n'hérite pas de `readOnlyLinks` (repère
          de club valable toute la saison, sans effet moteur). */}
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

      {/* PR 6 (lot 8) — confirmation légère avant de retirer « garder ses créneaux de saison ».
          La suppression passe par l'API Constraint (DELETE) ; le hook invalide la liste, la
          pastille disparaît. Modifier/supprimer une doléance ne touche PAS le plan (décision 8) —
          seul ce retrait explicite, ou un nouveau transfert, recale. */}
      <ConfirmDialog
        open={null !== removeSeasonSlots}
        title="Ne plus garder les créneaux de saison"
        description={null !== removeSeasonSlots ? <>L'équipe <strong>{removeSeasonSlots.teamName}</strong> ne gardera plus ses créneaux de saison sur cette période. Vous pourrez la re-transférer depuis les doléances.</> : null}
        confirmLabel="Confirmer"
        onConfirm={() => {
          if (null !== removeSeasonSlots) {
            deleteConstraint.mutate(removeSeasonSlots.constraintId);
          }
          setRemoveSeasonSlots(null);
        }}
        onCancel={() => setRemoveSeasonSlots(null)}
      />
    </div>
  );
}
