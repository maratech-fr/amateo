import { render, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { listboxTrigger, openListbox, pickListboxOption } from "@/test/pickListboxOption";

import { VenueSelect } from "./venue-select";

const venues = [
  { id: "v1", name: "ADN", color: "#ff0000" },
  { id: "v2", name: "JDR", color: "#00ff00" },
];

describe("VenueSelect (Listbox — pastille couleur, P4-164 PR-2)", () => {
  it("peint la pastille du gymnase choisi SUR le trigger", () => {
    render(<VenueSelect aria-label="Gymnase" venues={venues} value="v1" onValueChange={() => {}} />);
    const swatch = listboxTrigger("Gymnase").querySelector("span[style]");
    expect(swatch).toHaveStyle({ backgroundColor: "#ff0000" });
  });

  it("peint une pastille couleur SUR chaque option de la liste ouverte", async () => {
    const user = userEvent.setup();
    render(<VenueSelect aria-label="Gymnase" venues={venues} value="v1" onValueChange={() => {}} />);
    const options = within(await openListbox(user, "Gymnase")).getAllByRole("option");
    expect(options).toHaveLength(2);
    expect(options[0].querySelector("span[style]")).toHaveStyle({ backgroundColor: "#ff0000" });
    expect(options[1].querySelector("span[style]")).toHaveStyle({ backgroundColor: "#00ff00" });
  });

  it("remonte la valeur via onValueChange (plus un event DOM)", async () => {
    const user = userEvent.setup();
    const onValueChange = vi.fn();
    render(<VenueSelect aria-label="Gymnase" venues={venues} value="v1" onValueChange={onValueChange} />);
    await pickListboxOption(user, "Gymnase", "JDR");
    expect(onValueChange).toHaveBeenCalledWith("v2");
  });

  it("placeholder = option de tête SÉLECTIONNABLE (valeur vide) affichée dans le trigger", async () => {
    const user = userEvent.setup();
    const onValueChange = vi.fn();
    render(<VenueSelect aria-label="Gymnase" venues={venues} value="" onValueChange={onValueChange} placeholder="— gymnase —" />);
    expect(listboxTrigger("— gymnase —")).toBeInTheDocument();
    const list = await openListbox(user, "— gymnase —");
    await user.click(within(list).getByRole("option", { name: "— gymnase —" }));
    expect(onValueChange).toHaveBeenCalledWith("");
  });

  it("leadingOptions précèdent les gymnases, après le placeholder", async () => {
    const user = userEvent.setup();
    render(
      <VenueSelect aria-label="Gymnase" venues={venues} value="" onValueChange={() => {}} placeholder="— gymnase —" leadingOptions={[{ value: "all", label: "Tous" }]} />,
    );
    const options = within(await openListbox(user, "— gymnase —")).getAllByRole("option");
    expect(options.map((o) => o.textContent)).toEqual(["— gymnase —", "Tous", "ADN", "JDR"]);
  });

  it("`sub` = sous-ligne (nom intact) et `disabled` rend l'option inerte", async () => {
    const user = userEvent.setup();
    const onValueChange = vi.fn();
    const stateful = [
      { id: "v1", name: "ADN", color: "#ff0000", sub: "désactivé" },
      { id: "v2", name: "JDR", color: null, sub: "fermé lundi", disabled: true },
    ];
    render(<VenueSelect aria-label="Gymnase" venues={stateful} value="v1" onValueChange={onValueChange} />);
    const list = await openListbox(user, "Gymnase");
    const adn = within(list).getByRole("option", { name: "ADN" });
    expect(adn).toHaveTextContent("ADN");
    expect(adn).toHaveTextContent("désactivé");
    const jdr = within(list).getByRole("option", { name: "JDR" });
    expect(jdr).toHaveAttribute("aria-disabled", "true");
    await user.click(jdr);
    expect(onValueChange).not.toHaveBeenCalled();
  });
});
