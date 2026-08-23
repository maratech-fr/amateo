import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { TeamLink } from "@/features/matches/api";

import type { PriorityTier, SharedTrainingGroup, Team, TeamPeriodOverride } from "../api";

const stgCreate = vi.fn();
const stgUpdate = vi.fn();
const stgDelete = vi.fn();
const sharedGroupsState: { data: SharedTrainingGroup[] } = { data: [] };
const overridesState: { data: TeamPeriodOverride[] } = { data: [] };
const teamLinksState: { data: TeamLink[] } = { data: [] };

// Le mock IGNORE `schedulePlanId` (le provider renvoie socle+périodes) : c'est le PANNEAU qui
// filtre le socle. Le rendre fidèle ferait passer sous silence ce tri côté client.
vi.mock("../queries", () => ({
  useSharedTrainingGroups: () => ({ data: sharedGroupsState.data }),
  useTeamPeriodOverrides: () => ({ data: overridesState.data }),
  useCreateSharedTrainingGroup: () => ({ mutateAsync: stgCreate, isPending: false }),
  useUpdateSharedTrainingGroup: () => ({ mutateAsync: stgUpdate, isPending: false }),
  useDeleteSharedTrainingGroup: () => ({ mutate: stgDelete }),
}));

// Les passerelles décident l'ordre d'affichage (P2-34) — SERVIES par le module matchs. On les
// pilote depuis le test ; le vrai hook ne doit pas partir en réseau ici.
vi.mock("@/features/matches/queries", () => ({
  useTeamLinks: () => ({ data: teamLinksState.data }),
}));
// L'écran unique (dialog + ses données matchs) est testé chez lui : ici on stube son bouton.
vi.mock("@/features/matches/HabitsLinksButton", () => ({
  HabitsLinksButton: () => <button type="button">Gérer les passerelles</button>,
}));

import { MutualisationPanel } from "./MutualisationPanel";

const link = (teamAId: string, teamBId: string): TeamLink => ({ id: `${teamAId}-${teamBId}`, teamAId, teamBId, linkType: "NOT_SIMULTANEOUS", trainingIntensity: "PREFERRED" });

const team = (over: Partial<Team> & Pick<Team, "id" | "name">): Team => ({
  sportCategoryId: "senior",
  priorityTierId: 1,
  tierOrder: 0,
  gender: "M",
  level: null,
  sessionsPerWeek: 3,
  isActive: true,
  ...over,
});

const TEAMS: Team[] = [
  team({ id: "t1", name: "SM1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 3 }),
  team({ id: "t2", name: "SM2", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 2 }),
  team({ id: "t3", name: "SF1", priorityTierId: 2, tierOrder: 0, gender: "F", sessionsPerWeek: 2 }),
  team({ id: "t4", name: "U11", sportCategoryId: "u11", priorityTierId: 3, tierOrder: 0, sessionsPerWeek: 1 }),
];

const TIERS: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 2, label: "A", name: "Importante", color: null },
  { id: 3, label: "B", name: "Moyenne", color: null },
];

const group = (id: string, teamIds: string[], commonSessions = 1, schedulePlanId: string | null = null): SharedTrainingGroup => ({
  id,
  version: 1,
  createdAt: "2026-08-17T00:00:00+00:00",
  updatedAt: "2026-08-17T00:00:00+00:00",
  schedulePlanId,
  teamIds,
  commonSessions,
});

const renderPanel = (schedulePlanId: string | null = null, pausedTeamIds?: Set<string>) =>
  renderWithProviders(<MutualisationPanel teams={TEAMS} tiers={TIERS} schedulePlanId={schedulePlanId} pausedTeamIds={pausedTeamIds} />);

beforeEach(() => {
  stgCreate.mockClear();
  stgUpdate.mockClear();
  stgDelete.mockClear();
  sharedGroupsState.data = [];
  overridesState.data = [];
  teamLinksState.data = [];
});

