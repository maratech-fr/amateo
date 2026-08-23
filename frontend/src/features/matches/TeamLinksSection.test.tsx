import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PriorityTier, Team, TeamLink } from "./api";
import { TeamLinksSection } from "./TeamLinksSection";

const createLink = vi.fn();
const updateLink = vi.fn();
const deleteLink = vi.fn();
const linksState: { data: TeamLink[] } = { data: [] };

// On pilote les hooks, jamais le réseau — même patron que HabitsLinksDialog.test.
vi.mock("./queries", () => ({
  useTeamLinks: () => ({ data: linksState.data, isError: false }),
  useCreateTeamLink: () => ({ mutate: createLink, isPending: false }),
  useUpdateTeamLink: () => ({ mutate: updateLink, isPending: false }),
  useDeleteTeamLink: () => ({ mutate: deleteLink, isPending: false }),
}));

const team = (id: string, name: string): Team => ({ id, name, sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 });
const TEAMS: Team[] = [team("t1", "SM1"), team("t2", "SM2"), team("t3", "SF1")];
const TIERS: PriorityTier[] = [{ id: 1, label: "S", name: "Fanion", color: null }];

const teamLink = (over: Partial<TeamLink> = {}): TeamLink => ({ id: "l1", teamAId: "t1", teamBId: "t2", linkType: "NOT_SIMULTANEOUS", trainingIntensity: "PREFERRED", ...over });

beforeEach(() => {
  createLink.mockClear();
  updateLink.mockClear();
  deleteLink.mockClear();
  linksState.data = [];
});

describe("TeamLinksSection — filtrage et pré-remplissage (filterTeamId)", () => {
  it("ne montre que les passerelles nommant l'équipe filtrée", () => {
    linksState.data = [teamLink({ id: "l1", teamAId: "t1", teamBId: "t2" }), teamLink({ id: "l2", teamAId: "t2", teamBId: "t3" })];
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} filterTeamId="t1" />);

    // La passerelle SM1↔SM2 nomme t1 ; SM2↔SF1 non → absente.
    expect(screen.getByText(/SM1 ↔ SM2/)).toBeInTheDocument();
    expect(screen.queryByText(/SM2 ↔ SF1/)).toBeNull();
  });

  it("FIGE l'équipe A en texte quand ancrée : le nom s'affiche, le sélecteur A disparaît", () => {
    // Comportement changé (fondateur) : ancrée à SM1, A n'est plus un champ modifiable mais un
    // FAIT affiché en texte. Un sélecteur A laisserait créer un lien qui ne concerne pas SM1.
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} filterTeamId="t1" />);
    expect(screen.queryByLabelText("Première équipe du lien")).toBeNull();
    expect(screen.getByText("SM1")).toBeInTheDocument();
  });

  it("SANS filterTeamId (depuis /matchs), le sélecteur A EST là (comportement inchangé)", () => {
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} />);
    expect(screen.getByLabelText("Première équipe du lien")).toBeInTheDocument();
  });
});

