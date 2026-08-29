import { Check, Lock, Pencil, Plus, Trash2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { PeriodAnchorGate } from "./PeriodAnchorGate";
import { ProductRulesPanel, TravelRuleNotice, WellbeingRulesPanel } from "./ImplicitRulesPanel";
import { isWellbeingKey } from "../lib/implicitRules";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";

import { groupConstraints, orderedTagNames } from "../lib/constraintOrder";
import { groupedCoaches } from "../lib/ranking";
import { constraintPredicateParts, constraintTarget } from "@/features/planning/lib/describeConstraint";
import { groupTagsByAxis, tagLabel } from "../lib/tagLabels";
import { excludeTagNames, targetTagNames } from "@/shared/lib/tagTeamIds";
import { cn } from "@/shared/lib/utils";

import type { Constraint, ConstraintFamily, ConstraintPayload, ConstraintRuleType } from "../api";
import { DAYS } from "../lib/days";
import { dayLabelLong } from "@/shared/lib/days";
import { useCreateConstraint, useDeleteConstraint, usePriorityTiers, useUpdateConstraint, useWizardCoachPlayers, useWizardCoaches, useWizardConstraints, useWizardTeamTagAssignments, useWizardTeamTags, useWizardTeams, useActiveTeams, useActiveVenues, useWizardVenues, useReservations } from "../queries";
import { useCalendarEntry, useEntryConflicts, usePeriodAnchor } from "@/features/cockpit/queries";
import { sortByName } from "@/shared/lib/nameOrder";
import { useWizardStore } from "../store";
import { PeriodConstraints } from "./PeriodStructure";
import { ReservationPanel } from "./ReservationPanel";

const FAMILIES: { key: ConstraintFamily; label: string }[] = [
  { key: "TIME", label: "Horaires" },
  { key: "DAY", label: "Jours" },
  { key: "FACILITY", label: "Gymnase" },
  { key: "COACH_AVAILABILITY", label: "Dispo coach" },
];

// BONUS removed from the offer (audit ENG-12): it never had a distinct
// semantic anywhere (no weight, no engine branch) — legacy rows are honored as
// PREFERRED by the engine. RULE_LABEL keeps it for displaying existing rows.
const RULES: ConstraintRuleType[] = ["PREFERRED", "HARD", "LOCK"];

/** Libellés gestionnaire (jamais l'enum brut à l'écran). */
const RULE_LABEL: Record<ConstraintRuleType, string> = {
  HARD: "Obligatoire",
  PREFERRED: "Préféré",
  BONUS: "Bonus",
  LOCK: "Verrouillé",
};

/** Coerce a JSON config value (unknown) into a day-number array. */
const asNums = (v: unknown): number[] => (Array.isArray(v) ? v.map(Number).filter((n) => !Number.isNaN(n)) : []);

/**
 * ⚠ `legend` n'est pas décoratif : un jour coché est colorié pareil qu'il soit IMPOSÉ ou
 * ÉVITÉ. Le sens vit dans un `Select` voisin, que la couleur ne rappelle pas et qu'un
 * lecteur d'écran ne rattache à rien — les boutons n'annonçaient que « Lun », « Mar ».
 * P4-58(a) décrivait la polarité comme invisible ; elle ne l'est plus depuis que ce
 * sélecteur existe, mais le GROUPE, lui, restait muet. `aria-label` porté par le groupe
 * suit la polarité courante : le sens est dit là où le geste se fait.
 */
function DayPicker({ days, toggle, legend }: { days: Set<number>; toggle: (n: number) => void; legend: string }) {
  return (
    <div role="group" aria-label={legend} className="flex flex-wrap gap-1">
      {DAYS.map((d) => (
        <button
          key={d.n}
          type="button"
          onClick={() => toggle(d.n)}
          aria-pressed={days.has(d.n)}
          className={cn("rounded-md border px-2 py-1 text-xs", days.has(d.n) ? "border-accent bg-accent text-accent-foreground" : "border-border text-muted-foreground")}
        >
          {d.label}
        </button>
      ))}
    </div>
  );
}

export function ConstraintsStep() {
  const periodEntryId = useWizardStore((s) => (s.mode === "period" ? s.calendarEntryId : null));
  // Mode période SANS entrée résolue (état rehydraté possible : `mode` et
  // `calendarEntryId` sont persistés indépendamment) : l'ancre vaudrait `base`, donc
  // « écrivable » — et la réservation partirait sur le SOCLE du club alors que l'écran
  // annonce une période. On exige donc `period` en mode période, jamais `base`.
  const periodMode = useWizardStore((s) => "period" === s.mode);
  const clearDeepLinkOrigin = useWizardStore((s) => s.clearDeepLinkOrigin);
  // Les RÉSERVATIONS pendent au plan (inv. 5, lot C3) ; les contraintes DATÉES restent
  // ancrées à l'entrée — elles décrivent le FAIT, et le radar les lit par elle.
  //
  // `usePeriodAnchor` porte le pourquoi : `null` est une ancre LÉGITIME (= base), donc un
  // `?? null` nu poserait la réservation sur le socle pendant le chargement du plan.
  const anchor = usePeriodAnchor(periodEntryId);
  // D5 (P2-22) — MIROIR de `CalendarEntry::datedConstraintSourceId()` (backend) : les
  // contraintes DATÉES d'une semaine ENFANT pendent à sa MÈRE (`parentEntryId ?? id`). Lister
  // ET créer par l'id de l'enfant les rendrait invisibles à `PeriodConstraintSelector`. Les
  // FERMETURES, elles, sont déjà résolues serveur (on interroge l'enfant pour les conflits).
  const { data: currentEntry } = useCalendarEntry(periodEntryId);
  const sourceEntryId = null !== periodEntryId ? (currentEntry?.parentEntryId ?? periodEntryId) : null;
  const { data: constraints = [] } = useWizardConstraints(sourceEntryId);
  const { data: allTeams = [] } = useWizardTeams();
  const { data: tiers = [] } = usePriorityTiers();
  const { data: tags = [] } = useWizardTeamTags();
  const { data: tagAssignments = [] } = useWizardTeamTagAssignments();
  const { data: coaches = [] } = useWizardCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  // P2-15 : les sélecteurs d'une période ne proposent QUE les gymnases et les équipes
  // ACTIFS — décision fondateur : « je ne veux voir que les gymnases actifs ». Ce qui sort
  // du payload solveur ne doit pas être offert ici : le geste serait sans effet.
  // ⚠ Les ÉQUIPES suivent la même règle depuis la revue #342 round 2 : les laisser dans le
  // picker permettait d'épingler une équipe en pause — `OrphanPinGuard` ne regarde que la
  // salle/le jour/l'heure, donc la génération PASSE et l'équipe n'a de séance nulle part.
  const layerPlanId = "period" === anchor.state ? anchor.planId : null;
  // P2-37 D6 — un gymnase entièrement fermé sur la fenêtre est indisponible : `useActiveVenues`
  // le retire de la liste active et le range dans `disabledIds`, DEPUIS la donnée serveur
  // (`fullyClosedVenueIds` — jamais redérivée côté front). On interroge l'entrée COURANTE
  // (semaine enfant comprise) : le serveur résout la mère pour les fermetures.
  const { data: entryConflicts } = useEntryConflicts(periodEntryId);
  const { venues, disabledIds, layerRead: venuesRead } = useActiveVenues(layerPlanId, entryConflicts?.disabledVenueIds ?? [], entryConflicts?.fullyClosedVenueIds ?? []);
  const { teams, pausedIds, layerRead: teamsRead } = useActiveTeams(layerPlanId);
  const { data: allVenues = [] } = useWizardVenues();
  // Mode période dont l'ancre n'est PAS résolue (`loading`, `failed`, `absent`) : la couche
  // vaut null, donc les listes ci-dessus sont celles de la SAISON. `failed` et `absent` sont
  // des états TERMINAUX, pas un flash : sans cet aveu, l'onglet « Gymnases » (hors
  // `PeriodAnchorGate`) laissait relier une contrainte de période à un gymnase désactivé,
  // en silence. Même aveu qu'au récap (revue #342 round 2).
  const periodLayerUnresolved = periodMode && "period" !== anchor.state;
  // Les réservations de la couche courante, lues ici pour savoir quel gymnase désactivé
  // doit rester atteignable (cf. `reservationVenues`). Même ancre ET même garde que le
  // panneau : `null` est à la fois l'ancre base légitime et un plan non résolu, donc un
  // `enabled` codé en dur allait chercher les réservations du SOCLE et les publiait dans le
  // cache partagé « base », d'où le récap de la période les relisait (revue #342 round 2).
  const { data: reservationsForPanel = [] } = useReservations(layerPlanId, periodMode ? "period" === anchor.state : true);
  const create = useCreateConstraint();
  const update = useUpdateConstraint();
  const del = useDeleteConstraint();

  const [family, setFamily] = useState<ConstraintFamily>("TIME");
  // Onglets de PRÉSENTATION (ne créent AUCUNE contrainte) : "base" = règles immuables lecture
  // seule, "wellbeing" = règles réglables (implicit_rule_settings). "constraint"/"reserve"
  // pilotent le formulaire de contraintes / la réservation.
  const [mode, setMode] = useState<"constraint" | "reserve" | "base" | "wellbeing">("constraint");
  const [ruleType, setRuleType] = useState<ConstraintRuleType>("PREFERRED");
  // target: "" = toutes les équipes (CLUB) · "tag:NAME" = un groupe · sinon un id d'équipe (TEAM)
  const [target, setTarget] = useState("");
  // Affinage d'un groupe (P2-29) — visible SEULEMENT quand la cible est un tag. « ET AUSSI » =
  // intersection (l'équipe doit porter les deux) → `targetTags` ; « SAUF » = union soustraite →
  // `excludeTags`. Replié par défaut ; vidé dès que la cible n'est plus un tag (pas d'état
  // fantôme qui repartirait à la contrainte suivante).
  const [refineOpen, setRefineOpen] = useState(false);
  const [andTags, setAndTags] = useState<Set<string>>(new Set());
  const [exceptTags, setExceptTags] = useState<Set<string>>(new Set());
  const [minTime, setMinTime] = useState("");
  const [maxTime, setMaxTime] = useState("");
  // "finir avant" = maxEndTime (l'engine calcule fin = début + durée du créneau).
  const [endTime, setEndTime] = useState("");
  const [days, setDays] = useState<Set<number>>(new Set());
  // Trois modes JOUR, trois clés engine (littéraux HONNÊTES) :
  //   "forbidden" → forbiddenDays (à éviter, soft possible) ;
  //   "only"      → allowedDays (whitelist : SEULS ces jours, l'engine interdit le complément) ;
  //   "atLeast"   → forcedDays (« au moins une séance l'UN de ces jours » — somme agrégée sur
  //                 l'union, PAS « chacun »). "only" et "atLeast" sont TOUJOURS obligatoires.
  const [dayMode, setDayMode] = useState<"forbidden" | "only" | "atLeast">("forbidden");
  // "préfère" (preferredVenueId) · "évite" (forbiddenVenueId) · "impose"
  // (forcedVenueId, dur) · "au moins N" (minAtVenueId + minAtVenueCount, dur).
  const [venueMode, setVenueMode] = useState<"preferred" | "forbidden" | "forced" | "min">("preferred");
  const [venueId, setVenueId] = useState("");
  // Compteur du mode "au moins N séances dans ce gymnase" (défaut 1, le cas courant).
  const [venueMinCount, setVenueMinCount] = useState(1);
  const [coachId, setCoachId] = useState("");
  // "indisponible" (unavailableDays, blacklist) vs "disponible uniquement"
  // (availableDays, whitelist — l'engine intersecte les whitelists d'un coach).
  const [coachMode, setCoachMode] = useState<"unavailable" | "available">("unavailable");
  // Lot C: optional time window on the selected days ("" = whole day).
  const [coachFrom, setCoachFrom] = useState("");
  const [coachUntil, setCoachUntil] = useState("");
  const [pendingDelete, setPendingDelete] = useState<Constraint | null>(null);
  // id de la contrainte en cours d'édition (null = création) — réutilise le même formulaire.
  const [editingId, setEditingId] = useState<string | null>(null);
  // Le formulaire (haut de page) qu'il faut ramener à l'écran quand on édite une
  // ligne éloignée — P4-66.
  const formRef = useRef<HTMLDivElement>(null);

  const teamName = new Map(allTeams.map((t) => [t.id, t.name]));
  const coachName = new Map(coaches.map((c) => [c.id, `${c.firstName} ${c.lastName}`.trim()]));
  // Group the coach picker: Salariés, then Coachs-joueurs, then Bénévoles (batch item 1).
  const coachGroups = useMemo(() => groupedCoaches(coaches, new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId))), [coaches, coachPlayers]);
  // NOMMER ≠ CHOISIR (revue #342) : le nom auto d'une contrainte et la ré-édition d'une
  // contrainte existante visent parfois un gymnase désactivé — construire les libellés sur
  // la liste filtrée y écrivait littéralement « undefined ».
  const venueName = new Map(allVenues.map((v) => [v.id, v.name]));
  // ⚠ ATTEINDRE ≠ CHOISIR (revue #342). Un gymnase désactivé qui porte ENCORE une
  // réservation reste offert à l'écran « Réserver » — sinon le gestionnaire est PIÉGÉ :
  // `OrphanPinGuard` refuse la génération à cause de cet épinglage (422 nommant le
  // gymnase et le jour), et l'écran qui permet de l'enlever ne le montre plus.
  // Le picker de CONTRAINTES, lui, reste filtré : rien n'y est bloquant.
  const reservedVenueIds = new Set(reservationsForPanel.map((r) => r.venueId));
  const reservationVenues = allVenues.filter((v) => venues.some((a) => a.id === v.id) || reservedVenueIds.has(v.id));
  // ⚠ ATTEINDRE n'est pas AUTORISER (revue #342 round 2). Cette porte de sortie rouvrait le
  // piège dans l'autre sens : le gymnase réadmis revenait en grille pleinement éditable et
  // sans marque, donc on pouvait y CRÉER un nouvel épinglage orphelin — et repartir en 422.
  // Les identifiants voyagent jusqu'à la modale, qui montre le créneau et laisse RETIRER
  // sans laisser AJOUTER.
  // Idem pour une contrainte en cours d'édition : un picker doit toujours pouvoir afficher
  // sa PROPRE valeur, même désactivée, sinon le select rend blanc sur une contrainte qui
  // nomme pourtant un gymnase — et « corriger le trou » repointe une règle HARD ailleurs.
  const editVenues = "" !== venueId && !venues.some((v) => v.id === venueId) ? [...venues, ...allVenues.filter((v) => v.id === venueId)] : venues;

  // Les listes offertes ci-dessous sont-elles bien celles de la période ? Trois raisons de
  // dire non : l'ancre pas encore résolue, et chacune des deux lectures d'overrides.
  const layerNotices = [
    periodLayerUnresolved ? { message: "Les réglages de cette période ne sont pas encore chargés — les listes ci-dessous sont celles de la saison.", pending: "loading" === anchor.state } : null,
    "ready" === venuesRead ? null : { message: `Les réglages de gymnases de la période ${"loading" === venuesRead ? "sont en cours de lecture" : "n'ont pas pu être lus"} — la liste ci-dessous est celle de la saison et peut contenir un gymnase désactivé.`, pending: "loading" === venuesRead },
    "ready" === teamsRead ? null : { message: `La sélection d'équipes de la période ${"loading" === teamsRead ? "est en cours de lecture" : "n'a pas pu être lue"} — la liste ci-dessous est celle de la saison et peut contenir une équipe en pause.`, pending: "loading" === teamsRead },
  ].filter((n): n is { message: string; pending: boolean } => null !== n);

  // Only groups (tags) that ACTUALLY concern a team of the club: the backend always creates the
  // system tags, but a group with no assigned team (e.g. FEMININE when the club has no female
  // team) must never appear in the selector — ni dans l'affinage.
  const assignedTagIds = useMemo(() => new Set(tagAssignments.map((a) => a.tagId)), [tagAssignments]);
  const visibleTags = useMemo(() => tags.filter((t) => assignedTagIds.has(t.id)), [tags, assignedTagIds]);

  // Resolve the target into scope + optional tag (CLUB+tag(s) → N team constraints backend-side).
  const isTag = target.startsWith("tag:");
  const tagName = isTag ? target.slice(4) : "";
  const teamTargetId = !isTag && "" !== target ? target : "";
  const scope: "CLUB" | "TEAM" = "" !== teamTargetId ? "TEAM" : "CLUB";
  const scopeTargetId = "" !== teamTargetId ? teamTargetId : null;
  // Ordre canonique des tags (axe puis libellé) — pour un `targetTags`/`excludeTags` STABLE et un
  // nom auto déterministe. Même ordre que le sélecteur et le récap.
  const tagOrder = useMemo(() => new Map(orderedTagNames(visibleTags).map((n, i) => [n, i] as const)), [visibleTags]);
  const ordered = (names: Set<string>): string[] => [...names].sort((a, b) => (tagOrder.get(a) ?? 9999) - (tagOrder.get(b) ?? 9999));
  const andList = ordered(andTags);
  const exceptList = ordered(exceptTags);
  const refined = isTag && (andList.length > 0 || exceptList.length > 0);
  // Émission : `targetTag` SEUL tant qu'il n'y a ni « et aussi » ni « sauf » (zéro churn sur
  // l'existant) ; sinon `targetTags` (intersection) + `excludeTags` — JAMAIS les deux formes
  // ensemble (le backend rend 422 si `targetTag` et `targetTags` cohabitent).
  const tagConfig: Record<string, unknown> = !isTag
    ? {}
    : refined
      ? { targetTags: [tagName, ...andList], ...(exceptList.length > 0 ? { excludeTags: exceptList } : {}) }
      : { targetTag: tagName };
  // « au moins » n'existe que par équipe : si la cible quitte l'équipe APRÈS le
  // choix du mode, la valeur retombe d'elle-même sur « préfère » (dérivé au
  // rendu — jamais un état qui pourrait rester coincé sur l'option désactivée).
  const minAllowed = "TEAM" === scope || isTag;
  const effectiveVenueMode = "min" === venueMode && !minAllowed ? "preferred" : venueMode;
  // Le « qui » du nom auto : format « Groupe A + B sauf C » avec les libellés AFFICHÉS
  // (`tagLabel`, ce que le gestionnaire a sous les yeux), pas les noms techniques (P2-29).
  const whoGroup = (): string => {
    const parts = [tagLabel(tagName), ...andList.map(tagLabel)].join(" + ");

    return `Groupe ${parts}${exceptList.length > 0 ? ` sauf ${exceptList.map(tagLabel).join(", ")}` : ""}`;
  };
  const who = "" !== teamTargetId ? (teamName.get(teamTargetId) ?? "?") : isTag ? whoGroup() : "Toutes les équipes";
  // Changer la cible : hors tag → l'affinage se vide (repli inclus) ; vers un tag → ce tag ne
  // peut pas être aussi « et aussi »/« sauf » de lui-même, on l'en retire.
  const changeTarget = (value: string) => {
    setTarget(value);
    if (!value.startsWith("tag:")) {
      setAndTags(new Set());
      setExceptTags(new Set());
      setRefineOpen(false);
      return;
    }
    const primary = value.slice(4);
    setAndTags((prev) => new Set([...prev].filter((n) => n !== primary)));
    setExceptTags((prev) => new Set([...prev].filter((n) => n !== primary)));
  };
  // Cocher/décocher un tag en « et aussi » ou « sauf ». Un tag ne peut pas être dans les deux
  // (le backend rend 422 sur l'intersection targetTag/targetTags) : l'ajouter d'un côté le
  // retire de l'autre.
  const toggleAnd = (name: string) => {
    const adding = !andTags.has(name);
    setAndTags((p) => {
      const n = new Set(p);
      if (adding) {
        n.add(name);
      } else {
        n.delete(name);
      }
      return n;
    });
    if (adding) {
      setExceptTags((p) => {
        if (!p.has(name)) {
          return p;
        }
        const n = new Set(p);
        n.delete(name);
        return n;
      });
    }
  };
  const toggleExcept = (name: string) => {
    const adding = !exceptTags.has(name);
    setExceptTags((p) => {
      const n = new Set(p);
      if (adding) {
        n.add(name);
      } else {
        n.delete(name);
      }
      return n;
    });
    if (adding) {
      setAndTags((p) => {
        if (!p.has(name)) {
          return p;
        }
        const n = new Set(p);
        n.delete(name);
        return n;
      });
    }
  };
  const toggleDay = (n: number) => setDays((prev) => (prev.has(n) ? new Set([...prev].filter((x) => x !== n)) : new Set([...prev, n])));
  // Le NOM auto-généré écrit les jours EN TOUTES LETTRES (« jeudi », pas « Jeu ») — le
  // court reste réservé aux colonnes de la grille. Forme longue au foyer unique (D-22).
  const dayNames = (set: Set<number>) => [...set].sort((a, b) => a - b).map(dayLabelLong).join(", ");

  function build(): ConstraintPayload | null {
    if ("TIME" === family) {
      if ("" === minTime && "" === maxTime && "" === endTime) {
        return null;
      }
      const config: Record<string, unknown> = { ...tagConfig };
      if ("" !== minTime) {
        config.minStartTime = minTime;
      }
      if ("" !== maxTime) {
        config.maxStartTime = maxTime;
      }
      if ("" !== endTime) {
        config.maxEndTime = endTime;
      }
      const parts = [maxTime && `pas après ${maxTime}`, minTime && `pas avant ${minTime}`, endTime && `fini avant ${endTime}`].filter(Boolean).join(", ");
      // maxEndTime (fin de séance) n'existe que dur côté engine : le chemin soft
      // (preferredTime) ne lit que min/maxStartTime → une "Fini avant" préférée
      // serait un placebo. Dès qu'on impose une fin, la règle est obligatoire.
      const timeRule: ConstraintRuleType = "" !== endTime ? "HARD" : ruleType;
      return { name: `${who} · ${parts}`, scope, scopeTargetId, family, ruleType: timeRule, config };
    }
    if ("DAY" === family) {
      if (0 === days.size) {
        return null;
      }
      if ("only" === dayMode) {
        // "uniquement" = whitelist allowedDays (l'engine interdit tous les autres jours).
        return { name: `${who} · uniquement ${dayNames(days)}`, scope, scopeTargetId, family, ruleType: "HARD", config: { ...tagConfig, allowedDays: [...days] } };
      }
      if ("atLeast" === dayMode) {
        // "au moins une" = forcedDays (« au moins une séance l'un de ces jours ») — toujours dur.
        return { name: `${who} · au moins une séance ${dayNames(days)}`, scope, scopeTargetId, family, ruleType: "HARD", config: { ...tagConfig, forcedDays: [...days] } };
      }
      return { name: `${who} · pas ${dayNames(days)}`, scope, scopeTargetId, family, ruleType, config: { ...tagConfig, forbiddenDays: [...days] } };
    }
    if ("FACILITY" === family) {
      if ("" === venueId) {
        return null;
      }
      if ("forced" === effectiveVenueMode) {
        // "impose" = doit se dérouler dans ce gymnase (forcedVenueId), toujours dur.
        return { name: `${who} · impose ${venueName.get(venueId)}`, scope, scopeTargetId, family, ruleType: "HARD", config: { ...tagConfig, forcedVenueId: venueId } };
      }
      if ("min" === effectiveVenueMode) {
        // "au moins N séances ici" = compte plancher (minAtVenueId + minAtVenueCount),
        // toujours dur. Le backend refuse N > séances/semaine de l'équipe avant génération.
        const count = Math.max(1, venueMinCount);
        return { name: `${who} · au moins ${count} à ${venueName.get(venueId)}`, scope, scopeTargetId, family, ruleType: "HARD", config: { ...tagConfig, minAtVenueId: venueId, minAtVenueCount: count } };
      }
      const config = { ...tagConfig, ...("preferred" === effectiveVenueMode ? { preferredVenueId: venueId } : { forbiddenVenueId: venueId }) };
      const verb = "preferred" === effectiveVenueMode ? "préfère" : "évite";
      return { name: `${who} · ${verb} ${venueName.get(venueId)}`, scope, scopeTargetId, family, ruleType, config };
    }
    // COACH_AVAILABILITY
    if ("" === coachId || 0 === days.size) {
      return null;
    }
    const coachDaysKey = "available" === coachMode ? "availableDays" : "unavailableDays";
    // Lot C: optional time window on those days ("" = whole day).
    const window = "" !== coachFrom || "" !== coachUntil ? { ...(coachFrom ? { fromTime: coachFrom } : {}), ...(coachUntil ? { untilTime: coachUntil } : {}) } : {};
    const windowLabel = coachFrom && coachUntil ? ` de ${coachFrom} à ${coachUntil}` : coachFrom ? ` à partir de ${coachFrom}` : coachUntil ? ` jusqu'à ${coachUntil}` : "";
    return {
      name: `${coachName.get(coachId)} · ${"available" === coachMode ? "dispo uniquement" : "indispo"} ${dayNames(days)}${windowLabel}`,
      scope: "COACH",
      scopeTargetId: coachId,
      family,
      // Always hard: the engine enforces coach availability unconditionally.
      ruleType: "HARD",
      // SEC-13 : plus de `coachId` dans le config — il valait EXACTEMENT
      // `scopeTargetId` juste au-dessus, et c'est ce scope que le solveur lit.
      // Un doublon finit toujours par diverger ; la cible du coach a UN endroit.
      config: { [coachDaysKey]: [...days], ...window },
    };
  }

  // Clears only the per-constraint value inputs, keeping the target/venue/rule
  // so several constraints for the same team can be added in a row (old add()).
  const clearInputs = () => {
    setMinTime("");
    setMaxTime("");
    setEndTime("");
    setDays(new Set());
  };

  // Full reset: also drops the target + exits edit mode (used after an edit or on cancel).
  const resetForm = () => {
    setEditingId(null);
    setTarget("");
    setAndTags(new Set());
    setExceptTags(new Set());
    setRefineOpen(false);
    setDayMode("forbidden");
    setCoachMode("unavailable");
    setVenueMode("preferred");
    setVenueId("");
    setVenueMinCount(1);
    setCoachId("");
    setCoachFrom("");
    setCoachUntil("");
    setRuleType("PREFERRED");
    clearInputs();
  };

  const submit = () => {
    const payload = build();
    if (null === payload) {
      return;
    }
    if (null !== editingId) {
      // Edit: PUT the existing row, keep it active, then clear the whole form.
      // P2-25 — enregistrer la contrainte ciblée = « on a agi » → le retour nommé s'efface.
      update.mutate({ id: editingId, body: { ...payload, isActive: true } }, { onSuccess: () => (resetForm(), clearDeepLinkOrigin()) });
      return;
    }
    // Create: keep the target/rule for rapid multi-add, clear only the values.
    // In period mode, attach the constraint to the entry → dated (excluded from base). D5 :
    // une datée créée depuis une semaine ENFANT porte l'id de la MÈRE (`sourceEntryId`), sinon
    // `PeriodConstraintSelector` (qui lit `datedConstraintSourceId`) ne la voit jamais.
    create.mutate(null !== sourceEntryId ? { ...payload, calendarEntryId: sourceEntryId } : payload, { onSuccess: clearInputs });
  };

  // Load an existing constraint into the shared form (reverse of build()): resolve
  // the target picker + per-family config back into the controlled inputs.
  const editConstraint = (c: Constraint) => {
    setMode("constraint");
    setFamily(c.family);
    const cfg = c.config;
    // Forced modes (impose/uniquement/au moins une) + coach availability are pinned HARD by
    // build() and hide the rule selector — load them as PREFERRED so that if the
    // user later switches to a soft mode it does NOT stay a hard requirement (the
    // inherited HARD would otherwise leak through, keeping the venue/day forced).
    // DAY "uniquement" (allowedDays) AND "au moins une" (forcedDays) are both HARD-pinned.
    const isForced = ("FACILITY" === c.family && ("string" === typeof cfg.forcedVenueId || "string" === typeof cfg.minAtVenueId)) || ("DAY" === c.family && (Array.isArray(cfg.allowedDays) || Array.isArray(cfg.forcedDays))) || "COACH_AVAILABILITY" === c.family;
    setRuleType(isForced ? "PREFERRED" : c.ruleType);
    // Affinage : on repart propre, la branche « cible = tag » ci-dessous le repeuple si besoin.
    setAndTags(new Set());
    setExceptTags(new Set());
    setRefineOpen(false);
    if ("COACH_AVAILABILITY" === c.family) {
      setCoachId(c.scopeTargetId ?? ""); // SEC-13 : le scope EST la cible (le config ne la porte plus)
      const available = Array.isArray(cfg.availableDays);
      setCoachMode(available ? "available" : "unavailable");
      setDays(new Set(asNums(available ? cfg.availableDays : cfg.unavailableDays)));
      setCoachFrom("string" === typeof cfg.fromTime ? cfg.fromTime : "");
      setCoachUntil("string" === typeof cfg.untilTime ? cfg.untilTime : "");
    } else if ("TEAM" === c.scope && null !== c.scopeTargetId) {
      setTarget(c.scopeTargetId);
    } else {
      // CLUB : reconnaît les DEUX formes — `targetTag` legacy OU `targetTags`/`excludeTags`. La
      // 1re cible devient le tag principal, le reste l'affinage (déplié si non vide, pour que le
      // gestionnaire voie pourquoi le nom est long).
      const targets = targetTagNames(cfg);
      const excludes = excludeTagNames(cfg);
      if (targets.length > 0) {
        setTarget(`tag:${targets[0]}`);
        setAndTags(new Set(targets.slice(1)));
        setExceptTags(new Set(excludes));
        setRefineOpen(targets.length > 1 || excludes.length > 0);
      } else {
        setTarget("");
      }
    }
    if ("TIME" === c.family) {
      setMinTime("string" === typeof cfg.minStartTime ? cfg.minStartTime : "");
      setMaxTime("string" === typeof cfg.maxStartTime ? cfg.maxStartTime : "");
      setEndTime("string" === typeof cfg.maxEndTime ? cfg.maxEndTime : "");
    }
    if ("DAY" === c.family) {
      // Trois clés, trois modes : allowedDays → "uniquement", forcedDays → "au moins une",
      // sinon forbiddenDays → "à éviter".
      if (Array.isArray(cfg.allowedDays)) {
        setDayMode("only");
        setDays(new Set(asNums(cfg.allowedDays)));
      } else if (Array.isArray(cfg.forcedDays)) {
        setDayMode("atLeast");
        setDays(new Set(asNums(cfg.forcedDays)));
      } else {
        setDayMode("forbidden");
        setDays(new Set(asNums(cfg.forbiddenDays)));
      }
    }
    if ("FACILITY" === c.family) {
      if ("string" === typeof cfg.forcedVenueId) {
        setVenueMode("forced");
        setVenueId(cfg.forcedVenueId);
      } else if ("string" === typeof cfg.minAtVenueId) {
        setVenueMode("min");
        setVenueId(cfg.minAtVenueId);
        setVenueMinCount("number" === typeof cfg.minAtVenueCount ? cfg.minAtVenueCount : 1);
      } else if ("string" === typeof cfg.forbiddenVenueId) {
        setVenueMode("forbidden");
        setVenueId(cfg.forbiddenVenueId);
      } else {
        setVenueMode("preferred");
        setVenueId("string" === typeof cfg.preferredVenueId ? cfg.preferredVenueId : "");
      }
    }
    setEditingId(c.id);
    // P4-66 (retour fondateur 2026-08-02 : « je dois scroller ») — le formulaire
    // d'édition est EN HAUT, la ligne cliquée peut être loin plus bas : sans ça
    // le stylo semble ne rien faire. `requestAnimationFrame` laisse React peindre
    // les champs pré-remplis avant qu'on les amène à l'écran. L'appel de la
    // MÉTHODE est optionnel lui aussi : elle n'existe pas partout (jsdom), et ce
    // code vit dans un rAF — hors du filet de React, une absence remonterait en
    // erreur NON GÉRÉE (4 tests voisins pollués avant ce garde-fou).
    requestAnimationFrame(() => formRef.current?.scrollIntoView?.({ block: "center", behavior: "smooth" }));
  };

  // ── P2-25 — porte d'entrée : l'URL peut ouvrir l'onglet et l'éditeur (une fois chacun) ──
  const [searchParams] = useSearchParams();
  const editTarget = searchParams.get("edit");
  const tabTarget = searchParams.get("tab");
  // P2-28 — un diagnostic « règle assouplie » du planning atterrit ici en ciblant SA règle
  // (`?rule=<ruleKey>`) : le panneau des règles du système l'ouvre, la surligne et la scrolle.
  const ruleTarget = searchParams.get("rule");
  const consumedEditRef = useRef<string | null>(null);
  const consumedTabRef = useRef(false);
  const consumedRuleTabRef = useRef(false);
  // `?tab=reserve` (cible du « Retour à la réservation ») → bascule une fois sur l'onglet Réserver.
  useEffect(() => {
    if (consumedTabRef.current || "reserve" !== tabTarget) {
      return;
    }
    consumedTabRef.current = true;
    setMode("reserve");
  }, [tabTarget]);
  // `?rule=<ruleKey>` (cible du « Ajuster cette règle » d'un diagnostic) → bascule une fois sur
  // l'onglet Bien-être, où le panneau surligne et scrolle la règle visée. Clé inconnue → no-op :
  // atterrissage propre (on reste sur l'onglet par défaut, rien n'est surligné).
  useEffect(() => {
    if (consumedRuleTabRef.current || !isWellbeingKey(ruleTarget)) {
      return;
    }
    consumedRuleTabRef.current = true;
    setMode("wellbeing");
  }, [ruleTarget]);
  // `?step=constraints&edit=Y` → ouvre l'éditeur PRÉ-REMPLI sur Y (editConstraint = inverse de
  // build()). Introuvable (id supprimé, autre couche) → no-op : atterrissage propre, jamais un
  // écran cassé. `constraints` en dep : quand la liste charge, on retente et on POSITIONNE.
  useEffect(() => {
    if (null === editTarget || consumedEditRef.current === editTarget) {
      return;
    }
    const target = constraints.find((c) => c.id === editTarget);
    if (undefined === target) {
      return;
    }
    consumedEditRef.current = editTarget;
    // editConstraint (inverse de build()) écrit plusieurs états ; ref consommé = one-shot.
    // eslint-disable-next-line react-hooks/set-state-in-effect -- one-shot : positionne l'éditeur PRÉ-REMPLI sur la contrainte ciblée
    editConstraint(target);
    // P4-95 — DEEP-LINK SEULEMENT : on amène aussi la LIGNE ciblée à l'écran (centrée). Le stylo
    // MANUEL, lui, garde son seul scroll-formulaire (P4-66). rAF planifié APRÈS `editConstraint`
    // (qui a déjà programmé le scroll du formulaire) → il gagne, la ligne finit centrée. Appel de
    // méthode optionnel : `scrollIntoView` n'existe pas en jsdom (même garde que P4-66).
    requestAnimationFrame(() => document.querySelector(`[data-constraint-id="${editTarget}"]`)?.scrollIntoView?.({ block: "center", behavior: "smooth" }));
  }, [editTarget, constraints]);

  const teamPicker = (
    <Select aria-label="Cible" title="Qui est concerné : tout le club, un groupe (tag), ou une équipe précise" className="h-8 w-48" value={target} onChange={(e) => changeTarget(e.target.value)}>
      <option value="">Toutes les équipes</option>
      {/* Groups by axis: Genre, Niveau, Âge (Lot B) — then the teams by tier below. */}
      {groupTagsByAxis(visibleTags).map((group) => (
        <optgroup key={group.label} label={group.label}>
          {group.tags.map((t) => (
            <option key={t.id} value={`tag:${t.name}`}>
              {tagLabel(t.name)}
            </option>
          ))}
        </optgroup>
      ))}
      {groupTeamsByTier(teams, tiers).map((group) => (
        <optgroup key={group.tier?.id ?? "orphan"} label={tierGroupLabel(group.tier)}>
          {group.teams.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
            </option>
          ))}
        </optgroup>
      ))}
    </Select>
  );

  // Les tags proposés à l'affinage : les MÊMES que le sélecteur (`visibleTags`), triés par
  // libellé affiché, MOINS le tag déjà choisi comme cible principale (l'affiner par lui-même
  // n'a pas de sens). Liste simple (pas de sous-groupes d'axe) : deux colonnes de cases restent
  // lisibles ainsi, comme la maquette validée.
  const refineTags = [...visibleTags]
    .filter((t) => t.name !== tagName)
    .sort((a, b) => tagLabel(a.name).localeCompare(tagLabel(b.name), "fr"));
  // « Affiner ce groupe » — visible SEULEMENT quand la cible est un tag (jamais pour « Toutes
  // les équipes », jamais pour une équipe précise). Replié par défaut ; l'affinage compte
  // (`refined`) reste appliqué même replié — d'où le pastillage du lien.
  const refinePanel = isTag ? (
    <div className="w-full basis-full">
      <button
        type="button"
        className="text-xs text-muted-foreground underline decoration-dotted underline-offset-2 hover:text-foreground"
        aria-expanded={refineOpen}
        onClick={() => setRefineOpen((v) => !v)}
      >
        Affiner ce groupe{refined ? " (actif)" : ""}
      </button>
      {refineOpen ? (
        <div className="mt-2 flex flex-wrap gap-x-6 gap-y-3">
          <fieldset className="min-w-0 border-0 p-0">
            <legend className="mb-1 text-xs font-semibold text-muted-foreground">Et aussi (l'équipe doit porter les deux)</legend>
            <div className="flex flex-wrap gap-x-4 gap-y-1">
              {refineTags.map((t) => (
                <label key={t.id} className="flex items-center gap-1 text-xs">
                  <input type="checkbox" checked={andTags.has(t.name)} onChange={() => toggleAnd(t.name)} />
                  {tagLabel(t.name)}
                </label>
              ))}
            </div>
          </fieldset>
          <fieldset className="min-w-0 border-0 p-0">
            <legend className="mb-1 text-xs font-semibold text-muted-foreground">Sauf (retirer ces groupes)</legend>
            <div className="flex flex-wrap gap-x-4 gap-y-1">
              {refineTags.map((t) => (
                <label key={t.id} className="flex items-center gap-1 text-xs">
                  <input type="checkbox" checked={exceptTags.has(t.name)} onChange={() => toggleExcept(t.name)} />
                  {tagLabel(t.name)}
                </label>
              ))}
            </div>
          </fieldset>
        </div>
      ) : null}
    </div>
  ) : null;

  const list = constraints.filter((c) => c.family === family);

  // Sections with a header per group. The grouping DIMENSION depends on the
  // family (shared groupConstraints helper — same on the recap) : coaches by
  // staffing group, gymnase by venue, horaire/jours by tag axis then team.
  const sections = useMemo(
    () =>
      groupConstraints(list, family, {
        // Groupement = NOMMAGE, donc listes COMPLÈTES : une contrainte visant une équipe en
        // pause ou un gymnase désactivé doit rester listée et lisible — la filtrer ici la
        // faisait disparaître de l'écran où on vient la corriger.
        teams: allTeams,
        tiers,
        tags,
        coaches,
        coachPlayerIds: new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId)),
        venues: allVenues,
        coachName: (id) => coachName.get(id) ?? "Coach",
        venueName: (id) => venueName.get(id) ?? "Gymnase",
      }),
    // coachName/venueName are fresh Maps each render; the real inputs are the data.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [list, family, allTeams, tiers, tags, coaches, coachPlayers, allVenues],
  );

  return (
    <div>
      {/* P4-55 — « coach jamais en double » a été RETIRÉ de cette phrase : c'est faux depuis
          D-14 (deux équipes d'un même coach au MÊME gymnase sont autorisées), et le
          raccourci décourageait une pratique que le produit permet. Le détail exact vit
          désormais dans l'onglet « Base », gardé contre la dérive du moteur. */}
      <p className="mb-3 text-sm text-muted-foreground">
        Ici, ajoutez vos préférences et restrictions : ciblez
        <strong> tout le club</strong>, un <strong>groupe</strong> (ex. les jeunes → pas de créneau après 19h50) ou une <strong>équipe</strong> précise. La capacité d'un gymnase se règle
        sur l'écran <strong>Gymnases</strong> (1, 2 ou 3 équipes par créneau). L'onglet <strong>Base</strong> montre ce que le système applique d'office ; <strong>Bien-être</strong> laisse régler les règles de confort.
      </p>

      {/* Même règle qu'au récap : quand les réglages de la période ne sont pas lus, on ne
          masque RIEN — mais on ne laisse pas croire que la liste est celle de la période.
          Le silence ici laissait relier une contrainte à un gymnase désactivé.
          CHARGER ≠ ÉCHOUER : le ton suit l'état, sinon le bandeau d'alerte se déclenche en
          régime normal et n'alerte plus de rien (revue #342 round 2). */}
      {layerNotices.map((notice) => (
        <p
          key={notice.message}
          className={cn("mb-3 rounded-md px-3 py-2 text-sm", notice.pending ? "text-muted-foreground" : "border border-warning/40 bg-warning/10 text-foreground")}
        >
          {notice.message}
        </p>
      ))}

      {/* Family + reservation tabs. « Base » puis « Bien-être » sont les DEUX PREMIERS onglets —
          décision fondateur : plus logique qu'un accordéon. Ce sont des onglets de PRÉSENTATION,
          pas des familles de contrainte (ils ne créent rien : Base est en lecture seule,
          Bien-être règle les implicit_rule_settings). */}
      <div className="mb-3 flex flex-wrap gap-1 border-b border-border">
        {(
          [
            ["base", "Base"],
            ["wellbeing", "Bien-être"],
          ] as const
        ).map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => {
              if (null !== editingId) {
                resetForm();
              }
              setMode(key);
            }}
            className={cn("-mb-px border-b-2 px-3 py-1.5 text-sm", key === mode ? "border-accent font-medium text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}
          >
            {label}
          </button>
        ))}
        {FAMILIES.map((f) => {
          const active = "constraint" === mode && f.key === family;
          return (
            <button
              key={f.key}
              type="button"
              onClick={() => {
                // Switching family cancels any in-progress edit (the form is shared).
                if (null !== editingId) {
                  resetForm();
                }
                setMode("constraint");
                setFamily(f.key);
              }}
              className={cn("-mb-px border-b-2 px-3 py-1.5 text-sm", active ? "border-accent font-medium text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}
            >
              {f.label}
            </button>
          );
        })}
        <button
          type="button"
          onClick={() => {
            if (null !== editingId) {
              resetForm();
            }
            setMode("reserve");
          }}
          className={cn(
            "-mb-px flex items-center gap-1 border-b-2 px-3 py-1.5 text-sm",
            "reserve" === mode ? "border-accent font-medium text-foreground" : "border-transparent text-muted-foreground hover:text-foreground",
          )}
        >
          <Lock className="size-3.5" />
          Réserver
        </button>
      </div>

      {/* Mode période : les contraintes HÉRITÉES du planning principal vivent DANS l'onglet
          de leur famille, juste au-dessus du formulaire d'ajout (fondateur 2026-07-24 —
          plus d'écran à part au-dessus des onglets).
          ⚠️ MONTÉ EN PERMANENCE, masqué en CSS sur l'onglet « Réserver » : PeriodConstraints
          sérialise ses écritures d'override via un état `inflight` interne, qu'un démontage
          perdrait — basculer d'onglet pendant une écriture en vol laisserait passer un
          second POST (create dupliqué → 422 muet, case qui semble ignorer les clics).
          Revue #284 round 1. */}
      {periodEntryId ? (
        <div className={cn("constraint" !== mode && "hidden")} aria-hidden={"constraint" !== mode}>
          <PeriodConstraints calendarEntryId={periodEntryId} family={family} />
        </div>
      ) : null}

      {/* Onglets de présentation : Base (immuables, lecture seule) et Bien-être (réglables). Aucun
          ne crée de contrainte. */}
      {"base" === mode ? (
        <>
          <ProductRulesPanel />
          {/* P2-53 RMM-8 — entrée informative « Trajet entre gymnases », visible seulement si une
              matrice existe (opt-in dérivé serveur-side). Lecture seule : aucun rail d'intensité. */}
          <TravelRuleNotice />
        </>
      ) : "wellbeing" === mode ? (
        // Même ancrage que « Réserver » : en période le panneau règle la COPIE
        // du plan (schedulePlanId), jamais le socle du club ; hors période, la saison (null).
        periodMode ? (
          <PeriodAnchorGate
            anchor={anchor}
            loadingLabel="Chargement du planning de la période…"
            errorLabel="Impossible de charger le planning de la période."
          >
            {(schedulePlanId) => <WellbeingRulesPanel ruleTarget={ruleTarget} schedulePlanId={schedulePlanId} />}
          </PeriodAnchorGate>
        ) : (
          <WellbeingRulesPanel ruleTarget={ruleTarget} schedulePlanId={null} />
        )
      ) : "reserve" === mode ? (
        // Une seule échelle d'états pour l'ancre — la PORTE. Le premier jet
        // re-implémentait ses quatre cas en ternaire imbriqué : les libellés
        // divergeaient déjà entre les deux copies au round suivant.
        periodMode ? (
          <PeriodAnchorGate
            anchor={anchor}
            loadingLabel="Chargement du planning de la période…"
            errorLabel="Impossible de charger le planning de la période."
          >
            {(schedulePlanId) => <ReservationPanel teams={allTeams} pausedTeamIds={pausedIds} tiers={tiers} venues={reservationVenues} disabledVenueIds={disabledIds} schedulePlanId={schedulePlanId} entryId={periodEntryId} />}
          </PeriodAnchorGate>
        ) : (
          <ReservationPanel teams={allTeams} pausedTeamIds={pausedIds} tiers={tiers} venues={reservationVenues} disabledVenueIds={disabledIds} schedulePlanId={null} entryId={periodEntryId} />
        )
      ) : (
        <>
          {/* Per-family add form */}
          <div ref={formRef} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-border bg-card p-3">
        {("TIME" === family || "DAY" === family || "FACILITY" === family) && teamPicker}
        {("TIME" === family || "DAY" === family || "FACILITY" === family) && refinePanel}

        {"TIME" === family && (
          <>
            <label className="text-xs text-muted-foreground">
              Pas avant
              <Input aria-label="Pas avant" type="time" className="mt-0.5 h-8 w-28" value={minTime} onChange={(e) => setMinTime(e.target.value)} />
            </label>
            <label className="text-xs text-muted-foreground">
              Pas après
              <Input aria-label="Pas après" type="time" className="mt-0.5 h-8 w-28" value={maxTime} onChange={(e) => setMaxTime(e.target.value)} />
            </label>
            <label className="text-xs text-muted-foreground">
              Fini avant
              <Input aria-label="Fini avant" type="time" className="mt-0.5 h-8 w-28" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
            </label>
          </>
        )}

        {"DAY" === family && (
          <>
            <Select aria-label="Type de jour" className="h-8 w-36" value={dayMode} onChange={(e) => setDayMode(e.target.value as "forbidden" | "only" | "atLeast")}>
              <option value="forbidden">à éviter</option>
              <option value="only">uniquement</option>
              <option value="atLeast">au moins une</option>
            </Select>
            <DayPicker days={days} toggle={toggleDay} legend={"only" === dayMode ? "Seuls jours autorisés" : "atLeast" === dayMode ? "Au moins une séance l'un de ces jours" : "Jours à éviter"} />
          </>
        )}

        {"FACILITY" === family && (
          <>
            <Select aria-label="Préférence" className="h-8 w-28" value={effectiveVenueMode} onChange={(e) => setVenueMode(e.target.value as "preferred" | "forbidden" | "forced" | "min")}>
              <option value="preferred">préfère</option>
              <option value="forbidden">évite</option>
              <option value="forced">impose</option>
              {/* « Au moins N ici » = un compte PAR ÉQUIPE. Une équipe précise ou un
                  GROUPE conviennent (l'éclatement par tag produit N contraintes par
                  équipe) ; seul « Toutes les équipes » se ferme — avant, la contrainte
                  se créait puis bloquait le récap (BCCL 2026-08-05, en anglais brut). */}
              <option value="min" disabled={!minAllowed} title={!minAllowed ? "Choisissez une équipe ou un groupe dans « Cible »" : undefined}>
                au moins{!minAllowed ? " (équipe ou groupe requis)" : ""}
              </option>
            </Select>
            {"min" === effectiveVenueMode && (
              <label className="text-xs text-muted-foreground">
                Combien
                <Input aria-label="Nombre de séances" type="number" min={1} className="mt-0.5 h-8 w-16" value={venueMinCount} onChange={(e) => setVenueMinCount(Math.max(1, Number(e.target.value) || 1))} />
              </label>
            )}
            <VenueSelect
              aria-label="Gymnase"
              className="h-8"
              wrapperClassName="w-48"
              placeholder="— gymnase —"
              venues={sortByName(editVenues).map((v) => ({ id: v.id, name: v.name + (disabledIds.has(v.id) ? " (désactivé pour cette période)" : ""), color: v.color }))}
              value={venueId}
              onChange={(e) => setVenueId(e.target.value)}
            />
          </>
        )}

        {"COACH_AVAILABILITY" === family && (
          <>
            <Select aria-label="Coach" className="h-8 w-44" value={coachId} onChange={(e) => setCoachId(e.target.value)}>
              <option value="">— coach —</option>
              {(
                [
                  ["Salariés", coachGroups.salaried],
                  ["Coachs-joueurs", coachGroups.player],
                  ["Bénévoles", coachGroups.other],
                ] as const
              ).map(([label, group]) =>
                group.length > 0 ? (
                  <optgroup key={label} label={label}>
                    {group.map((c) => (
                      <option key={c.id} value={c.id}>
                        {coachName.get(c.id)}
                      </option>
                    ))}
                  </optgroup>
                ) : null,
              )}
            </Select>
            <Select aria-label="Disponibilité" className="h-8 w-44" value={coachMode} onChange={(e) => setCoachMode(e.target.value as "unavailable" | "available")}>
              <option value="unavailable">indisponible</option>
              <option value="available">disponible uniquement</option>
            </Select>
            <DayPicker days={days} toggle={toggleDay} legend={"available" === coachMode ? "Jours de disponibilité exclusive" : "Jours d'indisponibilité"} />
            {/* Lot C: optional time window on the selected days (empty = whole day). */}
            <label className="flex items-center gap-1 text-xs text-muted-foreground">
              de
              <Input type="time" aria-label="Heure de début" className="h-8 w-28" value={coachFrom} onChange={(e) => setCoachFrom(e.target.value)} />
            </label>
            <label className="flex items-center gap-1 text-xs text-muted-foreground">
              à
              <Input type="time" aria-label="Heure de fin" className="h-8 w-28" value={coachUntil} onChange={(e) => setCoachUntil(e.target.value)} />
            </label>
          </>
        )}

        {"COACH_AVAILABILITY" === family || ("TIME" === family && "" !== endTime) || ("DAY" === family && "forbidden" !== dayMode) || ("FACILITY" === family && ("forced" === effectiveVenueMode || "min" === effectiveVenueMode)) ? (
          // Coach availability + "impose"/"uniquement"/"au moins une" + "Fini avant" are
          // ALWAYS hard (a person can't be in two places; a forced venue, a whitelist/at-least
          // day rule, and a gym-closing end-bound are musts, not nudges) — the payload pins
          // HARD, so a rule selector here would be a lie.
          <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">Obligatoire</span>
        ) : (
          <Select aria-label="Règle" className="h-8 w-28" value={ruleType} onChange={(e) => setRuleType(e.target.value as ConstraintRuleType)}>
            {RULES.map((r) => (
              <option key={r} value={r}>
                {RULE_LABEL[r]}
              </option>
            ))}
          </Select>
        )}
        {null !== editingId && (
          <Button size="sm" variant="ghost" className="ml-auto h-8" onClick={resetForm} title="Annuler la modification">
            Annuler
          </Button>
        )}
        <Button
          size="icon"
          className={cn("size-8", null === editingId && "ml-auto")}
          onClick={submit}
          disabled={create.isPending || update.isPending}
          title={null !== editingId ? "Enregistrer la contrainte" : "Ajouter la contrainte"}
          aria-label={null !== editingId ? "Enregistrer la contrainte" : "Ajouter la contrainte"}
        >
          {null !== editingId ? <Check className="size-4" /> : <Plus className="size-4" />}
        </Button>
      </div>

      {/* List for the active family — grouped by group (tag) then team (ranked). */}
      {0 === list.length ? (
        <EmptyHint>Aucune contrainte dans cette famille.</EmptyHint>
      ) : (
        // P4-107 (4ᵉ tranche) — un vrai `<table>` avec `<thead>`/`<tbody>`, pas une grille de
        // `<div>` : c'est la seule règle de sévérité HAUTE rendue par la passe de design sur ce
        // lot, et c'est ce qui fait annoncer « Règle : pas après » par un lecteur d'écran au
        // lieu d'une bouillie de cellules. `overflow-x-auto` garde la promesse sur petit écran.
        // ⚠ Le tableau est BORNÉ, et c'est le même raisonnement que le reste du lot : cinq
        // colonnes de contenu court étalées sur 1650 px rejouent le défaut qu'on corrige —
        // les actions se retrouvent à ~700 px du libellé qu'elles concernent. La ligne doit
        // se lire comme UNE unité. (Choix ergonomique : le corpus de design est muet sur la
        // largeur d'un tableau de données — cf. `frontend-spec.md` §6.9.)
        <div className="max-w-5xl overflow-x-auto rounded-lg border border-border">
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                <th scope="col" className="px-3 py-2 font-semibold">Cible</th>
                <th scope="col" className="px-3 py-2 font-semibold">Règle</th>
                <th scope="col" className="px-3 py-2 font-semibold">Valeur</th>
                <th scope="col" className="px-3 py-2 font-semibold">Niveau</th>
                {/* Colonne d'actions : nommée pour le lecteur d'écran, muette à l'œil. */}
                <th scope="col" className="px-3 py-2 font-semibold"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            {sections.map((section) => (
              <tbody key={section.key}>
                <tr>
                  {/* `rowgroup` : cette cellule TITRE un groupe de lignes, elle n'est pas un
                      en-tête de colonne — sans quoi elle se glisserait parmi les cinq. */}
                  <th scope="rowgroup" colSpan={5} data-testid="constraint-section" className="bg-muted/40 px-3 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {section.label}
                  </th>
                </tr>
                {section.items.map((c: Constraint) => {
                  // Le VOCABULAIRE vient du foyer unique (`describeConstraint`), celui-là même
                  // que lit le panneau de créneau du planning — jamais du `name`, texte libre
                  // qui peut être périmé ou copié d'une autre règle (docblock du module).
                  const parts = constraintPredicateParts(c, (id) => venueName.get(id));
                  const target = constraintTarget(c, { venueName: (id) => venueName.get(id), teamName: (id) => teamName.get(id), coachName: (id) => coachName.get(id), tagLabel });

                  return (
                    <tr key={c.id} data-constraint-id={c.id} className={cn("border-b border-border/60 last:border-0", editingId === c.id ? "bg-accent/10 ring-1 ring-inset ring-accent" : "")}>
                      <td className="px-3 py-2 align-top">{target ?? "—"}</td>
                      {0 === parts.length ? (
                        // Règle non descriptible fidèlement (clé inconnue, gymnase supprimé) : on
                        // rend le NOM en entier plutôt qu'une cellule vide, qui laisserait croire
                        // qu'il n'y a rien à appliquer.
                        <td className="px-3 py-2 align-top text-muted-foreground" colSpan={2}>{c.name}</td>
                      ) : (
                        <>
                          <td className="px-3 py-2 align-top">
                            {parts.map((part, i) => (
                              <div key={`v${i}`}>{part.verb}</div>
                            ))}
                          </td>
                          <td className="px-3 py-2 align-top font-medium">
                            {parts.map((part, i) => (
                              <div key={`w${i}`}>{part.value}</div>
                            ))}
                          </td>
                        </>
                      )}
                      <td className="px-3 py-2 align-top">
                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{RULE_LABEL[c.ruleType]}</span>
                      </td>
                      <td className="px-3 py-2 align-top">
                        {/* `p-1.5 -m-1.5` : 28 px cliquables autour d'une icône de 16, SANS
                            épaissir la ligne — la passe de design nommait `w-6 h-6` comme le
                            mauvais exemple de cible de clic. */}
                        <div className="flex items-center justify-end gap-1">
                          <button type="button" aria-label="Modifier" className="-m-1.5 rounded p-1.5 text-muted-foreground hover:text-foreground" onClick={() => editConstraint(c)}>
                            <Pencil className="size-4" />
                          </button>
                          <button type="button" aria-label="Supprimer" className="-m-1.5 rounded p-1.5 text-muted-foreground hover:text-destructive" onClick={() => setPendingDelete(c)}>
                            <Trash2 className="size-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            ))}
          </table>
        </div>
      )}
        </>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title="Supprimer cette contrainte ?"
        description={pendingDelete ? <>« {pendingDelete.name} » sera définitivement supprimée.</> : null}
        confirmLabel="Supprimer"
        onCancel={() => setPendingDelete(null)}
        onConfirm={() => {
          if (pendingDelete) {
            del.mutate(pendingDelete.id);
          }
          setPendingDelete(null);
        }}
      />
    </div>
  );
}