describe("MutualisationPanel — le sélecteur d'équipes (cases + split liées/reste porté par les passerelles)", () => {
  it("liste toutes les équipes à plat tant que rien n'est coché (le split n'apparaît qu'à la première coche)", () => {
    teamLinksState.data = [link("t1", "t4")];
    renderPanel();
    // Pas de bloc « Équipes liées » avant la première coche.
    expect(screen.queryByRole("group", { name: "Équipes liées" })).toBeNull();
    for (const name of ["SM1", "SM2", "SF1", "U11"]) {
      expect(screen.getByRole("checkbox", { name })).toBeInTheDocument();
    }
  });

  it("dès qu'une équipe passerelée est cochée, remonte l'équipe LIÉE (pas la catégorie) et replie le reste", async () => {
    const user = userEvent.setup();
    // SM1 (senior/M) est passerelée à U11 (autre catégorie) — jamais « proche » par catégorie.
    // C'est la PASSERELLE, pas l'heuristique supprimée, qui la remonte.
    teamLinksState.data = [link("t1", "t4")];
    renderPanel();

    await user.click(screen.getByRole("checkbox", { name: "SM1" }));

    const liees = screen.getByRole("group", { name: "Équipes liées" });
    expect(within(liees).getByRole("checkbox", { name: "SM1" })).toBeInTheDocument();
    expect(within(liees).getByRole("checkbox", { name: "U11" })).toBeInTheDocument();
    // SM2 (même catégorie mais NON liée) est dans le reste, replié.
    expect(screen.queryByRole("checkbox", { name: "SM2" })).toBeNull();

    // …mais toujours atteignable en un clic.
    await user.click(screen.getByRole("button", { name: "Afficher toutes les équipes" }));
    expect(screen.getByRole("checkbox", { name: "SM2" })).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: "SF1" })).toBeInTheDocument();
  });

  it("sans passerelle pour l'ancre, PAS de bloc « liées » — la liste reste plate (toutes visibles)", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    // Aucun bloc « liées », aucune équipe masquée : le reste ne se cache jamais pour rien.
    expect(screen.queryByRole("group", { name: "Équipes liées" })).toBeNull();
    for (const name of ["SM1", "SM2", "SF1", "U11"]) {
      expect(screen.getByRole("checkbox", { name })).toBeInTheDocument();
    }
  });

  it("avertit sans bloquer au-delà de 3 équipes cochées", async () => {
    const user = userEvent.setup();
    renderPanel();
    // Sans passerelle : liste plate, toutes les cases visibles d'emblée.
    for (const name of ["SM1", "SM2", "SF1", "U11"]) {
      await user.click(screen.getByRole("checkbox", { name }));
    }

    expect(screen.getByRole("alert")).toHaveTextContent(/inhabituel/);
    // Jamais bloquant : le bouton reste actif (le serveur accepte jusqu'à 10).
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeEnabled();
  });

  it("état vide : aucune passerelle déclarée → hint qui renvoie à l'écran des passerelles", () => {
    renderPanel();
    expect(screen.getByText(/Aucune passerelle déclarée/)).toBeInTheDocument();
    // Le geste d'ouverture est offert (bouton « Gérer les passerelles »).
    expect(screen.getByRole("button", { name: "Gérer les passerelles" })).toBeInTheDocument();
  });

  it("dès qu'une passerelle existe, le hint « aucune passerelle » disparaît", () => {
    teamLinksState.data = [link("t1", "t2")];
    renderPanel();
    expect(screen.queryByText(/Aucune passerelle déclarée/)).toBeNull();
  });
});

