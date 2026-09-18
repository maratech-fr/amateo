import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { pickListboxOption } from "@/test/pickListboxOption";

import type { Venue, VenueLabelInventoryRow } from "./api";
import { VenueLabelsSection } from "./VenueLabelsSection";

// On exerce l'ÉCRAN d'appariement : l'inventaire (prop de la query) et les deux mutations
// sont les seuls doubles — on prouve le GESTE (la mutation appelée avec le bon corps, drapeau
// `reassign` compris), pas l'invalidation ni le toast (gardés dans queries.test.tsx). `VenueSelect`
// reste RÉEL (primitive partagée testée ailleurs) : on pilote son Listbox via le helper maison.
const h = vi.hoisted(() => ({
  inventory: undefined as VenueLabelInventoryRow[] | undefined,
  mutateAttach: vi.fn(),
  mutateDetach: vi.fn(),
}));
vi.mock("./queries", () => ({
  useVenueLabelInventory: () => ({ data: h.inventory }),
  useAttachVenueLabel: () => ({ mutate: h.mutateAttach, isPending: false }),
  useDetachVenueLabel: () => ({ mutate: h.mutateDetach, isPending: false }),
}));

const venues: Venue[] = [
  { id: "v-alpha", name: "Gymnase Alpha", color: null, externalLabels: [] },
  { id: "v-beta", name: "Gymnase Beta", color: null, externalLabels: [] },
];

const row = (over: Partial<VenueLabelInventoryRow> = {}): VenueLabelInventoryRow => ({
  labelKey: "gymnase mateo",
  displayLabel: "GYMNASE MATEO",
  venueId: null,
  suggestedVenueId: null,
  homeCount: 3,
  placedCount: 1,
  unplacedCount: 2,
  ...over,
});

beforeEach(() => {
  h.inventory = undefined;
  h.mutateAttach.mockClear();
  h.mutateDetach.mockClear();
});

