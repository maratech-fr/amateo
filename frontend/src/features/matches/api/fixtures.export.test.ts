import { beforeEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";

import { exportFixturesPdf } from "./fixtures";

/**
 * Promesse : le navigateur attend le PDF au-delà des 30 s du worker. Avec le délai par défaut de ky
 * (10 s), un export club entier serait abandonné côté client alors que le serveur le livre et compte
 * le crédit. Le serveur reçoit exactement la plage et l'équipe choisies.
 */
vi.mock("@/shared/api/client", () => ({ api: { post: vi.fn() } }));

describe("exportFixturesPdf", () => {
  beforeEach(() => {
    vi.mocked(api.post).mockReset();
  });

  it("poste la plage et l'équipe avec un délai de 60 s, et rend le fichier", async () => {
    const blob = new Blob(["%PDF"]);
    vi.mocked(api.post).mockReturnValue({ blob: () => Promise.resolve(blob) } as never);
    const input = { from: "2026-11-09", to: "2026-11-15", teamId: "team-13" };

    await expect(exportFixturesPdf(input)).resolves.toBe(blob);

    expect(api.post).toHaveBeenCalledWith("fixtures/export-pdf", expect.objectContaining({ json: input, timeout: 60_000 }));
  });
});