describe("MutualisationPanel — pré-validation FAIL-SAFE (le serveur reste juge)", () => {
  it("plafonne K au plus petit sessionsPerWeek effectif de la sélection", async () => {
    const user = userEvent.setup();
    renderPanel();
    // SM1 = 3 séances, SM2 = 2 → plafond 2.
    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));

    const k = screen.getByRole("spinbutton", { name: "Séances communes" });
    await user.clear(k);
    await user.type(k, "5");
    // Ramené au plafond 2, jamais 5.
    expect(k).toHaveValue(2);
    expect(screen.getByText(/au plus 2 séances communes/)).toBeInTheDocument();
  });

  it("honore l'override de période dans le plafond (l'override est prioritaire)", async () => {
    const user = userEvent.setup();
    // En période, SM1 tombe à 1 séance via override → plafond 1 même si sa valeur de saison est 3.
    overridesState.data = [{ id: "o", schedulePlanId: "plan-1", teamId: "t1", isActive: true, sessionsPerWeek: 1 }];
    renderPanel("plan-1");

    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));
    expect(screen.getByText(/au plus 1 séance commune/)).toBeInTheDocument();
  });

  it("désactive une équipe déjà membre d'un autre groupe de la portée, avec la raison EN TEXTE", () => {
    sharedGroupsState.data = [group("g1", ["t3", "t4"])];
    renderPanel();

    const sf1 = screen.getByRole("checkbox", { name: "SF1" });
    expect(sf1).toBeDisabled();
    // La raison nomme la co-équipière, ce n'est pas un simple `title` de survol.
    expect(screen.getByText(/déjà mutualisée avec U11/)).toBeInTheDocument();
  });

  it("affiche le motif d'un 422 serveur (lu depuis error.data via errorMessage)", async () => {
    const user = userEvent.setup();
    const err = new HTTPError(new Response(null, { status: 422 }), new Request("http://localhost/"), { method: "POST" } as never);
    (err as { data?: unknown }).data = { error: "Une équipe du groupe est inconnue de cette saison." };
    stgCreate.mockRejectedValueOnce(err);
    renderPanel();

    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(await screen.findByText("Une équipe du groupe est inconnue de cette saison.")).toBeInTheDocument();
  });

  // P4-126 — un 422 d'API Platform ne porte NI `error` NI `message` : son motif vit dans `detail`
  // et `violations[].message`. `apiErrorMessage` ne lisait que error/message → toast générique et
  // motif perdu à l'écran. `errorMessage` lit detail puis violations : le motif REMONTE.
  it("affiche le motif d'un 422 porté par violations[] (forme API Platform, sans error/message)", async () => {
    const user = userEvent.setup();
    const message = "Une équipe fait déjà partie d'un autre groupe mutualisé pour cette portée.";
    const err = new HTTPError(new Response(null, { status: 422 }), new Request("http://localhost/"), { method: "POST" } as never);
    (err as { data?: unknown }).data = { title: "An error occurred", detail: message, violations: [{ propertyPath: "", message }] };
    stgCreate.mockRejectedValueOnce(err);
    renderPanel();

    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(await screen.findByText(message)).toBeInTheDocument();
  });
});

