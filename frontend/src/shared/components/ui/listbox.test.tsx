import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";

import { Listbox, type ListboxGroup, type ListboxOption } from "./listbox";

const OPTIONS: ListboxOption[] = [
  { value: "a", label: "Alpha" },
  { value: "b", label: "Bravo" },
  { value: "c", label: "Charlie" },
];

/** Controlled harness so a selection is reflected back into the trigger. */
function Harness(props: { onValueChange?: (v: string) => void; initial?: string } & Partial<React.ComponentProps<typeof Listbox>>) {
  const { onValueChange, initial = "", ...rest } = props;
  const [value, setValue] = useState(initial);

  return (
    <Listbox
      aria-label="Équipe"
      options={OPTIONS}
      {...rest}
      value={value}
      onValueChange={(v) => {
        setValue(v);
        onValueChange?.(v);
      }}
    />
  );
}

const trigger = () => screen.getByRole("button", { name: /Équipe/ });
const list = () => screen.getByRole("listbox");

describe("Listbox — trigger & rôles", () => {
  it("expose un trigger button[aria-haspopup=listbox], fermé par défaut, sans listbox rendue", () => {
    render(<Harness />);
    const t = trigger();
    expect(t).toHaveAttribute("aria-haspopup", "listbox");
    expect(t).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("listbox")).toBeNull();
  });

  it("nom accessible du trigger = libellé + valeur (jamais l'aria-label seul)", () => {
    render(<Harness initial="a" />);
    // "Équipe" (label sr-only) + "Alpha" (valeur) — la valeur reste lue.
    expect(screen.getByRole("button", { name: "Équipe Alpha" })).toBeInTheDocument();
  });

  it("affiche le placeholder quand aucune valeur", () => {
    render(<Harness placeholder="— choisir —" />);
    expect(screen.getByRole("button", { name: "Équipe — choisir —" })).toBeInTheDocument();
  });

  it("le placeholder est une option en tête qui EFFACE la valeur (parité <select> natif)", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness initial="a" placeholder="— aucun —" onValueChange={onValueChange} />);
    await user.click(trigger());
    await user.click(within(list()).getByRole("option", { name: "— aucun —" }));
    expect(onValueChange).toHaveBeenCalledWith("");
  });

  it("disabled : trigger désactivé, n'ouvre pas au clic", async () => {
    const user = userEvent.setup();
    render(<Harness disabled />);
    expect(trigger()).toBeDisabled();
    await user.click(trigger());
    expect(screen.queryByRole("listbox")).toBeNull();
  });

  it("groupe : role=group nommé par son titre, options dedans", async () => {
    const user = userEvent.setup();
    const groups: ListboxGroup[] = [
      { id: "s", label: "S · Fanion", options: [{ value: "s1", label: "SM1" }] },
      { id: "b", label: "B · Moyenne", options: [{ value: "b1", label: "U15" }] },
    ];
    render(<Harness options={undefined} groups={groups} />);
    await user.click(trigger());
    const group = screen.getByRole("group", { name: "S · Fanion" });
    expect(within(group).getByRole("option", { name: "SM1" })).toBeInTheDocument();
  });
});

describe("Listbox — ouverture", () => {
  it.each(["{Enter}", " ", "{ArrowDown}", "{ArrowUp}"])("s'ouvre au clavier (%s) depuis le trigger", async (key) => {
    const user = userEvent.setup();
    render(<Harness />);
    trigger().focus();
    await user.keyboard(key);
    expect(screen.getByRole("listbox")).toBeInTheDocument();
    expect(trigger()).toHaveAttribute("aria-expanded", "true");
  });

  it("s'ouvre au clic et met le focus sur l'option sélectionnée", async () => {
    const user = userEvent.setup();
    render(<Harness initial="b" />);
    await user.click(trigger());
    expect(within(list()).getByRole("option", { name: "Bravo" })).toHaveFocus();
  });

  it("sans valeur, ouvre sur la première option", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(trigger());
    expect(within(list()).getByRole("option", { name: "Alpha" })).toHaveFocus();
  });
});

