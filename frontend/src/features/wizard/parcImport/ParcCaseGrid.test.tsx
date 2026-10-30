import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { formatMinutes } from "@/shared/lib/time";
import { renderWithProviders } from "@/test/utils";

import { STEP } from "../lib/weekGrid";
import { PARC_IMPORT_TEXTS } from "./texts";
// ⚠ Composant PAS ENCORE CRÉÉ (rouge légitime, option A — TANK écrit ROUGE, NEO crée `ParcCaseGrid`).
// L'étape « Réservations » de l'assistant d'import rend UNE grille par gymnase (géométrie
// `weekGrid.ts`), empilées ; une case cliquable ouvre l'éditeur de créneau pré-rempli ; une case en
// alerte porte ⚠ + la RAISON serveur DANS son nom accessible. Contrat ci-dessous (signature
// adaptable mécaniquement par NEO).
import { ParcCaseGrid } from "./ParcCaseGrid";

/**
 * Lot correctif import IA « G2 » PR1 (micro-décisions 9-10) — `parcImport/ParcCaseGrid`.
 *
 * PROMESSES :
 *  - UNE grille par gymnase, empilées ; l'en-tête porte le nom du gymnase, suffixé « (à créer) »
 *    pour un gymnase pas encore existant ;
 *  - une CASE est cliquable (bouton) et son clic OUVRE l'éditeur de créneau pré-rempli
 *    (`onEditCase(index)`), la clé de case étant stable (`case:${index}`) ;
 *  - une case EN ALERTE porte ⚠ ET la raison SERVEUR dans son NOM ACCESSIBLE
 *    (`caseToVerify(raison)`), jamais la couleur seule (a11y).
 *
 * Tests sur les CLÉS de `texts.ts` (jamais une phrase recopiée). Clés RÉFÉRENCÉES ET PAS ENCORE
 * CRÉÉES (NEO les crée) : `gridVenueToCreate(name)` (« … (à créer) »), `caseToVerify(raison)`
 * (« … · à vérifier : <raison> »).
 */

const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...args: unknown[]) => string> & Record<string, string>;

/** Le contrat de données que NEO consomme (une case dérivée par réservation/créneau, par gymnase). */
interface GridCase {
  index: number;
  venueName: string;
  jour: number;
  debut: string;
  duree: number;
  libelle: string;
  /** Raison serveur si la case est en alerte (chevauchement, capacité…), sinon null. */
  alerte?: string | null;
  /** Retours fondateur 2026-10-26 — la case est une MUTUALISATION (marquée lien + nom de groupe). */
  mutual?: boolean;
  /** Le nom du groupe mutualisé porté par la case marquée (donnée serveur), sinon null. */
  groupe?: string | null;
}

const venues = [
  { name: "Gymnase Léon-Blum", toCreate: false },
  { name: "Palais des Sports", toCreate: true },
];

const cases: GridCase[] = [
  { index: 0, venueName: "Gymnase Léon-Blum", jour: 1, debut: "18:00", duree: 90, libelle: "SM1 · lundi 18:00" },
  { index: 1, venueName: "Palais des Sports", jour: 2, debut: "19:00", duree: 90, libelle: "SF1 · mardi 19:00", alerte: "Ce créneau en chevauche un autre du même gymnase ce jour-là." },
];

