import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { PageHeader } from "./page-header";

// Le bouton « Signaler » vit dans `shared/feedback/` (descendu de `features/` — un en-tête
// PARTAGÉ ne peut pas remonter vers une feature, AUD-FRT-21). On le remplace par un double
// fidèle : un vrai bouton accessible qui expose ses props, pour éprouver le CÂBLAGE (screen,
// scheduleId, présence) sans monter la modale.
vi.mock("@/shared/feedback/FeedbackButton", () => ({
  FeedbackButton: ({ screen: screenProp, scheduleId }: { screen?: string; scheduleId?: string | null }) => (
    <button type="button" data-screen={screenProp} data-schedule-id={scheduleId ?? ""}>
      Signaler
    </button>
  ),
}));

describe("PageHeader — l'en-tête de page partagé (trait + titre à gauche, Signaler à droite)", () => {
  it("rend le titre dans un h1 de niveau 1, avec le trait d'accent", () => {
    render(<PageHeader title="Matchs" screen="/matchs" />);
    const heading = screen.getByRole("heading", { level: 1, name: "Matchs" });
    expect(heading).toBeInTheDocument();
    expect(heading).toHaveClass("border-l-[3px]", "border-accent", "text-2xl");
  });

  it("rend TOUJOURS un bouton « Signaler » accessible", () => {
    render(<PageHeader title="Profil" screen="/profile" />);
    expect(screen.getByRole("button", { name: "Signaler" })).toBeInTheDocument();
  });

  it("transmet screen et scheduleId au bouton de signalement", () => {
    render(<PageHeader title="Planning A" screen="/planning" scheduleId="sched-42" />);
    const button = screen.getByRole("button", { name: "Signaler" });
    expect(button).toHaveAttribute("data-screen", "/planning");
    expect(button).toHaveAttribute("data-schedule-id", "sched-42");
  });

  it("rend les actions de page AVANT le bouton « Signaler »", () => {
    render(
      <PageHeader
        title="Matchs"
        screen="/matchs"
        actions={
          <button type="button">Nouveau match</button>
        }
      />,
    );
    const action = screen.getByRole("button", { name: "Nouveau match" });
    const signaler = screen.getByRole("button", { name: "Signaler" });
    // L'action doit précéder « Signaler » dans le flux du document.
    expect(action.compareDocumentPosition(signaler) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("rend le sous-titre quand il est fourni", () => {
    render(<PageHeader title="Profil" screen="/profile" subtitle="BCCL · admin" />);
    expect(screen.getByText("BCCL · admin")).toBeInTheDocument();
  });

  it("masque « Signaler » quand showFeedback est false (page publique déconnectée)", () => {
    render(<PageHeader title="Confidentialité" screen="/confidentialite" showFeedback={false} />);
    expect(screen.queryByRole("button", { name: "Signaler" })).not.toBeInTheDocument();
    // Le titre reste rendu — seule la porte de signalement est retirée.
    expect(screen.getByRole("heading", { level: 1, name: "Confidentialité" })).toBeInTheDocument();
  });
});