describe("MutualisationPanel — créer / modifier / supprimer", () => {
  it("crée un groupe : POST { schedulePlanId, teamIds, commonSessions }", async () => {
    const user = userEvent.setup();
    renderPanel(null);

    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(stgCreate).toHaveBeenCalledOnce();
    const arg = stgCreate.mock.calls[0][0] as { schedulePlanId: string | null; teamIds: string[]; commonSessions: number };
    expect(arg.schedulePlanId).toBeNull();
    expect([...arg.teamIds].sort()).toEqual(["t1", "t2"]);
    expect(arg.commonSessions).toBe(1);
  });

  it("le bouton reste inerte tant qu'il n'y a pas au moins deux équipes", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeDisabled();
  });

  it("modifie un groupe existant : préremplissage puis PUT { teamIds, commonSessions } sur le même id", async () => {
    const user = userEvent.setup();
    sharedGroupsState.data = [group("g1", ["t1", "t2"], 2)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // Préremplissage : les deux membres sont cochés, K = 2 (et un membre du groupe édité n'est PAS verrouillé).
    expect(screen.getByRole("checkbox", { name: "SM1" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "SM2" })).toBeChecked();
    expect(screen.getByRole("spinbutton", { name: "Séances communes" })).toHaveValue(2);

    await user.click(screen.getByRole("button", { name: "Enregistrer le groupe" }));
    expect(stgCreate).not.toHaveBeenCalled();
    const arg = stgUpdate.mock.calls[0][0] as { id: string; body: { teamIds: string[]; commonSessions: number } };
    expect(arg.id).toBe("g1");
    expect([...arg.body.teamIds].sort()).toEqual(["t1", "t2"]);
    expect(arg.body.commonSessions).toBe(2);
  });

  it("supprime sous une confirmation qui NOMME l'impact", async () => {
    const user = userEvent.setup();
    sharedGroupsState.data = [group("g1", ["t1", "t2"], 1)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    const dialog = screen.getByRole("dialog");
    // L'impact est nommé, pas un « OK ? » nu.
    expect(within(dialog).getByText(/SM1 \+ SM2 — 1 séance commune/)).toBeInTheDocument();
    expect(stgDelete).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));
    expect(stgDelete).toHaveBeenCalledWith("g1");
  });
});

describe("MutualisationPanel — portée", () => {
  it("en portée socle, ne liste QUE les groupes du socle (le provider renvoie aussi les périodes)", () => {
    sharedGroupsState.data = [group("g-base", ["t1", "t2"], 1, null), group("g-period", ["t3", "t4"], 1, "plan-9")];
    renderPanel(null);

    expect(screen.getByText(/SM1 \+ SM2 — 1 séance commune/)).toBeInTheDocument();
    expect(screen.queryByText(/SF1 \+ U11/)).toBeNull();
  });

  it("n'offre pas une équipe en pause pour la période", () => {
    renderPanel("plan-1", new Set(["t4"]));
    expect(screen.queryByRole("checkbox", { name: "U11" })).toBeNull();
  });
});

describe("MutualisationPanel — ouvert depuis une équipe (P2-45 : initialTeamId, hideLinksManager)", () => {
  it("pré-coche l'équipe d'ouverture (initialTeamId)", () => {
    renderWithProviders(<MutualisationPanel teams={TEAMS} tiers={TIERS} schedulePlanId={null} initialTeamId="t1" />);
    expect(screen.getByRole("checkbox", { name: "SM1" })).toBeChecked();
    // Falsification : une autre équipe n'est PAS cochée d'office.
    expect(screen.getByRole("checkbox", { name: "SM2" })).not.toBeChecked();
  });

  it("sans initialTeamId, aucune équipe n'est cochée d'office (le pré-cochage vient bien de la prop)", () => {
    renderWithProviders(<MutualisationPanel teams={TEAMS} tiers={TIERS} schedulePlanId={null} />);
    expect(screen.getByRole("checkbox", { name: "SM1" })).not.toBeChecked();
  });

  it("hideLinksManager masque « Gérer les passerelles » (jamais un dialog dans le dialog)", () => {
    renderWithProviders(<MutualisationPanel teams={TEAMS} tiers={TIERS} schedulePlanId={null} hideLinksManager />);
    expect(screen.queryByRole("button", { name: "Gérer les passerelles" })).toBeNull();
    // Falsification : sans la prop, le bouton EST là (cf. l'état vide plus haut).
  });

  it("l'ANCRE (initialTeamId) n'est pas décochable : case cochée + disabled + raison en texte", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MutualisationPanel teams={TEAMS} tiers={TIERS} schedulePlanId={null} initialTeamId="t1" />);

    const anchor = screen.getByRole("checkbox", { name: "SM1" });
    expect(anchor).toBeChecked();
    expect(anchor).toBeDisabled();
    // Un clic sur une case disabled est inerte : elle reste cochée.
    await user.click(anchor);
    expect(anchor).toBeChecked();
    // La raison est DITE (pas un title de survol).
    expect(screen.getByText(/retirez-la en supprimant le groupe/)).toBeInTheDocument();
  });

  it("une équipe NON-ancre reste décochable normalement", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MutualisationPanel teams={TEAMS} tiers={TIERS} schedulePlanId={null} initialTeamId="t1" />);

    await user.click(screen.getByRole("checkbox", { name: "SM2" }));
    expect(screen.getByRole("checkbox", { name: "SM2" })).toBeChecked();
    await user.click(screen.getByRole("checkbox", { name: "SM2" }));
    expect(screen.getByRole("checkbox", { name: "SM2" })).not.toBeChecked();
  });
});