describe("ParcCaseGrid — grille de réservations par gymnase (refonte import IA PR1)", () => {
  it("rend UNE grille par gymnase, empilées, avec le suffixe « (à créer) » pour un gymnase neuf", () => {
    renderWithProviders(<ParcCaseGrid venues={venues} cases={cases} onEditCase={vi.fn()} />);

    // Un gymnase EXISTANT : son nom nu en en-tête.
    expect(screen.getByRole("heading", { name: "Gymnase Léon-Blum" })).toBeInTheDocument();
    // Un gymnase À CRÉER : en-tête suffixé « (à créer) » (clé de texte centralisée).
    expect(screen.getByRole("heading", { name: T.gridVenueToCreate("Palais des Sports") })).toBeInTheDocument();
  });

  it("clic sur une case → ouvre l'éditeur de créneau pré-rempli (onEditCase avec l'index de la case)", async () => {
    const user = userEvent.setup();
    const onEditCase = vi.fn();
    renderWithProviders(<ParcCaseGrid venues={venues} cases={cases} onEditCase={onEditCase} />);

    await user.click(screen.getByRole("button", { name: "SM1 · lundi 18:00" }));

    expect(onEditCase).toHaveBeenCalledWith(0);
  });

  it("une case en alerte porte la RAISON serveur dans son nom accessible (jamais la couleur seule)", () => {
    renderWithProviders(<ParcCaseGrid venues={venues} cases={cases} onEditCase={vi.fn()} />);

    // La case en alerte est atteignable par son nom accessible enrichi « … · à vérifier : <raison> ».
    const reason = "Ce créneau en chevauche un autre du même gymnase ce jour-là.";
    expect(screen.getByRole("button", { name: T.caseToVerify(reason) })).toBeInTheDocument();
  });
});

/**
 * Défaut A (capture réelle 05-reservations.png, club Rillieux) — la grille de l'import était « très
 * en-dessous » de la grille du wizard (`steps/ReservationGrid.tsx`) : AUCUNE gouttière d'heure, cases
 * qui se CHEVAUCHENT au même endroit au lieu d'être côte à côte, libellé qui RÉPÈTE le gymnase (déjà
 * en en-tête), et un ÉNORME vide vertical (plage pas réduite à l'occupé). Défaut B : pas de bandeau
 * récapitulatif « N cases à vérifier » en tête, avec un lien par case en alerte.
 *
 * Contrats jsdom (structure/styles calculés en JS, PAS le layout — jsdom n'a pas de moteur de mise en
 * page) ; NEO RÉUTILISE la géométrie partagée :
 *  - plage dynamique comme `ReservationGrid.tsx:43-48` (`weekGrid.ts` : `START_MIN/END_MIN/STEP/ROW_H`),
 *    gouttière d'heures via `formatMinutes` (foyer unique `shared/lib/time.ts`) ;
 *  - voies des cases qui se chevauchent via le foyer partagé `shared/lib/gridLayout.ts::assignLanes`
 *    (déjà consommé par `planning/lib/grid.ts`), qui pose `lane`/`laneCount` → left/width par voie ;
 *  - nouvelle clé `gridAlertSummary(n)` de `texts.ts` pour le bandeau (option A — RÉFÉRENCÉE, PAS
 *    ENCORE CRÉÉE, rouge légitime comme `gridVenueToCreate`/`caseToVerify` l'étaient) ; bouton de saut
 *    par case en alerte nommé d'après la clé `goToLine` existante.
 */
