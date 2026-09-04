import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { SharedTrainingBlock } from "../api";

const h = { reservations: [] as Array<Record<string, unknown>> };
// P2-27 — les groupes de mutualisation affichés au récap.
const sharedBlocksState: { data: SharedTrainingBlock[] } = { data: [] };

type TeamRow = { id: string; name: string; sportCategoryId: string; priorityTierId: number; tierOrder: number; gender: null; level: null; sessionsPerWeek: number; isActive: boolean };
const team = (id: string, name: string, tier: number): TeamRow => ({ id, name, sportCategoryId: "c", priorityTierId: tier, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true });

// P2-15 : la COUCHE que le récap décrit — période (équipes/gymnases actifs) ou socle.
const deleteReservationMock = vi.hoisted(() => vi.fn());
// P2-62 — le retrait EN LOT d'un entraînement mutualisé passe par `mutateAsync` (N DELETE séquentiels).
const deleteReservationAsyncMock = vi.hoisted(() => vi.fn(() => Promise.resolve()));
const { recapLayer, anchorState, storeState, constraintsState, constraintsArg, constraintsByEntry, calendarEntryState, calendarEntryById, conflictsState } = vi.hoisted(() => ({
  anchorState: { value: { state: "period", planId: "plan-1" } as { state: string; planId: string | null } },
  storeState: { value: { mode: "season", calendarEntryId: null } as { mode: string; calendarEntryId: string | null } },
  // P2-22 / P2-59 — les contraintes affichées (D4) + le dernier id passé à useWizardConstraints.
  constraintsState: { data: [] as Array<Record<string, unknown>> },
  constraintsArg: { value: undefined as string | null | undefined },
  // P2-59 — genèses/faits lus PAR entrée : la semaine pour ses genèses, la mère pour ses faits.
  // Vide → repli sur `constraintsState.data`.
  constraintsByEntry: { map: {} as Record<string, Array<Record<string, unknown>>> },
  calendarEntryState: { data: { parentEntryId: null } as { parentEntryId: string | null } | undefined },
  // P2-59 — les entrées de calendrier par id (la mère porte son parentEntryId null).
  calendarEntryById: { map: {} as Record<string, { parentEntryId: string | null; title?: string }> },
  // P2-37 D5/D6 + indispo INFORMATIVE (2026-08-18) — les fermetures servies par /conflicts :
  // `closures` porte le motif, `fullyClosedVenueIds` un gymnase entièrement fermé, et
  // `effectiveClosedWeekdays` l'ÉTAT EFFECTIF jour par jour (un jour rouvert n'y figure PAS).
  conflictsState: { data: { closures: [], fullyClosedVenueIds: [], effectiveClosedWeekdays: {}, disabledVenueIds: [] } as Record<string, unknown> },
  recapLayer: {
    teams: [] as unknown[],
    pausedIds: [] as string[],
    venues: [] as unknown[],
    slots: [] as unknown[],
    teamsRead: "ready" as "loading" | "failed" | "ready",
    venuesRead: "ready" as "loading" | "failed" | "ready",
  },
}));

