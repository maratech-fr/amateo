import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PriorityTier, SharedBlockSession, SharedTrainingBlock, Team, TeamPeriodOverride } from "../api";
import { sharedGroupLabel } from "../lib/sharedTraining";

const stbCreate = vi.fn();
const stbUpdate = vi.fn();
const stbDelete = vi.fn();
const blocksState: { data: SharedTrainingBlock[] } = { data: [] };
const overridesState: { data: TeamPeriodOverride[] } = { data: [] };

// Le mock IGNORE `schedulePlanId` (le provider renvoie socle+périodes) : c'est le PANNEAU qui
// filtre le socle.
vi.mock("../queries", () => ({
  useSharedTrainingBlocks: () => ({ data: blocksState.data }),
  useTeamPeriodOverrides: () => ({ data: overridesState.data }),
  useCreateSharedTrainingBlock: () => ({ mutateAsync: stbCreate, isPending: false }),
  useUpdateSharedTrainingBlock: () => ({ mutateAsync: stbUpdate, isPending: false }),
  useDeleteSharedTrainingBlock: () => ({ mutate: stbDelete }),
}));

import { WIZARD_TEXTS as T } from "../texts";
import { SharedTrainingBlockPanel } from "./SharedTrainingBlockPanel";

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
  team({ id: "t1", name: "U9F1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 3 }),
  team({ id: "t2", name: "U9F2", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 2 }),
  team({ id: "t3", name: "U9M1", priorityTierId: 2, tierOrder: 0, sessionsPerWeek: 2 }),
];

const TIERS: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 2, label: "A", name: "Importante", color: null },
];

const block = (id: string, teamIds: string[], commonSessions = 1, schedulePlanId: string | null = null, sessions: SharedBlockSession[] = []): SharedTrainingBlock => ({
  id,
  version: 1,
  createdAt: "2026-08-31T00:00:00+00:00",
  updatedAt: "2026-08-31T00:00:00+00:00",
  schedulePlanId,
  teamIds,
  commonSessions,
  label: null,
  sessions,
});

const renderPanel = (schedulePlanId: string | null = null, initialTeamId?: string, pausedTeamIds?: ReadonlySet<string>) =>
  renderWithProviders(<SharedTrainingBlockPanel teams={TEAMS} tiers={TIERS} schedulePlanId={schedulePlanId} initialTeamId={initialTeamId} pausedTeamIds={pausedTeamIds} />);

type User = ReturnType<typeof userEvent.setup>;
/** Le formulaire de création vit dans un accordéon replié (corps démonté) : on l'ouvre d'abord. */
const openNewGroup = (user: User) => user.click(screen.getByRole("button", { name: T.blockNewTitle }));
/** Monte le panneau et ouvre le formulaire. */
const renderOpenPanel = async (user: User, schedulePlanId: string | null = null) => {
  renderPanel(schedulePlanId);
  await openNewGroup(user);
};
const formHeader = (name: string) => screen.getByRole("button", { name });
const groupRows = () => screen.getAllByRole("listitem").map((li) => li.textContent);
const nameOfTeam = (id: string): string => TEAMS.find((t) => t.id === id)?.name ?? id;

beforeEach(() => {
  // jsdom n'implémente pas le défilement : on observe l'appel qui amène le formulaire à l'écran.
  Element.prototype.scrollIntoView = vi.fn();
  stbCreate.mockClear();
  stbUpdate.mockClear();
  stbDelete.mockClear();
  blocksState.data = [];
  overridesState.data = [];
});

