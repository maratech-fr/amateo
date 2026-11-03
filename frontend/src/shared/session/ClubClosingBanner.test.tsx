import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { ClubClosingBanner } from "./ClubClosingBanner";

const meState = vi.hoisted(() => ({ data: undefined as unknown }));
vi.mock("./queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./queries")>()),
  useMe: () => ({ data: meState.data }),
}));

const meWithClosing = (closingOn: string | null) => ({
  membershipStatus: "active",
  role: "member",
  club: { id: "c1", name: "BC Test", closingOn },
});

/**
 * Promesse (PR 2, décision fondateur 3) : quand le club n'a plus de gestionnaire, CHAQUE écran
 * dit la date d'effacement (`me.club.closingOn`, JJ/MM/AAAA) — un bandeau d'information SANS
 * bouton (le geste de reprise arrive en PR 3) ; rien quand `closingOn` est null.
 * Maison : `shared/session/` (PR 3 l'enrichit, son périmètre autorisé inclut `shared/session`).
 */
describe("ClubClosingBanner", () => {
  afterEach(() => {
    meState.data = undefined;
  });

  it("annonce la date d'effacement quand le club ferme, sans aucun bouton", () => {
    meState.data = meWithClosing("2026-12-15");
    render(<ClubClosingBanner />);
    expect(screen.getByRole("status")).toHaveTextContent("15/12/2026");
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("ne rend rien quand le club a un gestionnaire (closingOn null)", () => {
    meState.data = meWithClosing(null);
    render(<ClubClosingBanner />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("ne rend rien tant que la session n'est pas chargée", () => {
    render(<ClubClosingBanner />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });
});
