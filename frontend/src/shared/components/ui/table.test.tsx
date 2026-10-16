import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from "./table";

describe("Table (primitive partagée, PR-2b)", () => {
  it("rend un <table> avec caption, en-têtes et cellules", () => {
    render(
      <Table>
        <TableCaption>Matchs du mois</TableCaption>
        <TableHeader>
          <TableRow>
            <TableHead>Date</TableHead>
            <TableHead>Équipe</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow>
            <TableCell>sam. 3 oct.</TableCell>
            <TableCell>U13</TableCell>
          </TableRow>
        </TableBody>
      </Table>,
    );
    // Le caption nomme le tableau (accessible name du role table).
    expect(screen.getByRole("table", { name: "Matchs du mois" })).toBeInTheDocument();
    expect(screen.getByText("sam. 3 oct.")).toBeInTheDocument();
    expect(screen.getByText("U13")).toBeInTheDocument();
  });

  it("les TableHead portent scope=col (en-têtes de colonne)", () => {
    render(
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Statut</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow>
            <TableCell>Placé</TableCell>
          </TableRow>
        </TableBody>
      </Table>,
    );
    const th = screen.getByRole("columnheader", { name: "Statut" });
    expect(th).toHaveAttribute("scope", "col");
  });

  it("enveloppe la table dans un conteneur à défilement horizontal (jamais la page)", () => {
    const { container } = render(
      <Table>
        <TableBody>
          <TableRow>
            <TableCell>x</TableCell>
          </TableRow>
        </TableBody>
      </Table>,
    );
    const scroller = container.querySelector(".overflow-x-auto");
    expect(scroller).not.toBeNull();
    expect(scroller?.querySelector("table")).not.toBeNull();
  });
});

/**
 * Conteneur de défilement RÉGLABLE (lot 5 PR E / P4-284).
 *
 * UNE promesse : **la primitive accepte un réglage de son conteneur de défilement (`wrapperClassName`),
 * pour que les écrans dont le tableau a un corps défilant borné (`min-h-0 flex-1 overflow-auto` côté
 * `ClubViewTable`, `max-h-[24rem] overflow-auto` côté `TravelMatrixModal`) puissent migrer vers la
 * primitive À RENDU ISO — sans que le `overflow-x-auto` figé d'aujourd'hui les en empêche** (décision
 * fondateur 2026-10-16, condition expresse « même rendu avant et après, en-tête figé »).
 *
 * Le fait que l'EN-TÊTE reste figé pendant le défilement (`position: sticky`) n'est PAS prouvable ici :
 * jsdom n'a aucun moteur de mise en page (il ne calcule ni `sticky`, ni `overflow`, ni `boundingBox`,
 * `.claude/rules/frontend.md`). Cette preuve-là vit en e2e (`tests/e2e/tables-sticky.spec.ts`, filet
 * d'iso-rendu). Ici, on prouve seulement, au plus bas coût, que le CONTRAT de la prop existe et
 * plombe jusqu'au conteneur — et que le défaut ne régresse pas.
 *
 * ── CONTRAT red → green ──────────────────────────────────────────────────────────────────────
 * ROUGE tant que `Table` n'expose pas `wrapperClassName` : à l'exécution, la prop inconnue part dans
 * `...props` et atterrit sur le `<table>` (jamais sur la div conteneur), donc le conteneur ne porte
 * pas les classes → l'assertion tombe (Vitest) ; au lint, `tsc -b` refuse la prop en trop (la suite
 * ne type-checke pas tant que NEO n'a pas ajouté la prop — c'est l'état ROUGE voulu, Vitest exécute
 * malgré tout, esbuild ne type-checkant pas). NEO la verdit en destructurant `wrapperClassName` et en
 * le fusionnant par `cn` sur la div conteneur — à rendu ISO (le défaut `overflow-x-auto` + bordure
 * reste la valeur de base, `variant="inline"` inchangé).
 */
describe("Table — conteneur de défilement réglable (PR E / P4-284)", () => {
  /** La div conteneur qui enveloppe le `<table>` (là où vivent `overflow-*` et la bordure). */
  function wrapperOf(container: HTMLElement): HTMLElement {
    const table = container.querySelector("table");
    expect(table, "la primitive doit rendre un <table>").not.toBeNull();
    const wrapper = table?.parentElement;
    expect(wrapper, "le <table> doit être enveloppé d'une div conteneur").not.toBeNull();
    return wrapper as HTMLElement;
  }

  it("fusionne wrapperClassName sur le conteneur (classes de défilement borné)", () => {
    const { container } = render(
      // `wrapperClassName` est le CONTRAT que NEO doit ajouter (red → green). Tant qu'il n'existe pas,
      // Vitest tombe sur l'assertion ci-dessous et `tsc -b` refuse la prop en trop (double ROUGE voulu).
      <Table wrapperClassName="min-h-0 flex-1 overflow-auto">
        <TableBody>
          <TableRow>
            <TableCell>x</TableCell>
          </TableRow>
        </TableBody>
      </Table>,
    );
    const wrapper = wrapperOf(container);
    // Le conteneur porte les classes de défilement borné demandées — pas le <table>.
    expect(wrapper.className).toContain("overflow-auto");
    expect(wrapper.className).toContain("min-h-0");
    expect(wrapper.className).toContain("flex-1");
    expect(container.querySelector("table")?.className ?? "").not.toContain("overflow-auto");
  });

  it("sans réglage, le conteneur par défaut est INCHANGÉ (overflow-x-auto + bordure)", () => {
    const { container } = render(
      <Table>
        <TableBody>
          <TableRow>
            <TableCell>x</TableCell>
          </TableRow>
        </TableBody>
      </Table>,
    );
    const wrapper = wrapperOf(container);
    expect(wrapper.className).toContain("overflow-x-auto");
    expect(wrapper.className).toContain("border");
  });

  it("le variant inline garde son conteneur NU (overflow-x-auto, sans bordure)", () => {
    const { container } = render(
      <Table variant="inline">
        <TableBody>
          <TableRow>
            <TableCell>x</TableCell>
          </TableRow>
        </TableBody>
      </Table>,
    );
    const wrapper = wrapperOf(container);
    expect(wrapper.className).toContain("overflow-x-auto");
    expect(wrapper.className).not.toContain("border-border");
  });
});