describe("SharedTrainingBlockPanel — la LISTE DÉROULANTE des séances communes (bornée, affichée)", () => {
  it("désactive la liste déroulante tant qu'il y a moins de deux équipes", async () => {
    await renderOpenPanel(userEvent.setup());
    expect(screen.getByRole("combobox", { name: "Séances communes" })).toBeDisabled();
    expect(screen.getByText(/Choisissez au moins 2 équipes/)).toBeInTheDocument();
  });

  it("n'offre que 1..min(séances effectives) une fois deux équipes cochées, et annonce le plafond", async () => {
    const user = userEvent.setup();
    await renderOpenPanel(user);
    // U9F1 = 3 séances, U9F2 = 2 → plafond 2 : options 1 et 2, JAMAIS 3.
    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));

    const select = screen.getByRole("combobox", { name: "Séances communes" });
    expect(select).toBeEnabled();
    const values = [...select.querySelectorAll("option")].map((o) => (o as HTMLOptionElement).value);
    expect(values).toEqual(["1", "2"]);
    expect(screen.getByText(/Jusqu'à 2 avec les équipes choisies/)).toBeInTheDocument();
  });

  it("suit l'override de période dans le plafond (l'override est prioritaire)", async () => {
    const user = userEvent.setup();
    overridesState.data = [{ id: "o", schedulePlanId: "plan-1", teamId: "t1", isActive: true, sessionsPerWeek: 1, source: null }];
    await renderOpenPanel(user, "plan-1");

    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));
    const values = [...screen.getByRole("combobox", { name: "Séances communes" }).querySelectorAll("option")].map((o) => (o as HTMLOptionElement).value);
    expect(values).toEqual(["1"]);
  });
});

describe("SharedTrainingBlockPanel — multi-appartenance PERMISE (jamais de verrou)", () => {
  it("une équipe déjà dans un autre bloc reste COCHABLE, avec un simple repère informatif", async () => {
    blocksState.data = [block("b1", ["t1", "t3"])];
    await renderOpenPanel(userEvent.setup());

    const u9f1 = screen.getByRole("checkbox", { name: "U9F1" });
    // Multi-appartenance permise : jamais désactivée (contraste avec le groupe K).
    expect(u9f1).toBeEnabled();
    const row = u9f1.closest("div") as HTMLElement;
    expect(within(row).getByText(/déjà dans 1 groupe/)).toBeInTheDocument();
  });
});

