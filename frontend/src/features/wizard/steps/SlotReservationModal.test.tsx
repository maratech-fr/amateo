import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { openListbox, pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";
import { WIZARD_TEXTS } from "../texts";

import type { Coach, PriorityTier, Reservation, SharedTrainingBlock, Team, TeamSoloBudget, Venue, VenueTrainingSlot } from "../api";

// Les mutations sont mockées : le test porte sur le GESTE (quel rail est appelé, dans quel ordre),
// pas sur react-query.
const createMut = vi.fn();
const delMut = vi.fn();
const groupMut = vi.fn();
let callOrder: string[] = [];

vi.mock("../queries", () => ({
  useCreateReservation: () => ({ mutateAsync: createMut, isPending: false }),
  useDeleteReservation: () => ({ mutateAsync: delMut, isPending: false }),
  useCreateGroupReservation: () => ({ mutateAsync: groupMut, isPending: false }),
}));

import { SlotReservationModal } from "./SlotReservationModal";

const team = (id: string, name: string, sessionsPerWeek = 2): Team =>
  ({ id, name, sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: "M", level: null, sessionsPerWeek, isActive: true }) as Team;

const TEAMS: Team[] = [team("a", "SM1"), team("b", "SM2"), team("c", "SM3"), team("d", "SM4")];
const TIERS: PriorityTier[] = [{ id: 1, label: "S", name: "Fanion", color: null }];
const VENUE: Venue = { id: "v1", name: "Gymnase A", color: null, canSplit: true, isActive: true };
const SLOT: VenueTrainingSlot = { id: "slot1", venueId: "v1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90, capacity: 2 };

const block = (id: string, teamIds: string[], commonSessions = 1): SharedTrainingBlock =>
  ({ id, version: 1, createdAt: "2026-08-31T00:00:00+00:00", updatedAt: "2026-08-31T00:00:00+00:00", schedulePlanId: null, teamIds, commonSessions, label: null, sessions: [] });

const resa = (teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation =>
  ({ id: `${teamId}-${venueId}-${dayOfWeek}-${startTime}`, schedulePlanId: null, teamId, venueId, dayOfWeek, startTime, durationMinutes: 90 });

/** Budget solo servi par le backend (P2-60) — le sélecteur AFFICHE ce budget, il ne le recalcule pas. */
const soloBudget = (teamId: string, o: Partial<TeamSoloBudget> = {}): TeamSoloBudget =>
  ({ teamId, schedulePlanId: null, effectiveSessions: 2, blockSessions: 0, residual: 2, individualUsed: 0, inBlock: false, ...o });
// Défaut : chaque équipe garde 2 créneaux libres ; a/b sont membres du bloc « g » (inBlock).
const DEFAULT_BUDGETS: TeamSoloBudget[] = [soloBudget("a", { inBlock: true }), soloBudget("b", { inBlock: true }), soloBudget("c"), soloBudget("d")];

/** Une HTTPError ky (4xx) porteuse d'un corps `{ error }` — `errorMessage` lit `error.data` ET `error.response.status`. */
const httpError = (message: string): HTTPError => {
  const response = new Response(JSON.stringify({ error: message }), { status: 422, headers: { "content-type": "application/json" } });
  const err = new HTTPError(response, new Request("http://localhost/api/reservations"), {} as never);
  (err as unknown as { data?: unknown }).data = { error: message };
  return err;
};

function renderModal(overrides: Partial<Parameters<typeof SlotReservationModal>[0]> = {}) {
  return renderWithProviders(
    <SlotReservationModal
      slot={SLOT}
      venue={VENUE}
      teams={TEAMS}
      tiers={TIERS}
      reservations={[]}
      teamCoaches={[]}
      coachesPending={false}
      coachesFailed={false}
      onRetryCoaches={vi.fn()}
      venues={[VENUE]}
      venueCanSplit={new Map([["v1", true]])}
      sharedTrainingBlocks={[block("g", ["a", "b"])]}
      teamSoloBudgets={DEFAULT_BUDGETS}
      budgetsPending={false}
      budgetsFailed={false}
      onRetryBudgets={vi.fn()}
      schedulePlanId={null}
      onClose={vi.fn()}
      {...overrides}
    />,
  );
}

/** Le trigger de la listbox (bouton), absent quand la saisie est fermée. */
const selectorTrigger = () => screen.queryByRole("button", { name: /Ajouter une équipe/ });
const BLOCK_LABEL = "SM1 + SM2 — 1 séance commune";

beforeEach(() => {
  callOrder = [];
  createMut.mockReset().mockImplementation(async () => void callOrder.push("create"));
  delMut.mockReset().mockImplementation(async () => void callOrder.push("del"));
  groupMut.mockReset().mockImplementation(async () => {
    callOrder.push("group");

    return { ids: ["r1", "r2"], count: 2 };
  });
});

describe("SlotReservationModal — relais de l'adjoint (chantier H)", () => {
  // Décor : coach m principal de SM3 et SM4 ; SM4 a un adjoint j. SM3 déjà réservée au gymnase 2
  // (17:00-18:30) ; on pose SM4 sur le créneau v1 18:00-19:30. Le coach enchaîne : il quitte SM3 à
  // 18:30 et rejoint SM4 à 18:00 → retard 30 (trajet v1↔v2 nul). Tolérable, et j peut ouvrir.
  const SHARED_COACHES = [
    { id: "tc-c", teamId: "c", coachId: "m", role: "MAIN" as const },
    { id: "tc-d", teamId: "d", coachId: "m", role: "MAIN" as const },
    { id: "tc-j", teamId: "d", coachId: "j", role: "ASSISTANT" as const },
  ];
  const V2: Venue = { id: "v2", name: "Gymnase B", color: null, canSplit: false, isActive: true };
  // Les coachs NOMMÉS, comme `useWizardCoaches`/`listCoaches` les sert : le CTA de relais doit
  // nommer l'adjoint éligible (« Jules Martin »), pas un « adjoint » générique.
  const COACHES: Coach[] = [
    { id: "m", firstName: "Maxime", lastName: "Durand" } as Coach,
    { id: "j", firstName: "Jules", lastName: "Martin" } as Coach,
  ];

  function renderRelay(extra: Partial<Parameters<typeof SlotReservationModal>[0]> = {}) {
    return renderModal({
      teamCoaches: SHARED_COACHES,
      coaches: COACHES,
      reservations: [resa("c", "v2", 1, "17:00")],
      venues: [VENUE, V2],
      sharedTrainingBlocks: [],
      // Barème de trajet v1↔v2 = 0 (coach non véhiculé) → retard 30, tolérable. Donnée servie au
      // wizard par le backend (chantier H) — prop consommée par la modale pour décider du relais.
      venueTravelTimes: [{ venueAId: "v1", venueBId: "v2", walkingMinutes: 0, drivingMinutes: 0 }],
      ...extra,
    });
  }

  it("au refus d'un dédoublement TOLÉRABLE avec adjoint, propose un CTA de tolérance (pas le refus dur)", async () => {
    renderRelay();
    await pickListboxOption(userEvent.setup(), "Ajouter une équipe", "SM4");

    // Un CTA primaire propose de tolérer le retard et nomme l'adjoint qui ouvrira la séance.
    expect(screen.getByRole("button", { name: /tolérer ce retard/i })).toBeInTheDocument();
    // Un champ éditable (0..30) porte le retard pré-rempli.
    expect(screen.getByRole("spinbutton", { name: /retard toléré/i })).toBeInTheDocument();
    // Un bandeau d'avertissement (role=status), jamais le refus dur « ne peut pas être ajoutée ».
    expect(screen.queryByText(/ne peut pas être ajoutée/i)).toBeNull();
  });

  it("le CTA NOMME l'adjoint éligible, jamais un « adjoint » générique", async () => {
    // Promesse validée : « Tolérer ce retard (~N minutes) — Jules Martin (adjoint) ouvrira la
    // séance ». Le CTA porte le PRÉNOM NOM de l'adjoint qui ouvrira (SM4 → adjoint Jules Martin).
    renderRelay();
    await pickListboxOption(userEvent.setup(), "Ajouter une équipe", "SM4");

    expect(screen.getByRole("button", { name: /tolérer ce retard.*Jules Martin.*adjoint.*ouvrira la séance/i })).toBeInTheDocument();
  });

  it("une fois le retard toléré, une pastille l'affiche et un bouton texte le retire", async () => {
    renderRelay();
    const user = userEvent.setup();
    await pickListboxOption(user, "Ajouter une équipe", "SM4");
    await user.click(screen.getByRole("button", { name: /tolérer ce retard/i }));

    expect(screen.getByText(/retard toléré · \d+ min/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /retirer la tolérance/i })).toBeInTheDocument();
  });

  it("un dédoublement NON tolérable (sans adjoint) garde le refus dur, sans CTA", async () => {
    // SM4 sans adjoint j : rien n'ouvre la séance → refus dur inchangé, aucun CTA de tolérance.
    renderRelay({ teamCoaches: SHARED_COACHES.filter((l) => l.coachId !== "j") });
    await pickListboxOption(userEvent.setup(), "Ajouter une équipe", "SM4");

    expect(screen.queryByRole("button", { name: /tolérer ce retard/i })).toBeNull();
    expect(screen.getByText(/ne peut pas être ajoutée/i)).toBeInTheDocument();
  });

  it("un second coach principal tient la séance : ni refus ni tolérance à proposer", async () => {
    // SM3 (m) réservée au gymnase 2 au même moment que SM4 (m + l) qu'on pose ici : l tient SM4.
    const secondMain = { id: "tc-l", teamId: "d", coachId: "l", role: "MAIN" as const };
    renderRelay({ teamCoaches: [...SHARED_COACHES, secondMain], reservations: [resa("c", "v2", 1, "18:00")] });
    await pickListboxOption(userEvent.setup(), "Ajouter une équipe", "SM4");

    expect(screen.queryByText(/ne peut pas être ajoutée/i)).toBeNull();
    expect(screen.queryByRole("button", { name: /tolérer ce retard/i })).toBeNull();
  });
});

describe("SlotReservationModal — mutualisation par bloc (P2-51)", () => {
  it("offre le bloc sous « Entraînements mutualisés » sur un créneau LIBRE", async () => {
    renderModal();
    const list = await openListbox(userEvent.setup(), "Ajouter une équipe");
    const option = within(list).getByRole("option", { name: BLOCK_LABEL });
    expect(option).toBeInTheDocument();
    expect(option.closest('[role="group"]')).toHaveAccessibleName("Entraînements mutualisés");
  });

  it("n'offre PAS le bloc sur un créneau occupé, et dit POURQUOI (raison visible)", async () => {
    renderModal({ reservations: [resa("c", "v1", 1, "18:00")] });
    const list = await openListbox(userEvent.setup(), "Ajouter une équipe");
    expect(within(list).queryByRole("option", { name: BLOCK_LABEL })).toBeNull();
    expect(screen.getByText(/ne se pose que sur un créneau libre/i)).toBeInTheDocument();
  });

  it("un bloc posé dans le brouillon ferme le sélecteur sur une raison NOMMÉE", async () => {
    renderModal();
    await pickListboxOption(userEvent.setup(), "Ajouter une équipe", BLOCK_LABEL);
    expect(screen.getByText(/occupe seul ce créneau/i)).toBeInTheDocument();
    expect(selectorTrigger()).toBeNull();
    // Le lot en brouillon est nommé et « à valider ».
    expect(screen.getByText(/SM1 \+ SM2/)).toBeInTheDocument();
    expect(screen.getByText(WIZARD_TEXTS.toValidate)).toBeInTheDocument();
  });

  it("« retirer SM4 + poser le bloc » passe en UNE validation, retraits AVANT ajouts, un seul appel au rail bloc", async () => {
    const onClose = vi.fn();
    const user = userEvent.setup();
    renderModal({ reservations: [resa("d", "v1", 1, "18:00")], onClose });

    await user.click(screen.getByRole("button", { name: "Retirer SM4" }));
    await pickListboxOption(user, "Ajouter une équipe", BLOCK_LABEL);
    await user.click(screen.getByRole("button", { name: "Valider" }));

    expect(callOrder).toEqual(["del", "group"]); // le retrait libère la case AVANT que le bloc s'y pose
    expect(delMut).toHaveBeenCalledWith("d-v1-1-18:00");
    expect(groupMut).toHaveBeenCalledTimes(1);
    expect(groupMut).toHaveBeenCalledWith({ sharedTrainingBlockId: "g", venueId: "v1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90, schedulePlanId: null });
    expect(createMut).not.toHaveBeenCalled(); // JAMAIS N POST individuels
    expect(onClose).toHaveBeenCalled();
  });

  it("un lot mutualisé DÉJÀ posé s'affiche en UNE ligne, et son retrait empile N DELETE", async () => {
    renderModal({ reservations: [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00")] });

    // Une seule ligne pour le lot (pas deux verrous anonymes).
    await userEvent.click(screen.getByRole("button", { name: "Retirer l'entraînement mutualisé SM1 + SM2" }));
    await userEvent.click(screen.getByRole("button", { name: "Valider" }));

    expect(delMut).toHaveBeenCalledTimes(2);
    expect(delMut).toHaveBeenCalledWith("a-v1-1-18:00");
    expect(delMut).toHaveBeenCalledWith("b-v1-1-18:00");
    expect(groupMut).not.toHaveBeenCalled();
  });

  it("le retrait d'un lot posé s'annonce en UNE ligne « à valider », avec UN Undo qui restaure les deux (P2-62)", async () => {
    renderModal({ reservations: [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00")] });

    await userEvent.click(screen.getByRole("button", { name: "Retirer l'entraînement mutualisé SM1 + SM2" }));

    // UNE ligne de lot « à valider » et UN Undo — jamais deux Undo par membre (un Undo partiel
    // promettrait le retrait d'UN seul membre, que le serveur ne fait pas : il vide toute la case).
    expect(screen.getByText(/retrait à valider/)).toBeInTheDocument();
    const undo = screen.getByRole("button", { name: "Annuler le retrait de l'entraînement mutualisé SM1 + SM2" });
    expect(screen.queryByRole("button", { name: "Annuler le retrait de SM1" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Annuler le retrait de SM2" })).toBeNull();

    // L'Undo restaure les DEUX ids : la ligne posée du lot réapparaît.
    await userEvent.click(undo);
    expect(screen.getByRole("button", { name: "Retirer l'entraînement mutualisé SM1 + SM2" })).toBeInTheDocument();
  });

  it("valider le retrait d'un lot ferme la modale sans erreur, même si une sœur répond déjà 404 (P2-62)", async () => {
    const onClose = vi.fn();
    // Le DELETE tolère le 404 (api.ts, testé à part) : la 2ᵉ sœur, déjà emportée par la 1ʳᵉ, résout.
    delMut.mockReset().mockResolvedValue(undefined);
    renderModal({ reservations: [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00")], onClose });

    await userEvent.click(screen.getByRole("button", { name: "Retirer l'entraînement mutualisé SM1 + SM2" }));
    await userEvent.click(screen.getByRole("button", { name: "Valider" }));

    expect(delMut).toHaveBeenCalledTimes(2);
    expect(onClose).toHaveBeenCalled();
    expect(screen.queryByRole("alert")).toBeNull();
  });

  // P4-150 — sur un créneau libre où aucune équipe ni aucun groupe n'est proposable,
  // la copie d'écran de l'état vide est assertée (elle ne s'affiche QUE dans ce cas).
  it("annonce « Aucune équipe disponible » (role=status) quand rien n'est proposable sur un créneau libre", () => {
    renderModal({ teams: [], sharedTrainingBlocks: [] });
    const hint = screen.getByText(WIZARD_TEXTS.noTeamAvailable);
    expect(hint).toBeInTheDocument();
    // L'invite REMPLACE le contrôle et s'annonce à un lecteur d'écran.
    expect(hint).toHaveAttribute("role", "status");
  });

  it("un bloc ayant atteint ses séances communes n'est PAS offert, avec sa raison", async () => {
    // Une case complète {a,b} ailleurs dans la portée → séances communes (1) atteintes.
    renderModal({ reservations: [resa("a", "v1", 3, "20:00"), resa("b", "v1", 3, "20:00")] });
    const list = await openListbox(userEvent.setup(), "Ajouter une équipe");
    expect(within(list).queryByRole("option", { name: BLOCK_LABEL })).toBeNull();
    expect(screen.getByText(/séance commune est déjà posée/i)).toBeInTheDocument();
    expect(screen.getByText(/indisponible/i)).toBeInTheDocument();
  });

  /**
   * #12 (décision fondateur 2026-10-13 « groupe mutualisé = une équipe ») — sur un créneau à capacité 1
   * occupé par un bloc COMPLET {SM1,SM2} (deux réservations), la modale dit « un entraînement mutualisé
   * occupe seul ce créneau » et JAMAIS « Créneau complet (2/1) » : le groupe compte pour UN occupant.
   * Angle MODALE de la même promesse que `ReservationGrid.test.tsx` (grille) et `blockOccupancy.test.ts`
   * (foyer) ; domaine sensible (mutualisation, charte §1). Le CONTRÔLE prouve que la case n'est pas
   * reconnue par hasard : sans le bloc déclaré, elle retombe sur « Créneau complet (2/1) ».
   */
  it("un créneau cap 1 occupé par un bloc complet ne déclenche PAS « Créneau complet (2/1) »", () => {
    renderModal({
      slot: { ...SLOT, capacity: 1 },
      venueCanSplit: new Map([["v1", false]]),
      reservations: [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00")],
    });

    // Le bon motif : le groupe occupe la case seul (règle b), pas un faux « complet ».
    expect(screen.getByText(/occupe seul ce créneau/i)).toBeInTheDocument();
    expect(screen.queryByText(/Créneau complet/i)).toBeNull();
    expect(screen.queryByText(/2\/1/)).toBeNull();
  });

  it("CONTRÔLE — la même case cap 1 SANS le bloc déclaré retombe bien sur « Créneau complet (2/1) »", () => {
    renderModal({
      slot: { ...SLOT, capacity: 1 },
      venueCanSplit: new Map([["v1", false]]),
      sharedTrainingBlocks: [],
      reservations: [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00")],
    });

    expect(screen.getByText(/Créneau complet/i)).toBeInTheDocument();
    expect(screen.getByText(/2\/1/)).toBeInTheDocument();
  });
});

describe("SlotReservationModal — budget solo servi par le backend (P2-60 / P4-164)", () => {
  it("un membre de bloc à résidu nul RESTE visible, désactivé, avec aiguillage vers son groupe (P4-164)", async () => {
    // SM1 : toutes ses séances viennent du bloc (résidu 0) → option DÉSACTIVÉE (jamais disparue),
    // motif + « passez par le groupe … » ; le bloc SM1 + SM2 reste proposé.
    renderModal({
      teamSoloBudgets: [
        soloBudget("a", { residual: 0, blockSessions: 2, inBlock: true }),
        soloBudget("b", { residual: 0, blockSessions: 2, inBlock: true }),
        soloBudget("c"),
        soloBudget("d"),
      ],
    });
    const list = await openListbox(userEvent.setup(), "Ajouter une équipe");
    const sm1 = within(list).getByRole("option", { name: "SM1" });
    expect(sm1).toHaveAttribute("aria-disabled", "true");
    const desc = document.getElementById(sm1.getAttribute("aria-describedby") ?? "");
    expect(desc?.textContent).toMatch(/Tous ses créneaux sont placés/);
    expect(desc?.textContent).toMatch(/passez par le groupe SM1 \+ SM2/);
    expect(within(list).getByRole("option", { name: BLOCK_LABEL })).toBeInTheDocument();
  });

  it("étiquette chaque option de son résidu (count à droite) et « hors groupe » (sous-ligne) — pluriel/singulier", async () => {
    renderModal({
      sharedTrainingBlocks: [],
      teamSoloBudgets: [
        soloBudget("a", { residual: 3, individualUsed: 1, effectiveSessions: 4, blockSessions: 1, inBlock: true }), // reste 2 hors groupe
        soloBudget("b", { residual: 1, individualUsed: 0, effectiveSessions: 2, blockSessions: 1, inBlock: true }), // reste 1 hors groupe
        soloBudget("c", { residual: 1, individualUsed: 0, effectiveSessions: 1, blockSessions: 0, inBlock: false }), // reste 1 créneau
        soloBudget("d", { residual: 2, individualUsed: 0, effectiveSessions: 2, blockSessions: 0, inBlock: false }), // reste 2 créneaux
      ],
    });
    const list = await openListbox(userEvent.setup(), "Ajouter une équipe");
    // Le NOM de l'option reste l'équipe seule ; le résidu et « hors groupe » sont des textes distincts.
    const opt = (name: string) => within(list).getByRole("option", { name });
    expect(within(opt("SM1")).getByText(WIZARD_TEXTS.soloResidual(2))).toBeInTheDocument();
    expect(within(opt("SM1")).getByText(WIZARD_TEXTS.outOfGroup)).toBeInTheDocument();
    expect(within(opt("SM2")).getByText(WIZARD_TEXTS.soloResidual(1))).toBeInTheDocument();
    expect(within(opt("SM2")).getByText(WIZARD_TEXTS.outOfGroup)).toBeInTheDocument();
    expect(within(opt("SM3")).getByText(WIZARD_TEXTS.soloResidual(1))).toBeInTheDocument();
    expect(within(opt("SM3")).queryByText(WIZARD_TEXTS.outOfGroup)).toBeNull();
    expect(within(opt("SM4")).getByText(WIZARD_TEXTS.soloResidual(2))).toBeInTheDocument();
  });

  it("une équipe en PAUSE n'est JAMAIS proposée, même à résidu disponible (verrou décision)", async () => {
    renderModal({ sharedTrainingBlocks: [], pausedTeamIds: new Set(["a"]) });
    const list = await openListbox(userEvent.setup(), "Ajouter une équipe");
    expect(within(list).queryByRole("option", { name: "SM1" })).toBeNull(); // a en pause → ni active, ni désactivée
    expect(within(list).getByRole("option", { name: "SM2" })).toBeInTheDocument();
  });

  it("une fois choisie, une équipe dont le dernier créneau est consommé quitte le sélecteur", async () => {
    // Créneau divisible (2 places) ; SM3 n'a qu'un créneau libre, les autres en ont encore (le sélecteur reste).
    const user = userEvent.setup();
    renderModal({ sharedTrainingBlocks: [], teamSoloBudgets: [soloBudget("a"), soloBudget("b"), soloBudget("c", { residual: 1, effectiveSessions: 1 }), soloBudget("d")] });
    await pickListboxOption(user, "Ajouter une équipe", "SM3");
    // SM3 passe « à valider » et ne réapparaît plus dans le sélecteur (créneau libre restant, mais résidu épuisé + déjà sur la case).
    expect(screen.getByText(WIZARD_TEXTS.toValidate)).toBeInTheDocument();
    const list = await openListbox(user, "Ajouter une équipe");
    expect(within(list).queryByRole("option", { name: "SM3" })).toBeNull();
  });

  it("résidu 0 pour toutes : les équipes restent VISIBLES et désactivées avec leur motif (P4-164)", async () => {
    renderModal({
      sharedTrainingBlocks: [],
      teamSoloBudgets: [soloBudget("a", { residual: 0 }), soloBudget("b", { residual: 0 }), soloBudget("c", { residual: 0 }), soloBudget("d", { residual: 0 })],
    });
    const list = await openListbox(userEvent.setup(), "Ajouter une équipe");
    const sm1 = within(list).getByRole("option", { name: "SM1" });
    expect(sm1).toHaveAttribute("aria-disabled", "true");
    expect(within(sm1).getByText(WIZARD_TEXTS.allSlotsPlaced)).toBeInTheDocument();
    expect(within(sm1).queryByText(/passez par le groupe/)).toBeNull(); // pas inBlock → aucun aiguillage
  });

  it("budget en échec : la saisie est fermée (fail-closed) avec un bouton Réessayer", async () => {
    const onRetryBudgets = vi.fn();
    const user = userEvent.setup();
    renderModal({ teamSoloBudgets: null, budgetsFailed: true, onRetryBudgets });
    expect(selectorTrigger()).toBeNull();
    const retry = screen.getByRole("button", { name: /réessayer/i });
    await user.click(retry);
    expect(onRetryBudgets).toHaveBeenCalled();
  });

  it("un 422 serveur sur un ajout unitaire affiche le message du serveur dans l'alerte, la ligne « à valider » restant", async () => {
    createMut.mockReset().mockRejectedValue(httpError("SM1 a déjà toutes ses réservations individuelles pour ce plan."));
    const user = userEvent.setup();
    renderModal({ sharedTrainingBlocks: [] });

    await pickListboxOption(user, "Ajouter une équipe", "SM1"); // choisir SM1 (résidu 2 par défaut)
    expect(screen.getByText(WIZARD_TEXTS.toValidate)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Valider" }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("SM1 a déjà toutes ses réservations individuelles pour ce plan.");
    // La ligne « à valider » de l'équipe refusée reste, avec son Undo (rejeu possible).
    expect(screen.getByText(WIZARD_TEXTS.toValidate)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Annuler l'ajout de SM1" })).toBeInTheDocument();
  });
});

