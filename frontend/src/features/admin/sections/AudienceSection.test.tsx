import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminAudienceResponse } from "../api";
import { getAdminAudience } from "../api";
import { AudienceSection } from "./AudienceSection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminAudience: vi.fn(),
  };
});

const mockAudience = vi.mocked(getAdminAudience);

/** Décor « données » : deux fenêtres distinctes (d7 ≠ d30) + top pages/sources. */
function buildData(): AdminAudienceResponse {
  return {
    configured: true,
    available: true,
    windows: {
      d7: { visitors: 300, visits: 450, pageviews: 1200, trend: 20 },
      d30: { visitors: 900, visits: 1300, pageviews: 5000, trend: -5 },
    },
    topPages: [
      { value: "/", count: 500 },
      { value: "/tarifs", count: 200 },
    ],
    topSources: [
      { value: "google.com", count: 300 },
      { value: "instagram.com", count: 60 },
    ],
  };
}

describe("AudienceSection", () => {
  beforeEach(() => {
    mockAudience.mockReset();
  });

  it("montre un indicateur de chargement tant que la mesure n'est pas arrivée", async () => {
    let resolve!: (value: AdminAudienceResponse) => void;
    mockAudience.mockReturnValue(new Promise<AdminAudienceResponse>((r) => (resolve = r)));
    renderWithProviders(<AudienceSection />);

    // Le panneau de chargement partagé porte role="status" (patron PanelLoading).
    expect(screen.getByRole("status")).toBeInTheDocument();

    resolve(buildData());
    await screen.findByText("/tarifs");
  });

  it("annonce « non configurée » quand la clé/le site Umami manque", async () => {
    mockAudience.mockResolvedValue({ configured: false } as AdminAudienceResponse);
    renderWithProviders(<AudienceSection />);

    expect(await screen.findByText(/mesure d.audience non configur/i)).toBeInTheDocument();
  });

  it("annonce « indisponible » quand Umami ne répond pas", async () => {
    mockAudience.mockResolvedValue({ configured: true, available: false } as AdminAudienceResponse);
    renderWithProviders(<AudienceSection />);

    expect(await screen.findByText(/indisponible/i)).toBeInTheDocument();
  });

  it("affiche les chiffres des deux fenêtres, le top pages et le top sources", async () => {
    mockAudience.mockResolvedValue(buildData());
    renderWithProviders(<AudienceSection />);

    // Les deux fenêtres rendent leurs visiteurs (valeurs distinctes d7 vs d30).
    expect(await screen.findByText("300")).toBeInTheDocument();
    expect(screen.getByText("900")).toBeInTheDocument();

    // Top pages (chemin) et top sources (référent) de la fenêtre 30 j.
    expect(screen.getByText("/tarifs")).toBeInTheDocument();
    expect(screen.getByText("google.com")).toBeInTheDocument();
  });
});
