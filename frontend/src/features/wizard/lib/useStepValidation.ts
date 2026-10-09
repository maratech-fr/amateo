import { anchorIsWritable, usePeriodAnchor } from "@/features/cockpit/queries";
import { useVenueMatchWindows } from "@/features/matches/queries";
import { readFailed, readLoading } from "@/shared/lib/readState";
import type { Reservation, Team, Venue, VenueTrainingSlot } from "../api";
import { useConstraintValidation, usePeriodSlots, useReservations, useTeamPeriodOverrides, useVenuePeriodOverrides, useVenueSlots, useWizardCoachPlayers, useWizardCoaches, useWizardTeamCoaches, useWizardTeams, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";
import { DAY_LABEL_LONG } from "@/shared/lib/days";

import { activeTeams, activeVenues, pausedTeamIds } from "./activeLayer";
import { dayLabel, hhmm } from "./days";
import { effectiveSlotCapacity, sharedSlotStatuses, slotKey } from "./reservationSlots";
import { okValidation, type RecapNotice, type StepValidation, type WizardStepId } from "./steps";

// D-22 : libellés longs partagés (index 0 vide EXPRÈS — le jour ISO commence à 1).
const DAY_LABELS = DAY_LABEL_LONG;

/**
 * Non-blocking warnings on pre-generation reservations (W6). These never gate
 * "Suivant": the solver stays the authority and returns INFEASIBLE + diagnostics
 * if a reservation set is truly unsatisfiable. We only surface likely mistakes
 * at entry time.
 *   1. A slot shared by more teams than the venue slot allows (canSplit + capacity).
 *   2. A team reserved more slots than its sessions/week.
 *   3. A team with two sessions on the same day.
 */
export function computeReservationWarnings(reservations: Reservation[], teams: Team[], venues: Venue[], slots: VenueTrainingSlot[]): string[] {
  const warnings: string[] = [];
  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));
  const venueSplit = new Map(venues.map((v) => [v.id, v.canSplit]));
  // D-02/D-03 : `slotKey` et la capacité effective viennent de `reservationSlots` — la porte
  // en avait sa PROPRE copie, et les deux avaient divergé sur les deux points qui comptent :
  // la clé n'y normalisait pas l'heure (les réservations stockent « 18:00 », les créneaux
  // peuvent porter « 18:00:00 » : la Map ne retrouvait alors pas le créneau, `?? 1`
  // s'appliquait) et un gymnase pas encore chargé y valait 1 au lieu de sa capacité. L'étape
  // criait « max 1 » pendant que le Récap, branché sur la source, annonçait « 2 places ».
  const slotCapacity = new Map(slots.map((s) => [slotKey(s.venueId, s.dayOfWeek, s.startTime), effectiveSlotCapacity(s, venueSplit)]));
  // v2 — la capacité BRUTE (avant clamp `canSplit`) à côté de l'effective : c'est l'écart entre
  // les deux qui NOMME la cause. Un créneau saisi à 2 mais effectif à 1 sur un gymnase non
  // divisible n'est pas « max 1 » au hasard — c'est la divisibilité qui manque, et on le dit.
  const slotRawCapacity = new Map(slots.map((s) => [slotKey(s.venueId, s.dayOfWeek, s.startTime), s.capacity]));

  // Rule 1 — same slot shared by more distinct teams than allowed.
  const bySlot = new Map<string, Set<string>>();
  for (const r of reservations) {
    const key = slotKey(r.venueId, r.dayOfWeek, r.startTime);
    bySlot.set(key, (bySlot.get(key) ?? new Set()).add(r.teamId));
  }
  bySlot.forEach((teamIds, key) => {
    const [venueId, day, start] = key.split("|");
    const allowed = slotCapacity.get(key) ?? 1;
    if (teamIds.size > allowed) {
      // Cause NOMMÉE : le créneau a été saisi pour ≥ 2 équipes (capacité brute), mais le gymnase
      // n'est PAS déclaré divisible (test STRICT sur false — jamais sur `undefined`, cas gymnase
      // pas encore chargé), donc l'effectif retombe à 1. Ici on dit QUOI corriger. Présentation
      // (choix de libellé), pas une règle métier : le clamp vit dans `effectiveSlotCapacity`.
      if (1 === allowed && (slotRawCapacity.get(key) ?? 1) >= 2 && false === venueSplit.get(venueId)) {
        warnings.push(`Créneau partagé par ${teamIds.size} équipes : le gymnase ${venueName.get(venueId) ?? "?"} n'est pas déclaré divisible — cochez « terrain divisible » dans l'étape Gymnases.`);
      } else {
        warnings.push(`Créneau partagé par ${teamIds.size} équipes (max ${allowed}) : ${venueName.get(venueId) ?? "?"} ${DAY_LABELS[Number(day)] ?? ""} ${start}.`);
      }
    }
  });

  // Rules 2 & 3 — per team: total count vs sessions/week, and same-day duplicates.
  const byTeam = new Map<string, Reservation[]>();
  for (const r of reservations) {
    byTeam.set(r.teamId, [...(byTeam.get(r.teamId) ?? []), r]);
  }
  byTeam.forEach((group, teamId) => {
    // P3-20 (décision fondateur 2026-08-06) — « plus de réservations que de séances » a
    // quitté cet écran : c'est une INCOHÉRENCE, plus un avertissement, et le verdict est
    // rendu par le SERVEUR (`ValidateConstraintsController::overBookedTeamBlockers`, où
    // vivent déjà les bloqueurs). Le laisser ici en warning ferait deux vérités : l'écran
    // dirait « à surveiller » pendant que la porte refuse de s'ouvrir (§7.2 pt 2).
    const perDay = new Map<number, number>();
    for (const r of group) {
      perDay.set(r.dayOfWeek, (perDay.get(r.dayOfWeek) ?? 0) + 1);
    }
    perDay.forEach((count, day) => {
      if (2 <= count) {
        warnings.push(`${teamName.get(teamId) ?? "?"} : ${count} séances le même jour (${DAY_LABELS[day] ?? ""}).`);
      }
    });
  });

  return warnings;
}