// Le plan de la période : ancre des réservations depuis le lot C3 (inv. 5).
vi.mock("@/features/cockpit/queries", () => ({
  useSchedulePlanForEntry: () => ({ data: { id: "plan-1" }, isLoading: false }),
  usePeriodAnchor: () => anchorState.value,
  anchorIsWritable: (a: { state: string }) => "period" === a.state || "base" === a.state,
  useCalendarEntry: (id: string | null) => ({ data: null != id && id in calendarEntryById.map ? calendarEntryById.map[id] : calendarEntryState.data }),
  useEntryConflicts: () => ({ data: conflictsState.data, isError: false, refetch: vi.fn() }),
}));
vi.mock("../queries", () => ({
  useWizardTeams: () => ({
    data: [
      { id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 3, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
      { id: "t2", name: "Fanion", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
    ],
  }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: null, isActive: true }] }),
  // P2-15 : le récap compte la COUCHE courante (période ou socle).
  useActiveVenues: () => ({ venues: recapLayer.venues, disabledIds: new Set<string>(), layerRead: recapLayer.venuesRead }),
  useActiveTeams: () => ({ teams: recapLayer.teams, pausedIds: new Set(recapLayer.pausedIds), layerRead: recapLayer.teamsRead }),
  useGridSlots: () => ({ data: recapLayer.slots }),
  useVenueSlots: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [] }),
  useWizardCoachPlayers: () => ({ data: [] }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardConstraints: (entryId?: string | null, enabled = true) => {
    constraintsArg.value = entryId;
    if (false === enabled) {
      return { data: [] };
    }
    if (null != entryId && entryId in constraintsByEntry.map) {
      return { data: constraintsByEntry.map[entryId] };
    }
    return { data: constraintsState.data };
  },
  useWizardTeamTags: () => ({ data: [] }),
  // P4-44 — le récap peut retirer une réservation orpheline (seul écran capable de la montrer).
  useDeleteReservation: () => ({ mutate: deleteReservationMock, mutateAsync: deleteReservationAsyncMock, isPending: false }),
  useReservations: () => ({ data: h.reservations }),
  useSharedTrainingBlocks: () => ({ data: sharedBlocksState.data }),
  usePriorityTiers: () => ({
    data: [
      { id: 1, label: "S", name: "Fanion", color: null },
      { id: 3, label: "B", name: "Moyenne", color: null },
    ],
  }),
}));
vi.mock("../lib/useStepValidation", () => ({ useStepValidation: () => ({ errors: [] }) }));
vi.mock("../store", () => ({ useWizardStore: (sel: (s: { mode: string; calendarEntryId: string | null }) => unknown) => sel(storeState.value) }));
vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import { RecapStep } from "./RecapStep";
import { toast } from "@/shared/stores/toastStore";

/** Un bloc de mutualisation de la portée courante (P2-51) — forme minimale pour le récap. */
const recapBlock = (teamIds: string[]): SharedTrainingBlock =>
  ({ id: "g", version: 1, createdAt: "2026-08-31T00:00:00+00:00", updatedAt: "2026-08-31T00:00:00+00:00", schedulePlanId: null, teamIds, commonSessions: 1 });

