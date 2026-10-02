import { fireEvent, render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MailboxListResponse, MailboxMessage } from "./api";
import { MailboxPage } from "./MailboxPage";

let listData: MailboxListResponse | undefined;
let listError = false;
let messageData: MailboxMessage | undefined;

vi.mock("./queries", () => ({
  useMailbox: () => ({ data: listData, isError: listError, refetch: vi.fn() }),
  useMailboxMessage: (id: string | null) => ({ data: null == id ? undefined : messageData, isError: false, refetch: vi.fn() }),
}));
// PageHeader tire le canal feedback (react-query/stores) — réduit à un marqueur inerte.
vi.mock("@/shared/components/ui/page-header", () => ({
  PageHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));

function renderPage() {
  return render(
    <MemoryRouter>
      <MailboxPage />
    </MemoryRouter>,
  );
}

const summary = {
  id: "m1",
  from: "noreply@amateo.test",
  to: "coach@club.fr",
  subject: "Relance des vœux",
  simulatedDate: "2026-12-24",
  createdAt: "2026-10-02T10:00:00+00:00",
};

describe("MailboxPage", () => {
  beforeEach(() => {
    listData = undefined;
    listError = false;
    messageData = undefined;
  });

  it("montre un état vide quand aucun e-mail n'a été intercepté", () => {
    listData = { messages: [], count: 0 };
    renderPage();
    expect(screen.getByText("Boîte vide")).toBeInTheDocument();
  });

  it("LISTE les e-mails interceptés (sujet, expéditeur, destinataire)", () => {
    listData = { messages: [summary], count: 1 };
    renderPage();
    expect(screen.getByText("Relance des vœux")).toBeInTheDocument();
    expect(screen.getByText(/noreply@amateo\.test/)).toBeInTheDocument();
    expect(screen.getByText(/coach@club\.fr/)).toBeInTheDocument();
  });

  it("ouvre le DÉTAIL (corps texte) au clic sur un message", () => {
    listData = { messages: [summary], count: 1 };
    messageData = { ...summary, bodyText: "Bonjour, pensez à répondre.", bodyHtml: null };
    renderPage();

    expect(screen.getByText("Aucun message sélectionné")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /Relance des vœux/ }));
    expect(screen.getByText("Bonjour, pensez à répondre.")).toBeInTheDocument();
  });
});
