import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { Listbox, type ListboxGroup, type ListboxOption } from "./listbox";

const OPTIONS: ListboxOption[] = [
  { value: "a", label: "Alpha" },
  { value: "b", label: "Bravo" },
  { value: "c", label: "Charlie" },
];

/** ≥ 8 real options → the search field appears (P4-198 threshold). Sub-lines + one disabled row
 *  so the filter can be exercised on `label`, on `sub`, and against a keyboard-reachable-but-inert
 *  option that must stay visible. Labels are chosen so `gym ber` matches Berthelot ONLY (Auclair,
 *  not Aubert, so "ber" does not leak). */
const MANY: ListboxOption[] = [
  { value: "1", label: "Gymnase Berthelot" },
  { value: "2", label: "Gymnase Auclair" },
  { value: "3", label: "Stade Municipal", sub: "Tous ses créneaux sont placés" },
  { value: "4", label: "Salle des Fêtes" },
  { value: "5", label: "Complexe Nord" },
  { value: "6", label: "Halle Ouest", disabled: true, sub: "Épuisé" },
  { value: "7", label: "U13M1" },
  { value: "8", label: "U13M2" },
  { value: "9", label: "Cosec Sud" },
  { value: "10", label: "Palais des Sports" },
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

describe("Listbox — recherche (P4-198)", () => {
  it("7 options réelles : PAS de champ de recherche (byte-identique)", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY.slice(0, 7)} />);
    await user.click(trigger());
    expect(screen.queryByRole("textbox")).toBeNull();
    // Focus initial sur la première option (comportement d'origine, seuil non atteint).
    expect(within(list()).getByRole("option", { name: "Gymnase Berthelot" })).toHaveFocus();
  });

  it("8 options réelles : un champ de recherche (nom = searchLabel, défaut « Rechercher »)", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY.slice(0, 8)} />);
    await user.click(trigger());
    expect(screen.getByRole("textbox", { name: "Rechercher" })).toBeInTheDocument();
  });

  it("le placeholder et les leadingOptions ne comptent pas dans le seuil", async () => {
    const user = userEvent.setup();
    // 7 vraies options + placeholder + 1 leadingOption = 9 lignes, mais 7 RÉELLES → pas de champ.
    render(<Harness options={MANY.slice(0, 7)} placeholder="— aucun —" leadingOptions={[{ value: "all", label: "Tous" }]} />);
    await user.click(trigger());
    expect(screen.queryByRole("textbox")).toBeNull();
  });

  it("met le focus DANS le champ à l'ouverture (≥ 8)", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY} />);
    await user.click(trigger());
    expect(screen.getByRole("textbox", { name: "Rechercher" })).toHaveFocus();
  });

  it("filtre en tokens ET : « gym ber » → Berthelot seul", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "gym ber");
    const opts = within(list()).getAllByRole("option");
    expect(opts).toHaveLength(1);
    expect(opts[0]).toHaveAccessibleName("Gymnase Berthelot");
  });

  it("insensible aux accents ; une option désactivée qui matche RESTE visible et inerte", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness options={MANY} onValueChange={onValueChange} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "épuis"); // matche « Épuisé » (sous-ligne) de Halle Ouest
    const opt = within(list()).getByRole("option", { name: "Halle Ouest" });
    expect(opt).toHaveAttribute("aria-disabled", "true");
    await user.click(opt);
    expect(onValueChange).not.toHaveBeenCalled();
    expect(screen.getByRole("listbox")).toBeInTheDocument();
  });

  it("matche sur la sous-ligne (sub), pas seulement le libellé", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "placés");
    const opts = within(list()).getAllByRole("option");
    expect(opts).toHaveLength(1);
    expect(opts[0]).toHaveAccessibleName("Stade Municipal");
  });

  it("masque un groupe entièrement filtré, en-tête compris", async () => {
    const user = userEvent.setup();
    const groups: ListboxGroup[] = [
      { id: "g1", label: "Gymnases", options: [{ value: "1", label: "Gymnase Berthelot" }, { value: "2", label: "Gymnase Auclair" }] },
      {
        id: "g2",
        label: "Équipes",
        options: [
          { value: "7", label: "U13M1" },
          { value: "8", label: "U13M2" },
          { value: "9", label: "U15M1" },
          { value: "10", label: "U15M2" },
          { value: "11", label: "U17M1" },
          { value: "12", label: "U17M2" },
        ],
      },
    ];
    render(<Harness options={undefined} groups={groups} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "gymnase");
    expect(screen.getByRole("group", { name: "Gymnases" })).toBeInTheDocument();
    expect(screen.queryByRole("group", { name: "Équipes" })).toBeNull();
  });

  it("placeholder et leadingOptions restent visibles même sans correspondance", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY} placeholder="— aucun —" leadingOptions={[{ value: "all", label: "Tous" }]} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "zzzzz");
    expect(within(list()).getByRole("option", { name: "— aucun —" })).toBeInTheDocument();
    expect(within(list()).getByRole("option", { name: "Tous" })).toBeInTheDocument();
  });

  it("affiche « Aucun résultat pour « xyz » » quand rien ne matche", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "zzzzz");
    expect(screen.getByText("Aucun résultat pour « zzzzz »")).toBeInTheDocument();
  });

  it("annonce le nombre de résultats dans une région aria-live polie", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "gymnase"); // Berthelot + Auclair
    const live = screen.getByText("2 résultats");
    expect(live).toHaveAttribute("aria-live", "polite");
  });

  it("↓ depuis le champ entre dans la liste (première option)", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY} />);
    await user.click(trigger());
    expect(screen.getByRole("textbox")).toHaveFocus();
    await user.keyboard("{ArrowDown}");
    const opts = within(list()).getAllByRole("option");
    expect(opts[0]).toHaveFocus();
  });

  it("Entrée dans le champ sélectionne la première option filtrée non désactivée", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness options={MANY} onValueChange={onValueChange} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "u13m1");
    await user.keyboard("{Enter}");
    expect(onValueChange).toHaveBeenCalledWith("7");
    expect(screen.queryByRole("listbox")).toBeNull();
  });

  it("Entrée sans requête ne sélectionne rien et laisse la liste ouverte", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness options={MANY} onValueChange={onValueChange} />);
    await user.click(trigger());
    await user.keyboard("{Enter}");
    expect(onValueChange).not.toHaveBeenCalled();
    expect(screen.getByRole("listbox")).toBeInTheDocument();
  });

  it("Entrée dans le champ ne choisit PAS une option désactivée (aucune non-désactivée ne matche)", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness options={MANY} onValueChange={onValueChange} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "ouest"); // Halle Ouest, désactivée, seule à matcher
    await user.keyboard("{Enter}");
    expect(onValueChange).not.toHaveBeenCalled();
  });

  it("Échap depuis le champ ferme la liste sans remonter au parent (la modale survit)", async () => {
    const parentKeydown = vi.fn();
    const user = userEvent.setup();
    const { container } = render(<Harness options={MANY} />);
    container.addEventListener("keydown", parentKeydown);
    await user.click(trigger());
    expect(screen.getByRole("textbox")).toHaveFocus();
    await user.keyboard("{Escape}");
    expect(screen.queryByRole("listbox")).toBeNull();
    expect(parentKeydown).not.toHaveBeenCalled();
    expect(trigger()).toHaveFocus();
    container.removeEventListener("keydown", parentKeydown);
  });

  it("Tab depuis le champ ferme SANS sélectionner", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<Harness options={MANY} onValueChange={onValueChange} />);
    await user.click(trigger());
    expect(screen.getByRole("textbox")).toHaveFocus();
    await user.keyboard("{Tab}");
    expect(screen.queryByRole("listbox")).toBeNull();
    expect(onValueChange).not.toHaveBeenCalled();
  });

  it("le filtre est vidé à la réouverture", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY} />);
    await user.click(trigger());
    await user.type(screen.getByRole("textbox"), "gymnase");
    expect(within(list()).getAllByRole("option")).toHaveLength(2);
    await user.keyboard("{Escape}");
    await user.click(trigger());
    expect(screen.getByRole("textbox")).toHaveValue("");
    expect(within(list()).getAllByRole("option").length).toBeGreaterThan(2);
  });

  it("le nom accessible du champ vient de searchLabel", async () => {
    const user = userEvent.setup();
    render(<Harness options={MANY} searchLabel="Rechercher une équipe" />);
    await user.click(trigger());
    expect(screen.getByRole("textbox", { name: "Rechercher une équipe" })).toBeInTheDocument();
  });

  it("aucune violation axe sur l'état ouvert avec le champ de recherche (≥ 8)", async () => {
    const user = userEvent.setup();
    const { container } = render(<Harness options={MANY} />);
    await user.click(trigger());
    expect(await axe(container)).toHaveNoViolations();
  });
});
