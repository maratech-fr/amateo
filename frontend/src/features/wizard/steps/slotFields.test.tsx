import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { DurationSelect, GROUP_LABEL_MAX, GroupLabelField } from "./slotFields";

/** Contrôleur minimal : `GroupLabelField` est contrôlé, on lui prête un état pour observer la saisie. */
function Harness({ capacity }: { capacity: number }) {
  const [value, setValue] = useState("");
  return <GroupLabelField capacity={capacity} value={value} onChange={setValue} />;
}

describe("GroupLabelField (P2-17 D7)", () => {
  it("n'apparaît PAS sous une capacité de 1 (aligné sur le refus 422 du backend)", () => {
    render(<GroupLabelField capacity={1} value="" onChange={vi.fn()} />);
    expect(screen.queryByRole("textbox", { name: "Libellé de groupe" })).toBeNull();
  });

  it("apparaît dès 2 équipes, enregistre la saisie et plafonne à 40 caractères", async () => {
    const { container } = render(<Harness capacity={2} />);
    const input = screen.getByRole("textbox", { name: "Libellé de groupe" });
    expect(input).toHaveAttribute("maxlength", String(GROUP_LABEL_MAX));
    await userEvent.type(input, "CEC3");
    expect(input).toHaveValue("CEC3");
    // Nommé explicitement (aria-label) — pas par le seul placeholder (AGENTS.md §axe).
    expect(await axe(container)).toHaveNoViolations();
  });
});

/**
 * D8 (lot 2) — l'option « Autre… » en fin de select ouvre une saisie libre par pas de 15 min
 * (min 15) ; quand l'heure de début est connue, la fin ne peut pas dépasser minuit. Un bouton
 * de confirmation désactivé DIT son motif (`disabledReason`, A11Y-30).
 */
describe("DurationSelect — durée « Autre… » (D8)", () => {
  it("propose « Autre… » en fin de liste et révèle une saisie quand on la choisit", async () => {
    const onChange = vi.fn();
    render(<DurationSelect value={60} onChange={onChange} aria-label="Durée" />);
    const select = screen.getByRole("combobox", { name: "Durée" });
    expect(within(select).getByRole("option", { name: "Autre…" })).toBeInTheDocument();
    await userEvent.selectOptions(select, "__other__");
    expect(screen.getByRole("spinbutton", { name: /minutes/i })).toBeInTheDocument();
  });

  it("confirme une durée valide multiple de 15 et la remonte en minutes", async () => {
    const onChange = vi.fn();
    render(<DurationSelect value={60} onChange={onChange} aria-label="Durée" />);
    await userEvent.selectOptions(screen.getByRole("combobox", { name: "Durée" }), "__other__");
    const input = screen.getByRole("spinbutton", { name: /minutes/i });
    await userEvent.clear(input);
    await userEvent.type(input, "165");
    await userEvent.click(screen.getByRole("button", { name: /^OK$/i }));
    expect(onChange).toHaveBeenCalledWith(165);
  });

  it("refuse (bouton désactivé, motif annoncé) une durée sous 15 min ou non multiple de 15", async () => {
    render(<DurationSelect value={60} onChange={vi.fn()} aria-label="Durée" />);
    await userEvent.selectOptions(screen.getByRole("combobox", { name: "Durée" }), "__other__");
    const input = screen.getByRole("spinbutton", { name: /minutes/i });
    await userEvent.clear(input);
    await userEvent.type(input, "10");
    const ok = screen.getByRole("button", { name: /^OK$/i });
    expect(ok).toHaveAttribute("aria-disabled", "true");
    expect(ok).toHaveAttribute("title", expect.stringContaining("15 minutes"));
  });

  it("refuse une durée qui ferait dépasser minuit quand le début est connu", async () => {
    render(<DurationSelect value={60} startTime="23:00" onChange={vi.fn()} aria-label="Durée" />);
    await userEvent.selectOptions(screen.getByRole("combobox", { name: "Durée" }), "__other__");
    const input = screen.getByRole("spinbutton", { name: /minutes/i });
    await userEvent.clear(input);
    await userEvent.type(input, "120");
    const ok = screen.getByRole("button", { name: /^OK$/i });
    expect(ok).toHaveAttribute("aria-disabled", "true");
    expect(ok).toHaveAttribute("title", expect.stringContaining("minuit"));
  });
});
