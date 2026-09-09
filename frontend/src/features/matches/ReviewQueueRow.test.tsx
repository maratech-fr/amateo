import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { listboxTrigger, openListbox } from "@/test/pickListboxOption";

import type { Fixture, Venue } from "./api";
import { ReviewQueueRow } from "./ReviewQueueRow";

const venues: Venue[] = [
  { id: "v1", name: "Gymnase Alpha", color: null, externalLabels: [] },
  { id: "v2", name: "Coubertin", color: null, externalLabels: [] },
];

let seq = 0;
function fx(extra: Partial<Fixture> = {}): Fixture {
  seq += 1;
  return {
    id: `fx-${seq}`,
    teamId: "t1",
    seasonId: "s1",
    competitionId: null,
    matchDate: "2026-11-07",
    homeAway: "HOME",
    opponentLabel: "BRON",
    status: "PLACED",
    venueId: null,
    kickoffTime: null,
    externalRef: null,
    fbiVenueLabel: "GYMNASE MATEO",
    placementSource: null,
    unplacedReason: null,
    reviewState: "NEW",
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null,
    suggestedVenueId: null,
    ...extra,
  };
}

function renderRow(fixture: Fixture, onAttach = vi.fn()) {
  render(
    <ul>
      <ReviewQueueRow fixture={fixture} venues={venues} onValidateLine={vi.fn()} onResolve={vi.fn()} onPlace={vi.fn()} onAttach={onAttach} busy={false} />
    </ul>,
  );
  return { onAttach };
}

const TRIGGER = /Choisir le gymnase pour la rencontre/;

beforeEach(() => {
  seq = 0;
});

describe("ReviewQueueRow — rattacher un gymnase (P4-187b)", () => {
  it("un domicile importé sans salle porte la mention + le bouton « Rattacher »", () => {
    renderRow(fx());
    expect(screen.getByText(/GYMNASE MATEO · non rattaché/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Rattacher" })).toBeInTheDocument();
  });

  it("le bloc s'affiche AUSSI sur une ligne déjà traitée (REVIEWED)", () => {
    renderRow(fx({ reviewState: "REVIEWED", reviewedAt: "2026-10-01T10:00:00+00:00" }));
    expect(screen.getByRole("button", { name: "Rattacher" })).toBeInTheDocument();
  });

  it("absent sur une AWAY (jamais un domicile à rattacher)", () => {
    renderRow(fx({ homeAway: "AWAY" }));
    expect(screen.queryByRole("button", { name: "Rattacher" })).not.toBeInTheDocument();
  });

  it("absent sur un domicile déjà rattaché (venueId posé)", () => {
    renderRow(fx({ venueId: "v1" }));
    expect(screen.queryByRole("button", { name: "Rattacher" })).not.toBeInTheDocument();
  });

  it("« Rattacher » ouvre un sélecteur PRÉ-SÉLECTIONNÉ sur la proposition présente dans la liste, Confirmer actif", async () => {
    const user = userEvent.setup();
    renderRow(fx({ suggestedVenueId: "v1" }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    expect(listboxTrigger(TRIGGER)).toHaveTextContent("Gymnase Alpha");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeEnabled();
  });

  it("sans proposition → placeholder et Confirmer DÉSACTIVÉ tant que rien n'est choisi", async () => {
    const user = userEvent.setup();
    renderRow(fx({ suggestedVenueId: null }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    expect(listboxTrigger(TRIGGER)).toHaveTextContent("Choisir un gymnase");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
  });

  it("proposition HORS liste (id inconnu) → placeholder, jamais un pari", async () => {
    const user = userEvent.setup();
    renderRow(fx({ suggestedVenueId: "v-fantome" }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    expect(listboxTrigger(TRIGGER)).toHaveTextContent("Choisir un gymnase");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
  });

  it("Confirmer envoie onAttach avec le libellé BRUT (le serveur normalise)", async () => {
    const user = userEvent.setup();
    const { onAttach } = renderRow(fx({ suggestedVenueId: "v1" }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    expect(onAttach).toHaveBeenCalledWith({ venueId: "v1", label: "GYMNASE MATEO" });
  });

  it("un choix manuel (sans proposition) alimente onAttach", async () => {
    const user = userEvent.setup();
    const { onAttach } = renderRow(fx({ suggestedVenueId: null }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    const list = await openListbox(user, TRIGGER);
    await user.click(within(list).getByRole("option", { name: "Coubertin" }));
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    expect(onAttach).toHaveBeenCalledWith({ venueId: "v2", label: "GYMNASE MATEO" });
  });

  it("Annuler replie le bloc (retour au bouton « Rattacher »)", async () => {
    const user = userEvent.setup();
    renderRow(fx({ suggestedVenueId: "v1" }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    await user.click(screen.getByRole("button", { name: "Annuler" }));
    expect(screen.getByRole("button", { name: "Rattacher" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Confirmer" })).not.toBeInTheDocument();
  });
});
