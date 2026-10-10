import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry } from "@/features/cockpit/api";

import { WishesTab } from "./WishesTab";

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

const mutualizationsState: { data: unknown[] } = { data: [] };
const createMutu = vi.fn();
const updateMutu = vi.fn();
const deleteMutu = vi.fn();
vi.mock("./mutualizationQueries", () => ({
  useCoachWishMutualizations: () => ({ data: mutualizationsState.data }),
  useCreateCoachWishMutualization: () => ({ mutate: createMutu, isPending: false }),
  useUpdateCoachWishMutualization: () => ({ mutate: updateMutu, isPending: false }),
  useDeleteCoachWishMutualization: () => ({ mutate: deleteMutu, isPending: false }),
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
  wishedDays: [],
  comment: null,
  done: false,
  ...over,
});

describe("WishesTab", () => {
  beforeEach(() => {
    wishesState.data = [];
    mutualizationsState.data = [];
    // Un cas vide les liens coach : sans ré-armement, il contaminerait les suivants.
    teamCoachesState.data = [
      { id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" },
      { id: "tc3", teamId: "t3", coachId: "c2", role: "MAIN" },
    ];
    createMut.mockClear();
    updateMut.mockClear();
    deleteMut.mockClear();
    createMutu.mockClear();
    updateMutu.mockClear();
    deleteMutu.mockClear();
  });

  it("groupe les doléances par semaine quand aucun filtre de semaine", () => {
    wishesState.data = [wish({ id: "w1", weekStart: "2026-02-16" }), wish({ id: "w2", teamId: "t2", weekStart: "2026-02-23" })];
    render(<WishesTab mother={mother} weekFilter={null} />);
    // Deux en-têtes de semaine (les deux semaines des vacances), en format FR jj/mm/aaaa (lot 5).
    expect(screen.getByText(/Semaine du 16\/02\/2026/)).toBeInTheDocument();
    expect(screen.getByText(/Semaine du 23\/02\/2026/)).toBeInTheDocument();
    expect(screen.getByText("SM1", { exact: false })).toBeInTheDocument();
  });

  // P4-150 — la copie d'écran de l'état vide (semaine sans aucune doléance) est assertée.
  it("annonce « Aucune doléance pour cette semaine. » quand la semaine filtrée est vide", () => {
    wishesState.data = [];
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);
    expect(screen.getByText("Aucune doléance pour cette semaine.")).toBeInTheDocument();
  });

  it("ne montre qu'une semaine quand weekFilter est posé (vue wizard d'un plan de semaine)", () => {
    wishesState.data = [wish({ id: "w1", weekStart: "2026-02-16" }), wish({ id: "w2", teamId: "t2", weekStart: "2026-02-23" })];
    render(<WishesTab mother={mother} weekFilter="2026-02-23" />);
    expect(screen.queryByText(/Semaine du 16\/02\/2026/)).toBeNull();
    // La doléance de la semaine filtrée (U13) est là ; celle de l'autre semaine non.
    expect(screen.getByText("U13", { exact: false })).toBeInTheDocument();
    expect(screen.queryByText("SM1", { exact: false })).toBeNull();
  });

  it("cocher « traité » appelle update avec done inversé", async () => {
    wishesState.data = [wish({ id: "w1", done: false })];
    render(<WishesTab mother={mother} weekFilter={null} />);
    await userEvent.click(screen.getByRole("checkbox", { name: /Traité/ }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ done: true }) }));
  });

  it("cocher « traité » sur une doléance dé-attribuée préserve coachId null (pas de 422)", async () => {
    // Revue #10 C1 finding #1 : envoyer coachId:"" échouait le NotBlank et une doléance
    // dé-attribuée ne pouvait jamais être cochée. On préserve null.
    wishesState.data = [wish({ id: "w1", coachId: null, done: false })];
    render(<WishesTab mother={mother} weekFilter={null} />);
    await userEvent.click(screen.getByRole("checkbox", { name: /Traité/ }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ coachId: null, done: true }) }));
  });

  // Lot 5 (fondateur 2026-10-10) — une doléance qui n'a JAMAIS eu de coach (coachId null, p. ex.
  // saisie manuelle Vétérans) n'affiche AUCUN coach : « U13 », pas « U13 · coach dé-attribué ».
  it("une doléance sans coach (coachId null) n'affiche aucun coach, pas « dé-attribué »", () => {
    wishesState.data = [wish({ id: "w1", coachId: null, teamId: "t2" })];
    render(<WishesTab mother={mother} weekFilter={null} />);
    expect(screen.getByText("U13")).toBeInTheDocument();
    expect(screen.queryByText(/dé-attribué/)).toBeNull();
  });

  // …mais le VRAI cas « coach retiré » (coachId posé mais introuvable — coach supprimé/parti) garde
  // le libellé « coach dé-attribué ».
  it("une doléance dont le coach a été retiré (coachId introuvable) affiche « coach dé-attribué »", () => {
    wishesState.data = [wish({ id: "w1", coachId: "cGone", teamId: "t2" })];
    render(<WishesTab mother={mother} weekFilter={null} />);
    expect(screen.getByText(/coach dé-attribué/)).toBeInTheDocument();
  });

  it("éditer une doléance ATTRIBUÉE garde son coach (pas de dé-attribution SILENCIEUSE)", async () => {
    // Lot 5 (fondateur 2026-10-10) — PLUS de champ Coach : le coach est déduit et, à l'édition,
    // le `coachId` existant est gardé TEL QUEL, jamais dé-attribué en silence. Enregistrer sans
    // rien toucher garde donc le coach d'origine.
    wishesState.data = [wish({ id: "w1", coachId: "c1", teamId: "t1", weekStart: "2026-02-16" })];
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);
    await user.click(screen.getByRole("button", { name: /^Modifier la doléance/ }));
    // Plus aucun champ Coach dans le formulaire.
    expect(screen.queryByLabelText("Coach")).toBeNull();
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ body: expect.objectContaining({ coachId: "c1" }) }), expect.anything());
  });

  it("éditer une doléance dé-attribuée ne la réattribue PAS au coach MAIN", async () => {
    // Revue #10 C1 finding #2 : l'équipe t1 a un coach MAIN (c1) ; éditer une doléance
    // dé-attribuée retombait sur lui, corrompant l'auteur. On garde coachId null.
    wishesState.data = [wish({ id: "w1", coachId: null, teamId: "t1", weekStart: "2026-02-16" })];
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);
    await user.click(screen.getByRole("button", { name: /^Modifier la doléance/ }));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ coachId: null }) }), expect.anything());
  });

  it("le filtre par équipe masque les autres équipes", async () => {
    wishesState.data = [wish({ id: "w1", teamId: "t1" }), wish({ id: "w2", teamId: "t2" })];
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);
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
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    // Équipe SM1 (défaut) → coach MAIN c1 pré-rempli ; on soumet directement.
    await user.click(screen.getByRole("button", { name: /Ajouter la doléance/ }));
    expect(createMut).toHaveBeenCalledWith(expect.objectContaining({ calendarEntryId: "e1", weekStart: "2026-02-16", teamId: "t1", coachId: "c1" }), expect.anything());
  });

  // ── P4-312 — jours souhaités (informatif) ──

  it("affiche les jours souhaités d'une doléance remontée", () => {
    wishesState.data = [wish({ id: "w1", wishedDays: [2], unavailableDays: [3] })];
    render(<WishesTab mother={mother} weekFilter={null} />);
    expect(screen.getByText(/souhaité : Mar/)).toBeInTheDocument();
    expect(screen.getByText(/indispo : Mer/)).toBeInTheDocument();
  });

  // Lot 5 (fondateur 2026-10-10) — un jour souhaité est au minimum un jour DISPONIBLE : rendre un
  // jour indisponible le DÉSACTIVE côté souhaités (pas seulement décoché). On souhaite donc un
  // jour disponible.
  it("le formulaire d'ajout envoie les jours souhaités ; un jour rendu indisponible est désactivé côté souhaités", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    const dispo = screen.getByRole("group", { name: "Jours disponibles" });
    const wished = screen.getByRole("group", { name: "Jours souhaités" });
    // mercredi rendu indisponible (dépressé côté disponibles) → désactivé côté souhaités.
    await user.click(within(dispo).getByRole("button", { name: "mercredi" }));
    expect(within(wished).getByRole("button", { name: "mercredi" })).toHaveAttribute("aria-disabled", "true");
    // On souhaite mardi (disponible).
    await user.click(within(wished).getByRole("button", { name: "mardi" }));

    await user.click(screen.getByRole("button", { name: /Ajouter la doléance/ }));
    expect(createMut).toHaveBeenCalledWith(expect.objectContaining({ wishedDays: [2], unavailableDays: [3] }), expect.anything());
  });

  // Lot 5 — « Jours disponibles » est rendu AU-DESSUS de « Jours souhaités ».
  it("rend « Jours disponibles » AVANT « Jours souhaités »", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    const dispo = screen.getByRole("group", { name: "Jours disponibles" });
    const wished = screen.getByRole("group", { name: "Jours souhaités" });
    expect(dispo.compareDocumentPosition(wished) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  // Lot 5 — « Créneaux souhaités » est un champ NOMBRE (spinbutton) borné 0-7 (bornes serveur).
  it("rend « Créneaux souhaités » en champ nombre borné 0-7", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    const slots = screen.getByRole("spinbutton", { name: "Créneaux souhaités" });
    expect(slots).toHaveAttribute("type", "number");
    expect(slots).toHaveAttribute("min", "0");
    expect(slots).toHaveAttribute("max", "7");
  });

  // ── P2-63 A — « Jours disponibles » (inversion de présentation, payload inchangé) ──

  it("A — défaut tout pressé ; dépresser jeu+ven ⇒ unavailableDays:[4,5]", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    const dispo = screen.getByRole("group", { name: "Jours disponibles" });
    // Tous les jours pressés par défaut (le coach EST disponible partout, il dépresse les creux).
    expect(within(dispo).getByRole("button", { name: "lundi" })).toHaveAttribute("aria-pressed", "true");
    await user.click(within(dispo).getByRole("button", { name: "jeudi" }));
    await user.click(within(dispo).getByRole("button", { name: "vendredi" }));
    await user.click(screen.getByRole("button", { name: /Ajouter la doléance/ }));
    expect(createMut).toHaveBeenCalledWith(expect.objectContaining({ unavailableDays: [4, 5] }), expect.anything());
  });

  it("A — ré-affiche une réponse existante (unavailableDays:[4]) : 6 jours pressés, jeudi dépressé", async () => {
    wishesState.data = [wish({ id: "w1", unavailableDays: [4], teamId: "t1", weekStart: "2026-02-16" })];
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);
    await user.click(screen.getByRole("button", { name: /^Modifier la doléance/ }));
    const dispo = screen.getByRole("group", { name: "Jours disponibles" });
    expect(within(dispo).getByRole("button", { name: "jeudi" })).toHaveAttribute("aria-pressed", "false");
    expect(within(dispo).getByRole("button", { name: "lundi" })).toHaveAttribute("aria-pressed", "true");
  });

  // ── P2-63 C — sélecteur d'équipe partagé (TeamSelect, groupé par rang) ──

  it("C — le sélecteur « Équipe » est un TeamSelect groupé par rang", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    await user.click(screen.getByRole("button", { name: /^Équipe SM1/ }));
    expect(within(screen.getByRole("group", { name: /Fanion/ })).getByRole("option", { name: "Fanion" })).toBeInTheDocument();
    expect(within(screen.getByRole("group", { name: /Moyenne/ })).getByRole("option", { name: "SM1" })).toBeInTheDocument();
  });

  // ── P3-14 (retour terrain 2026-07-31) ──

  // (a) « La liste de coachs n'est pas triée » : elle sortait dans l'ordre brut de l'API,
  // alors que le regroupement salariés / coachs-joueurs / bénévoles existe et sert déjà au
  // récap et à l'onglet contraintes.
  it("groupe les coachs du filtre par staffing", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);

    await user.click(screen.getByRole("button", { name: /Coachs/ }));
    expect(screen.getByText("Salariés")).toBeInTheDocument();
    expect(screen.getByText("Bénévoles")).toBeInTheDocument();
  });

  // Les équipes du filtre suivent le RANG, comme partout où une équipe se choisit.
  it("groupe les équipes du filtre par rang, fanion d'abord", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);

    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    const headers = screen.getAllByText(/^(S · Fanion|B · Moyenne)$/).map((el) => el.textContent);
    expect(headers.indexOf("S · Fanion")).toBeLessThan(headers.indexOf("B · Moyenne"));
  });

  // P2-63 PR 2 / Q5 (fondateur 2026-10-09) — la saisie MANUELLE offre TOUTES les équipes,
  // coach facultatif : « il est facultatif si on passe en mode manuel ». U13 n'a aucun lien
  // MAIN mais doit être proposée (inverse exact de la règle 2026-08-01, qui ne vaut plus que
  // pour la collecte par mail).
  it("offre à la saisie manuelle TOUTES les équipes, y compris sans coach principal", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);

    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    // TeamSelect (P2-63 C) : les options ne vivent que le panneau ouvert.
    await user.click(screen.getByRole("button", { name: /^Équipe SM1/ }));
    expect(screen.getByRole("option", { name: "U13" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "SM1" })).toBeInTheDocument();
  });

  // …mais le FILTRE la garde : il sert à LIRE des doléances existantes, dont celles d'une
  // équipe qui a perdu son coach depuis. Cacher ne vaut que pour un CHOIX.
  it("garde toutes les équipes dans le filtre, y compris sans coach", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);

    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    expect(screen.getByRole("button", { name: "U13" })).toBeInTheDocument();
  });

  // Lot 5 (fondateur 2026-10-10) — plus de champ Coach : le coach est DÉDUIT. À la création, le
  // coach PRINCIPAL de l'équipe (recalculé au changement d'équipe) ; SM1 → Maxime (c1).
  it("déduit le coach principal de l'équipe à la création (sans champ Coach)", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);

    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    // Aucun champ Coach n'est rendu (déduction automatique).
    expect(screen.queryByLabelText("Coach")).toBeNull();
    // SM1 (défaut) → coach MAIN c1 déduit et envoyé au payload.
    await user.click(screen.getByRole("button", { name: /Ajouter la doléance/ }));
    expect(createMut).toHaveBeenCalledWith(expect.objectContaining({ teamId: "t1", coachId: "c1" }), expect.anything());
  });

  // ⚠ CHOISIR n'est pas NOMMER (leçon #342) : un coach qui a perdu son lien MAIN reste PORTÉ par
  // la doléance — l'édition ne le re-dérive JAMAIS vers le MAIN courant en silence. Léa (c2)
  // n'encadre pas SM1, mais une doléance SM1 qu'elle porte garde c2 à l'enregistrement.
  it("garde le coach d'une doléance existante même s'il n'encadre plus l'équipe (pas de re-dérivation)", async () => {
    wishesState.data = [wish({ id: "w1", teamId: "t1", coachId: "c2", weekStart: "2026-02-16" })]; // Léa n'encadre pas SM1
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);

    await user.click(screen.getByRole("button", { name: /^Modifier la doléance/ }));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    // Le coach d'origine (c2) survit — jamais re-dérivé vers le MAIN de SM1 (c1).
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ coachId: "c2" }) }), expect.anything());
  });

  // ── D2 — section Mutualisations ──

  it("liste une mutualisation déclarée avec ses partenaires et le nombre de séances", () => {
    mutualizationsState.data = [{ id: "m1", calendarEntryId: "e1", teamId: "t1", coachId: "c1", partnerTeamIds: ["t2", "t3"], sharedSlots: 2, done: false }];
    render(<WishesTab mother={mother} weekFilter={null} />);
    expect(screen.getByRole("heading", { name: "Mutualisations" })).toBeInTheDocument();
    expect(screen.getByText(/souhaite mutualiser 2 séances avec : U13, Fanion/)).toBeInTheDocument();
  });

  it("annonce l'état vide des mutualisations", () => {
    render(<WishesTab mother={mother} weekFilter={null} />);
    expect(screen.getByText("Aucune mutualisation déclarée pour cette période.")).toBeInTheDocument();
  });

  it("cocher « traité » sur une mutualisation appelle update avec done inversé", async () => {
    mutualizationsState.data = [{ id: "m1", calendarEntryId: "e1", teamId: "t1", coachId: "c1", partnerTeamIds: ["t2"], sharedSlots: 1, done: false }];
    render(<WishesTab mother={mother} weekFilter={null} />);
    await userEvent.click(screen.getByRole("checkbox", { name: /Traité — mutualisation/ }));
    expect(updateMutu).toHaveBeenCalledWith(expect.objectContaining({ id: "m1", body: expect.objectContaining({ done: true }) }));
  });

  it("le formulaire d'ajout de mutualisation soumet équipe + coach MAIN + partenaire + séances", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);
    await user.click(screen.getByRole("button", { name: /Ajouter une mutualisation/ }));
    // Équipe SM1 (défaut) → coach MAIN c1 ; on coche U13 en partenaire.
    await user.click(screen.getByRole("checkbox", { name: "Partenaire U13" }));
    await user.click(screen.getByRole("button", { name: /Ajouter la mutualisation/ }));
    expect(createMutu).toHaveBeenCalledWith(expect.objectContaining({ calendarEntryId: "e1", teamId: "t1", coachId: "c1", partnerTeamIds: ["t2"], sharedSlots: 1 }), expect.anything());
  });

  // P2-63 PR 2 / Q5 — sans AUCUN coach principal, la saisie manuelle reste possible (coach
  // facultatif) : « Ajouter » n'est plus désactivé, et le formulaire offre toutes les équipes.
  it("permet la saisie manuelle même quand aucune équipe n'a de coach principal", async () => {
    teamCoachesState.data = [];
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);

    const add = screen.getByRole("button", { name: "Ajouter" });
    expect(add).not.toHaveAttribute("aria-disabled", "true");
    await user.click(add);
    expect(screen.getByRole("button", { name: /^Équipe SM1/ })).toBeInTheDocument();
  });

  // ── P2-63 PR 2 / D — semaine d'abord, équipe servie désactivée, coach facultatif ──

  // Le founder : « sélection de la semaine d'abord ». Le sélecteur Semaine précède Équipe.
  it("D — rend la Semaine AVANT l'Équipe dans le formulaire d'ajout", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter={null} />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    const semaine = screen.getByLabelText("Semaine");
    const equipe = screen.getByRole("button", { name: /^Équipe SM1/ });
    // Ordre du DOM : Semaine précède Équipe (comparaison de position documentaire).
    expect(semaine.compareDocumentPosition(equipe) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  // Une équipe DÉJÀ servie sur la semaine choisie est DÉSACTIVÉE avec motif, jamais masquée.
  it("D — désactive avec motif une équipe déjà servie sur la semaine, sans la masquer", async () => {
    wishesState.data = [wish({ id: "w1", teamId: "t1", weekStart: "2026-02-16" })]; // SM1 servie S1
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    await user.click(screen.getByRole("button", { name: /^Équipe SM1/ }));

    const served = screen.getByRole("option", { name: /SM1/ });
    expect(served).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByText("a déjà une doléance cette semaine")).toBeInTheDocument();
    // U13, non servie, reste choisissable.
    expect(screen.getByRole("option", { name: "U13" })).not.toHaveAttribute("aria-disabled", "true");
  });

  // Q5 — coach FACULTATIF : une équipe sans coach principal (U13) se saisit avec « (aucun) »
  // et le payload porte coachId:null.
  it("Q5 — soumet une doléance manuelle sans coach (coachId null) pour une équipe sans coach", async () => {
    const user = userEvent.setup();
    render(<WishesTab mother={mother} weekFilter="2026-02-16" />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    await user.click(screen.getByRole("button", { name: /^Équipe SM1/ }));
    await user.click(screen.getByRole("option", { name: "U13" }));
    // U13 n'a pas de coach principal : le coach déduit est « aucun » → coachId null au payload.
    await user.click(screen.getByRole("button", { name: /Ajouter la doléance/ }));
    expect(createMut).toHaveBeenCalledWith(expect.objectContaining({ teamId: "t2", coachId: null }), expect.anything());
  });
});