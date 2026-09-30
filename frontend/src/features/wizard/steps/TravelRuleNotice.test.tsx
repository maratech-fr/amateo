import { fireEvent, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { VenueTravelRuleSetting, VenueTravelTime } from "../api";

const matrixState: { data: VenueTravelTime[] } = { data: [] };
const settingState: { data: VenueTravelRuleSetting | undefined } = { data: undefined };
const readonlyState: { value: boolean } = { value: false };
const updateMutate = vi.fn();

vi.mock("../queries", () => ({
  useVenueTravelTimes: () => ({ data: matrixState.data }),
  // `enabled` est ignoré ici : le test pilote directement `settingState`.
  useTravelRuleSetting: () => ({ data: settingState.data }),
  useUpdateTravelRuleSetting: () => ({ mutate: updateMutate, isPending: false }),
}));

vi.mock("@/shared/session/queries", () => ({
  useWorkingSeason: () => ({ isReadonly: readonlyState.value }),
}));

import { TravelRuleNotice } from "./ImplicitRulesPanel";

const row: VenueTravelTime = { id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, walkingMinutes: null, drivingSource: "AUTO", walkingSource: null };
const setting = (intensity: "OFF" | "PREFERRED" | "MANDATORY", tolerance = 20, def = 20): VenueTravelRuleSetting => ({
  ruleKey: "travelTime",
  intensity,
  toleranceMinutes: tolerance,
  defaultMinutes: def,
  isDefault: "PREFERRED" === intensity && 20 === tolerance && 20 === def,
});

beforeEach(() => {
  matrixState.data = [];
  settingState.data = undefined;
  readonlyState.value = false;
  updateMutate.mockClear();
});

describe("TravelRuleNotice — cran + battement toléré + temps par défaut", () => {
  it("ABSENTE tant qu'aucune ligne de matrice n'existe", () => {
    matrixState.data = [];
    renderWithProviders(<TravelRuleNotice />);
    expect(screen.queryByText("Trajet entre gymnases")).toBeNull();
    expect(screen.queryByLabelText("Niveau de la règle de trajet entre gymnases")).toBeNull();
  });

  it("PRÉSENTE dès qu'une ligne existe — trois crans, pastille « Active », champs à 20/20", () => {
    matrixState.data = [row];
    settingState.data = setting("PREFERRED");
    renderWithProviders(<TravelRuleNotice />);
    expect(screen.getByText("Trajet entre gymnases")).toBeInTheDocument();
    expect(screen.getByLabelText("Inactive — trajet entre gymnases")).toHaveAttribute("aria-pressed", "false");
    expect(screen.getByLabelText("Préféré — trajet entre gymnases")).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByLabelText("Obligatoire — trajet entre gymnases")).toHaveAttribute("aria-pressed", "false");
    expect(screen.getByText("Active")).toBeInTheDocument();
    expect(screen.getByLabelText<HTMLInputElement>("Battement toléré, en minutes").value).toBe("20");
    expect(screen.getByLabelText<HTMLInputElement>("Temps par défaut pour un couple de gymnases sans temps, en minutes").value).toBe("20");
  });

  it("la pastille « Active » n'a pas `text-accent` sur son texte (repli AA, StatusPill accent, P4-178)", () => {
    matrixState.data = [row];
    settingState.data = setting("PREFERRED");
    renderWithProviders(<TravelRuleNotice />);
    const active = screen.getByText("Active");
    expect(active).not.toHaveClass("text-accent");
  });

  it("reflète l'intensité stockée MANDATORY (cran pressé)", () => {
    matrixState.data = [row];
    settingState.data = setting("MANDATORY");
    renderWithProviders(<TravelRuleNotice />);
    expect(screen.getByLabelText("Obligatoire — trajet entre gymnases")).toHaveAttribute("aria-pressed", "true");
  });

  it("OFF : pastille « Inactive » (pas « Active »), cran pressé, champs minutes désactivés", () => {
    matrixState.data = [row];
    settingState.data = setting("OFF");
    renderWithProviders(<TravelRuleNotice />);
    // Le mot « Inactive » apparaît aussi sur le cran et dans l'aide : on prouve l'état OFF par
    // l'ABSENCE de la pastille « Active » + le cran pressé.
    expect(screen.queryByText("Active")).toBeNull();
    expect(screen.getByLabelText("Inactive — trajet entre gymnases")).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByLabelText<HTMLInputElement>("Battement toléré, en minutes").disabled).toBe(true);
    expect(screen.getByLabelText<HTMLInputElement>("Temps par défaut pour un couple de gymnases sans temps, en minutes").disabled).toBe(true);
  });

  it("cliquer un cran ÉCRIT le payload complet (mute la prod, pas le mock)", () => {
    matrixState.data = [row];
    settingState.data = setting("PREFERRED", 20, 20);
    renderWithProviders(<TravelRuleNotice />);
    fireEvent.click(screen.getByLabelText("Obligatoire — trajet entre gymnases"));
    expect(updateMutate).toHaveBeenCalledWith({ intensity: "MANDATORY", toleranceMinutes: 20, defaultMinutes: 20 });
  });

  it("passer Inactive écrit OFF", () => {
    matrixState.data = [row];
    settingState.data = setting("PREFERRED");
    renderWithProviders(<TravelRuleNotice />);
    fireEvent.click(screen.getByLabelText("Inactive — trajet entre gymnases"));
    expect(updateMutate).toHaveBeenCalledWith({ intensity: "OFF", toleranceMinutes: 20, defaultMinutes: 20 });
  });

  it("éditer le battement toléré et quitter le champ ÉCRIT la nouvelle valeur (bornée)", () => {
    matrixState.data = [row];
    settingState.data = setting("PREFERRED", 20, 20);
    renderWithProviders(<TravelRuleNotice />);
    const field = screen.getByLabelText<HTMLInputElement>("Battement toléré, en minutes");
    fireEvent.change(field, { target: { value: "10" } });
    fireEvent.blur(field);
    expect(updateMutate).toHaveBeenCalledWith({ intensity: "PREFERRED", toleranceMinutes: 10, defaultMinutes: 20 });
  });

  it("une valeur hors bornes est ramenée dans les bornes avant l'écriture (100 → 60)", () => {
    matrixState.data = [row];
    settingState.data = setting("PREFERRED", 20, 20);
    renderWithProviders(<TravelRuleNotice />);
    const field = screen.getByLabelText<HTMLInputElement>("Battement toléré, en minutes");
    fireEvent.change(field, { target: { value: "100" } });
    fireEvent.blur(field);
    expect(updateMutate).toHaveBeenCalledWith({ intensity: "PREFERRED", toleranceMinutes: 60, defaultMinutes: 20 });
  });

  it("saison archivée : les crans sont désactivés", () => {
    matrixState.data = [row];
    settingState.data = setting("MANDATORY");
    readonlyState.value = true;
    renderWithProviders(<TravelRuleNotice />);
    expect(screen.getByLabelText<HTMLButtonElement>("Préféré — trajet entre gymnases").disabled).toBe(true);
  });
});
