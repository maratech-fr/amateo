import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { ReadOnlyNotice } from "./ReadOnlyNotice";
import { SESSION_TEXTS } from "./texts";

const session = vi.hoisted(() => ({ canManage: true }));
vi.mock("./queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./queries")>()),
  useCanManage: () => session.canManage,
}));

/**
 * Promesse (PR 2) : un écran de saisie dit au Membre qu'il est en CONSULTATION — un bandeau
 * `role="status"` portant `SESSION_TEXTS.readOnlyNotice` — et ne dit rien à un gestionnaire.
 */
describe("ReadOnlyNotice", () => {
  afterEach(() => {
    session.canManage = true;
  });

  it("annonce la consultation à un Membre", () => {
    session.canManage = false;
    render(<ReadOnlyNotice />);
    expect(screen.getByRole("status")).toHaveTextContent(SESSION_TEXTS.readOnlyNotice);
  });

  it("ne rend rien pour un gestionnaire", () => {
    render(<ReadOnlyNotice />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(screen.queryByText(SESSION_TEXTS.readOnlyNotice)).not.toBeInTheDocument();
  });
});