describe("MutualisationPanel — recherche d'équipes (P2-45 suite)", () => {
  const search = () => screen.getByRole("searchbox", { name: "Rechercher une équipe" });

  it("filtre la liste : la correspondante reste, la non-correspondante disparaît", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.type(search(), "SF1");
    expect(screen.getByRole("checkbox", { name: "SF1" })).toBeInTheDocument();
    expect(screen.queryByRole("checkbox", { name: "SM1" })).toBeNull();
  });

  it("est insensible à la casse", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.type(search(), "sf1");
    expect(screen.getByRole("checkbox", { name: "SF1" })).toBeInTheDocument();
  });

  it("une équipe COCHÉE survit au filtre même si son nom ne correspond pas", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    await user.type(search(), "SF1");
    // SM1 ne correspond pas à « SF1 » mais reste visible car cochée.
    expect(screen.getByRole("checkbox", { name: "SM1" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "SF1" })).toBeInTheDocument();
  });

  it("une équipe VERROUILLÉE (déjà mutualisée) reste trouvable, avec sa raison", async () => {
    const user = userEvent.setup();
    sharedGroupsState.data = [group("g1", ["t3", "t4"])];
    renderPanel();
    await user.type(search(), "SF1");
    const sf1 = screen.getByRole("checkbox", { name: "SF1" });
    expect(sf1).toBeInTheDocument();
    expect(sf1).toBeDisabled();
    expect(screen.getByText(/déjà mutualisée avec U11/)).toBeInTheDocument();
  });

  it("résultat vide → message qui NOMME la requête (jamais une liste muette)", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.type(search(), "zzz");
    expect(screen.getByText(/Aucune équipe ne correspond à « zzz »/)).toBeInTheDocument();
    expect(screen.queryByRole("checkbox")).toBeNull();
  });

  it("une recherche active COURT-CIRCUITE le split liées/reste en liste plate", async () => {
    const user = userEvent.setup();
    // SM1↔U11 passerelées : cocher SM1 fait apparaître le bloc « Équipes liées » et replie SM2.
    teamLinksState.data = [link("t1", "t4")];
    renderPanel();
    await user.click(screen.getByRole("checkbox", { name: "SM1" }));
    expect(screen.getByRole("group", { name: "Équipes liées" })).toBeInTheDocument();
    expect(screen.queryByRole("checkbox", { name: "SM2" })).toBeNull();

    // Recherche « SM » : plus de bloc « liées », SM2 (qui était repliée) est directement visible.
    await user.type(search(), "SM");
    expect(screen.queryByRole("group", { name: "Équipes liées" })).toBeNull();
    expect(screen.getByRole("checkbox", { name: "SM2" })).toBeInTheDocument();
  });

  it("annonce le compte de résultats en aria-live (accord singulier/pluriel)", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.type(search(), "SM");
    expect(screen.getByText("2 équipes trouvées")).toBeInTheDocument();
    await user.clear(search());
    await user.type(search(), "SF1");
    expect(screen.getByText("1 équipe trouvée")).toBeInTheDocument();
    await user.clear(search());
    await user.type(search(), "zzz");
    expect(screen.getByText("Aucune équipe trouvée")).toBeInTheDocument();
  });
});
