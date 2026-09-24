import { useMemo, useState } from "react";

import { useCalendarEntry, usePeriodAnchor } from "@/features/cockpit/queries";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { buildTagTeamIds, resolveConstraintTeamIds, targetsTags } from "@/shared/lib/tagTeamIds";
import { cn } from "@/shared/lib/utils";

import type { Constraint, ConstraintRuleType } from "../api";
import {
  useCreatePeriodConstraintOverride,
  useDeletePeriodConstraintOverride,
  usePeriodConstraintOverrides,
  useWizardTeamTagAssignments,
  useWizardTeamTags,
  useTeamPeriodOverrides,
  useUpdatePeriodConstraintOverride,
  useWizardConstraints,
  useWizardTeams,
} from "../queries";
import { PeriodAnchorGate } from "./PeriodAnchorGate";

/** Libellés gestionnaire (jamais l'enum brut à l'écran). */
const RULE_LABEL: Record<ConstraintRuleType, string> = {
  HARD: "Obligatoire",
  PREFERRED: "Préféré",
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
