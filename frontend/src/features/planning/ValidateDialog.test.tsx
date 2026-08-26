import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { type OrphanImpact, ValidateDialog } from "./ValidateDialog";

const readyImpact = (over: Partial<OrphanImpact> = {}): OrphanImpact => ({
  orphanCount: 0,
  declaredCount: 0,
  loading: false,
  failed: false,
  onRetry: vi.fn(),
  ...over,
});

function renderDialog(orphan: OrphanImpact, over: { busy?: boolean; onConfirm?: () => void } = {}) {
  const onConfirm = over.onConfirm ?? vi.fn();
  render(<ValidateDialog hasAlerts={false} siblingCount={0} busy={over.busy ?? false} orphan={orphan} onConfirm={onConfirm} onCancel={vi.fn()} />);
  return { onConfirm };
}

describe("ValidateDialog — l'annonce « salle perdue » (P2-52)", () => {
  it("N=0 : AUCUNE annonce de salle perdue, et Valider est actif — le geste part comme aujourd'hui", () => {
    renderDialog(readyImpact({ orphanCount: 0 }));
    // Falsification : pas un mot de perte de salle quand rien n'est concerné (aucun bruit préventif).
    expect(screen.queryByText(/perdr(a|ont)/)).toBeNull();
    const valider = screen.getByRole("button", { name: "Valider" });
    expect(valider).toBeEnabled();
  });

  it("N>0 : l'annonce apparaît, avec le nombre et le sous-ensemble déjà déclaré", () => {
    renderDialog(readyImpact({ orphanCount: 3, declaredCount: 2 }));
    expect(screen.getByText(/3 matchs perdront leur salle/)).toBeInTheDocument();
    expect(screen.getByText(/dont 2 déjà déclarés à la fédération/)).toBeInTheDocument();
    // La phrase PARTAGÉE de re-soumission (composant `DeclaredFixturesNotice`) — unique.
    expect(screen.getByText(/matchs déjà déclarés à la fédération devront être re-soumis/)).toBeInTheDocument();
    // Récupérable : le ton reste « à placer », horaire conservé.
    expect(screen.getByText(/repasseront « à placer », leur horaire conservé/)).toBeInTheDocument();
  });

  it("N>0 sans match déclaré : l'annonce n'ajoute jamais « dont 0 »", () => {
    renderDialog(readyImpact({ orphanCount: 1, declaredCount: 0 }));
    expect(screen.getByText(/1 match perdra sa salle\./)).toBeInTheDocument();
    expect(screen.queryByText(/déjà déclaré/)).toBeNull();
  });

  it("impact EN VOL : Valider est désactivé et la vérification est annoncée", () => {
    renderDialog(readyImpact({ loading: true }));
    expect(screen.getByRole("button", { name: "Valider" })).toBeDisabled();
    expect(screen.getByText(/Vérification des matchs concernés…/)).toBeInTheDocument();
  });

  it("impact EN ÉCHEC : Valider reste désactivé, l'échec est dit, et Réessayer relance", async () => {
    const user = userEvent.setup();
    const onRetry = vi.fn();
    renderDialog(readyImpact({ failed: true, onRetry }));
    expect(screen.getByRole("button", { name: "Valider" })).toBeDisabled();
    expect(screen.getByText(/Vérification impossible pour l'instant/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Réessayer" }));
    expect(onRetry).toHaveBeenCalledOnce();
  });

  it("ne laisse JAMAIS confirmer sur un impact inconnu — le clic Valider n'appelle rien en vol", async () => {
    const user = userEvent.setup();
    const { onConfirm } = renderDialog(readyImpact({ loading: true }));
    await user.click(screen.getByRole("button", { name: "Valider" }));
    expect(onConfirm).not.toHaveBeenCalled();
  });
});
