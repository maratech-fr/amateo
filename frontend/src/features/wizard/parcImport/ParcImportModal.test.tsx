import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { PARC_IMPORT_TEXTS } from "./texts";

/**
 * Chantier G PR3 — PROMESSES de la modale d'import du parc (`ParcImportModal`). Elle consomme
 * l'APERÇU (dry-run) servi par `POST /api/clubs/{id}/parc-import/analyze` (PR1 #1252) et laisse le
 * gestionnaire trancher, ligne par ligne, avant l'import réel (PR2, écriture).
 *
 * Contrat servi (RECALÉ « G2 » sur parc-v2, mocké ICI au format EXACT de l'analyzer réel) :
 *   { format: "parc-v2",
 *     fiabilite: { taux, anomalies, bandeau },
 *     recap: { <section>: { total, apparie, suggere, aCreer, decoche, alerte } },
 *     sections: { <section>: [ { index, libelle, statut, coche, cibleId, raison, miseAJour?, groupe? } ] },
 *     alertes: [ { type, message } ] }
 *   sections = gymnases | equipes | creneaux | mutualisations | reservations — PLUS de section coachs (D11).
 *   statut ∈ apparie | suggere | aCreer | decoche | alerte ;
 *   coche = statut ∈ { apparie, suggere, aCreer } ; miseAJour = { champ:"seances", de, a } (équipes) ;
 *   groupe = libellé du bloc mutualisé (ex. « CEC »), sur les lignes de mutualisation.
 *   `libelle` et `raison` sont des DONNÉES serveur (affichées verbatim, jamais un littéral FR du front).
 *
 * Contrat du composant (défini ici, implémenté par NEO) :
 *   ParcImportModal({ origin: "teams" | "venues"; clubId; seasonId;
 *                     teams/venues: readonly {id,name}[]; club?: {name?,city?}; onClose })
 *   — le prompt n'utilise que les NOMS (+ le contexte club) ; les id servent au ré-appariement (D5).
 *   - Temps 1 — prompt : rend `buildParcPrompt({teams,venues,club?})` — PLUS de coachs (D11), le
 *     contexte club (nom/ville) est embarqué quand il existe — bouton PARC_IMPORT_TEXTS.copyPrompt
 *     (→ navigator.clipboard.writeText(prompt)).
 *   - Temps 2 — collage : zone de texte nommée PARC_IMPORT_TEXTS.pasteLabel + bouton
 *     PARC_IMPORT_TEXTS.analyze → useAnalyzeParc().mutate(texte, { onSuccess(analysis) }).
 *   - Temps 3 — aperçu : par section, chaque ligne = une case à cocher dont le NOM ACCESSIBLE est
 *     `libelle` et l'état coché est `coche` ; `raison` affichée pour decoche/alerte ; `miseAJour`
 *     affichée via PARC_IMPORT_TEXTS.sessionsUpdate(de,a) ; bandeau PARC_IMPORT_TEXTS.venuesCreatedWithoutAddress
 *     dès qu'un gymnase est `aCreer` ; bouton PARC_IMPORT_TEXTS.importAction(nbCoché) — grisé AVEC
 *     disabledReason PARC_IMPORT_TEXTS.nothingToImport quand rien n'est coché.
 *   - Temps 4 — import : au clic, useImportParc().mutate({ payload, decisions }, { onSuccess(result) }),
 *     contrat RÉEL de PR2 (#1253) : `payload` = le parc collé, `decisions` =
 *     `[{section, index, coche, cibleId?, corrections?}]` ; réponse `{recap:{<section>:{crees,apparies,misAJour,ignores}}}`.
 *   - Ré-appariement (D5 « SM1 → Senior 1 ▾ ») : pour les sections équipes / gymnases, une ligne
 *     apparie / suggere / aCreer porte un SÉLECTEUR (primitive riche TeamSelect / VenueSelect) listant
 *     les entités EXISTANTES de la section (par nom, valeur = id) + une option « Créer ». Choisir une
 *     entité envoie son `cibleId` dans la décision ; choisir « Créer » n'en envoie pas. Une CASE
 *     (ligne créneaux) porte EN PLUS un sélecteur de GYMNASE (D12) → `cibleId` du créneau ; une
 *     MUTUALISATION affiche et laisse corriger son `groupe` (D8) → `corrections.groupe`.
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
  /** Mutualisation : le libellé du bloc dérivé (ex. « CEC »), affiché et corrigeable (D8). */
  groupe?: string | null;
}

