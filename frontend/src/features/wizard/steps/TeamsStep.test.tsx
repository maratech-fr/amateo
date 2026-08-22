import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { TEAM_COLUMNS } from "../lib/teamColumns";

import type { TeamLink } from "@/features/matches/api";

import type { SharedTrainingGroup, Team } from "../api";

const baseTeam: Team = {
  id: "t1", name: "SM3", sportCategoryId: "cat1", priorityTierId: 5, tierOrder: 0,
  gender: "M", level: "DEPARTEMENTAL", sessionsPerWeek: 1, isActive: true,
};
// Mutable : « engagée » vient du serveur, donc les tests le font varier comme lui.
let team: Team = baseTeam;
// P4-36 : le tri et les flèches demandent PLUSIEURS équipes, dans plusieurs rangs et
// plusieurs catégories. `teamsState` remplace la liste quand un cas en a besoin.
const teamsState: { data: Team[] | null } = { data: null };
const CATEGORIES = [
  { id: "catVet", name: "Vétéran", sortOrder: 0 },
  { id: "cat1", name: "Senior", sortOrder: 1 },
  { id: "catU11", name: "U11", sortOrder: 6 },
];

// P2-27 — les groupes de mutualisation (repère « Mutualisée avec … » par ligne).
const sharedGroupsState: { data: SharedTrainingGroup[] } = { data: [] };
// P2-45 — les passerelles (repère « Passerelle avec … » + la modale Liens ouverte par ligne).
const teamLinksState: { data: TeamLink[] } = { data: [] };
const stgCreate = vi.fn();
const stgUpdate = vi.fn();
const stgDelete = vi.fn();
const createMut = vi.fn();
const updateMut = vi.fn();
const reorderMut = vi.fn();
const reorderPending = { value: false };
const deleteMut = vi.fn();
// P3-16 — l'impact d'une suppression vient du SERVEUR : le mock le rend tel quel, l'écran
// ne dérive plus aucun compte de son cache.
const deletionImpact = {
  value: {
    blocked: false,
    reason: null as string | null,
    lines: [{ key: "team_reservation", count: 1, one: "créneau réservé", many: "créneaux réservés" }],
    slotsInForce: 0,
    declaredFixtures: 0,
  },
};

