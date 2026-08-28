import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";
import { useNavTransition } from "@/shared/stores/navTransitionStore";

// Established club (a main plan exists) → free wizard navigation, not guided.
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "b1", hasFinishedVersion: true }, club: { id: "c", name: "C", onboardingCompleted: true } } }),
  // Le panneau des règles du système (dans l'étape Contraintes) lit la saison de travail pour
  // savoir si elle est archivée (lecture seule). Non-archivée par défaut ici.
  useWorkingSeason: () => null,
}));

// Garde d'abandon de période (retour fondateur 2026-07-18) : contrôle des
// données plan/versions par variables — le défaut (plan vide) arme le dialogue.
// La confirmation re-lit le serveur (fetchQuery → listSchedules) : `freshSchedules`
// pilote cette lecture FRAÎCHE, indépendamment du cache affiché (`schedulesData`).
const deleteEntryMutateAsync = vi.fn(() => Promise.resolve({}));
let periodPlanId: string | null = "plan-x";
let schedulesData: { schedulePlanId: string }[] | undefined = [];
let freshSchedules: { schedulePlanId: string }[] = [];
vi.mock("@/features/cockpit/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/cockpit/queries")>()),
  useCalendarEntry: () => ({ data: { id: "entry-x", title: "Vacances de la Toussaint", startDate: "2026-10-16", endDate: "2026-10-31" }, error: null }),
  usePeriodAnchor: () => (null === periodPlanId ? { state: "loading", planId: null } : { state: "period", planId: periodPlanId }),
  anchorIsWritable: (a: { state: string }) => "period" === a.state || "base" === a.state,
  useDeleteEntry: () => ({ mutate: vi.fn(), mutateAsync: deleteEntryMutateAsync, isPending: false }),
}));
vi.mock("@/features/planning/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/planning/queries")>()),
  useSchedules: () => ({ data: schedulesData }),
}));
vi.mock("@/features/planning/api", async (orig) => ({
  ...(await orig<typeof import("@/features/planning/api")>()),
  listSchedules: vi.fn(() => Promise.resolve(freshSchedules)),
}));

import * as api from "./api";
import { useWizardStore } from "./store";
import { WizardPage } from "./WizardLayout";

