import { fireEvent, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { PARC_IMPORT_TEXTS } from "./texts";

/**
 * Chantier G PR3 — PROMESSES de la modale d'import du parc (`ParcImportModal`). Elle consomme
 * l'APERÇU (dry-run) servi par `POST /api/clubs/{id}/parc-import/analyze` (PR1 #1252) et laisse le
 * gestionnaire trancher, ligne par ligne, avant l'import réel (PR2, écriture).
 *
 * Contrat servi (figé par PR1, mocké ICI au format EXACT) :
 *   { format: "parc-v1",
 *     recap: { <section>: { total, apparie, suggere, aCreer, decoche, alerte } },
 *     sections: { <section>: [ { index, libelle, statut, coche, cibleId, raison, miseAJour? } ] } }
 *   statut ∈ apparie | suggere | aCreer | decoche | alerte ;
 *   coche = statut ∈ { apparie, suggere, aCreer } ; miseAJour = { champ:"seances", de, a } (équipes).
 *   `libelle` et `raison` sont des DONNÉES serveur (affichées verbatim, jamais un littéral FR du front).
 *
 * Contrat du composant (défini ici, implémenté par NEO) :
 *   ParcImportModal({ origin: "teams" | "venues"; clubId; seasonId;
 *                     teams/venues/coaches: readonly string[]; onClose })
 *   - Temps 1 — prompt : rend `buildParcPrompt({teams,venues,coaches})`, bouton PARC_IMPORT_TEXTS.copyPrompt
 *     (→ navigator.clipboard.writeText(prompt)).
 *   - Temps 2 — collage : zone de texte nommée PARC_IMPORT_TEXTS.pasteLabel + bouton
 *     PARC_IMPORT_TEXTS.analyze → useAnalyzeParc().mutate(texte, { onSuccess(analysis) }).
 *   - Temps 3 — aperçu : par section, chaque ligne = une case à cocher dont le NOM ACCESSIBLE est
 *     `libelle` et l'état coché est `coche` ; `raison` affichée pour decoche/alerte ; `miseAJour`
 *     affichée via PARC_IMPORT_TEXTS.sessionsUpdate(de,a) ; bandeau PARC_IMPORT_TEXTS.venuesCreatedWithoutAddress
 *     dès qu'un gymnase est `aCreer` ; bouton PARC_IMPORT_TEXTS.importAction(nbCoché) — grisé AVEC
 *     disabledReason PARC_IMPORT_TEXTS.nothingToImport quand rien n'est coché.
 *   - Temps 4 — import : au clic, useImportParc().mutate(sélection, { onSuccess(result) }). ⚠ La
 *     SIGNATURE de l'import et la forme de `result` sont PROVISOIRES (PR2, chez un autre 💊 TANK) —
 *     le test ne garde que le CÂBLAGE (l'import est appelé), à réaligner sur le contrat PR2.
 *
 * On mute la PROD (le vrai composant) : seules les deux hooks de données (`./queries`) sont des
 * doubles contrôlables ; `buildParcPrompt`/`PARC_IMPORT_TEXTS` sont les vrais modules de la PR.
 */

interface Line {
  index: number;
  libelle: string;
  statut: "apparie" | "suggere" | "aCreer" | "decoche" | "alerte";
  coche: boolean;
  cibleId: string | null;
  raison: string | null;
  miseAJour?: { champ: "seances"; de: number; a: number };
}

interface Recap {
  total: number;
  apparie: number;
  suggere: number;
  aCreer: number;
  decoche: number;
  alerte: number;
}

type Section = "equipes" | "gymnases" | "creneaux" | "mutualisations" | "coachs" | "reservations";

interface Analysis {
  format: string;
  recap: Record<string, Recap>;
  sections: Record<string, Line[]>;
}

function mkLine(index: number, libelle: string, statut: Line["statut"], extra: Partial<Line> = {}): Line {
  const coche = "apparie" === statut || "suggere" === statut || "aCreer" === statut;
  return { index, libelle, statut, coche, cibleId: null, raison: null, ...extra };
}

function recapOf(lines: Line[]): Recap {
  const r: Recap = { total: lines.length, apparie: 0, suggere: 0, aCreer: 0, decoche: 0, alerte: 0 };
  for (const l of lines) {
    r[l.statut] += 1;
  }
  return r;
}

function analysisOf(partial: Partial<Record<Section, Line[]>>): Analysis {
  const sections: Record<string, Line[]> = { equipes: [], gymnases: [], creneaux: [], mutualisations: [], coachs: [], reservations: [] };
  for (const key of Object.keys(sections) as Section[]) {
    sections[key] = partial[key] ?? [];
  }
  const recap: Record<string, Recap> = {};
  for (const key of Object.keys(sections)) {
    recap[key] = recapOf(sections[key]);
  }
  return { format: "parc-v1", recap, sections };
}

function checkedCount(a: Analysis): number {
  return Object.values(a.sections).reduce((n, lines) => n + lines.filter((l) => l.coche).length, 0);
}

const q = vi.hoisted(() => ({
  analysis: { value: null as unknown },
  analyzePending: { value: false },
  importPending: { value: false },
  importResult: { value: null as unknown },
  analyzeMutate: vi.fn(),
  importMutate: vi.fn(),
}));

vi.mock("./queries", () => ({
  useAnalyzeParc: () => ({ mutate: q.analyzeMutate, isPending: q.analyzePending.value, data: q.analysis.value }),
  useImportParc: () => ({ mutate: q.importMutate, isPending: q.importPending.value, data: q.importResult.value }),
}));

import { ParcImportModal } from "./ParcImportModal";

const defaultProps = {
  origin: "teams" as const,
  clubId: "club-1",
  seasonId: "season-1",
  teams: ["Séniors Garçons 1", "U13 Masculins"] as const,
  venues: ["Gymnase Léon-Blum"] as const,
  coaches: ["Camille Durand"] as const,
  onClose: () => {},
};

beforeEach(() => {
  q.analysis.value = null;
  q.importResult.value = null;
  q.analyzePending.value = false;
  q.importPending.value = false;
  // mutate(texte, { onSuccess }) rend l'analyse mockée (pré-posée par le test) — comme l'API.
  q.analyzeMutate.mockImplementation((_text: unknown, opts?: { onSuccess?: (a: unknown) => void }) => {
    opts?.onSuccess?.(q.analysis.value);
  });
  q.importMutate.mockImplementation((_sel: unknown, opts?: { onSuccess?: (r: unknown) => void }) => {
    opts?.onSuccess?.(q.importResult.value);
  });
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.clearAllMocks();
});

/** Dépose un texte dans la zone de collage puis lance l'analyse (le double rend `analysis`). */
async function paste(user: ReturnType<typeof userEvent.setup>, analysis: Analysis, rawText = '{"format":"parc-v1"}'): Promise<void> {
  q.analysis.value = analysis;
  fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: rawText } });
  await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
}