describe("Listbox — navigation clavier", () => {
  it("↓ / ↑ déplacent le focus, Début / Fin sautent aux bornes", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(trigger());
    await user.keyboard("{ArrowDown}");
    expect(within(list()).getByRole("option", { name: "Bravo" })).toHaveFocus();
    await user.keyboard("{ArrowUp}");
    expect(within(list()).getByRole("option", { name: "Alpha" })).toHaveFocus();
    await user.keyboard("{End}");
    expect(within(list()).getByRole("option", { name: "Charlie" })).toHaveFocus();
    await user.keyboard("{Home}");
    expect(within(list()).getByRole("option", { name: "Alpha" })).toHaveFocus();
  });

  it("typeahead : une lettre déplace le focus sur l'option correspondante", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(trigger());
    await user.keyboard("c");
    expect(within(list()).getByRole("option", { name: "Charlie" })).toHaveFocus();
  });

  it("sélectionne au clic : onValueChange, ferme, focus rendu au trigger", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness onValueChange={onValueChange} />);
    await user.click(trigger());
    await user.click(within(list()).getByRole("option", { name: "Bravo" }));
    expect(onValueChange).toHaveBeenCalledWith("b");
    expect(screen.queryByRole("listbox")).toBeNull();
    expect(trigger()).toHaveFocus();
  });

  it("sélectionne au clavier (Entrée) l'option active", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness onValueChange={onValueChange} />);
    await user.click(trigger());
    await user.keyboard("{ArrowDown}{Enter}");
    expect(onValueChange).toHaveBeenCalledWith("b");
  });
});

describe("Listbox — option désactivée", () => {
  const withDisabled: ListboxOption[] = [
    { value: "a", label: "Alpha" },
    { value: "x", label: "Xray", disabled: true, sub: "Indisponible" },
    { value: "c", label: "Charlie" },
  ];

  it("est atteinte par ↓ mais Entrée/clic sont sans effet et la liste reste ouverte", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness options={withDisabled} onValueChange={onValueChange} />);
    await user.click(trigger());
    await user.keyboard("{ArrowDown}");
    const opt = within(list()).getByRole("option", { name: "Xray" });
    expect(opt).toHaveFocus();
    expect(opt).toHaveAttribute("aria-disabled", "true");
    await user.keyboard("{Enter}");
    expect(onValueChange).not.toHaveBeenCalled();
    expect(screen.getByRole("listbox")).toBeInTheDocument();
    await user.click(opt);
    expect(onValueChange).not.toHaveBeenCalled();
    expect(screen.getByRole("listbox")).toBeInTheDocument();
  });

  it("la sous-ligne d'une option est reliée par aria-describedby (nom = libellé seul)", async () => {
    const user = userEvent.setup();
    render(<Harness options={withDisabled} />);
    await user.click(trigger());
    const opt = within(list()).getByRole("option", { name: "Xray" });
    const descId = opt.getAttribute("aria-describedby");
    expect(descId).toBeTruthy();
    expect(document.getElementById(descId as string)?.textContent).toBe("Indisponible");
  });
});

describe("Listbox — fermeture", () => {
  it("Échap ferme, rend le focus au trigger, et NE remonte PAS à un parent (stopPropagation)", async () => {
    const parentKeydown = vi.fn();
    const user = userEvent.setup();
    const { container } = render(<Harness />);
    // Patron `useModalA11y` : un écouteur keydown NATIF sur un ancêtre (le panneau de modale).
    container.addEventListener("keydown", parentKeydown);
    await user.click(trigger());
    await user.keyboard("{Escape}");
    expect(screen.queryByRole("listbox")).toBeNull();
    expect(trigger()).toHaveFocus();
    // Le parent ne doit pas voir l'Échap, sinon la modale se fermerait.
    expect(parentKeydown).not.toHaveBeenCalled();
    container.removeEventListener("keydown", parentKeydown);
  });

  it("Tab ferme SANS sélectionner", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness onValueChange={onValueChange} />);
    await user.click(trigger());
    await user.keyboard("{Tab}");
    expect(screen.queryByRole("listbox")).toBeNull();
    expect(onValueChange).not.toHaveBeenCalled();
  });

  it("un clic dehors ferme la liste", async () => {
    const user = userEvent.setup();
    render(
      <div>
        <Harness />
        <button type="button">dehors</button>
      </div>,
    );
    await user.click(trigger());
    expect(screen.getByRole("listbox")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "dehors" }));
    expect(screen.queryByRole("listbox")).toBeNull();
  });
});