vi.mock("./api", async (orig) => ({
  // Partiel : les steps non ciblés (Contraintes en mode période) touchent d'autres
  // exports — un mock total ferait THROW tout l'arbre via l'ErrorBoundary du router.
  ...(await orig<typeof import("./api")>()),
  listTeams: vi.fn(() => Promise.resolve([{ id: "t1", name: "SF1", sportCategoryId: "cat1", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true }])),
  listSportCategories: vi.fn(() => Promise.resolve([{ id: "cat1", name: "U11", sortOrder: 0 }])),
  listPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Elite", color: null }, { id: 2, label: "A", name: "Régional", color: null }])),
  createTeam: vi.fn(() => Promise.resolve({})),
  updateTeam: vi.fn(() => Promise.resolve({})),
  reorderTeams: vi.fn(() => Promise.resolve({})),
  deleteTeam: vi.fn(() => Promise.resolve()),
  listVenues: vi.fn(() => Promise.resolve([])),
  listVenueSlots: vi.fn(() => Promise.resolve([])),
  createVenue: vi.fn(() => Promise.resolve({})),
  updateVenue: vi.fn(() => Promise.resolve({})),
  deleteVenue: vi.fn(() => Promise.resolve()),
  createSlot: vi.fn(() => Promise.resolve({})),
  deleteSlot: vi.fn(() => Promise.resolve()),
  listCoaches: vi.fn(() => Promise.resolve([])),
  listTeamCoaches: vi.fn(() => Promise.resolve([])),
  listCoachPlayers: vi.fn(() => Promise.resolve([])),
  createCoach: vi.fn(() => Promise.resolve({})),
  updateCoach: vi.fn(() => Promise.resolve({})),
  deleteCoach: vi.fn(() => Promise.resolve()),
  createTeamCoach: vi.fn(() => Promise.resolve({})),
  deleteTeamCoach: vi.fn(() => Promise.resolve()),
  createCoachPlayer: vi.fn(() => Promise.resolve({})),
  deleteCoachPlayer: vi.fn(() => Promise.resolve()),
  listConstraints: vi.fn(() => Promise.resolve([])),
  createConstraint: vi.fn(() => Promise.resolve({})),
  deleteConstraint: vi.fn(() => Promise.resolve()),
  // P2-28 — le panneau des règles du système (étape Contraintes) lit les 4 règles résolues.
  listImplicitRuleSettings: vi.fn(() => Promise.resolve([
    { ruleKey: "coachRestDay", intensity: "HARD", minRestDays: 1, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
    { ruleKey: "salarieDistribution", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
    { ruleKey: "maxConsecutiveSessions", intensity: "HARD", minRestDays: null, maxConsecutive: 3, maxConsecutiveDays: null, isDefault: true },
    { ruleKey: "ageAscending", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
  ])),
  validateConstraints: vi.fn(() => Promise.resolve({ valid: true, errors: {}, conflicts: [] })),
  createSchedule: vi.fn(() => Promise.resolve({ id: "s1" })),
  generateSchedule: vi.fn(() => Promise.resolve({})),
}));

beforeEach(() => {
  useWizardStore.setState({ stepId: "teams", mode: "season", calendarEntryId: null });
  useNavTransition.setState({ token: 0 }); // NR voile : aucune transition en cours entre deux tests
  vi.mocked(api.listTeams).mockResolvedValue([{ id: "t1", name: "SF1", sportCategoryId: "cat1", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true }]);
  deleteEntryMutateAsync.mockClear();
  periodPlanId = "plan-x";
  schedulesData = [];
  freshSchedules = [];
});

describe("Wizard (integration)", () => {
  it("renders the Teams step with a team grouped under its tier", async () => {
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    expect(await screen.findByDisplayValue("SF1")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "S · Fanion" })).toBeInTheDocument();
  });

  it("advances to the next step via Suivant when the step is valid", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    await screen.findByDisplayValue("SF1");

    await user.click(screen.getByRole("button", { name: "Suivant" }));
    // Assert on the Venues step BODY (its own control), not the store-derived
    // sticky header, so the test still proves the step component actually rendered.
    expect(await screen.findByLabelText("Nom du gymnase")).toBeInTheDocument();
  });

  it("blocks Suivant + shows an alert when there is no team", async () => {
    vi.mocked(api.listTeams).mockResolvedValue([]);
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    expect(await screen.findByRole("alert")).toHaveTextContent("Ajoutez au moins une équipe");
    expect(screen.getByRole("button", { name: "Suivant" })).toBeDisabled();
  });

  // Bug Toussaint (retour fondateur 2026-07-18) : « Adapter » crée la période
  // AVANT le wizard ; repartir sans rien générer laissait une entrée orpheline.
  it("quitting an untouched period adjustment offers to remove the period, and deletes on confirm", async () => {
    const user = userEvent.setup();
    useWizardStore.setState({ mode: "period", calendarEntryId: "entry-x", stepId: "constraints" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    await user.click(await screen.findByRole("button", { name: /Retour à l'accueil/ }));
    // Rien n'est supprimé sans confirmation explicite.
    expect(deleteEntryMutateAsync).not.toHaveBeenCalled();
    expect(await screen.findByRole("dialog")).toHaveTextContent("Quitter l'ajustement ?");

    await user.click(screen.getByRole("button", { name: "Retirer la période" }));
    // La confirmation re-vérifie le serveur (lecture fraîche) puis supprime.
    await waitFor(() => expect(deleteEntryMutateAsync).toHaveBeenCalledWith("entry-x"));
  });

  it("confirm does NOT delete when the fresh server read reveals a version launched meanwhile", async () => {
    // Le cache dit « vide » (dialogue armé) mais le serveur, relu à la confirmation,
    // a la version lancée entre-temps → période CONSERVÉE (revue #260 round 1 :
    // supprimer sur le cache détruirait la génération en vol via la cascade).
    const user = userEvent.setup();
    freshSchedules = [{ schedulePlanId: "plan-x" }];
    useWizardStore.setState({ mode: "period", calendarEntryId: "entry-x", stepId: "constraints" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    await user.click(await screen.findByRole("button", { name: /Retour à l'accueil/ }));
    await user.click(await screen.findByRole("button", { name: "Retirer la période" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(deleteEntryMutateAsync).not.toHaveBeenCalled();
  });

  it("still offers the removal dialog when the schedules cache is unresolved (no silent orphan)", async () => {
    // Round 2 : donnée en vol/en échec ≠ « il y a des versions » — sortir en
    // silence referait l'orphelin. Le dialogue s'arme ; la vérité se lit au
    // serveur à la confirmation (ici : frais = vide → suppression).
    const user = userEvent.setup();
    schedulesData = undefined;
    useWizardStore.setState({ mode: "period", calendarEntryId: "entry-x", stepId: "constraints" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    await user.click(await screen.findByRole("button", { name: /Retour à l'accueil/ }));
    expect(await screen.findByRole("dialog")).toHaveTextContent("Quitter l'ajustement ?");
    await user.click(screen.getByRole("button", { name: "Retirer la période" }));
    await waitFor(() => expect(deleteEntryMutateAsync).toHaveBeenCalledWith("entry-x"));
  });

  it("quitting a period whose plan HAS versions leaves silently (no dialog, nothing deleted)", async () => {
    const user = userEvent.setup();
    schedulesData = [{ schedulePlanId: "plan-x" }];
    useWizardStore.setState({ mode: "period", calendarEntryId: "entry-x", stepId: "constraints" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    await user.click(await screen.findByRole("button", { name: /Retour à l'accueil/ }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(deleteEntryMutateAsync).not.toHaveBeenCalled();
  });

  // ── P2-25 — le wizard est adressable ; un lien atterrit SUR l'objet, pas sur l'écran ──
  describe("deep-links (P2-25)", () => {
    beforeEach(() => {
      useWizardStore.setState({ maxIndex: 0, deepLinkOrigin: null });
    });

    it("`?step=venues&slot=X` ouvre le wizard SUR le créneau X (étape Gymnases + éditeur du créneau)", async () => {
      vi.mocked(api.listVenues).mockResolvedValue([{ id: "v1", name: "Gymnase A", color: "#3498DB", canSplit: true, isActive: true, externalRef: null }]);
      vi.mocked(api.listVenueSlots).mockResolvedValue([{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 }]);
      renderWithProviders(<WizardPage />, { route: "/wizard?step=venues&slot=s1" });

      // Sur l'ÉCRAN : l'étape Gymnases est rendue…
      expect(await screen.findByText(/Ajoutez vos gymnases/)).toBeInTheDocument();
      // …et sur l'OBJET : l'éditeur du créneau s1 est ouvert. C'EST le cœur du lot — sans
      // positionnement, l'écran serait là mais pas l'objet (falsification).
      expect(await screen.findByText("Modifier le créneau")).toBeInTheDocument();
    });

    it("un slot inconnu (id supprimé) → atterrissage propre sur l'étape, sans éditeur ni écran cassé", async () => {
      vi.mocked(api.listVenues).mockResolvedValue([{ id: "v1", name: "Gymnase A", color: "#3498DB", canSplit: true, isActive: true, externalRef: null }]);
      vi.mocked(api.listVenueSlots).mockResolvedValue([{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 }]);
      renderWithProviders(<WizardPage />, { route: "/wizard?step=venues&slot=ghost" });

      expect(await screen.findByText(/Ajoutez vos gymnases/)).toBeInTheDocument();
      expect(screen.queryByText("Modifier le créneau")).not.toBeInTheDocument();
    });

    it("`?step=constraints&edit=Y` ouvre l'éditeur PRÉ-REMPLI sur la contrainte Y", async () => {
      vi.mocked(api.listConstraints).mockResolvedValue([
        { id: "k1", name: "Toutes · pas après 19:50", scope: "CLUB", scopeTargetId: null, family: "TIME", ruleType: "PREFERRED", config: { maxStartTime: "19:50" }, isActive: true },
      ]);
      renderWithProviders(<WizardPage />, { route: "/wizard?step=constraints&edit=k1" });

      // Édition en cours (le bouton bascule en « Enregistrer ») ET le champ est PRÉ-REMPLI sur la
      // valeur de la contrainte — arriver sur l'écran ne suffit pas, il faut être SUR l'objet.
      expect(await screen.findByRole("button", { name: "Enregistrer la contrainte" })).toBeInTheDocument();
      expect(await screen.findByLabelText("Pas après")).toHaveValue("19:50");
    });

    it("un `edit=` inconnu → onglet Contraintes propre, aucun éditeur en cours", async () => {
      vi.mocked(api.listConstraints).mockResolvedValue([]);
      renderWithProviders(<WizardPage />, { route: "/wizard?step=constraints&edit=ghost" });

      // L'écran de contraintes est là (bouton « Ajouter »), mais pas en mode édition.
      expect(await screen.findByRole("button", { name: "Ajouter la contrainte" })).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: "Enregistrer la contrainte" })).not.toBeInTheDocument();
    });

    it("une `step` inconnue → atterrissage propre (l'étape par défaut), pas d'écran cassé", async () => {
      renderWithProviders(<WizardPage />, { route: "/wizard?step=zzz&slot=s1" });
      // Reste sur Équipes (l'étape courante), aucun saut.
      expect(await screen.findByDisplayValue("SF1")).toBeInTheDocument();
    });

    describe("retour nommé (règle C)", () => {
      it("apparaît SEULEMENT quand on est arrivé par un lien (from=), et nomme l'origine", async () => {
        vi.mocked(api.listVenues).mockResolvedValue([{ id: "v1", name: "Gymnase A", color: "#3498DB", canSplit: true, isActive: true, externalRef: null }]);
        vi.mocked(api.listVenueSlots).mockResolvedValue([{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 }]);
        renderWithProviders(<WizardPage />, { route: "/wizard?step=venues&slot=s1&from=reservation" });
        expect(await screen.findByRole("button", { name: /Retour à la réservation/ })).toBeInTheDocument();
      });

      it("ABSENT quand on ouvre l'étape sans lien (aucun from=)", async () => {
        vi.mocked(api.listVenues).mockResolvedValue([{ id: "v1", name: "Gymnase A", color: "#3498DB", canSplit: true, isActive: true, externalRef: null }]);
        vi.mocked(api.listVenueSlots).mockResolvedValue([{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 }]);
        renderWithProviders(<WizardPage />, { route: "/wizard?step=venues&slot=s1" });
        await screen.findByText(/Ajoutez vos gymnases/);
        expect(screen.queryByRole("button", { name: /Retour à/ })).not.toBeInTheDocument();
      });

      it("ÉPHÉMÈRE : disparaît dès qu'on repart (changement d'étape)", async () => {
        const user = userEvent.setup();
        vi.mocked(api.listVenues).mockResolvedValue([{ id: "v1", name: "Gymnase A", color: "#3498DB", canSplit: true, isActive: true, externalRef: null }]);
        vi.mocked(api.listVenueSlots).mockResolvedValue([{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 }]);
        // maxIndex haut → toutes les étapes du rail sont cliquables (club établi de toute façon).
        useWizardStore.setState({ maxIndex: 5, deepLinkOrigin: null });
        renderWithProviders(<WizardPage />, { route: "/wizard?step=venues&slot=s1&from=reservation" });

        expect(await screen.findByRole("button", { name: /Retour à la réservation/ })).toBeInTheDocument();
        // On repart : « Précédent » change d'étape (Gymnases → Équipes) → le retour nommé s'efface.
        await user.click(screen.getByRole("button", { name: "Précédent" }));
        await waitFor(() => expect(screen.queryByRole("button", { name: /Retour à la réservation/ })).not.toBeInTheDocument());
      });
    });
  });

  // UXS-06 — « valide » n'est pas « fait » : les contraintes sont OPTIONNELLES (aucune erreur sur
  // un club vierge). L'étape ne doit pourtant pas se cocher « terminée » tant que ses prérequis
  // (Équipes/Gymnases/Coachs) sont en erreur — sinon ✓ sur une étape que le club n'a pas commencée.
  describe("rail — Contraintes ne se coche pas avant ses prérequis (UXS-06)", () => {
    it("club vierge : Contraintes n'est PAS « terminée » tant qu'Équipes est en erreur", async () => {
      vi.mocked(api.listTeams).mockResolvedValue([]); // aucune équipe → Équipes en erreur
      renderWithProviders(<WizardPage />, { route: "/wizard" });
      await screen.findByRole("alert"); // l'écran a chargé (« Ajoutez au moins une équipe »)

      // Le rail ne coche pas Contraintes : son nom accessible n'a pas le suffixe « terminée ».
      const constraints = screen.getByRole("button", { name: /Contraintes/ });
      expect(constraints).not.toHaveAttribute("aria-label", "Contraintes — étape terminée");
    });

    it("NR : Équipes/Gymnases/Coachs remplis, Contraintes SANS contrainte reste « terminée »", async () => {
      // Choix légitime : un club réel sans aucune contrainte déclarée. Une fois le reste rempli,
      // l'étape doit pouvoir se cocher — le fix ne casse pas le cas nominal.
      vi.mocked(api.listVenues).mockResolvedValue([{ id: "v1", name: "Gymnase A", color: null, canSplit: false, isActive: true, externalRef: null }]);
      vi.mocked(api.listVenueSlots).mockResolvedValue([{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 }]);
      vi.mocked(api.listCoaches).mockResolvedValue([{ id: "co1", firstName: "Ana", lastName: "B", email: null, isEmployee: false, isActive: true, maxDaysOverride: null, isVehicled: false }]);
      renderWithProviders(<WizardPage />, { route: "/wizard" });

      expect(await screen.findByRole("button", { name: "Contraintes — étape terminée" })).toBeInTheDocument();
    });
  });

  it("enters sort mode from the footer « Trier » button and shows the tier drop zones", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    await screen.findByDisplayValue("SF1");

    // Nom EXACT : les en-têtes de colonne s'appellent « Trier par catégorie », « Trier par
    // rang »… depuis P4-36 — un `/trier/i` en attrapait sept (revue #347).
    await user.click(await screen.findByRole("button", { name: "Trier" }));
    expect(await screen.findByRole("button", { name: /terminer le tri/i })).toBeInTheDocument();
    expect(screen.getByText(/par sa poignée/i)).toBeInTheDocument();
  });
});

describe("bandeau de période — la forme (P4-38)", () => {
  it("porte le titre et les dates, et nomme la SORTIE plutôt que l'abandon", async () => {
    // Retour fondateur : « Quitter » se lisait comme « abandonner ma saisie » alors que le
    // geste ramène au cockpit. Les dates descendent sur une seconde ligne, sinon un titre
    // long (« Vacances d'Été — semaine du 17 août ») poussait la fenêtre sous les boutons.
    useWizardStore.setState({ mode: "period", calendarEntryId: "entry-x", stepId: "constraints" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    expect(await screen.findByRole("button", { name: /Retour à l'accueil/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Quitter$/ })).not.toBeInTheDocument();
    // ⚠ Le titre porte DÉJÀ le repère de semaine (`cockpit/queries.ts:349`) : le bandeau ne
    // le réécrit pas, sous peine de l'afficher deux fois.
    expect(screen.getByText(/Mode période —/)).toBeInTheDocument();
    // …et la LIGNE 2 dit la fenêtre. Sans cette assertion, le test s'appelait « porte le
    // titre et les dates » en n'en vérifiant aucune : vider la ligne 2 le laissait vert
    // (revue #350).
    expect(await screen.findByText(/^du 16-10-2026 au 31-10-2026$/)).toBeInTheDocument();
  });
});


/**
 * NR — l'armement du voile appartient au GESTE, jamais à l'action de store (lot C, GO fondateur).
 * WizardLayout appelle `jumpTo` TOUT SEUL au montage (deep-link, repli sur le premier trou, recap) :
 * si `armNavTransition` vivait dans l'action de store, arriver sur /wizard armerait le voile et
 * gèlerait le formulaire — le bug d'origine revenu par la porte de service. Falsifié dans les deux
 * sens : ce garde rougit si l'armement redescend un jour dans le store, OU s'il quitte le clic.
 */
describe("Wizard — le voile s'arme au GESTE, pas au montage (NR)", () => {
  it("les actions de nav du store (jumpTo/setStep/next/prev) — comme WizardLayout les appelle au montage — n'arment PAS le voile", () => {
    // WizardLayout appelle `jumpTo` TOUT SEUL au montage (deep-link, repli sur le premier trou,
    // recap) : ce ne sont pas des gestes. L'action de store doit donc être NEUTRE. Si l'armement
    // redescend un jour dans le store, ce test rougit.
    const before = useNavTransition.getState().token;
    useWizardStore.getState().jumpTo("venues");
    useWizardStore.getState().setStep("coaches");
    useWizardStore.getState().next();
    useWizardStore.getState().prev();
    expect(useNavTransition.getState().token).toBe(before);
  });

  it("le MÊME changement d'étape déclenché par un CLIC (Suivant) arme le voile", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    await screen.findByDisplayValue("SF1");
    const before = useNavTransition.getState().token;
    await user.click(screen.getByRole("button", { name: "Suivant" }));
    // L'armement est SYNCHRONE dans le handler du bouton (armNavTransition avant next()).
    await waitFor(() => expect(useNavTransition.getState().token).toBeGreaterThan(before));
  });
});
