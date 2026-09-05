import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { GeocodeCandidate } from "../api";
import * as wizardApi from "../api";

vi.mock("../api", async (importActual) => {
  const actual = await importActual<typeof import("../api")>();
  return { ...actual, geocodeAddress: vi.fn() };
});

import { VenueGeocodeField } from "./VenueGeocodeField";

const CANDIDATES: GeocodeCandidate[] = [
  { label: "12 Rue du Sport, 69100 Villeurbanne", latitude: 45.766, longitude: 4.88, score: 0.92 },
  { label: "12 Rue du Sport, 01000 Bourg", latitude: 46.2, longitude: 5.22, score: 0.31 },
];

beforeEach(() => {
  vi.mocked(wizardApi.geocodeAddress).mockReset();
});

describe("VenueGeocodeField — géocodage d'une adresse", () => {
  it("saisir une adresse → candidats → choisir écrit address + lat/long (chaînes)", async () => {
    vi.mocked(wizardApi.geocodeAddress).mockResolvedValue(CANDIDATES);
    const onLocate = vi.fn();
    renderWithProviders(<VenueGeocodeField venue={{ id: "v1", address: null, latitude: null, longitude: null }} onLocate={onLocate} />);

    fireEvent.change(screen.getByRole("textbox", { name: "Adresse" }), { target: { value: "12 rue du sport" } });
    fireEvent.click(screen.getByRole("button", { name: "Localiser" }));

    await waitFor(() => expect(vi.mocked(wizardApi.geocodeAddress)).toHaveBeenCalledWith("12 rue du sport"));
    const first = await screen.findByText("12 Rue du Sport, 69100 Villeurbanne");
    // Le premier candidat porte « Recommandé », le second (score faible) « correspondance approximative ».
    const recommande = screen.getByText("Recommandé");
    expect(recommande).toBeInTheDocument();
    // P4-178 — repli AA : la pastille passe par StatusPill accent, le texte reste `text-foreground`.
    expect(recommande).not.toHaveClass("text-accent");
    expect(screen.getByText("correspondance approximative")).toBeInTheDocument();

    fireEvent.click(first);
    expect(onLocate).toHaveBeenCalledWith({ address: "12 Rue du Sport, 69100 Villeurbanne", latitude: "45.766", longitude: "4.88" });
  });

  it("aucun candidat : message lisible, aucune écriture", async () => {
    vi.mocked(wizardApi.geocodeAddress).mockResolvedValue([]);
    const onLocate = vi.fn();
    renderWithProviders(<VenueGeocodeField venue={{ id: "v1", address: null, latitude: null, longitude: null }} onLocate={onLocate} />);

    fireEvent.change(screen.getByRole("textbox", { name: "Adresse" }), { target: { value: "zzzzzz" } });
    fireEvent.click(screen.getByRole("button", { name: "Localiser" }));

    expect(await screen.findByText(/Aucune adresse trouvée/)).toBeInTheDocument();
    expect(onLocate).not.toHaveBeenCalled();
  });

  it("service indisponible : une alerte lisible, aucune écriture", async () => {
    vi.mocked(wizardApi.geocodeAddress).mockRejectedValue(new Error("502"));
    const onLocate = vi.fn();
    renderWithProviders(<VenueGeocodeField venue={{ id: "v1", address: null, latitude: null, longitude: null }} onLocate={onLocate} />);

    fireEvent.change(screen.getByRole("textbox", { name: "Adresse" }), { target: { value: "12 rue du sport" } });
    fireEvent.click(screen.getByRole("button", { name: "Localiser" }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(onLocate).not.toHaveBeenCalled();
  });

  it("le bouton reste inerte sous 3 caractères (pas d'appel BAN pour rien)", () => {
    renderWithProviders(<VenueGeocodeField venue={{ id: "v1", address: null, latitude: null, longitude: null }} onLocate={vi.fn()} />);
    fireEvent.change(screen.getByRole("textbox", { name: "Adresse" }), { target: { value: "12" } });
    expect(screen.getByRole("button", { name: "Localiser" })).toBeDisabled();
  });

  it("gymnase FFBB déjà géolocalisé : « Localisé », aucun géocodage lancé, coordonnées intactes", () => {
    const onLocate = vi.fn();
    renderWithProviders(<VenueGeocodeField venue={{ id: "v1", address: null, latitude: "45.75", longitude: "4.85" }} onLocate={onLocate} />);

    expect(screen.getByText("Localisé")).toBeInTheDocument();
    // Pas de champ ouvert par défaut → rien n'est réécrit ; le géocodage n'est jamais appelé au montage.
    expect(screen.queryByRole("textbox", { name: "Adresse" })).toBeNull();
    expect(vi.mocked(wizardApi.geocodeAddress)).not.toHaveBeenCalled();
    expect(onLocate).not.toHaveBeenCalled();
  });
});
