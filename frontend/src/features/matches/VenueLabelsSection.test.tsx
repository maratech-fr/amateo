import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Venue } from "./api";
import { VenueLabelsSection } from "./VenueLabelsSection";

// On n'exerce QUE le composant : `useDetachVenueLabel` est le SEUL double (on prouve
// le GESTE — la mutation appelée avec le bon {venueId, label} —, pas l'invalidation,
// gardée dans queries.test.tsx). Pas de QueryClient ni de routeur : le composant est
// une feuille pilotée par sa prop `venues`.
const { mutate } = vi.hoisted(() => ({ mutate: vi.fn() }));
vi.mock("./queries", () => ({ useDetachVenueLabel: () => ({ mutate, isPending: false }) }));

const venue = (over: Partial<Venue> = {}): Venue => ({ id: "v1", name: "Gymnase Alpha", color: null, externalLabels: [], ...over });

beforeEach(() => {
  mutate.mockClear();
});

describe("VenueLabelsSection (P4-196)", () => {
  it("venues undefined (chargement) ⇒ rendu NUL (jamais un « aucun libellé » fabriqué)", () => {
    const { container } = render(<VenueLabelsSection venues={undefined} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("un gymnase sans alias n'a pas de ligne ; seul celui à alias apparaît", () => {
    render(<VenueLabelsSection venues={[venue({ id: "v1", name: "Sans Alias" }), venue({ id: "v2", name: "Gymnase Matéo", externalLabels: ["gymnase mateo"] })]} />);
    expect(screen.queryByText("Sans Alias")).not.toBeInTheDocument();
    expect(screen.getByText("Gymnase Matéo")).toBeInTheDocument();
    // Le libellé est affiché VERBATIM (la forme normalisée stockée), jamais re-cassé.
    expect(screen.getByText("gymnase mateo")).toBeInTheDocument();
  });

  it("aucun gymnase à alias ⇒ EmptyHint (pas de liste)", () => {
    render(<VenueLabelsSection venues={[venue(), venue({ id: "v2" })]} />);
    expect(screen.getByText(/Aucun gymnase ne porte de libellé FFBB/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Retirer le libellé/ })).not.toBeInTheDocument();
  });

  it("clic « Retirer » ouvre le dialogue de confirmation (« gardent ce gymnase »)", async () => {
    const user = userEvent.setup();
    render(<VenueLabelsSection venues={[venue({ id: "v2", name: "Gymnase Matéo", externalLabels: ["gymnase mateo"] })]} />);
    await user.click(screen.getByRole("button", { name: "Retirer le libellé « gymnase mateo » du gymnase Gymnase Matéo" }));
    expect(screen.getByText(/gardent ce gymnase/i)).toBeInTheDocument();
    // Rien n'est muté tant que l'on n'a pas confirmé.
    expect(mutate).not.toHaveBeenCalled();
  });

  it("Confirmer appelle la mutation avec {venueId, label} EXACTS (l'alias normalisé, jamais re-cassé)", async () => {
    const user = userEvent.setup();
    render(<VenueLabelsSection venues={[venue({ id: "v2", name: "Gymnase Matéo", externalLabels: ["gymnase mateo"] })]} />);
    await user.click(screen.getByRole("button", { name: "Retirer le libellé « gymnase mateo » du gymnase Gymnase Matéo" }));
    await user.click(screen.getByRole("button", { name: "Retirer" }));
    expect(mutate).toHaveBeenCalledTimes(1);
    expect(mutate).toHaveBeenCalledWith({ venueId: "v2", label: "gymnase mateo" });
  });

  it("Annuler ⇒ aucun appel de mutation", async () => {
    const user = userEvent.setup();
    render(<VenueLabelsSection venues={[venue({ id: "v2", name: "Gymnase Matéo", externalLabels: ["gymnase mateo"] })]} />);
    await user.click(screen.getByRole("button", { name: "Retirer le libellé « gymnase mateo » du gymnase Gymnase Matéo" }));
    await user.click(screen.getByRole("button", { name: "Annuler" }));
    expect(mutate).not.toHaveBeenCalled();
  });
});
