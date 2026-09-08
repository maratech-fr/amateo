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