interface Recap {
  total: number;
  apparie: number;
  suggere: number;
  aCreer: number;
  decoche: number;
  alerte: number;
}

type Section = "equipes" | "gymnases" | "creneaux" | "mutualisations" | "reservations";

interface Analysis {
  format: string;
  fiabilite: { taux: number; anomalies: number; bandeau: boolean };
  recap: Record<string, Recap>;
  sections: Record<string, Line[]>;
  alertes: Array<{ type: string; message: string }>;
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

function analysisOf(
  partial: Partial<Record<Section, Line[]>>,
  meta: { fiabilite?: Partial<Analysis["fiabilite"]>; alertes?: Analysis["alertes"] } = {},
): Analysis {
  const sections: Record<string, Line[]> = { equipes: [], gymnases: [], creneaux: [], mutualisations: [], reservations: [] };
  for (const key of Object.keys(sections) as Section[]) {
    sections[key] = partial[key] ?? [];
  }
  const recap: Record<string, Recap> = {};
  for (const key of Object.keys(sections)) {
    recap[key] = recapOf(sections[key]);
  }
  return {
    format: "parc-v2",
    fiabilite: { taux: 1, anomalies: 0, bandeau: false, ...meta.fiabilite },
    recap,
    sections,
    alertes: meta.alertes ?? [],
  };
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
  // {id,name}[] (D5) : le prompt n'en lit que les NOMS, le ré-appariement a besoin des id.
  // PLUS de `coaches` (D11, coachs retirés du format ET de l'écran).
  teams: [{ id: "t1", name: "Séniors Garçons 1" }, { id: "t2", name: "U13 Masculins" }] as const,
  venues: [{ id: "v1", name: "Gymnase Léon-Blum" }] as const,
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
async function paste(user: ReturnType<typeof userEvent.setup>, analysis: Analysis, rawText = '{"format":"parc-v2","cases":[]}'): Promise<void> {
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
    // ⚠ Contrat PROVISOIRE côté interaction (la passe ui-ux-pro-max fige QUELS champs simples —
    // jour/heure/durée/séances — sont éditables inline et avec quel contrôle). On garde ici le
    // REPRÉSENTANT : l'heure de début d'un créneau, amorcée depuis le parc collé, est modifiable sur
    // place. En parc-v2 le parc porte des CASES (plus de section `creneaux`) : le brouillon du créneau
    // dérivé est amorcé depuis la case correspondante. Si la passe de design change la forme, NEO le
    // signale à 💊 TANK.
    const user = userEvent.setup();
    const parc = JSON.stringify({
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Léon-Blum" }],
      cases: [{ gymnase: "Gymnase Léon-Blum", jour: 2, debut: "18:00", duree: 90, equipes: [] }],
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

  it("au clic Importer, l'import reçoit { payload, decisions } au format PR2 et le récap s'affiche", async () => {
    const user = userEvent.setup();
    q.importResult.value = { recap: { gymnases: { crees: 1, apparies: 0, misAJour: 0, ignores: 0 } } };
    const parc = JSON.stringify({ format: "parc-v2", gymnases: [{ nom: "Palais des Sports" }], cases: [] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }), parc);
    await user.click(await screen.findByRole("button", { name: PARC_IMPORT_TEXTS.importAction(1) }));

    expect(q.importMutate).toHaveBeenCalledTimes(1);
    const body = q.importMutate.mock.calls[0]?.[0] as { payload: unknown; decisions: Array<{ section: string; index: number; coche: boolean; cibleId?: string | null }> };
    expect(body).toHaveProperty("payload");
    expect(Array.isArray(body.decisions)).toBe(true);
    const dec = body.decisions.find((d) => "gymnases" === d.section && 0 === d.index);
    expect(dec?.coche).toBe(true);
    // Le récap PR2 s'affiche (données serveur) — le libellé de section et le compte vivent dans le
    // MÊME <li> (« Gymnases — 1 créé(s) … »), d'où le match par sous-chaîne.
    expect(await screen.findByText(PARC_IMPORT_TEXTS.sectionRecap(1, 0, 0, 0), { exact: false })).toBeInTheDocument();
  });

  it("s'ouvre aussi bien depuis l'étape Équipes que depuis l'étape Gymnases", async () => {
    // Le même composant, deux portes d'entrée (D13) : la zone de collage est présente des deux côtés.
    const { unmount } = renderWithProviders(<ParcImportModal {...defaultProps} origin="teams" />);
    expect(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel })).toBeInTheDocument();
    unmount();

    renderWithProviders(<ParcImportModal {...defaultProps} origin="venues" />);
    expect(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel })).toBeInTheDocument();
  });

  // ─────────── Ré-appariement par sélecteur (D5 « SM1 → Senior 1 ▾ ») ───────────
  // Le sélecteur porte un NOM ACCESSIBLE contextualisé incluant le libellé de la ligne (A11Y-28),
  // d'où la recherche du trigger par le libellé. Primitive riche (TeamSelect/VenueSelect/Listbox) :
  // trigger = bouton, panneau = listbox, choix = option (patron ConstraintStep/PeriodSteps).

  it("ré-appariement — une ligne gymnase liste les gymnases existants + « Créer » ; choisir un gymnase envoie son cibleId", async () => {
    const user = userEvent.setup();
    const venues = [{ id: "v1", name: "Gymnase Léon-Blum" }, { id: "v2", name: "Salle Mont Blanc" }] as const;
    renderWithProviders(<ParcImportModal {...defaultProps} venues={venues} />);

    await paste(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));

    await user.click(await screen.findByRole("button", { name: /Palais des Sports/ }));
    const list = screen.getByRole("listbox");
    expect(within(list).getByRole("option", { name: "Salle Mont Blanc" })).toBeInTheDocument();
    expect(within(list).getByRole("option", { name: PARC_IMPORT_TEXTS.rematchCreate })).toBeInTheDocument();
    await user.click(within(list).getByRole("option", { name: "Gymnase Léon-Blum" }));

    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.importAction(1) }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; cibleId?: string | null }> };
    const dec = body.decisions.find((d) => "gymnases" === d.section && 0 === d.index);
    expect(dec?.cibleId).toBe("v1");
  });

  it("ré-appariement — revenir sur « Créer » efface le cibleId (choisir « Créer » n'en envoie pas)", async () => {
    const user = userEvent.setup();
    const venues = [{ id: "v1", name: "Gymnase Léon-Blum" }, { id: "v2", name: "Salle Mont Blanc" }] as const;
    renderWithProviders(<ParcImportModal {...defaultProps} venues={venues} />);
    await paste(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));

    const open = async (): Promise<void> => void (await user.click(await screen.findByRole("button", { name: /Palais des Sports/ })));
    await open();
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: "Gymnase Léon-Blum" }));
    await open();
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: PARC_IMPORT_TEXTS.rematchCreate }));

    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.importAction(1) }));
    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; cibleId?: string | null }> };
    const dec = body.decisions.find((d) => "gymnases" === d.section && 0 === d.index);
    expect(dec?.cibleId ?? null).toBeNull();
  });

  it("ré-appariement : sélecteur pour équipes / gymnases, pas pour réservations ni mutualisations ; plus aucune UI coachs (D11)", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({
      equipes: [mkLine(0, "SM1", "aCreer")],
      gymnases: [mkLine(0, "Palais des Sports", "aCreer")],
      reservations: [mkLine(0, "SM1 · Gymnase X 18:00", "alerte", { raison: "collision" })],
      mutualisations: [mkLine(0, "A + B", "aCreer", { groupe: null })],
    });
    // Une section `coachs` héritée d'un vieil aperçu : le serveur n'en envoie plus (D11), l'écran ne
    // doit JAMAIS la rendre — on l'injecte ici pour prouver qu'elle est ignorée en silence.
    (analysis.sections as Record<string, Line[]>).coachs = [mkLine(0, "Coach Zed", "decoche", { raison: "section héritée" })];
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis);

    // Les deux sections d'entités portent leur sélecteur (trigger = bouton nommé par le libellé).
    expect(await screen.findByRole("button", { name: /SM1/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Palais des Sports/ })).toBeInTheDocument();
    // Aucune UI coachs : ni case à cocher, ni titre de section « Coachs ».
    expect(screen.queryByRole("checkbox", { name: "Coach Zed" })).not.toBeInTheDocument();
    expect(screen.queryByText(PARC_IMPORT_TEXTS.sectionTitle("coachs"))).not.toBeInTheDocument();
    // Réservation et mutualisation ne portent AUCUN sélecteur de ré-appariement d'entité.
    expect(screen.queryByRole("button", { name: /Gymnase X 18:00/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /A \+ B/ })).not.toBeInTheDocument();
  });

  // ─────────── Lot correctif import IA « G2 » PR2 — nouvelles promesses d'aperçu ───────────

  it("D12 — une case (ligne créneau) porte un sélecteur de gymnase ; corriger un gymnase mal lu part en cibleId du créneau", async () => {
    // Le gestionnaire voit une case dont le gymnase a été mal lu ; il choisit le BON gymnase existant
    // dans un sélecteur par case, et la correction part à l'import comme `cibleId` du créneau (le
    // backend honore déjà un `cibleId` de gymnase sur la ligne créneaux, ParcImportExecutor:261-263).
    const user = userEvent.setup();
    const venues = [{ id: "v1", name: "Gymnase Léon-Blum" }, { id: "v9", name: "Palais des Sports" }] as const;
    const parc = JSON.stringify({
      format: "parc-v2",
      gymnases: [{ nom: "Gymnaze Leon Blum" }],
      cases: [{ gymnase: "Gymnaze Leon Blum", jour: 2, debut: "18:00", duree: 90, equipes: [] }],
    });
    const analysis = analysisOf({ creneaux: [mkLine(0, "Gymnaze Leon Blum · jour 2 18:00", "aCreer")] });
    renderWithProviders(<ParcImportModal {...defaultProps} venues={venues} />);

    await paste(user, analysis, parc);

    // Le sélecteur de gymnase de la case (trigger = bouton nommé par le libellé du créneau).
    await user.click(await screen.findByRole("button", { name: /jour 2 18:00/ }));
    const list = screen.getByRole("listbox");
    await user.click(within(list).getByRole("option", { name: "Gymnase Léon-Blum" }));

    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.importAction(1) }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; cibleId?: string | null }> };
    const dec = body.decisions.find((d) => "creneaux" === d.section && 0 === d.index);
    expect(dec?.cibleId).toBe("v1");
  });

  it("D8 — une mutualisation affiche son groupe dérivé « CEC » et le laisse corriger ; la correction part en corrections.groupe", async () => {
    // ⚠ Contrat PROVISOIRE côté interaction (la passe ui-ux-pro-max fige le contrôle exact). Le groupe
    // DÉRIVÉ par le serveur (donnée `groupe` de la ligne) est montré comme nom du bloc et corrigeable ;
    // la valeur corrigée voyage dans `corrections.groupe` de la décision (le backend l'honore comme
    // label du bloc, cf. ParcImportExecuteTest::testACorrectedGroupeOverridesTheBlockLabel).
    const user = userEvent.setup();
    const analysis = analysisOf({ mutualisations: [mkLine(0, "U11M1 + U11M2", "aCreer", { groupe: "CEC" })] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis);

    // Le groupe dérivé « CEC » est montré, amorcé dans un champ corrigeable.
    const groupField = await screen.findByDisplayValue("CEC");
    fireEvent.change(groupField, { target: { value: "Section Loisirs" } });

    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.importAction(1) }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; corrections?: Record<string, unknown> }> };
    const dec = body.decisions.find((d) => "mutualisations" === d.section && 0 === d.index);
    expect(dec?.corrections?.groupe).toBe("Section Loisirs");
  });

  it("D10 — un résultat peu fiable affiche un bandeau, sans jamais bloquer l'import", async () => {
    // `fiabilite.bandeau` vrai → bandeau « résultat peu fiable » ; l'import reste POSSIBLE (jamais
    // bloquant) : le bouton n'est grisé qu'à zéro coché, pas par la fiabilité.
    const user = userEvent.setup();
    const analysis = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }, { fiabilite: { taux: 0.2, anomalies: 4, bandeau: true } });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis);

    expect(await screen.findByText(PARC_IMPORT_TEXTS.unreliableResult)).toBeInTheDocument();
    // L'import n'est PAS bloqué par la fiabilité : une ligne cochée → bouton actif.
    const importBtn = screen.getByRole("button", { name: PARC_IMPORT_TEXTS.importAction(1) });
    expect(importBtn).not.toHaveAttribute("aria-disabled", "true");
    expect(importBtn).toBeEnabled();
  });

  it("D10 — un résultat fiable n'affiche aucun bandeau de fiabilité", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }, { fiabilite: { taux: 1, anomalies: 0, bandeau: false } });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis);
    await screen.findByRole("checkbox", { name: "Palais des Sports" });

    expect(screen.queryByText(PARC_IMPORT_TEXTS.unreliableResult)).not.toBeInTheDocument();
  });

  it("D9 — les alertes transverses sont affichées lisiblement (message serveur verbatim)", async () => {
    // Les alertes du serveur (équipe à deux endroits, heure implausible, gymnase sans case) sont des
    // DONNÉES affichées telles quelles — jamais un littéral FR du front.
    const user = userEvent.setup();
    const alertes = [
      { type: "equipe_deux_endroits", message: "L'équipe « U11M1 » est placée dans deux gymnases au même moment — à trancher à la main." },
      { type: "heure_implausible", message: "Un créneau commence à 06:00 (gymnase « Palais des Sports ») — horaire inhabituel à vérifier." },
    ];
    const analysis = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }, { fiabilite: { taux: 1, anomalies: 2, bandeau: false }, alertes });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await paste(user, analysis);

    for (const alerte of alertes) {
      expect(await screen.findByText(alerte.message)).toBeInTheDocument();
    }
  });

  it("le prompt copié embarque le contexte club (nom, ville) quand le club existe", async () => {
    // Promise 6 — l'écran appelant passe le contexte club à `buildParcPrompt({teams,venues,club})` ;
    // il aide l'assistant sur les noms, surtout en terrain vierge. `buildParcPrompt` est le VRAI module.
    const user = userEvent.setup();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });

    renderWithProviders(<ParcImportModal {...defaultProps} club={{ name: "Basket Club Réaliste", city: "Rillieux" }} />);

    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.copyPrompt }));

    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(1));
    const copied = writeText.mock.calls[0]?.[0] as string;
    expect(copied).toContain("Basket Club Réaliste");
    expect(copied).toContain("Rillieux");
  });

  it("l'aide de collage recommande les assistants GPT, Gemini, Claude et Copilot", () => {
    // Promise 6 — chatbots recommandés (dont Copilot) : contenu de la clé de texte centralisée.
    for (const assistant of ["GPT", "Gemini", "Claude", "Copilot"]) {
      expect(PARC_IMPORT_TEXTS.promptHelp).toContain(assistant);
    }
  });
});
