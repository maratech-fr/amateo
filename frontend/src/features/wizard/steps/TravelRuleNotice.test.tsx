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
const setting = (intensity: "PREFERRED" | "MANDATORY"): VenueTravelRuleSetting => ({ ruleKey: "travelTime", intensity, isDefault: "PREFERRED" === intensity });

beforeEach(() => {
  matrixState.data = [];
  settingState.data = undefined;
  readonlyState.value = false;
  updateMutate.mockClear();
});

describe("TravelRuleNotice — le levier Préféré/Obligatoire", () => {
  it("ABSENTE tant qu'aucune ligne de matrice n'existe", () => {
    matrixState.data = [];
    renderWithProviders(<TravelRuleNotice />);
    expect(screen.queryByText("Trajet entre gymnases")).toBeNull();
    // Pas de matrice ⇒ pas de select.
    expect(screen.queryByLabelText("Niveau de la règle de trajet entre gymnases")).toBeNull();
  });

  it("PRÉSENTE dès qu'une ligne existe — avec un select Préféré/Obligatoire (défaut Préféré)", () => {
    matrixState.data = [row];
    settingState.data = setting("PREFERRED");
    renderWithProviders(<TravelRuleNotice />);
    expect(screen.getByText("Trajet entre gymnases")).toBeInTheDocument();
    const select = screen.getByLabelText<HTMLSelectElement>("Niveau de la règle de trajet entre gymnases");
    expect(select.value).toBe("PREFERRED");
    // Patron passerelles : la copie DIT le risque d'Obligatoire, TOUJOURS visible (pour être lu
    // AVANT de basculer), même quand on est en Préféré.
    expect(screen.getByText(/au risque de rendre le planning infaisable si les enchaînements sont trop serrés/)).toBeInTheDocument();
  });

  it("la pastille « Actif » n'a pas `text-accent` sur son texte (repli AA, StatusPill accent, P4-178)", () => {
    matrixState.data = [row];
    settingState.data = setting("PREFERRED");
    renderWithProviders(<TravelRuleNotice />);
    const actif = screen.getByText("Actif");
    expect(actif).toBeInTheDocument();
    expect(actif).not.toHaveClass("text-accent");
  });

  it("reflète l'intensité stockée MANDATORY", () => {
    matrixState.data = [row];
    settingState.data = setting("MANDATORY");
    renderWithProviders(<TravelRuleNotice />);
    const select = screen.getByLabelText<HTMLSelectElement>("Niveau de la règle de trajet entre gymnases");
    expect(select.value).toBe("MANDATORY");
  });

  it("changer le select ÉCRIT (mute la prod, pas le mock)", () => {
    matrixState.data = [row];
    settingState.data = setting("PREFERRED");
    renderWithProviders(<TravelRuleNotice />);
    const select = screen.getByLabelText("Niveau de la règle de trajet entre gymnases");
    fireEvent.change(select, { target: { value: "MANDATORY" } });
    expect(updateMutate).toHaveBeenCalledWith("MANDATORY");
  });

  it("saison archivée : pas de select, l'intensité s'affiche en lecture seule", () => {
    matrixState.data = [row];
    settingState.data = setting("MANDATORY");
    readonlyState.value = true;
    renderWithProviders(<TravelRuleNotice />);
    expect(screen.queryByLabelText("Niveau de la règle de trajet entre gymnases")).toBeNull();
    // La valeur en lecture seule est un <span> (le mot « Obligatoire » apparaît aussi dans la
    // copie, en <em> — on cible donc le span de la valeur).
    expect(screen.getByText("Obligatoire", { selector: "span" })).toBeInTheDocument();
  });
});
