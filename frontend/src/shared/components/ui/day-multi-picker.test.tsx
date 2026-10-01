import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";

import { DayMultiPicker } from "./day-multi-picker";

/**
 * Le sélecteur multi-jours UNIQUE (série « uniformité des écrans », PR 6/7). Contrat APG
 * toggle button : des boutons bascule `aria-pressed` dans un `<fieldset>`/`<legend>`, libellé
 * COURT visible (« Lun ») + nom accessible COMPLET (« lundi »), clavier Espace/Entrée.
 */
describe("DayMultiPicker", () => {
  it("rend sept boutons bascule, libellé court visible + nom accessible long, pressé selon value", () => {
    render(<DayMultiPicker value={[6]} onChange={() => {}} legend="Jours à éviter" />);

    // Nom accessible = le jour COMPLET (« lundi ») ; texte visible = la forme courte (« Lun »).
    const monday = screen.getByRole("button", { name: "lundi" });
    expect(monday).toHaveTextContent("Lun");
    expect(monday).toHaveAttribute("aria-pressed", "false");
    expect(screen.getByRole("button", { name: "samedi" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getAllByRole("button")).toHaveLength(7);
  });

  it("nomme le groupe par sa legend (fieldset)", () => {
    render(<DayMultiPicker value={[]} onChange={() => {}} legend="Jours d'indisponibilité" />);

    const group = screen.getByRole("group", { name: "Jours d'indisponibilité" });
    expect(within(group).getByRole("button", { name: "mercredi" })).toBeInTheDocument();
  });

  it("un clic bascule le jour et rend la sélection TRIÉE croissante", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<DayMultiPicker value={[6]} onChange={onChange} legend="Jours" />);

    await user.click(screen.getByRole("button", { name: "mardi" }));

    // Ajout de mardi (2) à samedi (6) → [2, 6], jamais [6, 2].
    expect(onChange).toHaveBeenCalledWith([2, 6]);
  });

  it("un clic sur un jour déjà pressé le retire", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<DayMultiPicker value={[2, 6]} onChange={onChange} legend="Jours" />);

    await user.click(screen.getByRole("button", { name: "samedi" }));

    expect(onChange).toHaveBeenCalledWith([2]);
  });

  it("le clavier (Espace, Entrée) bascule comme un bouton APG", async () => {
    const user = userEvent.setup();

    function Harness() {
      const [days, setDays] = useState<number[]>([]);
      return (
        <>
          <DayMultiPicker value={days} onChange={setDays} legend="Jours" />
          <output>{days.join(",")}</output>
        </>
      );
    }
    render(<Harness />);

    const thursday = screen.getByRole("button", { name: "jeudi" });
    thursday.focus();
    await user.keyboard(" ");
    expect(screen.getByRole("button", { name: "jeudi" })).toHaveAttribute("aria-pressed", "true");

    const friday = screen.getByRole("button", { name: "vendredi" });
    friday.focus();
    await user.keyboard("{Enter}");
    expect(screen.getByRole("button", { name: "vendredi" })).toHaveAttribute("aria-pressed", "true");
  });

  it("legendVisible rend la legend à l'écran, sinon elle reste accessible seulement", () => {
    const { rerender } = render(<DayMultiPicker value={[]} onChange={() => {}} legend="Jours indisponibles" />);
    // sr-only : nom accessible présent, mais non visible.
    expect(screen.getByText("Jours indisponibles")).toHaveClass("sr-only");

    rerender(<DayMultiPicker value={[]} onChange={() => {}} legend="Jours indisponibles" legendVisible />);
    expect(screen.getByText("Jours indisponibles")).not.toHaveClass("sr-only");
  });

  it("n'offre que les jours demandés via `days`", () => {
    render(<DayMultiPicker value={[]} onChange={() => {}} legend="Jours" days={[6, 7]} />);

    expect(screen.getAllByRole("button")).toHaveLength(2);
    expect(screen.getByRole("button", { name: "samedi" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "dimanche" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "lundi" })).not.toBeInTheDocument();
  });

  it("disabled coupe l'interaction (fieldset natif)", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<DayMultiPicker value={[]} onChange={onChange} legend="Jours" disabled />);

    const monday = screen.getByRole("button", { name: "lundi" });
    expect(monday).toBeDisabled();
    await user.click(monday);
    expect(onChange).not.toHaveBeenCalled();
  });

  it("le ton destructive habille l'état pressé d'une surface opaque (polarité « bloqué »)", () => {
    render(<DayMultiPicker value={[6]} onChange={() => {}} legend="Jours indisponibles" tone="destructive" />);

    // Surface OPAQUE (jamais une teinte /NN au repos) + texte foreground (AA sur teinte).
    const saturday = screen.getByRole("button", { name: "samedi" });
    expect(saturday.className).toContain("bg-surface-destructive");
    expect(saturday.className).toContain("text-foreground");
  });
});