/** Venues that carry no availability slot — the "gym without slot" rule, shared
 * by the venues and recap gates. P1-4 PR B : un gymnase à fenêtre MATCH est
 * épargné (loué pour les matchs seulement, il n'a légitimement aucun créneau
 * d'entraînement — ni de saison, ni de période).
 *
 * ⚑ D-24 — `WizardLayout` écrivait « la règle vit à TROIS sites, ils bougent ensemble » :
 * la porte (ici), le bandeau (`VenuesStep`) et l'atterrissage guidé (`WizardLayout`). Une
 * exemption ajoutée à un seul donne soit un bandeau rouge sans blocage — le gestionnaire ne
 * comprend pas ce qu'on lui reproche — soit un « Suivant » bloqué SANS message, l'écran ne
 * montrant rien à corriger. Les trois appellent désormais ce prédicat ; les libellés, eux,
 * restent propres à chaque site. */
export function venuesWithoutSlot(venues: Venue[], slots: VenueTrainingSlot[], matchVenueIds: ReadonlySet<string>): Venue[] {
  const withSlot = new Set(slots.map((s) => s.venueId));
  return venues.filter((v) => !withSlot.has(v.id) && !matchVenueIds.has(v.id));
}

/**
 * Validation of a step for the "Suivant" gate + nav badges. Blocking rules per
 * step: ≥1 team; every gym has ≥1 availability slot; ≥1 coach. The constraints
 * step only produces non-blocking reservation warnings (see above).
 */