describe("SharedTrainingBlockPanel — créer / modifier / supprimer", () => {
  it("crée un bloc : POST { schedulePlanId, teamIds, commonSessions } avec TOUTES les équipes cochées", async () => {
    const user = userEvent.setup();
    await renderOpenPanel(user, null);

    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));
    await user.selectOptions(screen.getByRole("combobox", { name: "Séances communes" }), "2");
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(stbCreate).toHaveBeenCalledOnce();
    const arg = stbCreate.mock.calls[0][0] as { schedulePlanId: string | null; teamIds: string[]; commonSessions: number };
    expect(arg.schedulePlanId).toBeNull();
    expect([...arg.teamIds].sort()).toEqual(["t1", "t2"]);
    expect(arg.commonSessions).toBe(2);
  });

  it("le bouton reste inerte tant qu'il n'y a pas au moins deux équipes", async () => {
    const user = userEvent.setup();
    await renderOpenPanel(user);
    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeDisabled();
  });

  it("modifie un bloc existant : préremplissage puis PUT { teamIds, commonSessions } sur le même id", async () => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2"], 2)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: /Modifier le groupe/ }));
    await user.click(screen.getByRole("button", { name: "Enregistrer le groupe" }));

    expect(stbUpdate).toHaveBeenCalledOnce();
    const arg = stbUpdate.mock.calls[0][0] as { id: string; body: { teamIds: string[]; commonSessions: number } };
    expect(arg.id).toBe("b1");
    expect([...arg.body.teamIds].sort()).toEqual(["t1", "t2"]);
    expect(arg.body.commonSessions).toBe(2);
  });

  it("baisse sous les cases réservées : le motif du 422 s'affiche et la saisie reste en édition", async () => {
    // Promesse fondateur 2026-10-30 : le serveur refuse, le gestionnaire lit pourquoi et ne perd rien.
    const user = userEvent.setup();
    const message = T.blockBelowReservedSessions(2);
    const err = new HTTPError(new Response(null, { status: 422 }), new Request("http://localhost/"), { method: "PUT" } as never);
    (err as { data?: unknown }).data = { title: "An error occurred", detail: message, violations: [{ propertyPath: "", message }] };
    stbUpdate.mockRejectedValueOnce(err);
    blocksState.data = [block("b1", ["t1", "t2"], 2)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: /Modifier le groupe/ }));
    await user.selectOptions(screen.getByRole("combobox", { name: "Séances communes" }), "1");
    await user.click(screen.getByRole("button", { name: "Enregistrer le groupe" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
    expect(screen.getByRole("button", { name: "Enregistrer le groupe" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "Séances communes" })).toHaveValue("1");
    expect(screen.getByRole("checkbox", { name: "U9F2" })).toBeChecked();
  });

  it("supprime un bloc après confirmation ; sans séance placée, la confirmation reste par équipes", async () => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2"], 1)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: /Supprimer le groupe/ }));
    // Bloc sans séance servie : on garde le texte par équipes, jamais de liste vide.
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/les séances communes de U9F1, U9F2/)).toBeInTheDocument();
    expect(within(dialog).queryAllByRole("listitem")).toHaveLength(0);

    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(stbDelete).toHaveBeenCalledWith("b1");
  });

  it("NOMME les séances emportées dans la confirmation (jour en français, heures HH:MM, gymnase)", async () => {
    const user = userEvent.setup();
    const session = (teamId: string, teamName: string): SharedBlockSession => ({
      teamId,
      teamName,
      dayOfWeek: 2,
      startTime: "2026-01-06T18:00:00+00:00",
      endTime: "2026-01-06T19:30:00+00:00",
      venueName: "Gymnase Jean-Macé",
    });
    blocksState.data = [block("b1", ["t1", "t2"], 1, "plan-1", [session("t1", "U9F1"), session("t2", "U9F2")])];
    renderPanel("plan-1");

    await user.click(screen.getByRole("button", { name: /Supprimer le groupe/ }));

    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/retirera ces séances du planning/)).toBeInTheDocument();
    const lines = within(dialog)
      .getAllByRole("listitem")
      .map((li) => li.textContent);
    expect(lines).toContain("U9F1 — mardi 18:00-19:30, Gymnase Jean-Macé");
    expect(lines).toContain("U9F2 — mardi 18:00-19:30, Gymnase Jean-Macé");
  });

  it("affiche le motif d'un 422 serveur (garde Σ) sans vider la sélection", async () => {
    const user = userEvent.setup();
    const message = "Le total des séances communes des blocs d'une équipe (4) dépasse son nombre de séances hebdomadaires (3).";
    const err = new HTTPError(new Response(null, { status: 422 }), new Request("http://localhost/"), { method: "POST" } as never);
    (err as { data?: unknown }).data = { title: "An error occurred", detail: message, violations: [{ propertyPath: "", message }] };
    stbCreate.mockRejectedValueOnce(err);
    await renderOpenPanel(user);

    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
    // La sélection est CONSERVÉE pour retenter (patron error-recovery).
    expect(screen.getByRole("checkbox", { name: "U9F1" })).toBeChecked();
  });
});

/**
 * ─────────── PR A lot 2 — E12 : « Nom du groupe » facultatif d'une séance commune ───────────
 * Un groupe mutualisé porte un `label` en base (servi en lecture) mais AUCUN écran ne le saisit :
 * le panneau ne propose qu'équipes + séances communes, et la liste (:272) affiche
 * `sharedGroupLabel(...)` (par les équipes), jamais le nom. TANK écrit ROUGE (option A), NEO implémente.
 *  (a) champ « Nom du groupe » facultatif à la création/modification → sa valeur part dans le payload API ;
 *  (b) la liste affiche le NOM s'il existe, sinon l'affichage par les équipes ;
 *  (c) vider le nom → retour à l'affichage par les équipes (branche fallback, témoin VERT).
 * Cohérence E11 : la case de réservation lit ce MÊME `label` (pas `slot.groupLabel`).
 * ⚠ FINDING : `SharedTrainingBlockPanel.tsx` n'est PAS dans `MIGRATED_FILES` — ajouter un libellé de
 * champ impose à NEO de MIGRER l'écran (texts.ts + inscription à `MIGRATED_FILES`). Élargissement de
 * périmètre à acter ; non fait ici. Le test vise un nom accessible (écran non migré → littéral toléré).
 */
