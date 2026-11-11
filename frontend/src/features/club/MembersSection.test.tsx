import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MembersResponse } from "@/features/auth/api";

// On mocke le module API VOISIN (auth/api) et on monte les VRAIS hooks react-query
// (queries.ts) sur un QueryClient réel — le test garde donc la logique de lecture,
// d'invalidation et de restitution d'erreur, pas seulement le câblage (règle §7.2).
const api = vi.hoisted(() => ({
  getMembers: vi.fn<() => Promise<MembersResponse>>(),
  changeMemberRole: vi.fn().mockResolvedValue({}),
  deactivateMember: vi.fn().mockResolvedValue(undefined),
  reactivateMember: vi.fn().mockResolvedValue(undefined),
  // Chantier Membre PR 3 (plan v5, D2) : « Accepter » = `changeMemberRole(id, "admin")` (le geste
  // du sélecteur, aucune route à part) ; « Refuser » = `POST /api/memberships/{id}/manager-request/refuse`.
  refuseManagerRequest: vi.fn().mockResolvedValue({}),
}));
vi.mock("@/features/auth/api", () => api);

const toast = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), info: vi.fn() }));
vi.mock("@/shared/stores/toastStore", () => ({ toast }));

import { CLUB_TEXTS } from "./texts";
import { MembersSection } from "./MembersSection";

const self = { id: "self", userId: "us", email: "boss@club.fr", firstName: "Grace", lastName: "Hopper", role: "admin", isSelf: true, managerRequestedAt: null, managerRequestEscalatedAt: null };
const bob = { id: "m-bob", userId: "ub", email: "bob@club.fr", firstName: "Bob", lastName: "Martin", role: "member", isSelf: false, managerRequestedAt: null, managerRequestEscalatedAt: null };

function renderSection(data: MembersResponse) {
  api.getMembers.mockResolvedValue(data);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MembersSection />
    </QueryClientProvider>,
  );
}