describe("ParcImportModal — aperçu de l'import du parc (chantier G PR3)", () => {
  it("temps 1 — le prompt pré-rempli embarque le périmètre du club et se copie", async () => {
    const user = userEvent.setup();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });

    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.copyPrompt }));

    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(1));
    const copied = writeText.mock.calls[0]?.[0] as string;
    expect(copied).toContain("Séniors Garçons 1");
    expect(copied).toContain("Gymnase Léon-Blum");
  });

  it("temps 2 — colle le JSON, lance l'analyse et affiche l'aperçu cochable", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis);

    expect(q.analyzeMutate).toHaveBeenCalledTimes(1);
    expect(await screen.findByRole("checkbox", { name: "Palais des Sports" })).toBeChecked();
    // Le bouton d'import chiffre ce qui sera posé (une ligne cochée).
    expect(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.importAction(1) })).toBeInTheDocument();
  });

  it("temps 3 — chaque ligne reflète son statut : apparié/suggéré/à créer cochés, décoché/alerte non cochés avec leur raison", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({
      gymnases: [
        mkLine(0, "Gymnase Léon-Blum", "apparie", { cibleId: "v1" }),
        mkLine(1, "Mateo", "suggere", { cibleId: "v2" }),
        mkLine(2, "Palais des Sports", "aCreer"),
        mkLine(3, "Gymnase", "decoche", { raison: "Plusieurs correspondances possibles — appariement ambigu, à choisir à la main." }),
      ],
    });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis);

    expect(await screen.findByRole("checkbox", { name: "Gymnase Léon-Blum" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "Mateo" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "Palais des Sports" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "Gymnase" })).not.toBeChecked();
    // La raison (donnée serveur) est affichée telle quelle pour la ligne décochée.
    expect(screen.getByText("Plusieurs correspondances possibles — appariement ambigu, à choisir à la main.")).toBeInTheDocument();
  });

  it("gymnase « à créer » : un bandeau prévient qu'il naîtra sans adresse", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    // Avec un gymnase aCreer, le bandeau est là.
    await paste(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));
    expect(await screen.findByText(PARC_IMPORT_TEXTS.venuesCreatedWithoutAddress)).toBeInTheDocument();
  });

  it("sans aucun gymnase à créer, pas de bandeau d'adresse", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysisOf({ gymnases: [mkLine(0, "Gymnase Léon-Blum", "apparie", { cibleId: "v1" })] }));
    await screen.findByRole("checkbox", { name: "Gymnase Léon-Blum" });
    expect(screen.queryByText(PARC_IMPORT_TEXTS.venuesCreatedWithoutAddress)).not.toBeInTheDocument();
  });

  it("une ligne décochée (appariement ambigu) nomme sa raison et reste cochable", async () => {
    const user = userEvent.setup();
    const raison = "Plusieurs correspondances possibles — appariement ambigu, à choisir à la main.";
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysisOf({ gymnases: [mkLine(0, "Gymnase", "decoche", { raison })] }));

    const box = await screen.findByRole("checkbox", { name: "Gymnase" });
    expect(box).not.toBeChecked();
    expect(screen.getByText(raison)).toBeInTheDocument();
    // « décochable » dans les deux sens : le gestionnaire peut la RÉ-INCLURE.
    await user.click(box);
    expect(box).toBeChecked();
  });

  it("équipe appariée avec un nombre de séances différent : la mise à jour est proposée, cochée, décochable", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({
      equipes: [mkLine(0, "SM1", "apparie", { cibleId: "t1", miseAJour: { champ: "seances", de: 2, a: 3 } })],
    });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis);

    const box = await screen.findByRole("checkbox", { name: "SM1" });
    expect(box).toBeChecked();
    expect(screen.getByText(PARC_IMPORT_TEXTS.sessionsUpdate(2, 3))).toBeInTheDocument();
    // La mise à jour reste refusable sans toucher au reste de l'équipe : on la décoche.
    await user.click(box);
    expect(box).not.toBeChecked();
  });

  it("correction sur place d'un champ simple : l'heure d'un créneau est éditable dans l'aperçu", async () => {
    // ⚠ Contrat PROVISOIRE côté interaction (la passe ui-ux-pro-max de PR3 fige QUELS champs
    // simples — jour/heure/durée/séances — sont éditables inline et avec quel contrôle). On garde
    // ici le REPRÉSENTANT : l'heure de début d'un créneau, amorcée depuis le parc collé, est
    // modifiable sur place. Si la passe de design change la forme, NEO le signale à 💊 TANK.
    const user = userEvent.setup();
    const parc = JSON.stringify({
      format: "parc-v1",
      equipes: [],
      gymnases: [{ nom: "Gymnase Léon-Blum" }],
      creneaux: [{ gymnase: "Gymnase Léon-Blum", jour: 2, debut: "18:00", duree: 90, equipes_en_parallele: 1 }],
      mutualisations: [],
      reservations: [],
    });
    const analysis = analysisOf({ creneaux: [mkLine(0, "Gymnase Léon-Blum · jour 2 18:00", "aCreer")] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis, parc);

    const startField = await screen.findByDisplayValue("18:00");
    fireEvent.change(startField, { target: { value: "19:00" } });
    expect(screen.getByDisplayValue("19:00")).toBeInTheDocument();
  });

  it("une structure complexe (mutualisation) en alerte se décoche et renvoie à la saisie manuelle — pas d'édition inline de sa composition", async () => {
    const user = userEvent.setup();
    const raison = "Les séances communes de cette mutualisation dépassent les séances hebdomadaires d'une des équipes — à ajuster à la main.";
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysisOf({ mutualisations: [mkLine(0, "U11M1 + U11M2", "alerte", { raison })] }));

    const box = await screen.findByRole("checkbox", { name: "U11M1 + U11M2" });
    expect(box).not.toBeChecked();
    expect(screen.getByText(raison)).toBeInTheDocument();
    // Sa composition n'est pas corrigeable sur place : aucun champ amorcé sur l'ensemble d'équipes.
    expect(screen.queryByDisplayValue("U11M1 + U11M2")).not.toBeInTheDocument();
  });

  it("deux équipes sur la même case non déclarées : la ligne alerte nomme les causes possibles", async () => {
    const user = userEvent.setup();
    const raison = "Deux équipes sur la même case sans mutualisation ni terrain divisé déclaré : mutualisation ? terrain divisé en deux ? erreur de lecture ? À trancher à la main.";
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysisOf({
      reservations: [
        mkLine(0, "U11M1 · Gymnase Léon-Blum 17:00", "alerte", { raison }),
        mkLine(1, "U11M2 · Gymnase Léon-Blum 17:00", "alerte", { raison }),
      ],
    }));

    const boxes = await screen.findAllByRole("checkbox", { name: /Gymnase Léon-Blum 17:00/ });
    for (const box of boxes) {
      expect(box).not.toBeChecked();
    }
    // La raison serveur nomme les trois pistes — on l'affiche verbatim.
    expect(screen.getAllByText(raison).length).toBeGreaterThanOrEqual(1);
  });

  it("une réservation déjà présente (idempotence) est décochée avec sa raison", async () => {
    const user = userEvent.setup();
    const raison = "Réservation déjà présente dans le planning.";
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysisOf({ reservations: [mkLine(0, "SM1 · Gymnase Léon-Blum 18:00", "decoche", { raison })] }));

    expect(await screen.findByRole("checkbox", { name: "SM1 · Gymnase Léon-Blum 18:00" })).not.toBeChecked();
    expect(screen.getByText(raison)).toBeInTheDocument();
  });

  it("le bouton Importer est grisé AVEC une raison tant qu'aucune ligne n'est cochée", async () => {
    const user = userEvent.setup();
    // Un aperçu où tout est en alerte/décoché → rien de cochable par défaut.
    const analysis = analysisOf({ gymnases: [mkLine(0, "Gymnase", "decoche", { raison: "ambigu" })] });
    expect(checkedCount(analysis)).toBe(0);
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis);
    await screen.findByRole("checkbox", { name: "Gymnase" });

    const importBtn = screen.getByRole("button", { name: PARC_IMPORT_TEXTS.importAction(0) });
    // disabledReason → aria-disabled (focalisable) + raison découvrable (A11Y-30).
    expect(importBtn).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByText(PARC_IMPORT_TEXTS.nothingToImport)).toBeInTheDocument();
  });

  it("au clic Importer, l'import est appelé (contrat PR2 provisoire)", async () => {
    // ⚠ PROVISOIRE : la SIGNATURE de l'import (sélection envoyée) et la forme du récap de retour
    // viennent de la PR2 (autre 💊 TANK). On ne garde ici que le CÂBLAGE : cocher puis Importer
    // DÉCLENCHE useImportParc().mutate. Le récap chiffré de fin sera réaligné sur le contrat PR2.
    const user = userEvent.setup();
    q.importResult.value = { message: "Import effectué." };
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));
    await user.click(await screen.findByRole("button", { name: PARC_IMPORT_TEXTS.importAction(1) }));

    expect(q.importMutate).toHaveBeenCalledTimes(1);
  });

  it("s'ouvre aussi bien depuis l'étape Équipes que depuis l'étape Gymnases", async () => {
    // Le même composant, deux portes d'entrée (D13) : la zone de collage est présente des deux côtés.
    const { unmount } = renderWithProviders(<ParcImportModal {...defaultProps} origin="teams" />);
    expect(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel })).toBeInTheDocument();
    unmount();

    renderWithProviders(<ParcImportModal {...defaultProps} origin="venues" />);
    expect(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel })).toBeInTheDocument();
  });
});