export function useStepValidation(stepId: WizardStepId): StepValidation {
  const teamsQuery = useWizardTeams();
  const venuesQuery = useWizardVenues();
  const slotsQuery = useVenueSlots();
  const coachesQuery = useWizardCoaches();
  // P1-4 PR B — l'exemption « fenêtre match » de la règle gymnase-sans-créneau.
  // En échec de lecture : AUCUNE exemption (plus strict, jamais moins) — le
  // bandeau de l'étape porte l'explication, le gate ne ment pas en vert.
  const matchWindowsQuery = useVenueMatchWindows();
  const { data: teams = [] } = teamsQuery;
  const { data: venues = [] } = venuesQuery;
  const { data: slots = [] } = slotsQuery;
  const { data: coaches = [] } = coachesQuery;
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  // Period (secondary planning) mode — keyed on the mode itself, NOT the entry id:
  // a period with a not-yet-resolved calendarEntryId is still period mode, and the
  // slots there are inherited & read-only regardless.
  const periodMode = useWizardStore((s) => "period" === s.mode);
  const periodEntryId = useWizardStore((s) => (s.mode === "period" ? s.calendarEntryId : null));
  // Reservations are server-backed now (base vs period overlay), not the client store.
  // Elles pendent au PLAN (inv. 5, lot C3) : le composant tient le déclencheur, il en
  // résout l'ancre.
  // Hors ancre certaine, ne PAS lire : on servirait le socle.
  const periodAnchor = usePeriodAnchor(periodEntryId);
  // En mode période, seul l'état `period` autorise la lecture : `base` (mode période
  // rehydraté sans entrée) lirait le SOCLE et le présenterait comme la période.
  const { data: reservations = [] } = useReservations(periodAnchor.planId, periodMode ? "period" === periodAnchor.state : anchorIsWritable(periodAnchor));
  // #8 PR-B — une période POSSÈDE désormais sa grille (elle n'est plus héritée en lecture
  // seule) : la règle « gymnase sans créneau » doit donc s'y appliquer AUSSI, mais sur les
  // créneaux de la PÉRIODE et en épargnant les gymnases explicitement désactivés (dont
  // l'absence de créneau servi est voulue). Sans elle, vider une grille passait « Suivant »
  // sans un mot et la période se générait à vide (revue #8 PR-B).
  const periodSlotsQuery = usePeriodSlots(periodMode ? periodAnchor.planId : null);
  const periodSlots = periodSlotsQuery.data ?? [];
  const periodOverridesQuery = useVenuePeriodOverrides(periodMode ? periodAnchor.planId : null);
  const periodOverrides = periodOverridesQuery.data ?? [];
  // La SÉLECTION d'équipes de la période : le verdict ne la lisait pas, donc le récap
  // pouvait rester vert pendant que le panneau Équipes affichait « Impossible de charger
  // la sélection ». Deux écrans, deux vérités — le défaut que cette PR corrige ailleurs.
  // Même clé react-query que le panneau : pas de requête supplémentaire en pratique.
  const periodTeamOverridesQuery = useTeamPeriodOverrides(periodMode ? periodAnchor.planId : null);
  // The pre-solve constraint check is only needed for the recap verdict, and only
  // while the user is actually on the recap OR generate step — firing it on every
  // earlier step is a wasted backend round-trip.
  const currentStepId = useWizardStore((s) => s.stepId);
  const constraintNeeded = "recap" === stepId && ("recap" === currentStepId || "generate" === currentStepId);
  const constraintQuery = useConstraintValidation(constraintNeeded, periodEntryId);
  const constraintValidation = constraintQuery.data;

  // On first load the queries default to [], which would flash a false blocking
  // error ("Ajoutez au moins une équipe") before the data arrives. Stay neutral
  // but mark pending so gates stay closed (isLoading = first load only).
  if (teamsQuery.isLoading || venuesQuery.isLoading || slotsQuery.isLoading || coachesQuery.isLoading || matchWindowsQuery.isLoading) {
    return { errors: [], warnings: [], pending: true };
  }

  // P4-20 — l'ancre de la période n'a PAS pu être chargée : on ne sait rien de sa
  // grille ni de ses réglages. Un verdict « prêt » serait un mensonge vert sur une
  // période cassée (décision fondateur : dans le doute, on bloque et on le DIT) ;
  // `pending` seul ressemblerait à « ça charge », le travers qu'on corrige.
  if ("failed" === periodAnchor.state) {
    return { errors: ["La période n'a pas pu être chargée — impossible de vérifier ses réglages."], warnings: [] };
  }
  if (periodMode && "absent" === periodAnchor.state) {
    return { errors: ["Cette période n'a pas encore d'espace de travail — utilisez « Adapter » pour en créer un."], warnings: [] };
  }
  // Mode période SANS entrée résolue (store rehydraté partiellement) : l'ancre vaut
  // `base`. Sans ce blocage, le verdict sortait VERT — calculé sur le socle — pendant
  // que ConstraintsStep affichait « Aucune période sélectionnée » : deux vérités.
  if (periodMode && "base" === periodAnchor.state) {
    return { errors: ["Aucune période sélectionnée — revenez au calendrier et rouvrez la période."], warnings: [] };
  }
  // Ancre encore en vol : neutre mais bloquant, comme les autres premiers chargements.
  if (periodMode && "loading" === periodAnchor.state) {
    return { errors: [], warnings: [], pending: true };
  }
  // P4-1 au niveau du VERDICT : la grille de période est ces deux requêtes. Un GET raté
  // laissait `[]` passer pour une grille vide et fabriquait un blocage « Gymnase(s) sans
  // créneau » nommant TOUS les gymnases — sur une grille en fait pleine. Le panneau, lui,
  // affichait correctement l'échec : deux écrans, deux vérités.
  if (periodMode && (readFailed(periodSlotsQuery) || readFailed(periodOverridesQuery))) {
    return { errors: ["La grille de la période n'a pas pu être chargée — impossible de vérifier ses créneaux."], warnings: [] };
  }
  if (periodMode && readFailed(periodTeamOverridesQuery)) {
    return { errors: ["La sélection d'équipes de la période n'a pas pu être chargée — impossible de la vérifier."], warnings: [] };
  }
  // Grille encore en vol : neutre mais PENDING — le gate de génération reste fermé.
  // Sans ça, `periodGridReady` faux vidait simplement `emptyVenues` : verdict vert et
  // « Générer » cliquable pendant la fenêtre de chargement d'une grille peut-être vide —
  // la faille exacte que la règle « gymnase sans créneau » existe pour fermer.
  if (periodMode && "period" === periodAnchor.state && (readLoading(periodSlotsQuery) || readLoading(periodOverridesQuery) || readLoading(periodTeamOverridesQuery))) {
    return { errors: [], warnings: [], pending: true };
  }

  // P2-15 — le VERDICT compte ce que le récap AFFICHE : les actifs de la période. Sans ça
  // (revue #342 round 2), une période dont toutes les équipes sont en pause annonçait
  // « Équipes 0 » et « Tout est prêt » dans la même vue, bouton « Générer » ouvert — la
  // génération partait et rendait un planning vide. Un écran qui compte autrement que sa
  // porte, ce sont deux vérités : le défaut que ce lot corrige partout ailleurs.
  // Passé les retours ci-dessus, les deux lectures d'overrides sont RÉSOLUES en période.
  // D-23 — ces quatre règles étaient RÉÉCRITES ici alors qu'`activeLayer` les porte, et que
  // P2-15 l'avait extrait précisément pour qu'il n'y ait qu'un foyer. Le récap lit la source,
  // la porte lisait sa copie : la prochaine règle ajoutée au foyer (un `BLANK` qui retirerait
  // aussi le gymnase, un `isActive` serveur) aurait fait diverger le verdict de l'affichage —
  // le défaut que le commentaire ci-dessus dénonce, commis quatre lignes plus bas.
  const pausedIds = pausedTeamIds(periodTeamOverridesQuery.data ?? []);
  const layerTeams = periodMode ? activeTeams(teams, pausedIds) : teams;
  const layerVenues = periodMode ? activeVenues(venues, periodOverrides) : venues;
  // Le message doit dire QUOI FAIRE : « ajoutez une équipe » sur une période dont les
  // équipes existent mais sont toutes en pause enverrait le gestionnaire au mauvais écran.
  const noTeamError = periodMode && teams.length > 0 ? "Aucune équipe n'est active pour cette période — cochez-en au moins une." : "Ajoutez au moins une équipe.";
  const noVenueError = periodMode && venues.length > 0 ? "Aucun gymnase n'est actif pour cette période — réactivez-en au moins un." : "Ajoutez au moins un gymnase.";

  if ("teams" === stepId) {
    return { errors: 0 === layerTeams.length ? [noTeamError] : [], warnings: [] };
  }
  // Les gymnases qui bloquent : sur le socle, tout gymnase sans créneau ; en période,
  // tout gymnase ACTIF (non désactivé) sans créneau de la période — un gymnase désactivé
  // n'a volontairement rien à servir.
  // En période, on n'évalue la règle QUE lorsque le plan est résolu : sans lui les
  // créneaux de la période ne sont pas encore lus (query désactivée → []), et bloquer là
  // dessus serait un faux « sans créneau » pendant le chargement. Un gymnase désactivé est
  // épargné : son absence de créneau servi est voulue.
  // period_slots vient d'une query SÉPARÉE qui ne démarre qu'une fois planId connu : il
  // faut attendre qu'elle ait CHARGÉ, pas seulement que le plan soit résolu, sinon on
  // confond « pas encore chargé » (undefined→[]) et « vraiment vide » et on crie « sans
  // créneau » sur une grille pleine (revue #8 PR-B round 2).
  // Les DEUX queries portent la grille : `periodSlots` dit les créneaux,
  // `periodOverrides` dit quels gymnases sont désactivés (donc légitimement sans
  // créneau). Armer la règle sur l'une sans l'autre criait « gymnase sans créneau »
  // sur un gymnase volontairement désactivé, le temps que les overrides arrivent.
  const periodGridReady =
    "period" === periodAnchor.state && !readLoading(periodSlotsQuery) && !readLoading(periodOverridesQuery);
  const matchVenueIds = new Set((matchWindowsQuery.data ?? []).map((w) => w.venueId));
  const emptyVenues = periodMode
    ? (periodGridReady ? venuesWithoutSlot(layerVenues, periodSlots, matchVenueIds) : [])
    : venuesWithoutSlot(venues, slots, matchVenueIds);

  if ("venues" === stepId) {
    const errors: string[] = [];
    if (0 === layerVenues.length) {
      errors.push(noVenueError);
    }
    if (emptyVenues.length > 0) {
      errors.push(`Gymnase(s) sans créneau d'entraînement ni fenêtre match : ${emptyVenues.map((v) => v.name).join(", ")}.`);
    }
    return { errors, warnings: [] };
  }
  if ("coaches" === stepId) {
    const linked = new Set([...teamCoaches.map((l) => l.coachId), ...coachPlayers.map((l) => l.coachId)]);
    const unlinked = coaches.filter((c) => !linked.has(c.id));
    return {
      errors: 0 === coaches.length ? ["Ajoutez au moins un coach."] : [],
      warnings: unlinked.length > 0 ? [`Coach(s) sans équipe : ${unlinked.map((c) => `${c.firstName} ${c.lastName}`.trim()).join(", ")}.`] : [],
    };
  }
  if ("constraints" === stepId) {
    return { errors: [], warnings: computeReservationWarnings(reservations, teams, venues, slots) };
  }
  if ("recap" === stepId) {
    const errors: string[] = [];
    if (0 === layerTeams.length) {
      errors.push(noTeamError);
    }
    if (0 === coaches.length) {
      errors.push("Ajoutez au moins un coach.");
    }
    if (0 === layerVenues.length) {
      errors.push(noVenueError);
    }
    if (emptyVenues.length > 0) {
      errors.push(`Gymnase(s) sans créneau d'entraînement ni fenêtre match : ${emptyVenues.map((v) => v.name).join(", ")}.`);
    }
    if (constraintValidation && !constraintValidation.valid) {
      for (const messages of Object.values(constraintValidation.errors)) {
        // Messages FRANÇAIS et nommés par le serveur (source unique depuis le
        // 2026-08-05 — la carte de traduction locale avait dérivé en silence).
        errors.push(...messages);
      }
      for (const conflict of constraintValidation.conflicts) {
        errors.push(conflict.reason);
      }
      // P2-9 PR B — impossibilités physiques (un coach à deux endroits en même temps).
      // Déjà formulées par le serveur, avec le gymnase et l'heure : pas de humanize, il
      // effacerait l'information qui dit QUELLE réservation retirer.
      errors.push(...(constraintValidation.blockers ?? []));
    }
    // Until the pre-solve check resolves, report pending so the generate gate
    // stays closed rather than briefly allowing a launch on an invalid setup.
    if (constraintNeeded && constraintQuery.isError) {
      // Le pré-solve n'a pas pu tourner : sans ce blocage le verdict sortait vert et la
      // génération partait sans la vérification que ce gate existe pour imposer — pour
      // échouer minutes plus tard en FAILED/INFEASIBLE inexpliqué.
      errors.push("La vérification des contraintes n'a pas pu être effectuée — réessayez avant de générer.");
    }

    // D2 (lot 2) — les notices de créneau partagé sont désormais FONDUES dans le verdict (source
    // unique lue par le rail, l'accordéon et le gate) plutôt que recalculées dans RecapStep. Même
    // couche que le reste du récap : les créneaux de la grille éditée (période ou socle) et les
    // réservations de l'ancre, canSplit lu des gymnases. La LOGIQUE vit dans `sharedSlotStatuses`.
    const gridSlots = periodMode ? periodSlots : slots;
    const canSplit = new Map(venues.map((v) => [v.id, v.canSplit]));
    const venueNameById = new Map(venues.map((v) => [v.id, v.name]));
    const notices: RecapNotice[] = sharedSlotStatuses(gridSlots, reservations, canSplit).map((s) => ({
      key: s.slot.id,
      // Partiel = une place se PERD (avertissement orange) ; non réservé = information neutre
      // (le système choisira, gris). Mêmes deux tons et mêmes textes qu'auparavant côté récap.
      tone: "partial" === s.kind ? "warning" : "muted",
      place: `${venueNameById.get(s.slot.venueId) ?? "?"} · ${dayLabel(s.slot.dayOfWeek)} ${hhmm(s.slot.startTime)}`,
      message:
        "partial" === s.kind
          ? `${s.reservedTeamIds.length} équipe(s) réservée(s) sur ${s.capacity} places — le système ne complétera pas ce créneau, la place restante restera vide. Réservez aussi l'autre équipe, ou retirez la réservation pour laisser le système choisir.`
          : `créneau partagé (${s.capacity} places) sans réservation — le système associera les équipes lui-même. Pour choisir lesquelles, réservez-les (étape Contraintes, onglet Réserver).`,
    }));

    // Les avertissements du serveur — une contrainte écartée du payload parce qu'elle
    // nomme un gymnase désactivé. ⚠ LUS HORS du `if (!valid)` ci-dessus : ils n'invalident
    // rien (règle #8), donc ils arrivent précisément avec `valid: true`. Pas de
    // `humanizeConstraintError` : le serveur nomme déjà la contrainte ET le gymnase,
    // l'humaniser effacerait ce qui permet d'agir. Le récap les rend dans son accordéon.
    return { errors, warnings: constraintValidation?.warnings ?? [], notices, pending: constraintNeeded && constraintQuery.isLoading };
  }
  return okValidation();
}