describe("MembersSection", () => {
  beforeEach(() => vi.clearAllMocks());

  it("liste actifs (badge « vous » + rôle) et désactivés", async () => {
    renderSection({ members: [self, bob], deactivated: [{ id: "m-ada", userId: "ua", email: "ada@club.fr", firstName: "Ada", lastName: "Lovelace", role: "member" }] });
    expect(await screen.findByText("Grace Hopper")).toBeInTheDocument();
    expect(screen.getByText("vous")).toBeInTheDocument();
    expect(screen.getByText("Bob Martin")).toBeInTheDocument();
    // Bloc désactivés : nom + rôle en libellé + geste de réactivation.
    expect(screen.getByText("Ada Lovelace")).toBeInTheDocument();
    expect(screen.getByText(/ada@club\.fr · Membre/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Réactiver/ })).toBeInTheDocument();
  });

  it("changer le rôle d'un autre membre appelle l'API avec (id, rôle)", async () => {
    const user = userEvent.setup();
    renderSection({ members: [self, bob], deactivated: [] });
    await screen.findByText("Bob Martin");
    await user.selectOptions(screen.getByLabelText("Rôle de Bob Martin"), "Gestionnaire");
    expect(api.changeMemberRole).toHaveBeenCalledWith("m-bob", "admin");
  });

  it("réactiver un désactivé appelle l'API avec son id", async () => {
    const user = userEvent.setup();
    renderSection({ members: [self], deactivated: [{ id: "m-ada", userId: "ua", email: "ada@club.fr", firstName: "Ada", lastName: "Lovelace", role: "member" }] });
    await screen.findByText("Ada Lovelace");
    await user.click(screen.getByRole("button", { name: /Réactiver/ }));
    expect(api.reactivateMember).toHaveBeenCalledWith("m-ada");
  });

  it("dernier gestionnaire sur soi : rôle et désactivation désactivés, avec explication (serveur seul juge sinon)", async () => {
    renderSection({ members: [self], deactivated: [] });
    await screen.findByText("Grace Hopper");
    expect(screen.getByLabelText("Rôle de Grace Hopper")).toBeDisabled();
    const deactivate = screen.getByRole("button", { name: /Désactiver/ });
    // Désactivé mais DÉCOUVRABLE (A11Y-30, `disabledReason`) : aria-disabled + raison en description.
    expect(deactivate).toHaveAttribute("aria-disabled", "true");
    expect(deactivate).toHaveAttribute("title", expect.stringContaining("seul gestionnaire"));
  });

  it("avec deux gestionnaires, le geste sur soi redevient possible (aucune prédiction du 409)", async () => {
    const coManager = { ...bob, role: "admin" };
    renderSection({ members: [self, coManager], deactivated: [] });
    await screen.findByText("Grace Hopper");
    expect(screen.getByLabelText("Rôle de Grace Hopper")).not.toBeDisabled();
  });

  it("une lecture en échec affiche un message d'erreur, pas une liste vide", async () => {
    api.getMembers.mockRejectedValue(new Error("boom"));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MembersSection />
      </QueryClientProvider>,
    );
    expect(await screen.findByText(/Impossible de charger les membres/)).toBeInTheDocument();
  });

  /**
   * Chantier Membre PR 3 (plan v5) — la pastille « Demande gestionnaire » (datée, « transmise au
   * club » quand le serveur l'a escaladée) et ses deux gestes nommés. « Accepter » EST le geste du
   * sélecteur de rôle (D2 : `changeMemberRole(id, "admin")`, aucun second mécanisme) ; « Refuser »
   * appelle la route de refus.
   */
  describe("demande à devenir gestionnaire (chantier Membre PR 3)", () => {
    const paul = { id: "m-paul", userId: "up", email: "paul@club.fr", firstName: "Paul", lastName: "Martin", role: "member", isSelf: false, managerRequestedAt: "2026-11-03T12:00:00+00:00", managerRequestEscalatedAt: null };
    const kim = { id: "m-kim", userId: "uk", email: "kim@club.fr", firstName: "Kim", lastName: "Lee", role: "member", isSelf: false, managerRequestedAt: null, managerRequestEscalatedAt: null };
    const me = { ...self, managerRequestedAt: null, managerRequestEscalatedAt: null };

    it("seul le Membre qui a demandé porte la pastille datée et les deux gestes nommés", async () => {
      renderSection({ members: [me, paul, kim], deactivated: [] });
      await screen.findByText("Paul Martin");

      expect(screen.getByText(CLUB_TEXTS.managerRequestPill("03/11/2026", false))).toBeInTheDocument();
      expect(screen.getByRole("button", { name: CLUB_TEXTS.acceptManagerRequestOf("Paul Martin") })).toBeInTheDocument();
      expect(screen.getByRole("button", { name: CLUB_TEXTS.refuseManagerRequestOf("Paul Martin") })).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: CLUB_TEXTS.acceptManagerRequestOf("Kim Lee") })).not.toBeInTheDocument();
      expect(screen.getAllByText(/Demande gestionnaire/)).toHaveLength(1);
    });

    it("escaladée : la pastille dit « transmise au club »", async () => {
      renderSection({ members: [me, { ...paul, managerRequestEscalatedAt: "2026-11-17T12:00:00+00:00" }], deactivated: [] });
      expect(await screen.findByText(CLUB_TEXTS.managerRequestPill("03/11/2026", true))).toBeInTheDocument();
    });

    /**
     * UXS-20 (petites lignes, Q2) — « Accepter » donne tous les droits de gestion : le geste passe
     * par une confirmation nommée. Rien ne part avant « Accepter » DANS le dialogue ; ensuite c'est
     * le geste du sélecteur de rôle (`changeMemberRole(id, "admin")`), une seule fois.
     */
    it("« Accepter » ouvre une confirmation : rien ne part avant, changeMemberRole(id, admin) une fois après", async () => {
      const user = userEvent.setup();
      renderSection({ members: [me, paul], deactivated: [] });
      await screen.findByText("Paul Martin");

      await user.click(screen.getByRole("button", { name: CLUB_TEXTS.acceptManagerRequestOf("Paul Martin") }));
      const dialog = screen.getByRole("dialog", { name: CLUB_TEXTS.acceptManagerRequestTitle("Paul Martin") });
      expect(within(dialog).getByText(CLUB_TEXTS.acceptManagerRequestDescription("Paul Martin"))).toBeInTheDocument();
      expect(api.changeMemberRole).not.toHaveBeenCalled();

      await user.click(within(dialog).getByRole("button", { name: CLUB_TEXTS.acceptManagerRequest }));
      await vi.waitFor(() => expect(api.changeMemberRole).toHaveBeenCalledTimes(1));
      expect(api.changeMemberRole).toHaveBeenCalledWith("m-paul", "admin");
      expect(api.refuseManagerRequest).not.toHaveBeenCalled();
    });

    /**
     * UXS-20 — « Refuser » se confirme : la route de refus ne part qu'après « Refuser » dans le
     * dialogue, avec l'id de l'adhésion, une seule fois.
     */
    it("« Refuser » ouvre une confirmation : rien ne part avant, la route de refus une fois après", async () => {
      const user = userEvent.setup();
      renderSection({ members: [me, paul], deactivated: [] });
      await screen.findByText("Paul Martin");

      await user.click(screen.getByRole("button", { name: CLUB_TEXTS.refuseManagerRequestOf("Paul Martin") }));
      const dialog = screen.getByRole("dialog", { name: CLUB_TEXTS.refuseManagerRequestTitle("Paul Martin") });
      expect(within(dialog).getByText(CLUB_TEXTS.refuseManagerRequestDescription("Paul Martin"))).toBeInTheDocument();
      expect(api.refuseManagerRequest).not.toHaveBeenCalled();

      await user.click(within(dialog).getByRole("button", { name: CLUB_TEXTS.refuseManagerRequest }));
      await vi.waitFor(() => expect(api.refuseManagerRequest).toHaveBeenCalledTimes(1));
      expect(api.refuseManagerRequest).toHaveBeenCalledWith("m-paul");
      expect(api.changeMemberRole).not.toHaveBeenCalled();
    });

    /** UXS-20 — le geste inverse : « Annuler » ou Échap ferment le dialogue sans rien écrire, la pastille reste. */
    it.each([
      ["Refuser", "Annuler"],
      ["Refuser", "Échap"],
      ["Accepter", "Annuler"],
      ["Accepter", "Échap"],
    ] as const)("« %s » puis %s : aucun appel, la pastille et les deux gestes restent", async (gesture, exit) => {
      const user = userEvent.setup();
      renderSection({ members: [me, paul], deactivated: [] });
      await screen.findByText("Paul Martin");

      const name = "Refuser" === gesture ? CLUB_TEXTS.refuseManagerRequestOf("Paul Martin") : CLUB_TEXTS.acceptManagerRequestOf("Paul Martin");
      await user.click(screen.getByRole("button", { name }));
      const dialog = screen.getByRole("dialog");
      if ("Annuler" === exit) {
        await user.click(within(dialog).getByRole("button", { name: "Annuler" }));
      } else {
        await user.keyboard("{Escape}");
      }

      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      expect(api.refuseManagerRequest).not.toHaveBeenCalled();
      expect(api.changeMemberRole).not.toHaveBeenCalled();
      expect(screen.getByText(CLUB_TEXTS.managerRequestPill("03/11/2026", false))).toBeInTheDocument();
      expect(screen.getByRole("button", { name: CLUB_TEXTS.refuseManagerRequestOf("Paul Martin") })).toBeInTheDocument();
    });

    it("un refus serveur (409 « Aucune demande en cours. ») est restitué en toast, jamais avalé", async () => {
      const user = userEvent.setup();
      api.refuseManagerRequest.mockRejectedValueOnce(new Error("409"));
      renderSection({ members: [me, paul], deactivated: [] });
      await screen.findByText("Paul Martin");
      await user.click(screen.getByRole("button", { name: CLUB_TEXTS.refuseManagerRequestOf("Paul Martin") }));
      await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: CLUB_TEXTS.refuseManagerRequest }));
      await vi.waitFor(() => expect(toast.error).toHaveBeenCalled());
    });
  });

  it("un geste refusé par le serveur est restitué (toast), jamais avalé", async () => {
    const user = userEvent.setup();
    api.deactivateMember.mockRejectedValueOnce(new Error("409"));
    renderSection({ members: [self, bob], deactivated: [] });
    await screen.findByText("Bob Martin");
    // Désactiver Bob (non-soi) : l'UI ne prédit rien, elle tente et restitue l'échec.
    await user.click(screen.getAllByRole("button", { name: /Désactiver/ })[1]);
    await vi.waitFor(() => expect(toast.error).toHaveBeenCalled());
  });
});
