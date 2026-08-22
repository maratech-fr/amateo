import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { VenueUnavailabilityCard } from "./VenueUnavailabilityCard";

const createMutate = vi.fn();
/** Les gymnases connus AU MOMENT du rendu — le cœur du bug se joue là. */
let venuesData: { id: string; name: string }[] | undefined = [];

vi.mock("@/features/matches/queries", () => ({
  useVenues: () => ({ data: venuesData }),
  useVenueUnavailabilities: () => ({ data: [] }),
  useUnavailabilityImpact: () => ({ data: { items: [] } }),
  useDeleteVenueUnavailability: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateVenueUnavailability: () => ({ mutate: createMutate, isPending: false }),
}));

/**
 * ⚑ Ce test naît d'un BLOCAGE MESURÉ, pas d'une intuition : le parcours e2e « incident » (P4-122)
 * restait sept minutes sur un bouton « Déclarer » désactivé, avec un formulaire que la capture
 * montrait COMPLET — gymnase « ADN » affiché sélectionné, dates valides, motif saisi.
 *
 * La cause, en deux temps : le bouton d'ouverture initialise `venueId` depuis `venues[0]` — mais
 * il lit une requête qui peut n'être PAS ENCORE RÉSOLUE, auquel cas `venueId` reste `""`. Et le
 * `<select>` n'avait AUCUNE option vide : le navigateur affiche donc la première option comme
 * sélectionnée pendant que l'état est vide, `canCreate` est faux, et le bouton meurt sans un mot.
 * Pire : cliquer l'option DÉJÀ affichée ne déclenche pas `change` — le premier gymnase de la liste
 * était inatteignable, il fallait en choisir un autre pour débloquer le formulaire.
 *
 * Le dépôt avait déjà la réponse : `DayDialog` porte la même liste avec son option vide.
 */
describe("VenueUnavailabilityCard — déclarer une indisponibilité", () => {
  beforeEach(() => {
    createMutate.mockReset();
    venuesData = [{ id: "v-adn", name: "ADN" }, { id: "v-camus", name: "Camus" }];
  });

  it("gymnases PAS ENCORE chargés à l'ouverture : l'écran dit « rien de choisi » au lieu d'en montrer un", async () => {
    const user = userEvent.setup();
    venuesData = undefined; // la requête n'a pas répondu quand le gestionnaire ouvre la modale
    renderWithProviders(<VenueUnavailabilityCard />);
    await user.click(screen.getAllByRole("button", { name: "Déclarer" })[0]);

    // Les gymnases arrivent APRÈS l'ouverture. On provoque le re-rendu par une frappe RÉELLE
    // (et non par un `rerender` de test, qui remonterait le composant hors de ses providers et
    // refermerait la modale — mordu en écrivant ce test).
    venuesData = [{ id: "v-adn", name: "ADN" }, { id: "v-camus", name: "Camus" }];
    await user.type(screen.getByLabelText("Motif de l'indisponibilité"), "x");

    // Sans l'option vide, le navigateur afficherait « ADN » alors que l'état est vide : l'écran
    // mentirait, et « Déclarer » resterait mort sans que rien ne l'explique.
    expect(await screen.findByRole("combobox", { name: "Gymnase indisponible" })).toHaveValue("");
  });

  it("« Déclarer » s'active dès que le formulaire est réellement complet — 1er gymnase COMPRIS", async () => {
    const user = userEvent.setup();
    renderWithProviders(<VenueUnavailabilityCard />);
    await user.click(screen.getAllByRole("button", { name: "Déclarer" })[0]);

    const submit = screen.getAllByRole("button", { name: "Déclarer" }).at(-1)!;
    expect(submit).toBeDisabled();

    // Le PREMIER gymnase — celui que le bug rendait inatteignable quand il s'affichait déjà.
    await user.selectOptions(screen.getByRole("combobox", { name: "Gymnase indisponible" }), "v-adn");
    await user.type(screen.getByLabelText("Début de l'indisponibilité"), "2026-09-01");
    await user.type(screen.getByLabelText("Fin de l'indisponibilité"), "2026-09-04");

    await waitFor(() => expect(submit).toBeEnabled());
    await user.click(submit);
    expect(createMutate).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v-adn", startDate: "2026-09-01", endDate: "2026-09-04" }), expect.anything());
  });
});
