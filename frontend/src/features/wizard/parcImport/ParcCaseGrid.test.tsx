import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

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