describe("SharedTrainingBlockPanel — E12 : nom du groupe (label)", () => {
  it("E12 (a) — le « Nom du groupe » saisi part dans le payload de création (label)", async () => {
    const user = userEvent.setup();
    await renderOpenPanel(user);
    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));

    // Champ « Nom du groupe » facultatif — ABSENT aujourd'hui (le panneau ne propose qu'équipes + séances).
    const nameField = screen.getByRole("textbox", { name: (n: string) => n.toLowerCase().includes("nom du groupe") });
    await user.type(nameField, "SM Shooting");
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(stbCreate).toHaveBeenCalledWith(expect.objectContaining({ label: "SM Shooting" }));
  });

  it("E12 (b/c) — la liste affiche le NOM quand il existe, sinon les équipes (fallback)", () => {
    blocksState.data = [
      { ...block("b1", ["t1", "t2"], 1), label: "SM Shooting" }, // avec nom
      block("b2", ["t1", "t3"], 1), // sans nom (label null) → fallback par les équipes
    ];
    renderPanel();

    // Avec nom → le NOM s'affiche. ROUGE aujourd'hui : la liste rend toujours `sharedGroupLabel` (équipes).
    expect(screen.getByText("SM Shooting")).toBeInTheDocument();
    // Sans nom → l'affichage par les équipes DEMEURE (témoin VERT du fallback, promesse (c)).
    const nameOf = (id: string): string => TEAMS.find((t) => t.id === id)?.name ?? id;
    expect(screen.getByText(sharedGroupLabel(["t1", "t3"], 1, nameOf))).toBeInTheDocument();
  });
});

/** Les groupes d'abord, et le formulaire seulement quand on le demande : la liste se lit sans chercher. */
describe("SharedTrainingBlockPanel — liste d'abord, formulaire replié", () => {
  it("l'intro définit le groupe ; la consigne de saisie n'apparaît qu'à l'ouverture du formulaire", async () => {
    const user = userEvent.setup();
    renderPanel();
    expect(T.blockIntro).not.toMatch(/cochez/i);
    expect(screen.getByText(T.blockIntro)).toBeInTheDocument();
    expect(screen.queryByText(/cochez-les/i)).toBeNull();

    await openNewGroup(user);
    expect(screen.getByText(/cochez-les/i)).toBeInTheDocument();
  });

  it("« Nouveau groupe » est replié à l'ouverture : aucune case, le corps n'est pas monté", () => {
    renderPanel(null, "t1");
    expect(formHeader(T.blockNewTitle)).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryAllByRole("checkbox")).toHaveLength(0);
  });

  it("les groupes de l'équipe ouverte passent en tête, sans perdre les autres", () => {
    blocksState.data = [block("b1", ["t2", "t3"]), block("b2", ["t1", "t2"])];
    renderPanel(null, "t1");
    expect(groupRows()).toEqual([sharedGroupLabel(["t1", "t2"], 1, nameOfTeam), sharedGroupLabel(["t2", "t3"], 1, nameOfTeam)]);
  });

  it("le crayon ouvre le formulaire en « Modifier le groupe » et l'amène à l'écran", async () => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2"], 2)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: T.blockEditAria("U9F1 + U9F2") }));

    expect(formHeader(T.blockEditTitle)).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("checkbox", { name: "U9F2" })).toBeChecked();
    expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
  });

  it("créer replie le formulaire et recoche l'équipe ouverte pour le groupe suivant", async () => {
    const user = userEvent.setup();
    renderPanel(null, "t1");
    await openNewGroup(user);
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(await screen.findByRole("button", { name: T.blockNewTitle })).toHaveAttribute("aria-expanded", "false");
    await openNewGroup(user);
    expect(screen.getByRole("checkbox", { name: "U9F1" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "U9F2" })).not.toBeChecked();
  });

  it.each([
    ["Enregistrer", T.blockSave],
    ["Annuler", T.blockCancel],
  ])("%s replie le formulaire et sort de la modification", async (_geste, label) => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2"], 2)];
    renderPanel(null, "t1");
    await user.click(screen.getByRole("button", { name: T.blockEditAria("U9F1 + U9F2") }));

    await user.click(screen.getByRole("button", { name: label }));

    expect(await screen.findByRole("button", { name: T.blockNewTitle })).toHaveAttribute("aria-expanded", "false");
    await openNewGroup(user);
    expect(screen.getByRole("checkbox", { name: "U9F2" }), "la sélection du groupe édité ne reste pas").not.toBeChecked();
  });

  it("replier à la main sort de la modification : la prochaine ouverture repart d'un nouveau groupe", async () => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2"], 2)];
    renderPanel();
    await user.click(screen.getByRole("button", { name: T.blockEditAria("U9F1 + U9F2") }));

    await user.click(formHeader(T.blockEditTitle));

    expect(formHeader(T.blockNewTitle)).toHaveAttribute("aria-expanded", "false");
    await openNewGroup(user);
    expect(screen.getByRole("checkbox", { name: "U9F1" })).not.toBeChecked();
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
  });

  it("supprimer le groupe en cours de modification sort de la modification", async () => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2"], 2)];
    renderPanel();
    await user.click(screen.getByRole("button", { name: T.blockEditAria("U9F1 + U9F2") }));

    await user.click(screen.getByRole("button", { name: T.blockDeleteAria("U9F1 + U9F2") }));
    await user.click(screen.getByRole("button", { name: T.blockDeleteConfirm }));

    expect(stbDelete).toHaveBeenCalledWith("b1");
    expect(formHeader(T.blockNewTitle)).toHaveAttribute("aria-expanded", "false");
  });
});

