import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { FilePicker } from "./file-picker";

/**
 * #3 (retours Rillieux) — `FilePicker` est la maison UNIQUE du dépôt de fichier,
 * extraite du patron de `ClubPage.tsx:152` (un label-bouton bordé, curseur main,
 * input natif caché, nom accessible conservé) et consommée par `TeamsImportModal`
 * et `ImportFbiDialog`. La zone « choisir un fichier » des modales était peu
 * visible, collée au texte et sans curseur main : la primitive la rend lisible
 * SANS changer la couture a11y (le nom accessible « Fichier FBI (.xlsx) » que
 * `journey.spec.ts:122` cible via `getByLabel` est préservé).
 *
 * Contrat de la primitive (ce test le fixe pour l'implémentation) :
 *   <FilePicker label="Fichier FBI (.xlsx)" accept=".xlsx" onFileChange={fn} disabled? />
 *   - rend un `<input type="file">` au nom accessible = `label` (via `aria-label`),
 *     VISUELLEMENT caché (classe `hidden`), dans un `<label>` bordé `cursor-pointer` ;
 *   - au choix d'un fichier, appelle `onFileChange(file)` ET affiche son nom ;
 *   - `disabled` désarme l'input (double-soumission).
 *
 * Sélecteurs : rôle + nom accessible quand c'est possible ; `file-picker.tsx` n'est
 * pas un écran MIGRÉ (pas de `texts.ts`), les libellés passés en props sont donc
 * assertés tels quels (ici le `label` fourni par le test, jamais une phrase recopiée
 * d'une source).
 */
describe("FilePicker", () => {
  const file = (): File =>
    new File(["xlsx-bytes"], "equipes.xlsx", {
      type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    });

  it("expose son nom accessible et relaie le fichier choisi à onFileChange", async () => {
    const onFileChange = vi.fn();
    render(<FilePicker label="Fichier FBI (.xlsx)" accept=".xlsx" onFileChange={onFileChange} />);

    const input = screen.getByLabelText("Fichier FBI (.xlsx)");
    expect(input).toHaveAttribute("type", "file");

    await userEvent.upload(input, file());

    expect(onFileChange).toHaveBeenCalledTimes(1);
    const picked = onFileChange.mock.calls[0]?.[0] as File;
    expect(picked).toBeInstanceOf(File);
    expect(picked.name).toBe("equipes.xlsx");
  });

  it("affiche le nom du fichier choisi (découvrabilité — reproche Rillieux #3)", async () => {
    render(<FilePicker label="Fichier FBI (.xlsx)" accept=".xlsx" onFileChange={vi.fn()} />);

    await userEvent.upload(screen.getByLabelText("Fichier FBI (.xlsx)"), file());

    expect(await screen.findByText(/equipes\.xlsx/)).toBeInTheDocument();
  });

  it("cache l'input natif et porte le curseur main sur le bouton-label", () => {
    render(<FilePicker label="Fichier FBI (.xlsx)" accept=".xlsx" onFileChange={vi.fn()} />);

    const input = screen.getByLabelText("Fichier FBI (.xlsx)");
    // L'input natif reste dans le DOM (nom accessible) mais est masqué : c'est le
    // label bordé qui est la cible visible — patron ClubPage.
    expect(input.className).toContain("hidden");

    const labelButton = input.closest("label");
    expect(labelButton, "l'input natif vit dans un <label> cliquable").not.toBeNull();
    expect(labelButton?.className).toContain("cursor-pointer");
  });

  it("désarme l'input quand disabled (anti double-soumission pendant un appel)", () => {
    render(<FilePicker label="Fichier FBI (.xlsx)" accept=".xlsx" onFileChange={vi.fn()} disabled />);

    expect(screen.getByLabelText("Fichier FBI (.xlsx)")).toBeDisabled();
  });
});
