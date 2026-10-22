import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { ConfirmDialog } from "./confirm-dialog";

const PHRASE = "modifier mon planning de saison";

describe("ConfirmDialog", () => {
  it("without confirmPhrase, the confirm button is enabled (additive — no behaviour change)", () => {
    render(<ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" onConfirm={vi.fn()} onCancel={vi.fn()} />);
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeEnabled();
    // Pas de champ de saisie sans confirmPhrase.
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
  });

  it("with confirmPhrase, the confirm button is disabled while nothing is typed", () => {
    render(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
    expect(screen.getByRole("textbox")).toBeInTheDocument();
  });

  it("stays disabled with a wrong phrase", async () => {
    const user = userEvent.setup();
    render(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    await user.type(screen.getByRole("textbox"), "autre chose");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
  });

  it("becomes enabled with the right phrase (trim + case-insensitive)", async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={onConfirm} onCancel={vi.fn()} />,
    );
    // Casse différente + espaces autour : la comparaison normalise.
    await user.type(screen.getByRole("textbox"), `  MODIFIER Mon Planning De Saison  `);
    const button = screen.getByRole("button", { name: "Confirmer" });
    expect(button).toBeEnabled();
    await user.click(button);
    expect(onConfirm).toHaveBeenCalledOnce();
  });

  it("resets the typed phrase when the dialog is closed and reopened", async () => {
    const user = userEvent.setup();
    const { rerender } = render(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    await user.type(screen.getByRole("textbox"), PHRASE);
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeEnabled();

    // Fermeture puis réouverture : la saisie repart vide, le bouton est de nouveau grisé.
    rerender(
      <ConfirmDialog open={false} title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    rerender(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    expect(screen.getByRole("textbox")).toHaveValue("");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
  });

  // ── PR4 #15 — action primaire optionnelle (3e bouton, ex. « Garder la période et quitter ») ──

  it("without primaryAction, renders STRICTLY the 2 buttons (cancel then confirm) — non-regression tous consommateurs", () => {
    // Promesse 2 : sans primaryAction, la primitive rend EXACTEMENT les 2 boutons d'avant, dans
    // l'ordre DOM d'avant (annuler ghost, puis confirmer). Garde que l'ajout de la 3e action
    // ne change rien pour les consommateurs existants.
    render(<ConfirmDialog open title="Sûr ?" onConfirm={vi.fn()} onCancel={vi.fn()} />);
    const buttons = within(screen.getByRole("dialog")).getAllByRole("button");
    expect(buttons.map((button) => button.textContent)).toEqual(["Annuler", "Confirmer"]);
  });

  it("with primaryAction, DOM/Tab order is primary → cancel → destructive", () => {
    // Promesse 2 : avec primaryAction, l'ordre DOM (= ordre de tabulation) est
    // primaire → annuler → destructif (la passe design PR4 fige cet ordre).
    render(
      <ConfirmDialog
        open
        title="Quitter ?"
        confirmLabel="Détruire"
        cancelLabel="Rester"
        primaryAction={{ label: "Garder et quitter", onClick: vi.fn() }}
        onConfirm={vi.fn()}
        onCancel={vi.fn()}
      />,
    );
    const buttons = within(screen.getByRole("dialog")).getAllByRole("button");
    expect(buttons.map((button) => button.textContent)).toEqual(["Garder et quitter", "Rester", "Détruire"]);
  });

  it("with primaryAction, clicking it fires ONLY its onClick (not confirm, not cancel)", async () => {
    const user = userEvent.setup();
    const onPrimary = vi.fn();
    const onConfirm = vi.fn();
    const onCancel = vi.fn();
    render(
      <ConfirmDialog
        open
        title="Quitter ?"
        confirmLabel="Détruire"
        cancelLabel="Rester"
        primaryAction={{ label: "Garder et quitter", onClick: onPrimary }}
        onConfirm={onConfirm}
        onCancel={onCancel}
      />,
    );
    await user.click(screen.getByRole("button", { name: "Garder et quitter" }));
    expect(onPrimary).toHaveBeenCalledOnce();
    expect(onConfirm).not.toHaveBeenCalled();
    expect(onCancel).not.toHaveBeenCalled();
  });

  it("with primaryAction, Escape cancels (never fires the primary action)", async () => {
    // Promesse 2 : Échap = annuler. Le focus initial reste le PANNEAU (pas le primaire), donc Échap
    // ne doit jamais déclencher l'action primaire — il annule, comme pour une modale à 2 boutons.
    const user = userEvent.setup();
    const onPrimary = vi.fn();
    const onCancel = vi.fn();
    render(
      <ConfirmDialog
        open
        title="Quitter ?"
        primaryAction={{ label: "Garder et quitter", onClick: onPrimary }}
        onConfirm={vi.fn()}
        onCancel={onCancel}
      />,
    );
    // Le bouton primaire EXISTE (sinon la primitive n'a pas la 3e action — ceci rougit avant Échap).
    expect(screen.getByRole("button", { name: "Garder et quitter" })).toBeInTheDocument();
    await user.keyboard("{Escape}");
    expect(onCancel).toHaveBeenCalledOnce();
    expect(onPrimary).not.toHaveBeenCalled();
  });

  it("aria-describedby points to the description element when a description exists", () => {
    // Promesse 2 : aria-describedby pointe la description quand elle existe (le lecteur d'écran
    // annonce le titre PUIS l'explication — ici « Vos réglages sont enregistrés »).
    render(<ConfirmDialog open title="Sûr ?" description="Vos réglages sont enregistrés." onConfirm={vi.fn()} onCancel={vi.fn()} />);
    const dialog = screen.getByRole("dialog");
    const describedBy = dialog.getAttribute("aria-describedby");
    expect(describedBy).toBeTruthy();
    expect(document.getElementById(describedBy as string)).toHaveTextContent("Vos réglages sont enregistrés.");
  });

  it("no aria-describedby when there is no description (never dangle a missing id)", () => {
    render(<ConfirmDialog open title="Sûr ?" onConfirm={vi.fn()} onCancel={vi.fn()} />);
    expect(screen.getByRole("dialog")).not.toHaveAttribute("aria-describedby");
  });
});
