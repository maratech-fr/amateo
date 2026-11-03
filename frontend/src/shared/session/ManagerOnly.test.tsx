import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { ManagerOnly } from "./ManagerOnly";

const session = vi.hoisted(() => ({ canManage: true }));
vi.mock("./queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./queries")>()),
  useCanManage: () => session.canManage,
}));

/**
 * Promesse (PR 2) : un geste d'écriture enveloppé dans `ManagerOnly` est MASQUÉ pour un Membre
 * (jamais grisé), rendu tel quel pour un gestionnaire. La couture est `useCanManage` de
 * `@/shared/session/queries` (D-31) : `ManagerOnly` doit l'importer depuis ce module.
 */
describe("ManagerOnly", () => {
  afterEach(() => {
    session.canManage = true;
  });

  it("rend le geste pour un gestionnaire", () => {
    render(<ManagerOnly><button type="button">Enregistrer</button></ManagerOnly>);
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeInTheDocument();
  });

  it("masque le geste pour un Membre (absent du DOM, pas désactivé)", () => {
    session.canManage = false;
    render(<ManagerOnly><button type="button">Enregistrer</button></ManagerOnly>);
    expect(screen.queryByRole("button", { name: "Enregistrer" })).not.toBeInTheDocument();
  });
});