describe("ParcCaseGrid — gouttière, voies, libellé & bandeau au niveau du wizard (refonte import IA PR1, défauts A/B)", () => {
  const mistral = [{ name: "Gymnase Mistral", toCreate: false }];

  it("rend une gouttière d'heures couvrant la plage OCCUPÉE, jamais la journée entière (08:00→23:00)", () => {
    const occupied: GridCase[] = [
      { index: 0, venueName: "Gymnase Mistral", jour: 1, debut: "18:00", duree: 90, libelle: "SM1" },
      { index: 1, venueName: "Gymnase Mistral", jour: 3, debut: "20:00", duree: 60, libelle: "SF1" },
    ];
    renderWithProviders(<ParcCaseGrid venues={mistral} cases={occupied} onEditCase={vi.fn()} />);

    // La gouttière porte les libellés d'heure de la plage occupée (la 1ʳᵉ case est à 18:00).
    expect(screen.getByText(formatMinutes(18 * 60))).toBeInTheDocument();
    // Le vide pleine journée est supprimé : aucune heure matinale hors de la plage occupée.
    expect(screen.queryByText(formatMinutes(8 * 60))).not.toBeInTheDocument();
    expect(screen.queryByText(formatMinutes(9 * 60))).not.toBeInTheDocument();
  });

  it("deux cases qui se chevauchent le même jour reçoivent des VOIES distinctes (jamais superposées)", () => {
    // Deux réservations, MÊME gymnase, MÊME jour, qui se chevauchent (18:00–19:30 et 18:30–20:00) :
    // sans voies elles se posent dans la MÊME boîte horizontale (défaut capture).
    const overlapping: GridCase[] = [
      { index: 0, venueName: "Gymnase Mistral", jour: 1, debut: "18:00", duree: 90, libelle: "SM1" },
      { index: 1, venueName: "Gymnase Mistral", jour: 1, debut: "18:30", duree: 90, libelle: "SF1" },
    ];
    renderWithProviders(<ParcCaseGrid venues={mistral} cases={overlapping} onEditCase={vi.fn()} />);

    const a = screen.getByRole("button", { name: "SM1" });
    const b = screen.getByRole("button", { name: "SF1" });
    // Signature HORIZONTALE (colonne + voie) : deux cases qui se chevauchent ne partagent jamais la
    // même boîte horizontale — colonne distincte, OU left/width de voie distincts.
    const horizontalBox = (el: HTMLElement): string => `${el.style.gridColumn}|${el.style.left}|${el.style.width}`;
    expect(horizontalBox(a)).not.toEqual(horizontalBox(b));
  });

  it("le libellé visible d'une case ne RÉPÈTE pas le nom de son gymnase (déjà en en-tête)", () => {
    // Le libellé SERVEUR d'une réservation inclut le gymnase (`reservationLabel` = « équipe · gymnase
    // heure ») ; la grille, qui coiffe déjà ses cases du nom du gymnase, ne doit pas le répéter.
    const withVenueInLabel: GridCase[] = [{ index: 0, venueName: "Gymnase Mistral", jour: 1, debut: "18:00", duree: 90, libelle: "U15M1 · Gymnase Mistral 18:00" }];
    renderWithProviders(<ParcCaseGrid venues={mistral} cases={withVenueInLabel} onEditCase={vi.fn()} />);

    const caseBtn = screen.getByRole("button");
    expect(caseBtn).toHaveTextContent("U15M1"); // l'équipe reste
    expect(caseBtn.textContent ?? "").not.toContain("Gymnase Mistral"); // le gymnase ne se répète pas
  });

  it("un bandeau récapitulatif en tête compte les cases EN ALERTE et chacune a un bouton qui ouvre SON éditeur (B)", async () => {
    const user = userEvent.setup();
    const onEditCase = vi.fn();
    const mixed: GridCase[] = [
      { index: 0, venueName: "Gymnase Mistral", jour: 1, debut: "18:00", duree: 90, libelle: "SM1" },
      { index: 1, venueName: "Gymnase Mistral", jour: 2, debut: "19:00", duree: 90, libelle: "SF1", alerte: "Ce créneau en chevauche un autre." },
      { index: 2, venueName: "Gymnase Mistral", jour: 3, debut: "20:00", duree: 90, libelle: "U15M1", alerte: "Capacité dépassée." },
    ];
    renderWithProviders(<ParcCaseGrid venues={mistral} cases={mixed} onEditCase={onEditCase} />);

    // Le bandeau chiffre les 2 cases à vérifier (clé de texte centralisée, pas une phrase recopiée).
    expect(screen.getByText(T.gridAlertSummary(2))).toBeInTheDocument();

    // Un bouton de saut par case en alerte, qui ouvre l'éditeur de CETTE case (onEditCase avec l'index).
    const jumps = screen.getAllByRole("button", { name: (accessibleName: string) => accessibleName.startsWith(T.goToLine as unknown as string) });
    expect(jumps.length).toBeGreaterThanOrEqual(2);
    await user.click(jumps[0]!);
    expect(onEditCase).toHaveBeenCalledWith(expect.any(Number));
    // L'index remonté désigne bien une case EN ALERTE (1 ou 2), jamais la case saine (0).
    expect([1, 2]).toContain(onEditCase.mock.calls[0]?.[0]);
  });
});

/**
 * Retours fondateur 2026-10-26 — dans la grille (étape Réservations), une case de MUTUALISATION doit
 * se DISTINGUER d'une réservation simple : pictogramme de lien (`Link2`) + nom de groupe, porté dans
 * son NOM ACCESSIBLE (jamais la couleur/l'icône seules, a11y). Clé de texte centralisée RÉFÉRENCÉE ET
 * PAS ENCORE CRÉÉE (rouge légitime, NEO la crée) : `mutualisationCaseLabel(groupe, equipes)` — ex.
 * « Mutualisation · Groupe U13F-U11F · U13F + U11F ». Le ROUTAGE du clic (bascule vers l'étape
 * Mutualisations) est prouvé côté modale (`ParcImportModal.test.tsx`) ; ici, le MARQUAGE de la case.
 */
