import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { GeocodeCandidate } from "@/shared/api/geocode";
import * as geocodeApi from "@/shared/api/geocode";
import { AddressGeocodeField } from "./address-geocode-field";

// `geocodeAddress`/`reverseGeocode` sont mockés À LA SOURCE (les hooks les importent de là).
vi.mock("@/shared/api/geocode", async (importActual) => {
  const actual = await importActual<typeof import("@/shared/api/geocode")>();
  return { ...actual, geocodeAddress: vi.fn(), reverseGeocode: vi.fn() };
});

const CANDIDATES: GeocodeCandidate[] = [
  { label: "12 Rue du Sport, 69100 Villeurbanne", latitude: 45.766, longitude: 4.88, score: 0.92, type: "housenumber" },
  { label: "12 Rue du Sport, 01000 Bourg", latitude: 46.2, longitude: 5.22, score: 0.31, type: "housenumber" },
];

// Un candidat au BON score mais de précision « rue » (pas un numéro) : la position est approximative.
const STREET_CANDIDATE: GeocodeCandidate[] = [{ label: "Cours Émile Zola, 69100 Villeurbanne", latitude: 45.77, longitude: 4.87, score: 0.9, type: "street" }];

const baseProps = { placeholder: "Adresse", label: "Adresse", statusWord: "Localisé" as const };

beforeEach(() => {
  vi.mocked(geocodeApi.geocodeAddress).mockReset();
  vi.mocked(geocodeApi.reverseGeocode).mockReset();
});

