import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry } from "@/features/cockpit/api";

import { CoachWishesModal } from "./CoachWishesModal";

// Saison couvrant les deux semaines des vacances de test (lun 2026-02-16 → dim 2026-03-01).
// P3-14 : le tri des filtres (staffing / rang) et le bornage du coach aux MAIN de l'équipe
// se lisent tous deux dans ces fixtures. `t2`/U13 n'a VOLONTAIREMENT aucun lien coach —
// c'est ce qui rend observable qu'elle sort du formulaire sans sortir du filtre.
const teamsState = { data: [
  { id: "t1", name: "SM1", priorityTierId: 3, tierOrder: 0 },
  { id: "t2", name: "U13", priorityTierId: 3, tierOrder: 1 },
  { id: "t3", name: "Fanion", priorityTierId: 1, tierOrder: 0 },
] as { id: string; name: string; priorityTierId: number; tierOrder: number }[] };
const coachesState = { data: [
  { id: "c1", firstName: "Maxime", lastName: "Durand", isEmployee: false },
  { id: "c2", firstName: "Léa", lastName: "Roy", isEmployee: true },
] as { id: string; firstName: string; lastName: string; isEmployee: boolean }[] };
const teamCoachesState = { data: [
  { id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" },
  { id: "tc3", teamId: "t3", coachId: "c2", role: "MAIN" },
] as { id: string; teamId: string; coachId: string; role: string }[] };
const coachPlayersState = { data: [] as { coachId: string; isActive: boolean }[] };
const tiersState = { data: [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 3, label: "B", name: "Moyenne", color: null },
] as { id: number; label: string; name: string; color: string | null }[] };

vi.mock("@/shared/session/queries", () => ({ useWorkingSeason: () => ({ startDate: "2025-09-01", endDate: "2026-06-30" }) }));
vi.mock("@/features/wizard/queries", () => ({
  useWizardTeams: () => ({ data: teamsState.data }),
  useWizardCoaches: () => ({ data: coachesState.data }),
  useWizardTeamCoaches: () => ({ data: teamCoachesState.data }),
  useWizardCoachPlayers: () => ({ data: coachPlayersState.data }),
  usePriorityTiers: () => ({ data: tiersState.data }),
}));

const wishesState: { data: unknown[] } = { data: [] };
const createMut = vi.fn();
const updateMut = vi.fn();
const deleteMut = vi.fn();
vi.mock("./queries", () => ({
  useCoachWishes: () => ({ data: wishesState.data }),
  useCreateCoachWish: () => ({ mutate: createMut, isPending: false }),
  useUpdateCoachWish: () => ({ mutate: updateMut, isPending: false }),
  useDeleteCoachWish: () => ({ mutate: deleteMut, isPending: false }),
}));

const mother: CalendarEntry = {
  id: "e1",
  kind: "period",
  title: "Toussaint",
  startDate: "2026-02-16",
  endDate: "2026-03-01",
  isDisruptive: false,
  periodType: "holiday",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  redatable: false, redateNeedsPreview: false,
};

const wish = (over: Record<string, unknown>) => ({
  id: "w1",
  calendarEntryId: "e1",
  weekStart: "2026-02-16",
  teamId: "t1",
  coachId: "c1",
  slotsWanted: 2,
  unavailableDays: [],
  comment: null,
  done: false,
  ...over,
});