describe("RecapStep — read-only summary", () => {
  beforeEach(() => {
    h.reservations = [];
    sharedBlocksState.data = [];
    conflictsState.data = { closures: [], fullyClosedVenueIds: [] };
    deleteReservationMock.mockClear();
    deleteReservationAsyncMock.mockClear();
    vi.mocked(toast.success).mockClear();
    // Défaut : la couche décrit les mêmes équipes que la liste de saison, aucune en pause.
    recapLayer.teams = [team("t1", "SM1", 3), team("t2", "Fanion", 1)];
    recapLayer.pausedIds = [];
    recapLayer.venues = [{ id: "v1", name: "Gymnase A", color: null, isActive: true }];
    recapLayer.slots = [];
    recapLayer.teamsRead = "ready";
    recapLayer.venuesRead = "ready";
    anchorState.value = { state: "period", planId: "plan-1" };
    storeState.value = { mode: "season", calendarEntryId: null };
  });

  // P2-15 (retour fondateur) — LE symptôme : « je sélectionne 6 équipes sur l'overlay, il
  // me dit 49 équipes ». Le compteur décrit ce qui sera GÉNÉRÉ, donc la couche courante.
  it("compte les équipes de la PÉRIODE, pas celles du club", async () => {
    recapLayer.teams = [team("t1", "SM1", 3)];
    recapLayer.pausedIds = ["t2"];
    renderWithProviders(<RecapStep />);

    // La carte compteur du haut : sa valeur est le nombre qui sera GÉNÉRÉ.
    const card = (await screen.findAllByText("Équipes"))[0];
    expect(card.previousElementSibling).toHaveTextContent("1");
  });

  // … et une équipe en pause reste VISIBLE, barrée : le récap sert à vérifier ce qu'on va
  // générer, y compris ce qu'on a délibérément mis de côté (décision fondateur).
  it("montre une équipe en pause, barrée, sans la compter", async () => {
    recapLayer.teams = [team("t1", "SM1", 3)];
    recapLayer.pausedIds = ["t2"];
    renderWithProviders(<RecapStep />);

    const card = (await screen.findAllByText("Équipes"))[0];
    expect(card.previousElementSibling).toHaveTextContent("1");
    // Le détail est dans un accordéon FERMÉ par défaut : on l'ouvre pour lire la liste.
    await userEvent.click(screen.getAllByRole("button", { name: /Équipes/ })[0]);
    // L'équipe en pause y reste LISTÉE, barrée — on doit voir ce qu'on a mis de côté.
    expect(screen.getByText("Fanion")).toHaveClass("line-through");
    expect(screen.getByText(/en pause pour cette période/)).toBeInTheDocument();
  });

  // Mode période dont l'ancre n'est pas résolue : les listes lues sont celles de la
  // SAISON. Les présenter comme celles de la période serait le mensonge que ce lot
  // corrige — on l'annonce (revue #342, trouvé en appliquant la règle à TOUS ses sites).
  it("annonce que les chiffres sont ceux de la saison tant que l'ancre n'est pas résolue", async () => {
    storeState.value = { mode: "period", calendarEntryId: "entry-1" };
    anchorState.value = { state: "loading", planId: null };
    renderWithProviders(<RecapStep />);

    expect(await screen.findByText(/ne sont pas encore chargés/)).toBeInTheDocument();
  });

  // FAIL-CLOSED (P4-20/P4-1) : sur une lecture ratée on ne masque RIEN, et on le DIT.
  // Masquer en silence ferait croire à une période plus petite qu'elle n'est.
  it("ne masque rien et l'annonce quand les réglages de la période sont illisibles", async () => {
    recapLayer.teamsRead = "failed";
    renderWithProviders(<RecapStep />);

    expect(await screen.findByText(/n'a pas pu être lue/)).toBeInTheDocument();
  });

  // CHARGER ≠ ÉCHOUER (revue #342 round 2). Le premier jet repliait `loading` sur `failed` :
  // le récap d'une période affichait « n'a pas pu être lue » à CHAQUE ouverture, sur une
  // requête simplement en vol. Un bandeau d'alerte qui se déclenche en régime normal
  // apprend au gestionnaire à l'ignorer — précisément le jour où la lecture échoue.
  it("dit « en cours de lecture » — pas « échec » — pendant le premier chargement", async () => {
    recapLayer.teamsRead = "loading";
    renderWithProviders(<RecapStep />);

    expect(await screen.findByText(/est en cours de lecture/)).toBeInTheDocument();
    expect(screen.queryByText(/n'a pas pu être lue/)).not.toBeInTheDocument();
  });

  it("lists reservations by team rank (fanion before B) with NO delete button (read-only)", async () => {
    // Server order puts the rank-B team first; the accordion must show rank-S first.
    h.reservations = [
      { id: "rB", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120 },
      { id: "rS", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90 },
    ];
    // P4-44 : leurs créneaux EXISTENT — sinon elles seraient orphelines et ce cas
    // mesurerait l'exception au lieu de la règle (lecture seule sur les saines).
    recapLayer.slots = [
      { id: "sB", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 },
      { id: "sS", venueId: "v1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, capacity: 1 },
    ];
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Réservations/ }));

    // Rank order: the Fanion (S) row precedes the SM1 (B) row in the DOM.
    const rows = screen.getAllByText(/^(Fanion|SM1)$/).map((el) => el.textContent);
    expect(rows.indexOf("Fanion")).toBeLessThan(rows.indexOf("SM1"));

    // Le récap reste en LECTURE SEULE sur les réservations SAINES : leur geste vit à
    // l'étape « Réserver », pas ici.
    expect(screen.queryByRole("button", { name: /Retirer la réservation/ })).not.toBeInTheDocument();
  });

  /**
   * P4-44 (décision fondateur 2026-08-07) — L'UNIQUE exception à la lecture seule.
   * Une réservation ORPHELINE (créneau déplacé ou supprimé) n'a AUCUNE case où
   * s'afficher à l'étape « Réserver » : sa grille boucle sur les créneaux. Le récap
   * est donc le seul écran capable de la montrer, et le serveur BLOQUE la génération
   * dessus — un blocage sans recours atteignable enfermerait le gestionnaire (P3-20).
   */
  it("signale une réservation orpheline et permet de la retirer — seul écran qui le peut", async () => {
    // La grille n'ouvre le gymnase qu'à 18h30 ; la réservation pointe 18h00.
    recapLayer.slots = [{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "18:30", durationMinutes: 90, capacity: 1 }];
    h.reservations = [{ id: "rOrpheline", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 }];
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Réservations/ }));

    expect(screen.getByText(/créneau supprimé ou déplacé/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Retirer la réservation/ })).toBeInTheDocument();
  });

  /**
   * P2-37 D5 — le prédicat LARGE : une réservation dont le gymnase est FERMÉ (ici ce jour-là)
   * devient non servie même si son créneau EXISTE encore. Le récap la nomme AVEC son motif
   * « gymnase fermé — {titre} », distinct du motif orphelin (créneau supprimé/déplacé). Et il
   * n'efface RIEN d'office (décision fondateur : on alerte) — seule la poubelle, à la main.
   */
  it("nomme l'équipe ET le motif « gymnase fermé » d'une réservation non servie, sans rien supprimer", async () => {
    // Le créneau EXISTE (donc pas orphelin ÉTROIT) — c'est la fermeture qui le rend non servi.
    recapLayer.slots = [{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90, capacity: 1 }];
    h.reservations = [{ id: "rFermee", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 }];
    // Indispo INFORMATIVE : le motif se lit de l'ÉTAT EFFECTIF servi (le jour 2 est fermé), pas
    // des fermetures brutes. `closures` reste le porteur du TITRE affiché.
    conflictsState.data = { closures: [{ constraintId: "cc", venueId: "v1", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [2] }], fullyClosedVenueIds: [], effectiveClosedWeekdays: { v1: { "2": "default-incident" } }, disabledVenueIds: [] };
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Réservations/ }));

    // L'équipe est NOMMÉE, et le motif dit « gymnase fermé — {titre} » (pas « supprimé/déplacé »).
    expect(screen.getByText(/SM1 — gymnase fermé — Travaux/)).toBeInTheDocument();
    expect(screen.queryByText(/créneau supprimé ou déplacé/)).toBeNull();
    // Aucune suppression passive : la poubelle est là, mais rien n'a été retiré au rendu.
    expect(screen.getByRole("button", { name: /Retirer la réservation de SM1/ })).toBeInTheDocument();
    expect(deleteReservationMock).not.toHaveBeenCalled();
  });

  // Indispo INFORMATIVE (2026-08-18) — item 7 : le récap lit l'ÉTAT EFFECTIF, pas les fermetures
  // brutes. Un jour ROUVERT (masque OPEN) n'apparaît PAS dans `effectiveClosedWeekdays`, donc une
  // réservation ce jour-là n'est plus annoncée fermée alors qu'une fermeture brute la couvrait.
  it("n'annonce PAS fermée une réservation sur un jour rouvert malgré l'indisponibilité (état effectif)", async () => {
    recapLayer.slots = [{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90, capacity: 1 }];
    h.reservations = [{ id: "rReouvert", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 }];
    // La fermeture BRUTE couvre le jour 2, mais l'état EFFECTIF ne le liste pas (rouvert) :
    // le récap ne doit rien annoncer de fermé sur cette réservation.
    conflictsState.data = { closures: [{ constraintId: "cc", venueId: "v1", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [2] }], fullyClosedVenueIds: [], effectiveClosedWeekdays: {}, disabledVenueIds: [] };
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Réservations/ }));

    expect(screen.queryByText(/gymnase fermé/)).toBeNull();
    expect(screen.queryByRole("button", { name: /Retirer la réservation de SM1/ })).toBeNull();
  });

  // P2-62 — on ne retire pas une équipe d'un groupe, on retire le groupe : les réservations
  // NON SERVIES d'une même case formant un entraînement mutualisé se fondent en UNE ligne de lot.
  it("fond les réservations non servies d'un entraînement mutualisé en UNE ligne, retirée en lot", async () => {
    recapLayer.slots = [{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "18:30", durationMinutes: 90, capacity: 1 }];
    h.reservations = [
      { id: "rA", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 },
      { id: "rB", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 },
    ];
    sharedBlocksState.data = [recapBlock(["t1", "t2"])];
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Réservations/ }));

    // UNE ligne de lot (membres nommés), UN bouton — pas deux verrous individuels.
    const remove = screen.getByRole("button", { name: "Retirer l'entraînement mutualisé SM1 + Fanion" });
    expect(screen.queryByRole("button", { name: "Retirer la réservation de SM1" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Retirer la réservation de Fanion" })).toBeNull();

    await user.click(remove);

    // N DELETE séquentiels (mutateAsync) puis un toast de succès nommant le lot.
    expect(deleteReservationAsyncMock).toHaveBeenCalledTimes(2);
    expect(deleteReservationAsyncMock).toHaveBeenCalledWith("rA");
    expect(deleteReservationAsyncMock).toHaveBeenCalledWith("rB");
    expect(toast.success).toHaveBeenCalledWith("Entraînement mutualisé SM1 + Fanion retiré");
  });

  it("deux réservations non servies sur la même case SANS bloc restent DEUX lignes", async () => {
    recapLayer.slots = [{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "18:30", durationMinutes: 90, capacity: 1 }];
    h.reservations = [
      { id: "rA", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 },
      { id: "rB", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 },
    ];
    sharedBlocksState.data = []; // aucun bloc : aucune fusion, chacune sa ligne.
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Réservations/ }));

    expect(screen.getByRole("button", { name: "Retirer la réservation de SM1" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retirer la réservation de Fanion" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /entraînement mutualisé/ })).toBeNull();
  });

  it("shows the team tiers open by default (ranks visible at first glance)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    // Open the outer "Équipes" accordion; the tier groups inside must be OPEN
    // (their team rows visible) with their rank labels shown, S before B.
    const equipesHeaders = screen.getAllByRole("button", { name: /Équipes/ });
    await user.click(equipesHeaders[0]);

    const sHeader = screen.getByRole("button", { name: /S · Fanion/ });
    expect(sHeader).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /B · Moyenne/ })).toBeInTheDocument();
    // defaultOpen: the S tier's team row is already visible without a click.
    expect(within(sHeader.parentElement as HTMLElement).getByText("Fanion")).toBeInTheDocument();
  });
});

