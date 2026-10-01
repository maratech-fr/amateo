import { render, screen } from "@testing-library/react";
import { CalendarClock } from "lucide-react";
import { describe, expect, it } from "vitest";

import { NoticeBanner } from "./notice-banner";

/**
 * P4-265 — le bandeau d'information partagé (remplace `WarningPanel`). On garde ce qui doit rester
 * VRAI quel que soit l'appelant : un fond OPAQUE `bg-surface-*` par ton (jamais une teinte `/NN`),
 * l'icône décorative, le texte qui porte seul le sens, et `message`/`children` séparés (validité HTML).
 */
describe("NoticeBanner — le bandeau partagé opaque", () => {
  it("pose un fond OPAQUE bg-surface-<ton> par ton (jamais bg-<ton>/NN)", () => {
    const cases: { tone: "warning" | "accent" | "destructive" | "muted"; surface: string }[] = [
      { tone: "warning", surface: "bg-surface-warning" },
      { tone: "accent", surface: "bg-surface-accent" },
      { tone: "destructive", surface: "bg-surface-destructive" },
      { tone: "muted", surface: "bg-surface-muted" },
    ];
    for (const { tone, surface } of cases) {
      const { container } = render(<NoticeBanner tone={tone} message="x" />);
      const cls = container.firstElementChild?.className ?? "";
      expect(cls, `${tone} porte ${surface}`).toContain(surface);
      expect(cls, `${tone} n'empile aucune teinte semi-transparente`).not.toMatch(/bg-(?:warning|accent|destructive|muted)\/\d+/);
      expect(cls).toContain("text-foreground");
    }
  });

  it("par défaut le ton est warning", () => {
    const { container } = render(<NoticeBanner message="x" />);
    expect(container.firstElementChild?.className).toContain("bg-surface-warning");
  });

  it("l'icône est DÉCORATIVE : hors de l'arbre accessible, le texte porte seul le sens", () => {
    render(<NoticeBanner icon={<CalendarClock data-testid="ico" />} message="Le texte dit tout." />);
    expect(screen.getByTestId("ico").closest("[aria-hidden]")).not.toBeNull();
    expect(screen.getByText("Le texte dit tout.")).toBeInTheDocument();
  });

  it("une ACTION vit à côté du texte, jamais dedans : un <div> dans un <p> est invalide", () => {
    const { container } = render(
      <NoticeBanner icon={<CalendarClock />} message="Déjà planifié.">
        <div data-testid="action">
          <button type="button">Ouvrir</button>
        </div>
      </NoticeBanner>,
    );
    expect(container.querySelector("p [data-testid='action']")).toBeNull();
    expect(screen.getByTestId("action")).toBeInTheDocument();
  });

  it("pas de role par défaut (état stable), mais role est transmis quand fourni", () => {
    const { container: stable } = render(<NoticeBanner message="État stable." />);
    expect(stable.querySelector("[role]")).toBeNull();

    render(<NoticeBanner role="alert" tone="destructive" message="Erreur." />);
    expect(screen.getByRole("alert")).toHaveTextContent("Erreur.");

    render(<NoticeBanner role="status" tone="accent" message="Info." />);
    expect(screen.getByRole("status")).toHaveTextContent("Info.");
  });

  it("message est OPTIONNEL : sans lui, aucun <p> — seul children porte le contenu (listes, lignes à icône propre)", () => {
    const { container } = render(
      <NoticeBanner tone="destructive" role="alert">
        <ul>
          <li>Ligne 1</li>
          <li>Ligne 2</li>
        </ul>
      </NoticeBanner>,
    );
    // La BOÎTE reste la primitive (fond opaque, texte foreground) ; pas de paragraphe vide.
    expect(container.firstElementChild?.querySelector("p")).toBeNull();
    expect(container.firstElementChild?.className).toContain("bg-surface-destructive");
    expect(screen.getByRole("alert")).toHaveTextContent("Ligne 1");
  });

  it("role region/note + ariaLabel : un bandeau-repère permanent garde son landmark d'origine", () => {
    render(
      <NoticeBanner tone="warning" role="region" ariaLabel="Séances à replacer">
        <p>Deux séances.</p>
      </NoticeBanner>,
    );
    expect(screen.getByRole("region", { name: "Séances à replacer" })).toHaveTextContent("Deux séances.");

    render(<NoticeBanner tone="warning" role="note" message="Créneau inactif — jour fermé." />);
    expect(screen.getByRole("note")).toHaveTextContent("Créneau inactif");
  });
});