describe("CoachWishesModal", () => {
  beforeEach(() => {
    wishesState.data = [];
    // Un cas vide les liens coach : sans ré-armement, il contaminerait les suivants.
    teamCoachesState.data = [
      { id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" },
      { id: "tc3", teamId: "t3", coachId: "c2", role: "MAIN" },
    ];
    createMut.mockClear();
    updateMut.mockClear();
    deleteMut.mockClear();
  });

  it("groupe les doléances par semaine quand aucun filtre de semaine", () => {
    wishesState.data = [wish({ id: "w1", weekStart: "2026-02-16" }), wish({ id: "w2", teamId: "t2", weekStart: "2026-02-23" })];
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    // Deux en-têtes de semaine (les deux semaines des vacances).
    expect(screen.getByText(/Semaine du 2026-02-16/)).toBeInTheDocument();
    expect(screen.getByText(/Semaine du 2026-02-23/)).toBeInTheDocument();
    expect(screen.getByText("SM1", { exact: false })).toBeInTheDocument();
  });

  // P4-150 — la copie d'écran de l'état vide (semaine sans aucune doléance) est assertée.
  it("annonce « Aucune doléance pour cette semaine. » quand la semaine filtrée est vide", () => {
    wishesState.data = [];
    render(<CoachWishesModal mother={mother} weekFilter="2026-02-16" onClose={() => {}} />);
    expect(screen.getByText("Aucune doléance pour cette semaine.")).toBeInTheDocument();
  });

  it("ne montre qu'une semaine quand weekFilter est posé (vue wizard d'un plan de semaine)", () => {
    wishesState.data = [wish({ id: "w1", weekStart: "2026-02-16" }), wish({ id: "w2", teamId: "t2", weekStart: "2026-02-23" })];
    render(<CoachWishesModal mother={mother} weekFilter="2026-02-23" onClose={() => {}} />);
    expect(screen.queryByText(/Semaine du 2026-02-16/)).toBeNull();
    // La doléance de la semaine filtrée (U13) est là ; celle de l'autre semaine non.
    expect(screen.getByText("U13", { exact: false })).toBeInTheDocument();
    expect(screen.queryByText("SM1", { exact: false })).toBeNull();
  });

  it("cocher « traité » appelle update avec done inversé", async () => {
    wishesState.data = [wish({ id: "w1", done: false })];
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    await userEvent.click(screen.getByRole("checkbox", { name: /Traité/ }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ done: true }) }));
  });

  it("cocher « traité » sur une doléance dé-attribuée préserve coachId null (pas de 422)", async () => {
    // Revue #10 C1 finding #1 : envoyer coachId:"" échouait le NotBlank et une doléance
    // dé-attribuée ne pouvait jamais être cochée. On préserve null.
    wishesState.data = [wish({ id: "w1", coachId: null, done: false })];
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    await userEvent.click(screen.getByRole("checkbox", { name: /Traité/ }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ coachId: null, done: true }) }));
  });

  it("une doléance dé-attribuée (coachId null) l'affiche explicitement", () => {
    wishesState.data = [wish({ id: "w1", coachId: null })];
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    expect(screen.getByText(/coach dé-attribué/)).toBeInTheDocument();
  });

  it("éditer une doléance ATTRIBUÉE ne peut pas la dé-attribuer (pas d'option coach vide)", async () => {
    // Revue #10 C1 round 2 finding #1 : autoriser le coach vide en édition (pour les
    // dé-attribuées) ne doit PAS laisser dé-attribuer une doléance attribuée.
    wishesState.data = [wish({ id: "w1", coachId: "c1", teamId: "t1", weekStart: "2026-02-16" })];
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // Pas d'option vide « Coach… » sur une doléance attribuée.
    const coachSelect = screen.getByLabelText("Coach") as HTMLSelectElement;
    expect(Array.from(coachSelect.options).some((o) => "" === o.value)).toBe(false);
    // Enregistrer garde le coach d'origine.
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ body: expect.objectContaining({ coachId: "c1" }) }), expect.anything());
  });

  it("éditer une doléance dé-attribuée ne la réattribue PAS au coach MAIN", async () => {
    // Revue #10 C1 finding #2 : l'équipe t1 a un coach MAIN (c1) ; éditer une doléance
    // dé-attribuée retombait sur lui, corrompant l'auteur. On garde coachId null.
    wishesState.data = [wish({ id: "w1", coachId: null, teamId: "t1", weekStart: "2026-02-16" })];
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ coachId: null }) }), expect.anything());
  });

  it("le filtre par équipe masque les autres équipes", async () => {
    wishesState.data = [wish({ id: "w1", teamId: "t1" }), wish({ id: "w2", teamId: "t2" })];
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    // Ouvre le filtre Équipes, coche U13, referme le popover.
    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    await user.click(screen.getByRole("button", { name: "U13" }));
    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    // Popover fermé : les noms d'équipe ne vivent plus que dans les items filtrés.
    expect(screen.getByText("U13", { exact: false })).toBeInTheDocument();
    expect(screen.queryByText("SM1", { exact: false })).toBeNull();
  });

  it("le formulaire d'ajout soumet le payload avec la semaine figée quand weekFilter", async () => {
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter="2026-02-16" onClose={() => {}} />);
    await user.click(screen.getByRole("button", { name: /Ajouter/ }));
    // Équipe SM1 (défaut) → coach MAIN c1 pré-rempli ; on soumet directement.
    await user.click(screen.getByRole("button", { name: /Ajouter la doléance/ }));
    expect(createMut).toHaveBeenCalledWith(expect.objectContaining({ calendarEntryId: "e1", weekStart: "2026-02-16", teamId: "t1", coachId: "c1" }), expect.anything());
  });

  // ── P3-14 (retour terrain 2026-07-31) ──

  // (a) « La liste de coachs n'est pas triée » : elle sortait dans l'ordre brut de l'API,
  // alors que le regroupement salariés / coachs-joueurs / bénévoles existe et sert déjà au
  // récap et à l'onglet contraintes.
  it("groupe les coachs du filtre par staffing", async () => {
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);

    await user.click(screen.getByRole("button", { name: /Coachs/ }));
    expect(screen.getByText("Salariés")).toBeInTheDocument();
    expect(screen.getByText("Bénévoles")).toBeInTheDocument();
  });

  // Les équipes du filtre suivent le RANG, comme partout où une équipe se choisit.
  it("groupe les équipes du filtre par rang, fanion d'abord", async () => {
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);

    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    const headers = screen.getAllByText(/^(S · Fanion|B · Moyenne)$/).map((el) => el.textContent);
    expect(headers.indexOf("S · Fanion")).toBeLessThan(headers.indexOf("B · Moyenne"));
  });

  // (b) décision fondateur : « comment avoir une doléance de coach si une équipe n'a pas de
  // coach ? ben c'est pas possible ». U13 n'a aucun lien MAIN → hors du FORMULAIRE.
  it("n'offre pas à la saisie une équipe sans coach principal", async () => {
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);

    await user.click(screen.getByRole("button", { name: /Ajouter/ }));
    const picker = screen.getByLabelText("Équipe");
    expect(within(picker).queryByRole("option", { name: "U13" })).toBeNull();
    expect(within(picker).getByRole("option", { name: "SM1" })).toBeInTheDocument();
  });

  // …mais le FILTRE la garde : il sert à LIRE des doléances existantes, dont celles d'une
  // équipe qui a perdu son coach depuis. Cacher ne vaut que pour un CHOIX.
  it("garde toutes les équipes dans le filtre, y compris sans coach", async () => {
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);

    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    expect(screen.getByRole("button", { name: "U13" })).toBeInTheDocument();
  });

  // « Je veux que les MAIN coach » : le select listait TOUT le club, on pouvait enregistrer
  // une équipe avec un coach qui ne l'encadre pas.
  it("n'offre que le coach principal de l'équipe choisie", async () => {
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);

    await user.click(screen.getByRole("button", { name: /Ajouter/ }));
    const picker = screen.getByLabelText("Coach");
    expect(within(picker).getByRole("option", { name: /Maxime Durand/ })).toBeInTheDocument();
    expect(within(picker).queryByRole("option", { name: /Léa Roy/ })).toBeNull();

    // Changer d'équipe change la liste : Fanion est encadrée par Léa, pas par Maxime.
    await user.selectOptions(screen.getByLabelText("Équipe"), "t3");
    expect(within(screen.getByLabelText("Coach")).getByRole("option", { name: /Léa Roy/ })).toBeInTheDocument();
    expect(within(screen.getByLabelText("Coach")).queryByRole("option", { name: /Maxime Durand/ })).toBeNull();
  });

  // ⚠ CHOISIR n'est pas NOMMER (leçon #342) : un coach qui a perdu son lien MAIN reste
  // affiché sur la doléance qu'il porte — sinon le select rend blanc sur une doléance qui
  // nomme pourtant quelqu'un, et « combler le trou » la réattribue en silence.
  it("garde le coach d'une doléance existante même s'il n'encadre plus l'équipe", async () => {
    wishesState.data = [wish({ id: "w1", teamId: "t1", coachId: "c2", weekStart: "2026-02-16" })]; // Léa n'encadre pas SM1
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    const picker = screen.getByLabelText("Coach") as HTMLSelectElement;
    expect(picker.value).toBe("c2");
    expect(within(picker).getByRole("option", { name: /Léa Roy.*n'encadre plus cette équipe/ })).toBeInTheDocument();
  });

  // Aucune équipe saisissable : on dit ce qui manque plutôt que d'ouvrir un formulaire sans
  // cible (un select d'équipes vide, dont rien n'expliquerait le vide).
  it("annonce ce qui manque quand aucune équipe n'a de coach principal", () => {
    teamCoachesState.data = [];
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);

    expect(screen.getByText(/rattachez-en un pour pouvoir saisir une doléance/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Ajouter/ })).toBeDisabled();
  });
});