describe("TeamLinksSection — équipe B filtrée UNIQUEMENT dans la modale ancrée (2026-08-23)", () => {
  it("ancrée : B ne propose plus une équipe DÉJÀ liée à A, ni A elle-même ; les autres restent", () => {
    // Lien SM1↔SM2 en état → SM2 absente du sélecteur B ; SF1 présente ; SM1 (=A) absente.
    linksState.data = [teamLink({ id: "l1", teamAId: "t1", teamBId: "t2" })];
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} filterTeamId="t1" />);

    const bSelect = screen.getByLabelText("Seconde équipe du lien");
    expect(within(bSelect).queryByRole("option", { name: "SM2" })).toBeNull();
    expect(within(bSelect).queryByRole("option", { name: "SM1" })).toBeNull();
    expect(within(bSelect).getByRole("option", { name: "SF1" })).toBeInTheDocument();
  });

  it("SANS filterTeamId : B n'est PAS filtrée — SM2 reste proposée même liée (décision fondateur)", () => {
    linksState.data = [teamLink({ id: "l1", teamAId: "t1", teamBId: "t2" })];
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} />);

    const bSelect = screen.getByLabelText("Seconde équipe du lien");
    expect(within(bSelect).getByRole("option", { name: "SM2" })).toBeInTheDocument();
    expect(within(bSelect).getByRole("option", { name: "SM1" })).toBeInTheDocument();
  });

  it("ancrée, une SEULE équipe (aucune autre à lier) : garde le formulaire, PAS la phrase « déjà liées »", () => {
    // Cas limite distinct : B vide parce qu'il n'y a pas d'autre équipe, pas parce qu'elles sont
    // toutes liées. Le formulaire reste (bouton présent, inerte), aucune phrase mensongère.
    renderWithProviders(<TeamLinksSection teams={[team("t1", "SM1")]} tiers={TIERS} filterTeamId="t1" />);
    expect(screen.getByRole("button", { name: "Ajouter la passerelle" })).toBeInTheDocument();
    expect(screen.queryByText(/Toutes les équipes sont déjà liées/)).toBeNull();
  });

  it("ancrée, toutes les équipes déjà liées à A : pas de sélecteur B, une phrase d'état", () => {
    linksState.data = [teamLink({ id: "l1", teamAId: "t1", teamBId: "t2" }), teamLink({ id: "l2", teamAId: "t1", teamBId: "t3" })];
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} filterTeamId="t1" />);

    expect(screen.queryByLabelText("Seconde équipe du lien")).toBeNull();
    expect(screen.getByText(/Toutes les équipes sont déjà liées à SM1/)).toBeInTheDocument();
  });
});

describe("TeamLinksSection — lecture seule vs édition (P2-45, tranchage A)", () => {
  it("readOnly : liste + intensité EN TEXTE, aucun contrôle d'édition, raison affichée", () => {
    linksState.data = [teamLink({ trainingIntensity: "PREFERRED" })];
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} filterTeamId="t1" readOnly />);

    // La raison est DITE (jamais une section grisée muette).
    expect(screen.getByText(/se déclarent au niveau de la saison/)).toBeInTheDocument();
    // L'intensité s'affiche en texte, pas via un Select.
    expect(screen.getByText(/Entraînement\s*:\s*Préféré/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Intensité d'entraînement, passerelle/)).toBeNull();
    // Aucun geste d'écriture.
    expect(screen.queryByRole("button", { name: "Ajouter la passerelle" })).toBeNull();
    expect(screen.queryByRole("button", { name: /Supprimer la passerelle/ })).toBeNull();
  });

  it("éditable (défaut) : les contrôles SONT là — le contrôle d'intensité et l'ajout", () => {
    linksState.data = [teamLink({ trainingIntensity: "PREFERRED" })];
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} filterTeamId="t1" />);

    expect(screen.getByLabelText("Intensité d'entraînement, passerelle SM1 – SM2")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Ajouter la passerelle" })).toBeInTheDocument();
    // Pas de phrase de lecture seule quand on peut éditer.
    expect(screen.queryByText(/se déclarent au niveau de la saison/)).toBeNull();
  });

  it("readOnly, aucune passerelle : le dit clairement pour l'équipe, sans contrôle", () => {
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} filterTeamId="t3" readOnly />);
    expect(screen.getByText(/Aucune passerelle pour cette équipe/)).toBeInTheDocument();
  });

  it("éditable : supprimer une passerelle appelle la mutation", async () => {
    const user = userEvent.setup();
    linksState.data = [teamLink()];
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} />);

    await user.click(screen.getByRole("button", { name: "Supprimer la passerelle SM1 – SM2" }));
    expect(deleteLink).toHaveBeenCalledWith("l1");
  });

  it("éditable : créer une passerelle avec l'équipe A pré-remplie", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamLinksSection teams={TEAMS} tiers={TIERS} filterTeamId="t1" />);

    await user.selectOptions(screen.getByLabelText("Seconde équipe du lien"), "t2");
    await user.click(screen.getByRole("button", { name: "Ajouter la passerelle" }));

    expect(createLink).toHaveBeenCalledWith({ teamAId: "t1", teamBId: "t2", linkType: "NOT_SIMULTANEOUS", trainingIntensity: "PREFERRED" });
  });
});
