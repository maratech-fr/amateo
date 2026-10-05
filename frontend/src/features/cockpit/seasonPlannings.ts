import type { Schedule } from "@/features/planning/api";
import { isSeasonPlanType, planRepresentative, visibleOverlayVersions, visibleSeasonPlans } from "@/features/planning/lib/versions";

import type { CalendarEntry, SchedulePlan } from "./api";

export interface PlanningRow {
  id: string;
  label: string;
  /** Clé de tri chronologique (fenêtre du plan) — `null` pour le socle, qui ne trie pas. */
  startDate: string | null;
  status: Schedule["status"];
  /** Le plan de ce planning pointe cette version : il est en vigueur (≠ « principal »). */
  isChosen: boolean;
  /** Planning secondaire (plan d'une période) ; sinon c'est LE planning principal de la saison. */
  isOverlay: boolean;
  /** Aucune version terminée : le planning est OUVERT (en cours) — rien d'exportable, à reprendre. */
  isOpen: boolean;
  /** Clé de regroupement des versions — permet de remonter au plan (calendarEntryId) pour « Reprendre ». */
  schedulePlanId: string | null;
}

/**
 * Le socle porte le NOM de son plan (ADR-0002 inv. 12 — renommable) : l'afficher,
 * pas un libellé générique. `seasonPlanName` = `me.seasonPlan?.name` ; le fallback
 * ne sert qu'aux états sans plan chargé.
 */
export function seasonPlannings(schedules: Schedule[], seasonPlanName: string | null = null, plans: SchedulePlan[] = [], entries: CalendarEntry[] = [], schedulesResolved = true): PlanningRow[] {
  const rows: PlanningRow[] = [];
  const seasonLabel = seasonPlanName ?? "Planning de saison";
  const seasonVersions = visibleSeasonPlans(schedules);
  const seasonMain = planRepresentative(seasonVersions);
  // Un planning sans version terminée reste VISIBLE (retour fondateur 2026-07-18) :
  // il est « en cours », le gestionnaire a une action à faire — on montre la
  // dernière version quelle qu'elle soit, export masqué par l'appelant (isOpen).
  const seasonShown = seasonMain ?? seasonVersions.at(-1) ?? null;
  if (null !== seasonShown) {
    rows.push({
      id: seasonShown.id,
      label: seasonLabel,
      status: seasonShown.status,
      isChosen: true === seasonShown.isChosen,
      isOverlay: false,
      isOpen: null === seasonMain,
      startDate: null,
      schedulePlanId: seasonShown.schedulePlanId,
    });
  }
  // ADR-0002 C4 : un planning secondaire = les versions d'un plan de période (schedulePlanId).
  const overlayPlanIds = [...new Set(schedules.filter((s) => !isSeasonPlanType(s.planType)).map((s) => s.schedulePlanId as string))];
  const periods: PlanningRow[] = [];
  for (const planId of overlayPlanIds) {
    const versions = visibleOverlayVersions(schedules, planId);
    const finished = planRepresentative(versions);
    const shown = finished ?? versions.at(-1) ?? null;
    if (null !== shown) {
      periods.push({
        id: shown.id,
        // Le nom du PLAN, comme pour le socle ci-dessus (ADR-0002 inv. 12) — une
        // ligne de cette liste EST un plan. C'était le nom de la VERSION affichée,
        // que les clients inventaient (« Version de période ») : un planning renommé
        // « Reprise d'été S1 » se relisait sous ce libellé générique. Le serveur nomme
        // désormais les versions, mais les lignes ANCIENNES gardent leur nom inventé —
        // raison de plus pour lire le plan. Fallback sur la version tant que les plans
        // ne sont pas chargés (l'appelant peut ne pas les passer) : jamais de ligne muette.
        label: plans.find((p) => p.id === planId)?.name ?? shown.name,
        status: shown.status,
        isChosen: true === shown.isChosen,
        isOverlay: true,
        isOpen: null === finished,
        startDate: plans.find((p) => p.id === planId)?.startDate ?? null,
        schedulePlanId: planId,
      });
    }
  }
  // Plans de période SANS AUCUNE version générée (retour fondateur 2026-07-19) :
  // un planning créé au picker mais pas encore généré doit rester visible — il est
  // « en cours », le gestionnaire a une action (Reprendre). `seasonPlannings` étant
  // piloté par les versions, ces plans n'avaient aucune ligne. `id` = plan.id (pas
  // de scheduleId : aucune version à consulter/exporter — la CTA sera « Reprendre »).
  // FAIL-CLOSED (revue B1 F4) : sans schedules résolus, on ne sait PAS quels plans
  // ont des versions — on n'invente aucune ligne DRAFT (un overlay validé serait
  // montré « en cours »). Une mère DÉCOUPÉE (revue B1 F3) porte encore un plan à 0
  // version, mais ses semaines-enfants tiennent l'affichage : on l'exclut (comme le
  // radar via !childrenByParent) pour ne pas doubler d'une ligne fantôme.
  if (schedulesResolved) {
    const planIdsWithVersions = new Set(overlayPlanIds);
    const motherEntryIds = new Set(entries.filter((e) => null !== e.parentEntryId).map((e) => e.parentEntryId as string));
    for (const plan of plans) {
      if (("CLOSURE" !== plan.type && "HOLIDAY" !== plan.type) || null === plan.calendarEntryId || planIdsWithVersions.has(plan.id) || motherEntryIds.has(plan.calendarEntryId)) {
        continue;
      }
      periods.push({
        id: plan.id,
        label: plan.name,
        status: "DRAFT",
        isChosen: false,
        isOverlay: true,
        isOpen: true,
        startDate: plan.startDate ?? null,
        schedulePlanId: plan.id,
      });
    }
  }
  // Tri CHRONOLOGIQUE, jamais alphabétique. Les libellés portent une date en toutes lettres
  // (« Semaine du 6 juillet 2026 ») : les comparer listait 10 août avant 13 juillet avant
  // 3 août. `startDate` est une date ISO, comparable telle quelle.
  // ⚠ Le repli NE retombe PAS sur le libellé : les plans ne sont pas toujours chargés (query
  // en vol ou en erreur), et depuis que les versions héritent du nom de leur plan, comparer
  // les libellés dans cet état reproduirait exactement le désordre qu'on vient de corriger
  // (revue #339 round 2). Une date absente trie en tête, à égalité entre elles — `Array.sort`
  // étant stable, ces lignes gardent leur ordre d'arrivée au lieu d'en recevoir un faux.
  periods.sort((a, b) => (a.startDate ?? "").localeCompare(b.startDate ?? ""));

  return [...rows, ...periods];
}

/** `openOverlays` : plannings secondaires SANS version terminée (en cours) — le
 *  sous-titre de la bannière les distingue pour ne pas laisser croire à un
 *  planning prêt (revue #260 round 2). */
export function seasonPlanCounts(schedules: Schedule[], plans: SchedulePlan[] = [], entries: CalendarEntry[] = [], schedulesResolved = true): { total: number; overlays: number; openOverlays: number } {
  const rows = seasonPlannings(schedules, null, plans, entries, schedulesResolved);
  const overlays = rows.filter((row) => row.isOverlay);
  return { total: rows.length, overlays: overlays.length, openOverlays: overlays.filter((row) => row.isOpen).length };
}