/**
 * Décision P3-8 (2026-08-04) — le récap avertit sur les créneaux PARTAGÉS sans bloquer.
 * La règle vit en fonction pure (`sharedSlotStatuses`, testée à part) ; ici on garde le
 * CÂBLAGE : l'écran rend bien les deux messages, nommés par gymnase · jour · heure.
 */
describe("RecapStep — créneaux partagés", () => {
  const sharedSlot = { id: "sl1", venueId: "v1", dayOfWeek: 6, startTime: "14:00", durationMinutes: 90, capacity: 2 };

  beforeEach(() => {
    h.reservations = [];
    conflictsState.data = { closures: [], fullyClosedVenueIds: [] };
    recapLayer.teams = [team("t1", "SM1", 3), team("t2", "Fanion", 1)];
    recapLayer.pausedIds = [];
    // canSplit ABSENT du mock d'origine : on le pose, c'est lui qui arme la capacité 2.
    recapLayer.venues = [{ id: "v1", name: "Gymnase A", color: null, isActive: true, canSplit: true }];
    recapLayer.slots = [sharedSlot];
    recapLayer.teamsRead = "ready";
    recapLayer.venuesRead = "ready";
    anchorState.value = { state: "period", planId: "plan-1" };
    storeState.value = { mode: "season", calendarEntryId: null };
  });

  it("annonce qu'un créneau partagé SANS réservation sera composé par le système", () => {
    renderWithProviders(<RecapStep />);
    expect(screen.getByText(/le système associera les équipes lui-même/)).toBeInTheDocument();
    expect(screen.getByText(/Gymnase A · Sam 14:00/)).toBeInTheDocument();
  });

  it("place l'encart EN BAS, avec la zone de décision — pas au-dessus des compteurs (UX fondateur 2026-08-04)", () => {
    renderWithProviders(<RecapStep />);
    const notice = screen.getByText(/le système associera les équipes lui-même/);
    const counters = screen.getAllByText("Équipes")[0];
    // compareDocumentPosition : FOLLOWING = l'encart vient APRÈS les compteurs dans le DOM.
    expect(counters.compareDocumentPosition(notice.closest("p") ?? notice) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("avertit qu'un créneau partagé PARTIELLEMENT réservé gardera sa place vide (ALIGN-07)", () => {
    h.reservations = [{ id: "r1", teamId: "t1", venueId: "v1", dayOfWeek: 6, startTime: "14:00" }];
    renderWithProviders(<RecapStep />);
    expect(screen.getByText(/le système ne complétera pas ce créneau/)).toBeInTheDocument();
  });

  it("se tait quand le créneau partagé est plein", () => {
    h.reservations = [
      { id: "r1", teamId: "t1", venueId: "v1", dayOfWeek: 6, startTime: "14:00" },
      { id: "r2", teamId: "t2", venueId: "v1", dayOfWeek: 6, startTime: "14:00" },
    ];
    renderWithProviders(<RecapStep />);
    expect(screen.queryByText(/créneau partagé|ne complétera pas/)).not.toBeInTheDocument();
  });
});

/**
 * D4/D5 (P2-22) — une fermeture de gymnase (venue_closed) se range sous SON gymnase et
 * affiche ses dates ; et une semaine enfant lit ses datées par la MÈRE.
 */
describe("RecapStep — fermetures de gymnase et semaine enfant", () => {
  beforeEach(() => {
    h.reservations = [];
    conflictsState.data = { closures: [], fullyClosedVenueIds: [] };
    deleteReservationMock.mockClear();
    recapLayer.teams = [team("t1", "SM1", 3), team("t2", "Fanion", 1)];
    recapLayer.pausedIds = [];
    recapLayer.venues = [{ id: "v1", name: "Gymnase A", color: null, isActive: true }];
    recapLayer.slots = [];
    recapLayer.teamsRead = "ready";
    recapLayer.venuesRead = "ready";
    anchorState.value = { state: "period", planId: "plan-1" };
    storeState.value = { mode: "season", calendarEntryId: null };
    constraintsState.data = [];
    constraintsByEntry.map = {};
    calendarEntryById.map = {};
    calendarEntryState.data = { parentEntryId: null };
  });

  it("range une fermeture sous son gymnase et affiche ses dates en meta (D4)", async () => {
    constraintsState.data = [{ id: "cc", name: "Gymnase A fermé", scope: "FACILITY", scopeTargetId: "v1", family: "FACILITY", ruleType: "HARD", config: { type: "venue_closed", startDate: "2026-05-01", endDate: "2026-05-10" }, isActive: true }];
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Contraintes/ }));
    expect(screen.getByText("Gymnase A fermé")).toBeInTheDocument();
    // Le nom porte déjà le titre ; la meta porte les dates (format fr), pas l'enum de règle.
    expect(screen.getByText(/du 1 mai 2026 au 10 mai 2026/)).toBeInTheDocument();
  });

  // P2-59 — le récap d'une semaine enfant affiche l'UNION genèses (datées de la semaine) ∪
  // faits (datés de la mère), miroir de CalendarEntry::datedConstraintSourceIds. Read-only ici.
  it("affiche l'union genèses (semaine) ∪ faits (mère) depuis une semaine enfant (P2-59)", async () => {
    storeState.value = { mode: "period", calendarEntryId: "child-week" };
    calendarEntryState.data = { parentEntryId: "mother-1" };
    calendarEntryById.map = { "mother-1": { parentEntryId: null, title: "Vacances d'été" } };
    constraintsByEntry.map = {
      "child-week": [{ id: "g1", name: "Genèse de la semaine", scope: "CLUB", scopeTargetId: null, family: "DAY", ruleType: "HARD", config: { forbiddenDays: [1] }, isActive: true }],
      "mother-1": [{ id: "f1", name: "Fait de la mère", scope: "CLUB", scopeTargetId: null, family: "DAY", ruleType: "HARD", config: { forbiddenDays: [7] }, isActive: true }],
    };
    const user = userEvent.setup();
    renderWithProviders(<RecapStep />);

    await user.click(screen.getByRole("button", { name: /Contraintes/ }));
    expect(screen.getByText("Genèse de la semaine")).toBeInTheDocument();
    expect(screen.getByText("Fait de la mère")).toBeInTheDocument();
  });

  // P2-51 — une section « Mutualisation » à côté des réservations : une ligne par bloc de la
  // portée courante, nommé avec le libellé partagé.
  it("liste les blocs de mutualisation de la portée courante", async () => {
    sharedBlocksState.data = [
      { id: "g1", version: 1, createdAt: "2026-08-17T00:00:00+00:00", updatedAt: "2026-08-17T00:00:00+00:00", schedulePlanId: "plan-1", teamIds: ["t1", "t2"], commonSessions: 1 },
    ];
    renderWithProviders(<RecapStep />);

    await userEvent.click(screen.getByRole("button", { name: /Mutualisation/ }));
    expect(screen.getByText("SM1 + Fanion — 1 séance commune")).toBeInTheDocument();
  });
});