describe("AddressGeocodeField — géocodage partagé", () => {
  it("saisir une adresse → candidats → choisir remonte le CANDIDAT fédéral (onPick)", async () => {
    vi.mocked(geocodeApi.geocodeAddress).mockResolvedValue(CANDIDATES);
    const onPick = vi.fn();
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={false} onPick={onPick} />);

    fireEvent.change(screen.getByRole("textbox", { name: "Adresse" }), { target: { value: "12 rue du sport" } });
    fireEvent.click(screen.getByRole("button", { name: "Localiser" }));

    await waitFor(() => expect(vi.mocked(geocodeApi.geocodeAddress)).toHaveBeenCalledWith("12 rue du sport"));
    const first = await screen.findByText("12 Rue du Sport, 69100 Villeurbanne");
    expect(screen.getByText("Recommandé")).not.toHaveClass("text-accent");
    expect(screen.getByText("correspondance approximative")).toBeInTheDocument();

    fireEvent.click(first);
    expect(onPick).toHaveBeenCalledWith(CANDIDATES[0]);
  });

  it("aucun candidat : message lisible, aucune écriture", async () => {
    vi.mocked(geocodeApi.geocodeAddress).mockResolvedValue([]);
    const onPick = vi.fn();
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={false} onPick={onPick} />);

    fireEvent.change(screen.getByRole("textbox", { name: "Adresse" }), { target: { value: "zzzzzz" } });
    fireEvent.click(screen.getByRole("button", { name: "Localiser" }));

    expect(await screen.findByText(/Aucune adresse trouvée/)).toBeInTheDocument();
    expect(onPick).not.toHaveBeenCalled();
  });

  it("service indisponible : une alerte lisible, aucune écriture", async () => {
    vi.mocked(geocodeApi.geocodeAddress).mockRejectedValue(new Error("502"));
    const onPick = vi.fn();
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={false} onPick={onPick} />);

    fireEvent.change(screen.getByRole("textbox", { name: "Adresse" }), { target: { value: "12 rue du sport" } });
    fireEvent.click(screen.getByRole("button", { name: "Localiser" }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(onPick).not.toHaveBeenCalled();
  });

  it("le bouton reste inerte sous 3 caractères (pas d'appel BAN pour rien)", () => {
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={false} onPick={vi.fn()} />);
    fireEvent.change(screen.getByRole("textbox", { name: "Adresse" }), { target: { value: "12" } });
    expect(screen.getByRole("button", { name: "Localiser" })).toBeDisabled();
  });

  it("déjà localisé : « {statusWord} », aucun champ ouvert, aucun géocodage au montage", () => {
    const onPick = vi.fn();
    renderWithProviders(<AddressGeocodeField {...baseProps} address="5 rue X" located={true} onPick={onPick} />);

    expect(screen.getByText("Localisé")).toBeInTheDocument();
    expect(screen.queryByRole("textbox", { name: "Adresse" })).toBeNull();
    expect(vi.mocked(geocodeApi.geocodeAddress)).not.toHaveBeenCalled();
    expect(onPick).not.toHaveBeenCalled();
  });

  it("non localisé avec `unlocatedStatus` : le statut permanent s'affiche + le champ pré-rempli", () => {
    renderWithProviders(<AddressGeocodeField {...baseProps} address="5 rue X" located={false} onPick={vi.fn()} unlocatedStatus="Siège non localisé — …" />);
    expect(screen.getByText(/Siège non localisé/)).toBeInTheDocument();
    expect(screen.getByRole("textbox", { name: "Adresse" })).toHaveValue("5 rue X");
  });

  // P4-271 (ajout fondateur) — la vue repliée : adresse saisie vs retrouvée vs inconnue + lien carte.
  it("localisé AVEC adresse saisie : l'adresse s'affiche, aucun reverse-geocoding", () => {
    renderWithProviders(<AddressGeocodeField {...baseProps} address="5 rue X" located={true} latitude={45.7} longitude={4.8} onPick={vi.fn()} />);
    expect(screen.getByText("5 rue X")).toBeInTheDocument();
    expect(vi.mocked(geocodeApi.reverseGeocode)).not.toHaveBeenCalled();
    expect(screen.queryByText(/Adresse retrouvée/)).toBeNull();
  });

  it("localisé SANS adresse mais avec coordonnées : l'adresse RETROUVÉE s'affiche", async () => {
    vi.mocked(geocodeApi.reverseGeocode).mockResolvedValue("5 Rue Émile Dunière, Villeurbanne");
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={true} latitude={45.76799} longitude={4.88853} onPick={vi.fn()} />);

    await waitFor(() => expect(vi.mocked(geocodeApi.reverseGeocode)).toHaveBeenCalledWith(45.76799, 4.88853));
    expect(await screen.findByText("Adresse retrouvée : 5 Rue Émile Dunière, Villeurbanne")).toBeInTheDocument();
  });

  it("localisé SANS adresse et reverse-geocoding vide : « Adresse inconnue »", async () => {
    vi.mocked(geocodeApi.reverseGeocode).mockResolvedValue(null);
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={true} latitude={45.7} longitude={4.8} onPick={vi.fn()} />);
    expect(await screen.findByText("Adresse retrouvée : Adresse inconnue")).toBeInTheDocument();
  });

  it("le lien « Voir sur la carte » pointe OpenStreetMap avec les coordonnées, en nouvel onglet sécurisé", () => {
    renderWithProviders(<AddressGeocodeField {...baseProps} address="5 rue X" located={true} latitude={45.7} longitude={4.8} onPick={vi.fn()} />);
    const link = screen.getByRole("link", { name: "Voir sur la carte" });
    expect(link).toHaveAttribute("href", "https://www.openstreetmap.org/?mlat=45.7&mlon=4.8#map=18/45.7/4.8");
    expect(link).toHaveAttribute("target", "_blank");
    expect(link).toHaveAttribute("rel", "noopener noreferrer");
  });

  // Lot E — précision : une rue (pas un numéro) est signalée « approximatif » dans la liste des
  // candidats (le point n'est pas un numéro de rue).
  it("un candidat de précision « rue » est signalé approximatif dans la liste", async () => {
    vi.mocked(geocodeApi.geocodeAddress).mockResolvedValue(STREET_CANDIDATE);
    const onPick = vi.fn();
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={false} onPick={onPick} />);
    fireEvent.change(screen.getByRole("textbox", { name: "Adresse" }), { target: { value: "cours emile zola" } });
    fireEvent.click(screen.getByRole("button", { name: "Localiser" }));

    const candidate = await screen.findByText("Cours Émile Zola, 69100 Villeurbanne");
    expect(screen.getByText("rue entière — ajoutez le numéro")).toBeInTheDocument();
    fireEvent.click(candidate);
    expect(onPick).toHaveBeenCalledWith(STREET_CANDIDATE[0]);
  });

  // Lot E — saisie manuelle : le sous-formulaire n'apparaît qu'avec onManualCoords ; une saisie
  // valide remonte les coordonnées bornées (même écriture que « Localiser »).
  it("saisir des coordonnées valides remonte le couple parsé (onManualCoords)", () => {
    const onManualCoords = vi.fn();
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={false} onPick={vi.fn()} onManualCoords={onManualCoords} />);
    fireEvent.click(screen.getByRole("button", { name: "Saisir les coordonnées" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Coordonnées (latitude, longitude) ou lien de carte" }), { target: { value: "45.76799, 4.88853" } });
    fireEvent.click(screen.getByRole("button", { name: "Placer" }));
    expect(onManualCoords).toHaveBeenCalledWith({ latitude: 45.76799, longitude: 4.88853 });
  });

  it("une saisie de coordonnées non reconnue montre une alerte, aucune écriture", () => {
    const onManualCoords = vi.fn();
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={false} onPick={vi.fn()} onManualCoords={onManualCoords} />);
    fireEvent.click(screen.getByRole("button", { name: "Saisir les coordonnées" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Coordonnées (latitude, longitude) ou lien de carte" }), { target: { value: "pas des coordonnées" } });
    fireEvent.click(screen.getByRole("button", { name: "Placer" }));
    expect(onManualCoords).not.toHaveBeenCalled();
    expect(screen.getByRole("alert")).toBeInTheDocument();
  });

  it("sans onManualCoords, aucune affordance « Saisir les coordonnées »", () => {
    renderWithProviders(<AddressGeocodeField {...baseProps} address={null} located={false} onPick={vi.fn()} />);
    expect(screen.queryByRole("button", { name: "Saisir les coordonnées" })).toBeNull();
  });
});
