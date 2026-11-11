import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { Modal } from "./modal";
import { ModalReportProviderContext } from "./modalReportContext";

/**
 * D14 (PR B « Signaler depuis une modale ») — la primitive `Modal` porte un drapeau « Signaler »
 * dans son EN-TÊTE, à GAUCHE de la croix, QUAND ET SEULEMENT QUAND un canal de signalement existe :
 * sous un provider `shared/feedback/` (monté dans l'app club), `useModalReport().openReport` n'est pas
 * null et le drapeau est rendu ; HORS provider (console superadmin, test de primitive) `openReport` est
 * null et AUCUN drapeau n'est rendu — plus un drapeau INERTE que l'admin ne pourrait pas actionner.
 * Le contexte neutre casse le cycle d'import (`FeedbackDialog` importe `Modal`, donc `Modal` ne peut PAS
 * importer `FeedbackDialog`).
 *
 * NOUVEAU (PR B) — le second cas (« hors provider → absent ») est ROUGE tant que NEO ne conditionne pas
 * le rendu du drapeau à `openReport !== null` (aujourd'hui `modal.tsx:102` ne teste que `showReportFlag`,
 * si bien que l'admin voit un drapeau INERTE — le défaut à fermer). Option A : TANK écrit ROUGE.
 *
 * Note de nommage : « Signaler » est un libellé en dur de `FeedbackButton`/`FeedbackDialog`
 * (shared/feedback non encore migré vers `texts.ts`). Le test vise le nom ACCESSIBLE du bouton.
 */
describe("Modal — drapeau « Signaler » dans l'en-tête (D14)", () => {
  it("rend un bouton « Signaler » dans l'en-tête, AVANT la croix « Fermer », SOUS un provider", () => {
    render(
      <ModalReportProviderContext value={{ openReport: vi.fn() }}>
        <Modal label="Fenêtre test" title="Titre test" onClose={() => {}}>
          contenu
        </Modal>
      </ModalReportProviderContext>,
    );
    const dialog = screen.getByRole("dialog");

    const signaler = within(dialog).getByRole("button", { name: "Signaler" });
    const fermer = within(dialog).getByRole("button", { name: "Fermer" });
    expect(signaler).toBeInTheDocument();
    // « Signaler » précède « Fermer » dans l'ordre DOM de l'en-tête (à GAUCHE de la croix).
    expect(signaler.compareDocumentPosition(fermer) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("ne rend AUCUN bouton « Signaler » hors d'un provider (openReport null)", () => {
    render(
      <Modal label="Fenêtre test" title="Titre test" onClose={() => {}}>
        contenu
      </Modal>,
    );
    const dialog = screen.getByRole("dialog");

    // Sans canal, pas de drapeau : il ne doit pas être rendu inerte.
    expect(within(dialog).queryByRole("button", { name: "Signaler" })).not.toBeInTheDocument();
    // La croix « Fermer » reste — seul le drapeau dépend du canal.
    expect(within(dialog).getByRole("button", { name: "Fermer" })).toBeInTheDocument();
  });
});

/**
 * Finitions d'interface (essai d'onboarding, 30/10, point 6) : ROUGE option A. Les deux pictos de l'en-tête
 * disent ce qu'ils font au survol (`title`) et réagissent au survol. Le rendu réel du survol se juge
 * sur capture : jsdom n'applique aucun style, ce test garde seulement la présence de la classe.
 */
describe("Modal — pictos de l'en-tête : infobulle et retour au survol", () => {
  function renderHeader(): HTMLElement {
    render(
      <ModalReportProviderContext value={{ openReport: vi.fn() }}>
        <Modal label="Fenêtre test" title="Titre test" onClose={() => {}}>
          contenu
        </Modal>
      </ModalReportProviderContext>,
    );
    return screen.getByRole("dialog");
  }

  it.each(["Signaler", "Fermer"])("le bouton « %s » porte une infobulle et garde son nom accessible", (name) => {
    const button = within(renderHeader()).getByRole("button", { name });

    expect(button.getAttribute("title") ?? "").not.toBe("");
  });

  // A11Y-33 : le bouton devient `Button variant="ghost"` dont le survol s'écrit `hover:enabled:bg-muted`
  // (survol borné aux boutons actifs, `button-variants.ts`) — la promesse « change de fond au survol » est la même.
  it.each(["Signaler", "Fermer"])("le bouton « %s » change de fond au survol", (name) => {
    const button = within(renderHeader()).getByRole("button", { name });

    expect(button.className).toMatch(/hover:(enabled:)?bg-/);
  });

  /**
   * A11Y-33 (petites lignes) — les deux pictos de l'en-tête sont des cibles de 36 px, la norme maison
   * du bouton-icône (`Button size="icon-sm"` = `size-9`), plus le `p-1` autour d'une icône de 16 px
   * (24 px). Rendu réel de l'en-tête de la primitive ; les cinq autres sites (calendrier du mois,
   * détail de séance, placement de match, diagnostics, verrous) sont gardés statiquement par
   * `src/test/iconTargetSizeGuard.test.ts`, qui couvre aussi ce fichier (angle nommé : le garde lit la
   * source, ce test lit le DOM rendu de la primitive partagée par toutes les modales).
   */
  it.each(["Signaler", "Fermer"])("le bouton « %s » est une cible de 36 px (size-9)", (name) => {
    const button = within(renderHeader()).getByRole("button", { name });

    expect(button.className.split(/\s+/)).toContain("size-9");
    expect(button.className.split(/\s+/)).not.toContain("p-1");
  });
});
