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