vi.mock("../queries", () => ({
  useWizardTeams: () => ({ data: teamsState.data ?? [team] }),
  useSportCategories: () => ({ data: CATEGORIES }),
  usePriorityTiers: () => ({
    data: [
      { id: 1, label: "S", name: "Elite", color: null },
      { id: 5, label: "D", name: "Bonus", color: null },
    ],
  }),
  useCreateTeam: () => ({ mutate: createMut, isPending: false }),
  useUpdateTeam: () => ({ mutate: updateMut }),
  useDeleteTeam: () => ({ mutate: deleteMut }),
  useReorderTeams: () => ({ mutate: reorderMut, isPending: reorderPending.value }),
  // P4-44 — le récap peut retirer une réservation orpheline (seul écran capable de la montrer).
  useDeleteReservation: () => ({ mutate: vi.fn(), isPending: false }),
  useReservations: () => ({ data: [{ id: "r1", teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 90, calendarEntryId: null }] }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardCoachPlayers: () => ({ data: [] }),
  useSharedTrainingGroups: () => ({ data: sharedGroupsState.data }),
  // P2-45 — la modale Liens embarque MutualisationPanel : ses hooks vivent dans le même provider.
  useCreateSharedTrainingGroup: () => ({ mutateAsync: stgCreate, isPending: false }),
  useUpdateSharedTrainingGroup: () => ({ mutateAsync: stgUpdate, isPending: false }),
  useDeleteSharedTrainingGroup: () => ({ mutate: stgDelete }),
  useTeamPeriodOverrides: () => ({ data: [] }),
  // P3-16 — l'impact d'une suppression est calculé par le SERVEUR : le mock rend une
  // réponse résolue et vide, l'écran n'en dérive plus aucun compte.
  useDeletionImpact: () => ({ data: deletionImpact.value, isPending: false, isError: false }),
}));

// P2-45 — les passerelles sont SERVIES par le module matchs. La modale Liens rend la section
// passerelles (matchs) : on pilote ses hooks, jamais le réseau.
const createLinkMut = vi.fn();
const updateLinkMut = vi.fn();
const deleteLinkMut = vi.fn();
vi.mock("@/features/matches/queries", () => ({
  useTeamLinks: () => ({ data: teamLinksState.data, isError: false }),
  useCreateTeamLink: () => ({ mutate: createLinkMut, isPending: false }),
  useUpdateTeamLink: () => ({ mutate: updateLinkMut, isPending: false }),
  useDeleteTeamLink: () => ({ mutate: deleteLinkMut, isPending: false }),
}));
// Le bouton « Gérer les passerelles » (masqué dans la modale) est testé chez lui : ici on le stube.
vi.mock("@/features/matches/HabitsLinksButton", () => ({
  HabitsLinksButton: () => <button type="button">Gérer les passerelles</button>,
}));

import { TeamsStep } from "./TeamsStep";

describe("TeamsStep", () => {
  beforeEach(() => {
    team = baseTeam;
    teamsState.data = null;
    sharedGroupsState.data = [];
    teamLinksState.data = [];
    stgCreate.mockClear();
    stgUpdate.mockClear();
    stgDelete.mockClear();
    reorderMut.mockClear();
    reorderPending.value = false;
    createMut.mockClear();
    updateMut.mockClear();
    deleteMut.mockClear();
  });

  it("deleting a team confirms the impact first, then deletes on confirm", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    // The row's Trash button (aria-label "Supprimer") opens the confirmation.
    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    const dialog = screen.getByRole("dialog");
    // P3-16 — la ligne d'impact vient du SERVEUR (libellé compris), plus du cache de l'écran.
    expect(within(dialog).getByText("1 créneau réservé")).toBeInTheDocument();
    expect(deleteMut).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));
    expect(deleteMut).toHaveBeenCalledWith("t1");
  });

  /** La ligne de l'équipe — le formulaire d'ajout porte les mêmes libellés. */
  const teamRow = (): HTMLElement => screen.getByRole("button", { name: "Supprimer" }).closest("div") as HTMLElement;

  it("locks Supprimer and the play level on a team already engaged in competition", () => {
    // Le serveur refuse les deux (ses matchs sont connus de la fédé) : l'écran ne
    // doit pas les proposer, sinon il promet un geste qui finit en 409.
    team = { ...baseTeam, isEngaged: true };
    renderWithProviders(<TeamsStep />);
    const row = teamRow();

    expect(within(row).getByRole("button", { name: "Supprimer" })).toBeDisabled();
    expect(within(row).getByRole("combobox", { name: "Niveau de jeu" })).toBeDisabled();
    // Ce qui reste libre le reste : le nom et les créneaux ne dépendent pas de la fédé.
    expect(within(row).getByRole("textbox", { name: "Nom" })).toBeEnabled();
    expect(within(row).getByRole("spinbutton", { name: "Séances/sem" })).toBeEnabled();
    // La raison est du TEXTE, pas un survol : un contrôle `disabled` sort de l'ordre de
    // tabulation et ne reçoit aucun événement souris — au clavier comme au lecteur
    // d'écran, un `title` laisserait deux contrôles grisés sans explication.
    // Deux niveaux, et il faut les DEUX : le pourquoi une fois pour la liste…
    expect(screen.getByText(/joue en compétition/)).toBeInTheDocument();
    // …et le marqueur sur LA ligne concernée, sinon on ne sait pas laquelle est verrouillée.
    expect(within(row.parentElement as HTMLElement).getByText(/Engagée en compétition/)).toBeInTheDocument();
  });

  it("leaves both open on a team that does not play yet", () => {
    renderWithProviders(<TeamsStep />);
    const row = teamRow();

    expect(within(row).getByRole("button", { name: "Supprimer" })).toBeEnabled();
    expect(within(row).getByRole("combobox", { name: "Niveau de jeu" })).toBeEnabled();
    expect(screen.queryByText(/joue en compétition/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Engagée en compétition/)).not.toBeInTheDocument();
  });

  // P2-27 — le repère « mutualisée » sur la ligne, nommant les co-équipières (jamais un simple
  // pictogramme). Les groupes du SOCLE (l'éditeur de saison ne travaille jamais une période).
  it("marks a team that is mutualised, naming the co-teams", () => {
    teamsState.data = [baseTeam, { ...baseTeam, id: "t2", name: "SM4" }];
    sharedGroupsState.data = [
      { id: "g1", version: 1, createdAt: "2026-08-17T00:00:00+00:00", updatedAt: "2026-08-17T00:00:00+00:00", schedulePlanId: null, teamIds: ["t1", "t2"], commonSessions: 1 },
    ];
    renderWithProviders(<TeamsStep />);

    expect(screen.getByText(/Mutualisée avec SM4/)).toBeInTheDocument();
  });

  it("leaves a team with neither group nor bridge unmarked (P2-45 : ni Mutualisée ni Passerelle)", () => {
    renderWithProviders(<TeamsStep />);
    expect(screen.queryByText(/Mutualisée/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Passerelle avec/)).not.toBeInTheDocument();
  });

  // P2-45 — la sous-ligne s'ÉTEND aux passerelles, avec l'INTENSITÉ (donnée SERVIE, régime 1).
  it("marks a bridged team, naming the partner AND its intensity", () => {
    teamsState.data = [baseTeam, { ...baseTeam, id: "t2", name: "SM4" }];
    teamLinksState.data = [{ id: "l1", teamAId: "t1", teamBId: "t2", linkType: "NOT_SIMULTANEOUS", trainingIntensity: "PREFERRED" }];
    renderWithProviders(<TeamsStep />);

    expect(screen.getByText(/Passerelle avec SM4 \(Préféré\)/)).toBeInTheDocument();
  });

  // P2-45 — l'affordance par équipe : un bouton-icône muet nommé « Liens de {équipe} » par ligne.
  it("offers a per-row « Liens de … » affordance", () => {
    renderWithProviders(<TeamsStep />);
    expect(screen.getByRole("button", { name: "Liens de SM3" })).toBeInTheDocument();
  });

  // P2-45 (falsification #2) — depuis TeamsEditor (SAISON), la mutualisation écrit sur le SOCLE
  // (schedulePlanId null). C'est la maison qui reprend le test supprimé de ConstraintsStep.
  it("creating a group from the season Teams step anchors it on the socle (schedulePlanId null)", async () => {
    const user = userEvent.setup();
    teamsState.data = [baseTeam, { ...baseTeam, id: "t2", name: "SM4" }];
    renderWithProviders(<TeamsStep />);

    await user.click(screen.getByRole("button", { name: "Liens de SM3" }));
    // La modale : SM3 est pré-cochée (initialTeamId), on ajoute SM4 puis on crée.
    await user.click(screen.getByRole("checkbox", { name: "SM4" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(stgCreate).toHaveBeenCalledOnce();
    const arg = stgCreate.mock.calls[0][0] as { schedulePlanId: string | null; teamIds: string[] };
    expect(arg.schedulePlanId).toBeNull();
    expect([...arg.teamIds].sort()).toEqual(["t1", "t2"]);
  });

  // P2-45 — en saison, les passerelles sont ÉDITABLES : le formulaire d'ajout est offert dans la modale.
  it("shows the bridge editing controls in the modal in season mode", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    await user.click(screen.getByRole("button", { name: "Liens de SM3" }));
    expect(screen.getByRole("button", { name: "Ajouter la passerelle" })).toBeInTheDocument();
    // Jamais un dialog dans le dialog : « Gérer les passerelles » est masqué.
    expect(screen.queryByRole("button", { name: "Gérer les passerelles" })).toBeNull();
  });

  it("shows a play-level select and no redundant inner heading", () => {
    renderWithProviders(<TeamsStep />);
    // Play-level select exists on both the add form and the row.
    expect(screen.getAllByLabelText("Niveau de jeu").length).toBeGreaterThan(0);
    // Point 5: the sticky wizard header owns the title; no inner "Équipes" h2.
    expect(screen.queryByRole("heading", { name: "Équipes" })).toBeNull();
  });

  it("keeps a Rang select on the ADD form but NOT on team rows (rang changed via Trier)", () => {
    renderWithProviders(<TeamsStep />); // exactly one team (t1) is rendered
    // Only the add form still offers a rank picker; the row has none — a team's
    // tier is changed via the "Trier" drag & drop, not an inline dropdown.
    expect(screen.getAllByLabelText("Rang")).toHaveLength(1);
  });

  it("keeps the gender select (categories are ungendered now)", () => {
    renderWithProviders(<TeamsStep />);
    expect(screen.getAllByLabelText("Genre").length).toBeGreaterThan(0);
  });

  it("warns when a competitive team is ranked Bonus (D)", () => {
    renderWithProviders(<TeamsStep />);
    // team t1 = DEPARTEMENTAL (competitive) + tier 5 (D) → warning.
    expect(screen.getByText(/en compétition classée Bonus/i)).toBeInTheDocument();
  });

  it("no warning for a loisir team ranked Bonus", () => {
    team.level = "LOISIR_ADULTE";
    renderWithProviders(<TeamsStep />);
    expect(screen.queryByText(/en compétition classée Bonus/i)).toBeNull();
    team.level = "DEPARTEMENTAL"; // restore
  });

  it("shows a required-name error (and does not create) when adding with an empty name", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);
    await user.click(screen.getByRole("button", { name: "Ajouter l'équipe" }));
    expect(screen.getByText(/nom de l'équipe est obligatoire/i)).toBeInTheDocument();
    expect(createMut).not.toHaveBeenCalled();
    // Typing clears the error.
    await user.type(screen.getByLabelText("Nom de l'équipe"), "SF1");
    expect(screen.queryByText(/nom de l'équipe est obligatoire/i)).toBeNull();
  });

  /**
   * AUD-A11Y-13 — le message d'erreur doit être RELIÉ au champ, pas seulement affiché.
   *
   * `aria-invalid` disait « ce champ est fautif » sans jamais dire pourquoi : le motif
   * vivait dans un `<p role="alert">` voisin, annoncé UNE fois par interruption puis perdu.
   * Un lecteur d'écran qui revient sur le champ apprend qu'il est invalide et rien d'autre.
   *
   * ⚠ Ce test regarde le LIEN (`aria-describedby` → l'id du message), pas la présence du
   * texte : celle-ci était déjà vérifiée plus haut, et elle passait pendant que le champ
   * restait muet.
   */
  it("links the error message to the field it describes", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);
    await user.click(screen.getByRole("button", { name: "Ajouter l'équipe" }));

    const field = screen.getByLabelText("Nom de l'équipe");
    expect(field).toHaveAttribute("aria-invalid", "true");

    const describedBy = field.getAttribute("aria-describedby");
    expect(describedBy).toBeTruthy();
    expect(document.getElementById(describedBy ?? "")).toHaveTextContent(/nom de l'équipe est obligatoire/i);
  });

  it("sends the play level when changed on a row", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);
    const rowLevel = screen.getAllByLabelText("Niveau de jeu")[1]; // [0] = add form, [1] = row
    await user.selectOptions(rowLevel, "REGIONAL");
    expect(updateMut).toHaveBeenCalled();
    const body = updateMut.mock.calls[0][0].body;
    expect(body.level).toBe("REGIONAL");
  });

  // ── P4-36 (retour terrain 2026-07-31) ──

  // (a) L'en-tête ne vivait QUE dans la branche « au moins une équipe », alors que le
  // formulaire d'ajout est AU-DESSUS : un club neuf saisissait sa première équipe à
  // l'aveugle, avec des placeholders pour seuls repères.
  it("nomme les colonnes du formulaire même quand aucune équipe n'existe", () => {
    teamsState.data = [];
    renderWithProviders(<TeamsStep />);

    expect(screen.getByText("Aucune équipe pour le moment.")).toBeInTheDocument();
    expect(screen.getByText("Niveau de jeu")).toBeInTheDocument();
    expect(screen.getByText("Séances")).toBeInTheDocument();
  });

  // (b) Le rang n'était qu'un titre de section : invisible dès qu'on trie autrement.
  it("affiche le rang sur chaque ligne, et les flèches hors du mode Trier", () => {
    renderWithProviders(<TeamsStep />);

    // « D » est le label du rang de l'équipe fixture (priorityTierId 5).
    expect(screen.getByTitle("D · Bonus")).toHaveTextContent("D");
    expect(screen.getByRole("button", { name: /Monter SM3/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Descendre SM3/ })).toBeInTheDocument();
  });

  // Les flèches persistent l'ordre COMPLET, comme la sortie du mode « Trier » — un envoi
  // partiel laisserait des équipes sans `tierOrder` explicite.
  it("déplace une équipe dans son rang et persiste l'ordre entier", async () => {
    teamsState.data = [
      { ...baseTeam, id: "a", name: "Alpha", tierOrder: 0 },
      { ...baseTeam, id: "b", name: "Bravo", tierOrder: 1 },
    ];
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    await user.click(screen.getByRole("button", { name: /Descendre Alpha/ }));
    expect(reorderMut).toHaveBeenCalledWith([
      { id: "b", priorityTierId: 5, tierOrder: 0 },
      { id: "a", priorityTierId: 5, tierOrder: 1 },
    ]);
  });

  it("désactive la flèche qui sortirait du rang", () => {
    teamsState.data = [
      { ...baseTeam, id: "a", name: "Alpha", tierOrder: 0 },
      { ...baseTeam, id: "b", name: "Bravo", tierOrder: 1 },
    ];
    renderWithProviders(<TeamsStep />);

    expect(screen.getByRole("button", { name: /Monter Alpha/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: /Descendre Bravo/ })).toBeDisabled();
  });

  // (c) Trier par une autre colonne bascule en LISTE PLATE : appliquer le tri à l'intérieur
  // de chaque section donnerait cinq listes triées séparément, ce qui ne répond pas à
  // « je veux voir mes équipes par catégorie ».
  it("bascule en liste plate au clic d'une colonne, et revient aux sections sur Rang", async () => {
    teamsState.data = [
      { ...baseTeam, id: "a", name: "Alpha", priorityTierId: 1, sportCategoryId: "catU11" },
      { ...baseTeam, id: "b", name: "Bravo", priorityTierId: 5, sportCategoryId: "catVet" },
    ];
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    // Par défaut : sections de rang, une par rang présent.
    expect(screen.getByRole("heading", { name: "S · Fanion" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Trier par catégorie/ }));
    expect(screen.queryByRole("heading", { name: "S · Fanion" })).toBeNull();
    // Vétéran (sortOrder 0) passe devant U11 (6) : c'est l'ordre SERVI, pas l'alphabet.
    const names = screen.getAllByLabelText("Nom").map((input) => (input as HTMLInputElement).value);
    expect(names).toEqual(["Bravo", "Alpha"]);

    await user.click(screen.getByRole("button", { name: /Trier par rang/ }));
    expect(screen.getByRole("heading", { name: "S · Fanion" })).toBeInTheDocument();
  });

  // Les flèches déplacent AU SEIN d'un rang : hors ordre par rang, ce geste n'a plus de
  // sens. Absentes plutôt qu'inertes — un bouton désactivé sans raison lisible est pire.
  it("retire les flèches quand la liste n'est plus triée par rang", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    await user.click(screen.getByRole("button", { name: /Trier par catégorie/ }));
    expect(screen.queryByRole("button", { name: /Monter SM3/ })).toBeNull();
    // …mais le rang reste LISIBLE sur la ligne, sinon la bascule perdrait l'information.
    expect(screen.getByTitle("D · Bonus")).toHaveTextContent("D");
  });

  // ── Revue #347 ──

  // Le défaut valait `categories[0]`, que le catalogue réordonné transforme en « Vétéran »
  // pour TOUS les clubs : vingt équipes de jeunes saisies d'affilée y tombaient toutes.
  it("exige une catégorie explicite au lieu d'en présumer une", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    // [0] = le formulaire d'ajout, [1] = la ligne de l'équipe fixture (même nom accessible).
    expect((screen.getAllByLabelText("Catégorie")[0] as HTMLSelectElement).value).toBe("");
    await user.type(screen.getByLabelText("Nom de l'équipe"), "SF1");
    await user.click(screen.getByRole("button", { name: "Ajouter l'équipe" }));

    expect(createMut).not.toHaveBeenCalled();
    expect(screen.getByText(/Choisissez la catégorie/i)).toBeInTheDocument();
  });

  // Le niveau se trie sur la HIÉRARCHIE affichée, pas sur le code de l'enum : l'alphabet
  // plaçait Départemental avant Élite, et les équipes sans niveau en tête.
  it("trie le niveau sur la hiérarchie sportive, sans niveau en fin", async () => {
    teamsState.data = [
      { ...baseTeam, id: "a", name: "Depart", level: "DEPARTEMENTAL" },
      { ...baseTeam, id: "b", name: "Elite", level: "ELITE" },
      { ...baseTeam, id: "c", name: "Vide", level: null },
    ];
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    await user.click(screen.getByRole("button", { name: /Trier par niveau de jeu/ }));
    const names = screen.getAllByLabelText("Nom").map((input) => (input as HTMLInputElement).value);
    expect(names).toEqual(["Elite", "Depart", "Vide"]);
  });

  // Un second clic sur « Rang » peignait une flèche descendante et n'inversait RIEN :
  // l'écran affirmait avoir obéi.
  it("inverse aussi les sections au second clic sur Rang", async () => {
    teamsState.data = [
      { ...baseTeam, id: "a", name: "Fanion", priorityTierId: 1 },
      { ...baseTeam, id: "b", name: "Bonus", priorityTierId: 5 },
    ];
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);

    const headings = () => screen.getAllByRole("heading", { level: 3 }).map((h) => h.textContent);
    expect(headings()).toEqual(["S · Fanion", "D · Bonus"]);

    await user.click(screen.getByRole("button", { name: /Trier par rang/ }));
    expect(headings()).toEqual(["D · Bonus", "S · Fanion"]);
  });

  // Une équipe au rang DÉRIVÉ était visible en liste plate puis s'évanouissait au retour
  // sur « Rang » — ni supprimable ni reclassable depuis l'écran qui en a la charge.
  it("ne perd jamais une équipe dont le rang est inconnu", () => {
    teamsState.data = [{ ...baseTeam, id: "x", name: "Orpheline", priorityTierId: 99 }];
    renderWithProviders(<TeamsStep />);

    expect(screen.getByDisplayValue("Orpheline")).toBeInTheDocument();
  });

  // Les flèches se taisent pendant qu'un ordre est en vol : sinon un clic juste après
  // « Terminer le tri » repart d'un cache périmé et annule le glisser-déposer.
  it("désarme les flèches tant qu'un ordre est en vol", () => {
    reorderPending.value = true;
    teamsState.data = [
      { ...baseTeam, id: "a", name: "Alpha", tierOrder: 0 },
      { ...baseTeam, id: "b", name: "Bravo", tierOrder: 1 },
    ];
    renderWithProviders(<TeamsStep />);

    expect(screen.getByRole("button", { name: /Descendre Alpha/ })).toBeDisabled();
  });

  /**
   * P4-107 (4ᵉ tranche) — **les trois rendus des mêmes colonnes ne peuvent plus diverger.**
   *
   * L'étape rend les mêmes colonnes à TROIS endroits : l'en-tête du formulaire d'ajout, le
   * formulaire, et l'en-tête + les lignes de la liste. Chacun portait ses classes, et ils
   * avaient DÉJÀ divergé — le Genre valait `w-24` en haut et `w-20` en bas, si bien que les
   * deux tableaux ne s'alignaient pas. `TEAM_COLUMNS` est désormais leur maison unique.
   *
   * ⚠ **Ce que ce test NE prouve PAS, et il faut le savoir en le lisant** : que « Homme » tienne
   * dans son sélecteur. jsdom n'a aucun moteur de mise en page — aucune largeur n'y existe. Il
   * garde la PARITÉ (modifier un seul site rougit) ; la troncature réelle se mesure en
   * Playwright, `tests/e2e/width-calibration.spec.ts`.
   */
  it("la largeur d'une colonne est la MÊME dans le formulaire et dans la ligne", () => {
    // L'équipe par défaut du harnais suffit : le sujet est la largeur, pas la donnée.
    renderWithProviders(<TeamsStep />);

    // Les DEUX sélecteurs « Genre » de l'écran — celui du formulaire d'ajout et celui de la
    // ligne — sont exactement ce qui avait divergé (`w-24` en haut, `w-20` en bas).
    const widthOf = (el: HTMLElement): string | undefined => el.className.split(/\s+/).find((c) => c.startsWith("w-"));
    const widths = screen.getAllByLabelText("Genre").map((el) => widthOf(el as HTMLElement));
    expect(widths.length).toBeGreaterThan(1);
    expect(new Set(widths).size, `les deux sites doivent porter la MÊME largeur, trouvé ${widths.join(" / ")}`).toBe(1);
    expect(widths[0]).toBe(TEAM_COLUMNS.gender);
    // Falsifié dans l'autre sens : ni l'une ni l'autre des deux valeurs divergentes d'avant —
    // `w-20` coupait « Homme » en « Homn », et `w-24` était l'autre moitié du désalignement.
    expect(TEAM_COLUMNS.gender).not.toBe("w-20");
    expect(TEAM_COLUMNS.gender).not.toBe("w-24");
  });
});

