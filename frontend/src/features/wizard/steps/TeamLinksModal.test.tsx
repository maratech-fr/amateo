import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { TeamLink } from "@/features/matches/api";

import type { PriorityTier, SharedTrainingBlock, Team } from "../api";

const blocksState: { data: SharedTrainingBlock[] } = { data: [] };
const teamLinksState: { data: TeamLink[] } = { data: [] };
// D4 — l'onglet « Entente (CTC) » règle l'entente par un PUT d'équipe complet (useUpdateTeam).
const updateMut = vi.fn();

vi.mock("../queries", () => ({
  useTeamPeriodOverrides: () => ({ data: [] }),
  // P2-51 — le BLOC est la SEULE notion de mutualisation montée dans la modale.
  useSharedTrainingBlocks: () => ({ data: blocksState.data }),
  useCreateSharedTrainingBlock: () => ({ mutateAsync: vi.fn(() => Promise.resolve({})), isPending: false }),
  useUpdateSharedTrainingBlock: () => ({ mutateAsync: vi.fn(() => Promise.resolve({})), isPending: false }),
  useDeleteSharedTrainingBlock: () => ({ mutate: vi.fn() }),
  // D4 — l'onglet Entente écrit l'entente de l'équipe d'ouverture (case + partenaire) par ce PUT.
  useUpdateTeam: () => ({ mutate: updateMut }),
}));
vi.mock("@/features/matches/queries", () => ({
  useTeamLinks: () => ({ data: teamLinksState.data, isError: false }),
  useCreateTeamLink: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateTeamLink: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteTeamLink: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { TeamLinksModal } from "./TeamLinksModal";
// D4 — écran MIGRÉ : les tests vérifient les CLÉS centralisées, jamais une phrase recopiée
// (charte docs/testing/tests-par-parcours.md § Sélecteurs front). Ce module est créé par NEO
// (option A : ces tests sont ROUGES tant que `../texts/teams` n'existe pas).
import { TEAMS_TEXTS } from "../texts/teams";

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
const TEAMS: Team[] = [team({ id: "t1", name: "SM1" }), team({ id: "t2", name: "SM2" })];
const TIERS: PriorityTier[] = [{ id: 1, label: "S", name: "Fanion", color: null }];

const renderModal = (readOnlyLinks = false, schedulePlanId: string | null = null) =>
  renderWithProviders(
    <TeamLinksModal team={{ id: "t1", name: "SM1" }} teams={TEAMS} tiers={TIERS} schedulePlanId={schedulePlanId} readOnlyLinks={readOnlyLinks} onClose={vi.fn()} />,
  );

/** Même modale, mais sur un parc d'équipes DONNÉ — pour piloter l'entente de l'équipe d'ouverture (t1). */
const renderModalWith = (teams: Team[], opts: { readOnlyLinks?: boolean; schedulePlanId?: string | null } = {}) =>
  renderWithProviders(
    <TeamLinksModal
      team={{ id: "t1", name: "SM1" }}
      teams={teams}
      tiers={TIERS}
      schedulePlanId={opts.schedulePlanId ?? null}
      readOnlyLinks={opts.readOnlyLinks ?? false}
      onClose={vi.fn()}
    />,
  );

/**
 * Harnais pour la preuve C (anti-perte de saisie) : la modale se DÉMONTE réellement sur « Échap »
 * (onClose rebranché sur un état), ce qu'un `onClose={vi.fn()}` ne ferait pas. Le flush du
 * partenaire au démontage ne peut se prouver que si le composant est effectivement démonté.
 */
function CloseHarness({ teams }: { teams: Team[] }) {
  const [open, setOpen] = useState(true);
  return open ? (
    <TeamLinksModal team={{ id: "t1", name: "SM1" }} teams={teams} tiers={TIERS} schedulePlanId={null} readOnlyLinks={false} onClose={() => setOpen(false)} />
  ) : (
    <div>modale fermée</div>
  );
}

/** t1 = l'équipe d'ouverture, avec l'entente voulue ; t2 = une co-équipière inerte pour la mutualisation. */
const ctcTeams = (ctc: Partial<Pick<Team, "isCtc" | "ctcPartner">>): Team[] => [team({ id: "t1", name: "SM1", ...ctc }), team({ id: "t2", name: "SM2" })];

beforeEach(() => {
  blocksState.data = [];
  teamLinksState.data = [];
  updateMut.mockClear();
});

describe("TeamLinksModal — deux ONGLETS (Passerelles · Mutualisation)", () => {
  // #10 (améliorations légères, retour Rillieux 2026-10-21) — mutualisation et passerelle vivaient
  // dans le MÊME bloc, trop chargé : la modale les sépare désormais en DEUX onglets (primitive
  // `Tabs`), « Passerelles » actif par défaut (l'ordre historique haut → bas de la modale).
  it("titre « Liens de {équipe} » et une barre d'onglets Passerelles · Mutualisation", () => {
    renderModal();
    expect(screen.getByRole("dialog", { name: "Liens de SM1" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Passerelles" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Mutualisation" })).toBeInTheDocument();
    // Passerelles est l'onglet actif par défaut.
    expect(screen.getByRole("tab", { name: "Passerelles" })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("tab", { name: "Mutualisation" })).toHaveAttribute("aria-selected", "false");
  });

  it("par défaut, SEUL le panneau Passerelles est visible ; la mutualisation est dans l'onglet voisin", () => {
    renderModal();
    // Panneau actif = Passerelles : son contenu est accessible…
    expect(screen.getByRole("heading", { level: 3, name: "Passerelles entre équipes" })).toBeInTheDocument();
    // …et celui de la mutualisation (panneau inactif, `hidden`) ne l'est pas tant qu'on n'a pas
    // activé son onglet (`getByRole` ignore un sous-arbre `hidden`).
    expect(screen.queryByRole("button", { name: "Créer le groupe" })).toBeNull();
  });

  it("activer l'onglet Mutualisation montre la mutualisation et masque les passerelles", async () => {
    const user = userEvent.setup();
    renderModal();

    await user.click(screen.getByRole("tab", { name: "Mutualisation" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
    // Les passerelles sont alors dans le panneau inactif.
    expect(screen.queryByRole("button", { name: "Ajouter la passerelle" })).toBeNull();
    // D13 (correction fondateur) — UNE seule mutualisation (rendue par `SharedTrainingBlockPanel`) :
    // pas de titre « Bloc d'équipes ».
    expect(screen.queryByRole("heading", { name: "Bloc d'équipes" })).toBeNull();
  });

  it("jamais un dialog dans le dialog : « Gérer les passerelles » est absent", () => {
    renderModal();
    expect(screen.queryByRole("button", { name: "Gérer les passerelles" })).toBeNull();
  });

  it("readOnlyLinks=false : passerelles éditables (onglet actif), mutualisation éditable (onglet voisin)", async () => {
    const user = userEvent.setup();
    renderModal(false);
    expect(screen.getByRole("button", { name: "Ajouter la passerelle" })).toBeInTheDocument();
    await user.click(screen.getByRole("tab", { name: "Mutualisation" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
  });

  it("readOnlyLinks=true : passerelles en lecture seule (raison dite, pas d'ajout), mutualisation TOUJOURS éditable", async () => {
    const user = userEvent.setup();
    renderModal(true, "plan-9");
    // Onglet Passerelles (actif) : raison dite, aucun ajout.
    expect(screen.getByText(/se déclarent au niveau de la saison/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Ajouter la passerelle" })).toBeNull();
    // La mutualisation reste éditable (ancrée au plan) même quand les passerelles ne le sont pas.
    await user.click(screen.getByRole("tab", { name: "Mutualisation" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
  });
});

/**
 * Chantier D (CTC) D4 — l'entente (CTC) se RÈGLE dans un 3ᵉ onglet « Entente (CTC) » de la modale
 * Liens (décision fondateur 2026-10-25). Promesses déplacées depuis `TeamsStep.test.tsx:665-699`
 * (la saisie a quitté la ligne) + micro-décisions ui-ux-pro-max. Niveau le plus bas : jsdom voit
 * tout (visibilité, aria, buffer, démontage) — aucune traversée serveur, aucun axe §7.1. La
 * PERSISTANCE et la neutralité moteur du champ sont prouvées côté backend (`TeamCtcFieldTest`,
 * `ScheduleConstraintBuilderCtcNeutralityTest`) ; ici on garde ce que le FRONT envoie et affiche.
 */
describe("TeamLinksModal — onglet « Entente (CTC) » (D4)", () => {
  /** Active l'onglet Entente et rend la case (helper commun aux cas ci-dessous). */
  const openEntente = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(screen.getByRole("tab", { name: TEAMS_TEXTS.ctcTabLabel }));
  };

  it("ajoute un 3ᵉ onglet « Entente (CTC) » APRÈS Passerelles · Mutualisation, inactif par défaut", () => {
    renderModal();
    const tabs = screen.getAllByRole("tab");
    expect(tabs).toHaveLength(3);
    expect(tabs[2]).toHaveAccessibleName(TEAMS_TEXTS.ctcTabLabel);
    expect(screen.getByRole("tab", { name: TEAMS_TEXTS.ctcTabLabel })).toHaveAttribute("aria-selected", "false");
  });

  it("la case porte le libellé « Cette équipe joue en entente (CTC) »", async () => {
    const user = userEvent.setup();
    renderModal();
    await openEntente(user);
    expect(screen.getByRole("checkbox", { name: TEAMS_TEXTS.ctcCheckboxLabel })).toBeInTheDocument();
  });

  it("fait précéder la case d'une phrase d'aide, reliée par aria-describedby (découvrable au lecteur d'écran)", async () => {
    const user = userEvent.setup();
    renderModal();
    await openEntente(user);

    const cb = screen.getByRole("checkbox", { name: TEAMS_TEXTS.ctcCheckboxLabel });
    const describedBy = cb.getAttribute("aria-describedby");
    expect(describedBy).toBeTruthy();
    const help = document.getElementById(describedBy ?? "");
    expect(help).not.toBeNull();
    expect(help).toHaveTextContent(TEAMS_TEXTS.ctcHelp);
    // L'aide est AVANT la case dans l'ordre du document (elle l'introduit).
    expect((help as HTMLElement).compareDocumentPosition(cb) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("masque le champ partenaire tant que la case est décochée (divulgation progressive)", async () => {
    const user = userEvent.setup();
    renderModal(); // t1 sans entente
    await openEntente(user);
    expect(screen.getByRole("checkbox", { name: TEAMS_TEXTS.ctcCheckboxLabel })).not.toBeChecked();
    expect(screen.queryByRole("textbox", { name: TEAMS_TEXTS.ctcPartnerLabel })).toBeNull();
  });

  it("le champ partenaire a un label visible « Partenaire(s) », le placeholder et maxLength 120", async () => {
    const user = userEvent.setup();
    renderModalWith(ctcTeams({ isCtc: true, ctcPartner: null }));
    await openEntente(user);

    const partner = screen.getByRole("textbox", { name: TEAMS_TEXTS.ctcPartnerLabel });
    expect(partner).toHaveAttribute("placeholder", TEAMS_TEXTS.ctcPartnerPlaceholder);
    expect(partner).toHaveAttribute("maxlength", "120");
    // Un <label> VISIBLE (nœud texte rendu), pas seulement un aria-label.
    expect(screen.getByText(TEAMS_TEXTS.ctcPartnerLabel)).toBeInTheDocument();
  });

  // Falsification du régime de lecture : `team` est un Pick {id,name} SANS isCtc/ctcPartner — lire
  // le snapshot plutôt que `teams` rendrait la case décochée et le champ vide (RED).
  it("lit l'équipe FRAÎCHE de `teams` (pas le snapshot `team`) pour l'état de l'entente", async () => {
    const user = userEvent.setup();
    renderModalWith(ctcTeams({ isCtc: true, ctcPartner: "AS Voisine" }));
    await openEntente(user);

    expect(screen.getByRole("checkbox", { name: TEAMS_TEXTS.ctcCheckboxLabel })).toBeChecked();
    expect((screen.getByRole("textbox", { name: TEAMS_TEXTS.ctcPartnerLabel }) as HTMLInputElement).value).toBe("AS Voisine");
  });

  it("cocher la case PUT le payload COMPLET de l'équipe avec isCtc=true", async () => {
    const user = userEvent.setup();
    renderModal(); // t1 sans entente, sessionsPerWeek 3, priorityTierId 1
    await openEntente(user);

    const cb = screen.getByRole("checkbox", { name: TEAMS_TEXTS.ctcCheckboxLabel });
    expect(cb).not.toBeChecked();
    await user.click(cb);

    expect(updateMut).toHaveBeenCalledOnce();
    const { id, body } = updateMut.mock.calls[0][0];
    expect(id).toBe("t1");
    expect(body.isCtc).toBe(true);
    // Payload COMPLET (lu de l'équipe fraîche), jamais un patch partiel : les autres champs sont là.
    expect(body).toMatchObject({ name: "SM1", sessionsPerWeek: 3, priorityTierId: 1 });
  });

  it("décocher PUT isCtc=false ET efface le partenaire (ctcPartner null)", async () => {
    const user = userEvent.setup();
    renderModalWith(ctcTeams({ isCtc: true, ctcPartner: "AS Voisine" }));
    await openEntente(user);

    await user.click(screen.getByRole("checkbox", { name: TEAMS_TEXTS.ctcCheckboxLabel }));
    expect(updateMut).toHaveBeenCalledOnce();
    expect(updateMut.mock.calls[0][0].body).toMatchObject({ isCtc: false, ctcPartner: null });
  });

  // B (anti-report d'un ancien partenaire) — décocher efface côté serveur ; recocher dans la foulée
  // ne doit PAS re-proposer l'ancien partenaire : le champ revient VIDE.
  it("recocher après avoir décoché laisse le champ partenaire VIDE (B)", async () => {
    const user = userEvent.setup();
    renderModalWith(ctcTeams({ isCtc: true, ctcPartner: "AS Voisine" }));
    await openEntente(user);

    const cb = screen.getByRole("checkbox", { name: TEAMS_TEXTS.ctcCheckboxLabel });
    expect((screen.getByRole("textbox", { name: TEAMS_TEXTS.ctcPartnerLabel }) as HTMLInputElement).value).toBe("AS Voisine");

    await user.click(cb); // décocher → le champ disparaît
    expect(screen.queryByRole("textbox", { name: TEAMS_TEXTS.ctcPartnerLabel })).toBeNull();

    await user.click(cb); // recocher → champ de nouveau présent, mais VIDE
    expect((screen.getByRole("textbox", { name: TEAMS_TEXTS.ctcPartnerLabel }) as HTMLInputElement).value).toBe("");
  });

  it("enregistre le partenaire saisi au BLUR", async () => {
    const user = userEvent.setup();
    renderModalWith(ctcTeams({ isCtc: true, ctcPartner: null }));
    await openEntente(user);

    await user.type(screen.getByRole("textbox", { name: TEAMS_TEXTS.ctcPartnerLabel }), "Entente des 3 Rivières");
    await user.tab(); // blur → enregistrement

    expect(updateMut).toHaveBeenCalled();
    expect(updateMut.mock.calls.at(-1)?.[0].body.ctcPartner).toBe("Entente des 3 Rivières");
  });

  it("« Entrée » dans le champ partenaire enregistre sans attendre le blur", async () => {
    const user = userEvent.setup();
    renderModalWith(ctcTeams({ isCtc: true, ctcPartner: null }));
    await openEntente(user);

    await user.type(screen.getByRole("textbox", { name: TEAMS_TEXTS.ctcPartnerLabel }), "AS Voisine{Enter}");

    expect(updateMut).toHaveBeenCalled();
    expect(updateMut.mock.calls.at(-1)?.[0].body.ctcPartner).toBe("AS Voisine");
  });

  // C (anti-perte de saisie) — « Échap » ferme SANS blur ; sans flush au démontage la frappe serait
  // perdue. Falsification : un composant qui n'enregistre QU'au blur ne poste rien ici (RED).
  it("flush au DÉMONTAGE : taper « AS Voisine » puis « Échap » enregistre ctcPartner (C)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CloseHarness teams={ctcTeams({ isCtc: true, ctcPartner: null })} />);
    await user.click(screen.getByRole("tab", { name: TEAMS_TEXTS.ctcTabLabel }));

    await user.type(screen.getByRole("textbox", { name: TEAMS_TEXTS.ctcPartnerLabel }), "AS Voisine");
    await user.keyboard("{Escape}"); // ferme la modale → démontage, SANS blur préalable

    expect(screen.getByText("modale fermée")).toBeInTheDocument(); // la modale a bien été démontée
    expect(updateMut).toHaveBeenCalled();
    expect(updateMut.mock.calls.at(-1)?.[0].body.ctcPartner).toBe("AS Voisine");
  });

  // L'onglet Entente n'hérite PAS de `readOnlyLinks` (décision 2) : un repère de club, valable toute
  // la saison, se règle même en période où les passerelles, elles, sont en lecture seule.
  it("readOnlyLinks=true (période) : Passerelles en lecture seule MAIS Entente ÉDITABLE (le PUT part)", async () => {
    const user = userEvent.setup();
    renderModalWith(ctcTeams({ isCtc: false, ctcPartner: null }), { readOnlyLinks: true, schedulePlanId: "plan-9" });

    // Les passerelles (onglet actif) restent en lecture seule.
    expect(screen.queryByRole("button", { name: "Ajouter la passerelle" })).toBeNull();

    await openEntente(user);
    await user.click(screen.getByRole("checkbox", { name: TEAMS_TEXTS.ctcCheckboxLabel }));
    expect(updateMut).toHaveBeenCalledOnce();
    expect(updateMut.mock.calls[0][0].body.isCtc).toBe(true);
  });
});
