import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { BlockerList } from "./BlockerList";

describe("BlockerList", () => {
  it("rends chaque bloqueur, un par ligne", () => {
    render(<BlockerList blockers={["Fanion : au moins une séance exigée le(s) dimanche, mais aucun créneau.", "SM1 : deux réservations pour une séance."]} />);

    expect(screen.getByText(/au moins une séance exigée/)).toBeInTheDocument();
    expect(screen.getByText(/deux réservations/)).toBeInTheDocument();
    expect(screen.getAllByRole("listitem")).toHaveLength(2);
  });

  it("s'annonce à l'apparition : la racine porte role=\"alert\" (validation asynchrone, AUD-FRT-23/24)", () => {
    render(<BlockerList blockers={["Un blocage."]} />);

    // Un lecteur d'écran doit apprendre qu'un blocage vient de surgir — sans role,
    // le panneau apparaissait muet.
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("À corriger avant de générer");
    expect(alert).toHaveTextContent("Un blocage.");
  });

  it("ne rend RIEN quand il n'y a aucun bloqueur (pas de live region vide qui parle)", () => {
    const { container } = render(<BlockerList blockers={[]} />);

    expect(container).toBeEmptyDOMElement();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});