describe("SharedTrainingBlockPanel — compteur de cochées", () => {
  it("le compteur suit les cases cochées, sous les cases", async () => {
    const user = userEvent.setup();
    await renderOpenPanel(user);
    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    expect(screen.getByText(/^1 équipe cochée/)).toBeInTheDocument();
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));
    expect(screen.getByText(/^2 équipes cochées/)).toBeInTheDocument();
  });
});

/** Une équipe en pause n'est jamais mutualisée d'office : ni cochée, ni comptée, ni envoyée à la création. */
describe("SharedTrainingBlockPanel — équipe en pause", () => {
  const PAUSED = new Set(["t1"]);

  it("l'équipe ouverte en pause n'est pas pré-cochée : elle ne compte pas et ne part pas au serveur", async () => {
    const user = userEvent.setup();
    renderPanel("plan-1", "t1", PAUSED);
    await openNewGroup(user);

    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));
    expect(screen.getByText(/^1 équipe cochée/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Créer le groupe" }), "une seule équipe réelle : pas de groupe").toBeDisabled();

    await user.click(screen.getByRole("checkbox", { name: "U9M1" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));
    expect([...(stbCreate.mock.calls[0][0] as { teamIds: string[] }).teamIds].sort()).toEqual(["t2", "t3"]);
  });

  it("en modification, un membre en pause reste une ligne muette : sans case, hors du compteur, toujours membre à l'enregistrement", async () => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2", "t3"], 1, "plan-1")];
    renderPanel("plan-1", undefined, PAUSED);

    await user.click(screen.getByRole("button", { name: T.blockEditAria("U9F1 + U9F2 + U9M1") }));

    const body = document.getElementById(formHeader(T.blockEditTitle).getAttribute("aria-controls") as string) as HTMLElement;
    expect(within(body).queryByRole("checkbox", { name: "U9F1" })).toBeNull();
    expect(within(body).getByText(/U9F1/)).toBeInTheDocument();
    expect(within(body).getByText(/^2 équipes cochées/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: T.blockSave }));
    expect([...(stbUpdate.mock.calls[0][0] as { body: { teamIds: string[] } }).body.teamIds].sort()).toEqual(["t1", "t2", "t3"]);
  });

  it("le seuil « inhabituel » se compte sur les équipes réellement cochées, pas sur les membres en pause", async () => {
    const user = userEvent.setup();
    const many = [...TEAMS, team({ id: "t4", name: "U9M2", priorityTierId: 2, tierOrder: 1 }), team({ id: "t5", name: "U9M3", priorityTierId: 2, tierOrder: 2 })];
    blocksState.data = [block("b1", ["t1", "t2", "t3", "t4"], 1, "plan-1")];
    renderWithProviders(<SharedTrainingBlockPanel teams={many} tiers={TIERS} schedulePlanId="plan-1" pausedTeamIds={PAUSED} />);

    await user.click(screen.getByRole("button", { name: T.blockEditAria("U9F1 + U9F2 + U9M1 + U9M2") }));

    // 4 membres dont 1 en pause : 3 cochées, sous le seuil.
    expect(screen.queryByRole("alert")).toBeNull();
  });
});
