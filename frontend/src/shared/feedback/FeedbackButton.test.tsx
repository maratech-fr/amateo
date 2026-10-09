import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { FeedbackButton } from "./FeedbackButton";

/**
 * D6 (lot 2) — le drapeau « Signaler » porte une icône au ton destructif (repère visuel), le
 * libellé restant gris : on attire l'œil sur le canal de signalement sans crier.
 */
describe("FeedbackButton (D6)", () => {
  it("rend le drapeau au ton destructif, libellé « Signaler » gris", () => {
    render(<FeedbackButton screen="wizard/teams" />);
    const button = screen.getByRole("button", { name: "Signaler" });
    // Le libellé reste discret (muted) ; seule l'icône passe en destructif.
    expect(button).toHaveClass("text-muted-foreground");
    expect(button.querySelector(".text-destructive")).not.toBeNull();
  });
});
