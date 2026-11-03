import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { getAdminClubRequests, getAdminPendingMemberships } from "../api";
import { useAdminStore } from "../store";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { ClubRequestsSection } from "./ClubRequestsSection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminClubRequests: vi.fn(),
    getAdminPendingMemberships: vi.fn(),
  };
});

const mockRequests = vi.mocked(getAdminClubRequests);
const mockMemberships = vi.mocked(getAdminPendingMemberships);

describe("ClubRequestsSection — états vides", () => {
  beforeEach(() => {
    mockRequests.mockReset().mockResolvedValue({ items: [] });
    mockMemberships.mockReset().mockResolvedValue({ items: [] });
    useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
  });

  // P4-149 (option 1) — les deux messages « rien en attente » passent par EmptyHint
  // variant="console" (skin partagé `text-console-muted`), comme leurs 14 voisins de la
  // console superadmin, jamais un <p> console fait main (`text-console-text-dim`).
  it("rend le vide des demandes de création via le skin console partagé (EmptyHint)", async () => {
    renderWithProviders(<ClubRequestsSection />, { route: "/admin" });

    const hint = await screen.findByText(ADMIN_SECTIONS_TEXTS.clubRequestsCreationEmpty);
    expect(hint.className).toContain("text-console-muted");
  });

  it("rend le vide des adhésions en attente via le skin console partagé (EmptyHint)", async () => {
    renderWithProviders(<ClubRequestsSection />, { route: "/admin" });

    const hint = await screen.findByText(ADMIN_SECTIONS_TEXTS.clubRequestsMembershipsEmpty);
    expect(hint.className).toContain("text-console-muted");
  });
});

/**
 * Chantier Membre PR 3 (plan v5, écart 8) : chaque demande porte son ÉTIQUETTE, d'après le `kind`
 * servi par le serveur (jamais redérivé) — Création, Reprise (club sans gestionnaire), Promotion
 * (gestionnaires sans réponse). Lecture seule : aucun bouton nouveau.
 */
describe("ClubRequestsSection — nature de chaque demande", () => {
  beforeEach(() => {
    mockMemberships.mockReset().mockResolvedValue({ items: [] });
    useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
  });

  it("étiquette Création, Reprise et Promotion, une par ligne", async () => {
    const base = { clubEmail: "club@ffbb.fr", status: "pending" as const, requesterName: "Léa Martin", requesterEmail: "lea@club.fr", createdAt: "2026-11-01T09:00:00+01:00", expiresAt: "2026-11-08" };
    mockRequests.mockReset().mockResolvedValue({
      items: [
        { ...base, id: "r-create", clubName: "Club Neuf", ara: "ARA0000010", kind: "create" },
        { ...base, id: "r-reprise", clubName: "Club Fermant", ara: "ARA0000011", kind: "reprise" },
        { ...base, id: "r-promotion", clubName: "Club Muet", ara: "ARA0000012", kind: "promotion" },
      ],
    });
    renderWithProviders(<ClubRequestsSection />, { route: "/admin" });

    expect(await screen.findByText(ADMIN_SECTIONS_TEXTS.clubRequestKindCreate)).toBeInTheDocument();
    expect(screen.getByText(ADMIN_SECTIONS_TEXTS.clubRequestKindReprise)).toBeInTheDocument();
    expect(screen.getByText(ADMIN_SECTIONS_TEXTS.clubRequestKindPromotion)).toBeInTheDocument();
  });
});