describe("VenueLabelsSection — écran d'appariement (E2, P4-205)", () => {
  it("inventaire undefined (chargement) ⇒ rendu NUL (jamais un « aucun libellé » fabriqué)", () => {
    h.inventory = undefined;
    const { container } = render(<VenueLabelsSection venues={venues} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("venues undefined (chargement) ⇒ rendu NUL", () => {
    h.inventory = [row()];
    const { container } = render(<VenueLabelsSection venues={undefined} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("inventaire vide ⇒ état vide « Aucun libellé de salle importé pour l'instant »", () => {
    h.inventory = [];
    render(<VenueLabelsSection venues={venues} />);
    expect(screen.getByText(/Aucun libellé de salle importé pour l'instant/i)).toBeInTheDocument();
  });

  it("une ligne par libellé, avec ses compteurs (N domiciles · N placés · N non placés)", () => {
    h.inventory = [row({ homeCount: 3, placedCount: 1, unplacedCount: 2 })];
    render(<VenueLabelsSection venues={venues} />);
    expect(screen.getByText("GYMNASE MATEO")).toBeInTheDocument();
    expect(screen.getByText(/3 domiciles · 1 placé · 2 non placés/)).toBeInTheDocument();
  });

  it("suggestion pré-sélectionnée : le gymnase suggéré s'affiche + pastille « d'après les rencontres »", () => {
    h.inventory = [row({ venueId: null, suggestedVenueId: "v-beta" })];
    render(<VenueLabelsSection venues={venues} />);
    // La valeur du Listbox (nom du gymnase) est sur le bouton déclencheur.
    expect(screen.getByRole("button", { name: /Gymnase pour le libellé GYMNASE MATEO/ }).textContent).toContain("Gymnase Beta");
    expect(screen.getByText("d'après les rencontres")).toBeInTheDocument();
  });

  it("un libellé confirmé n'affiche PAS la pastille de suggestion (chip = pas encore confirmé)", () => {
    h.inventory = [row({ venueId: "v-alpha", suggestedVenueId: null })];
    render(<VenueLabelsSection venues={venues} />);
    expect(screen.queryByText("d'après les rencontres")).not.toBeInTheDocument();
  });

  it("« Confirmer » (pas d'alias, suggestion acceptée) ⇒ mutation SANS reassign, label = labelKey", async () => {
    const user = userEvent.setup();
    h.inventory = [row({ venueId: null, suggestedVenueId: "v-beta" })];
    render(<VenueLabelsSection venues={venues} />);
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    expect(h.mutateAttach).toHaveBeenCalledTimes(1);
    expect(h.mutateAttach.mock.calls[0][0]).toEqual({ venueId: "v-beta", label: "gymnase mateo" });
  });

  it("changer de gymnase alors qu'un alias existe ⇒ ConfirmDialog nommant M/N puis reassign: true", async () => {
    const user = userEvent.setup();
    h.inventory = [row({ venueId: "v-alpha", suggestedVenueId: null, unplacedCount: 2, placedCount: 1 })];
    render(<VenueLabelsSection venues={venues} />);
    // On bascule la sélection sur un AUTRE gymnase.
    await pickListboxOption(user, "Gymnase pour le libellé GYMNASE MATEO", "Gymnase Beta");
    await user.click(screen.getByRole("button", { name: "Réaffecter" }));
    // Le dialogue annonce l'impact chiffré (M non placés basculent, N placés conservés).
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/2 domiciles non placés basculeront vers Gymnase Beta/)).toBeInTheDocument();
    expect(within(dialog).getByText(/1 placé conserve leur gymnase/)).toBeInTheDocument();
    // Rien muté tant que non confirmé.
    expect(h.mutateAttach).not.toHaveBeenCalled();
    await user.click(within(dialog).getByRole("button", { name: "Réaffecter" }));
    expect(h.mutateAttach).toHaveBeenCalledTimes(1);
    expect(h.mutateAttach.mock.calls[0][0]).toEqual({ venueId: "v-beta", label: "gymnase mateo", reassign: true });
  });

  it("pas d'alias mais les domiciles portent un AUTRE gymnase que celui choisi ⇒ « Réaffecter » + reassign (cas vécu 2026-09-14)", async () => {
    const user = userEvent.setup();
    // Alias retiré la veille : venueId null ; les 83 domiciles restés sur Alpha (suggestion unanime).
    h.inventory = [row({ venueId: null, suggestedVenueId: "v-alpha", unplacedCount: 83, placedCount: 0 })];
    render(<VenueLabelsSection venues={venues} />);
    await pickListboxOption(user, "Gymnase pour le libellé GYMNASE MATEO", "Gymnase Beta");
    // Plus de « Confirmer » (qui ne bougerait rien) : le geste est une ré-affectation.
    expect(screen.queryByRole("button", { name: "Confirmer" })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Réaffecter" }));
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/83 domiciles non placés basculeront vers Gymnase Beta/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Réaffecter" }));
    expect(h.mutateAttach.mock.calls[0][0]).toEqual({ venueId: "v-beta", label: "gymnase mateo", reassign: true });
  });

  it("alias confirmé mais des domiciles portent encore un autre gymnase ⇒ « Réaffecter » ACTIF sans changer la sélection", async () => {
    const user = userEvent.setup();
    // Alias posé sur Beta par « Confirmer » ; les 83 domiciles restés sur Alpha (suggestion ≠ confirmé).
    h.inventory = [row({ venueId: "v-beta", suggestedVenueId: "v-alpha", unplacedCount: 83, placedCount: 0 })];
    render(<VenueLabelsSection venues={venues} />);
    const button = screen.getByRole("button", { name: "Réaffecter" });
    expect(button).toBeEnabled();
    await user.click(button);
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/83 domiciles non placés basculeront vers Gymnase Beta/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Réaffecter" }));
    expect(h.mutateAttach.mock.calls[0][0]).toEqual({ venueId: "v-beta", label: "gymnase mateo", reassign: true });
  });

  it("« Retirer » ouvre le dialogue inchangé (« gardent ce gymnase ») puis detach {venueId, labelKey}", async () => {
    const user = userEvent.setup();
    h.inventory = [row({ venueId: "v-alpha", suggestedVenueId: null })];
    render(<VenueLabelsSection venues={venues} />);
    await user.click(screen.getByRole("button", { name: "Retirer" }));
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/gardent ce gymnase/i)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Retirer" }));
    expect(h.mutateDetach).toHaveBeenCalledTimes(1);
    expect(h.mutateDetach.mock.calls[0][0]).toEqual({ venueId: "v-alpha", label: "gymnase mateo" });
  });
});
