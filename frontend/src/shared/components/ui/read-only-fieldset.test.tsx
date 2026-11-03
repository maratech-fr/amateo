import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { ReadOnlyFieldset } from "./read-only-fieldset";

/**
 * Promesse (PR 2) : une zone de SAISIE pure enveloppée dans `ReadOnlyFieldset readOnly` se lit
 * mais ne se modifie pas — `<fieldset disabled>` désactive tous ses contrôles d'un coup, sans
 * les griser (opacité pleine, curseur normal : c'est une lecture, pas un état d'erreur).
 */
describe("ReadOnlyFieldset", () => {
  it("désactive tous les contrôles en lecture seule, sans les griser", () => {
    render(
      <ReadOnlyFieldset readOnly>
        <label>Nom<input defaultValue="BC Test" /></label>
        <button type="button">Choisir</button>
      </ReadOnlyFieldset>,
    );
    const group = screen.getByRole("group");
    expect(group).toBeDisabled();
    expect(screen.getByRole("textbox", { name: "Nom" })).toBeDisabled();
    expect(screen.getByRole("textbox", { name: "Nom" })).toHaveValue("BC Test");
    expect(screen.getByRole("button", { name: "Choisir" })).toBeDisabled();
    expect(group.className).toContain("[&_:disabled]:opacity-100");
    expect(group.className).toContain("[&_:disabled]:cursor-default");
  });

  it("laisse la saisie ouverte hors lecture seule, et garde la classe de l'appelant", () => {
    render(
      <ReadOnlyFieldset readOnly={false} className="space-y-2">
        <label>Nom<input /></label>
      </ReadOnlyFieldset>,
    );
    expect(screen.getByRole("group")).not.toBeDisabled();
    expect(screen.getByRole("group").className).toContain("space-y-2");
    expect(screen.getByRole("textbox", { name: "Nom" })).toBeEnabled();
  });
});
