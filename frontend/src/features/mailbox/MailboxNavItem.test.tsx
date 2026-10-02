import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MailboxNavItem } from "./MailboxNavItem";

let simulatedToday: string | null | undefined;
let mailboxCount: number | undefined;

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { club: { simulatedToday } } }),
}));
vi.mock("./queries", () => ({
  useMailbox: () => ({ data: undefined === mailboxCount ? undefined : { messages: [], count: mailboxCount } }),
}));

function renderNav() {
  return render(
    <MemoryRouter>
      <MailboxNavItem />
    </MemoryRouter>,
  );
}

describe("MailboxNavItem", () => {
  beforeEach(() => {
    simulatedToday = undefined;
    mailboxCount = undefined;
  });

  it("est ABSENTE quand le club n'a pas d'horloge simulée", () => {
    simulatedToday = null;
    renderNav();
    expect(screen.queryByRole("link", { name: /boîte aux lettres/i })).toBeNull();
  });

  it("est PRÉSENTE quand le club vit à une horloge simulée", () => {
    simulatedToday = "2026-12-24";
    renderNav();
    const link = screen.getByRole("link", { name: /boîte aux lettres/i });
    expect(link).toBeInTheDocument();
    expect(link.getAttribute("href")).toBe("/boite-aux-lettres");
  });

  it("affiche le COMPTEUR des e-mails interceptés", () => {
    simulatedToday = "2026-12-24";
    mailboxCount = 3;
    renderNav();
    expect(screen.getByText("3")).toBeInTheDocument();
  });

  it("n'affiche pas de compteur quand la boîte est vide", () => {
    simulatedToday = "2026-12-24";
    mailboxCount = 0;
    renderNav();
    expect(screen.getByRole("link", { name: /boîte aux lettres/i })).toBeInTheDocument();
    expect(screen.queryByText("0")).toBeNull();
  });
});
