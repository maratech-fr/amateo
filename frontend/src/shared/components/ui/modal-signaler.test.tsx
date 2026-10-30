import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Modal } from "./modal";

/**
 * D14 (PR B « Signaler depuis une modale ») — la primitive `Modal` porte un drapeau « Signaler »
 * dans son EN-TÊTE, à GAUCHE de la croix. Il ouvre `FeedbackDialog` en variante contextuelle via un
 * contexte léger (provider `shared/feedback/`, monté dans `App`) — SANS cycle d'import (`FeedbackDialog`
 * importe `Modal`, donc `Modal` ne peut PAS importer `FeedbackDialog` : le contexte casse le cycle).
 * TANK écrit ROUGE (option A), NEO implémente. ROUGE aujourd'hui : l'en-tête ne rend que titre + croix
 * (`modal.tsx:82-87`), aucun « Signaler ».
 *
 * Note de nommage : « Signaler » est pour l'instant un libellé en dur de `FeedbackButton`/`FeedbackDialog`
 * (shared/feedback non encore migré vers `texts.ts` — FINDING au rapport : NEO doit migrer + inscrire à
 * `MIGRATED_FILES`). Le test vise le nom ACCESSIBLE du bouton.
 */
describe("Modal — drapeau « Signaler » dans l'en-tête (D14)", () => {
  it("rend un bouton « Signaler » dans l'en-tête, AVANT la croix « Fermer »", () => {
    render(
      <Modal label="Fenêtre test" title="Titre test" onClose={() => {}}>
        contenu
      </Modal>,
    );
    const dialog = screen.getByRole("dialog");

    const signaler = within(dialog).getByRole("button", { name: "Signaler" });
    const fermer = within(dialog).getByRole("button", { name: "Fermer" });
    expect(signaler).toBeInTheDocument();
    // « Signaler » précède « Fermer » dans l'ordre DOM de l'en-tête (à GAUCHE de la croix).
    // eslint-disable-next-line no-bitwise
    expect(signaler.compareDocumentPosition(fermer) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });
});