describe("ParcCaseGrid — une case de mutualisation est marquée (refonte import IA PR1, retours 2026-10-26)", () => {
  it("rend une case de mutualisation avec un nom accessible de MUTUALISATION (lien + nom de groupe), distinct d'une réservation", () => {
    const onEditCase = vi.fn();
    const venues = [{ name: "Gymnase Mistral", toCreate: false }];
    const cases: GridCase[] = [
      { index: 0, venueName: "Gymnase Mistral", jour: 1, debut: "18:00", duree: 90, libelle: "SM1 · 18:00" },
      { index: 1, venueName: "Gymnase Mistral", jour: 3, debut: "17:00", duree: 90, libelle: "U13F + U11F", mutual: true, groupe: "U13F-U11F" },
    ];
    renderWithProviders(<ParcCaseGrid venues={venues} cases={cases} onEditCase={onEditCase} />);

    // La case mutualisée est atteignable par son nom accessible enrichi (clé centralisée), jamais par
    // son seul libellé nu — c'est ainsi qu'un lecteur d'écran la distingue d'une réservation simple.
    const mutual = screen.getByRole("button", { name: T.mutualisationCaseLabel("U13F-U11F", "U13F + U11F") });
    expect(mutual).toBeInTheDocument();
    // Une réservation simple garde un nom nu (jamais le préfixe « Mutualisation »).
    expect(screen.getByRole("button", { name: "SM1 · 18:00" })).toBeInTheDocument();
  });
});

/**
 * Finitions d'interface (essai ALAP, 30/10, points 8 et 9) : ROUGE option A. Contrat pour NEO : une
 * cellule de décor de la pause porte `data-pause="true"` ; clé `caseTooltip(nom, debut, fin)` à créer
 * dans `texts.ts` (« Nom · début → fin »). La couleur elle-même se juge sur capture (jsdom ne peint rien).
 */
describe("ParcCaseGrid — pause de midi teintée et infobulle de réservation (finitions d'interface)", () => {
  const mistral = [{ name: "Gymnase Mistral", toCreate: false }];
  const pauseCells = (container: HTMLElement): NodeListOf<Element> => container.querySelectorAll('[data-pause="true"]');

  it("teinte la plage 12:00-14:00 sur les 7 jours, et elle seule", () => {
    const covering: GridCase[] = [{ index: 0, venueName: "Gymnase Mistral", jour: 1, debut: "11:00", duree: 240, libelle: "SM1" }];
    const { container } = renderWithProviders(<ParcCaseGrid venues={mistral} cases={covering} onEditCase={vi.fn()} />);

    expect(pauseCells(container)).toHaveLength(7 * (120 / STEP));
  });

  it("n'affiche aucune teinte quand la grille ne couvre pas la pause", () => {
    const evening: GridCase[] = [{ index: 0, venueName: "Gymnase Mistral", jour: 1, debut: "18:00", duree: 90, libelle: "SM1" }];
    const { container } = renderWithProviders(<ParcCaseGrid venues={mistral} cases={evening} onEditCase={vi.fn()} />);

    expect(pauseCells(container)).toHaveLength(0);
  });

  it("l'infobulle d'une case de réservation dit « Nom · début → fin », sans le gymnase ni l'heure du libellé serveur", () => {
    const reservation: GridCase[] = [{ index: 0, venueName: "Gymnase Mistral", jour: 1, debut: "18:00", duree: 90, libelle: "U15M1 · Gymnase Mistral 18:00" }];
    renderWithProviders(<ParcCaseGrid venues={mistral} cases={reservation} onEditCase={vi.fn()} />);

    expect(screen.getByRole("button")).toHaveAttribute("title", T.caseTooltip("U15M1", formatMinutes(18 * 60), formatMinutes(19 * 60 + 30)));
  });
});
