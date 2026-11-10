import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { EMOJI_CATALOG } from "./emojiCatalog";
import { EmojiPicker } from "./EmojiPicker";

/**
 * Promesse (Actualités PR B, B6 — décision fondateur) : **la superadmin insère un emoji depuis la
 * barre d'outils, au clavier comme à la souris** : un bouton « Emoji » qui annonce son panneau, une
 * grille par thème où chaque emoji porte un nom accessible FR unique, les flèches qui déplacent le
 * focus (tabindex tournant, 8 colonnes), Échap qui rend le focus au bouton, Entrée ou clic qui
 * transmet le caractère et ferme le panneau.
 *
 * L'insertion AU CURSEUR dans le document est prouvée par `ReleaseNoteEditor.test.tsx` ; que chaque
 * caractère du catalogue passe le validateur serveur, par `editorOutput.test.ts` (fixture
 * `emoji-catalogue.json`) + `backend/tests/CrossStack/ReleaseNoteRichDocEditorFixturesTest.php`.
 */

const ALL_ITEMS = EMOJI_CATALOG.flatMap((theme) => theme.items);

function basketLabel(): string {
  const basket = ALL_ITEMS.find((item) => item.char === "\u{1F3C0}");
  expect(basket, "le catalogue contient le ballon de basket").toBeDefined();
  return (basket as { label: string }).label;
}

/** Les boutons d'emoji de la grille (dans les groupes de thèmes), dans l'ordre de lecture. */
function emojiButtons(dialog: HTMLElement): HTMLElement[] {
  return within(dialog).getAllByRole("group").flatMap((group) => within(group).getAllByRole("button"));
}

async function openPicker(onPick = vi.fn()) {
  const user = userEvent.setup();
  render(<EmojiPicker onPick={onPick} />);
  const trigger = screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesEmoji });
  await user.click(trigger);
  const dialog = screen.getByRole("dialog", { name: ADMIN_SECTIONS_TEXTS.releaseNotesEmojiDialog });
  return { user, trigger, dialog, onPick };
}

describe("emojiCatalog — catalogue interne curaté", () => {
  it("6 thèmes, environ 80 emoji, ballon de basket compris", () => {
    expect(EMOJI_CATALOG).toHaveLength(6);
    expect(ALL_ITEMS.length).toBeGreaterThanOrEqual(60);
    expect(ALL_ITEMS.length).toBeLessThanOrEqual(120);
    expect(ALL_ITEMS.map((item) => item.char)).toContain("\u{1F3C0}");
  });

  it("chaque emoji est unique et porte un libellé FR non vide et unique (son nom accessible)", () => {
    const chars = ALL_ITEMS.map((item) => item.char);
    const labels = ALL_ITEMS.map((item) => item.label.trim());
    expect(new Set(chars).size).toBe(chars.length);
    expect(labels.every((label) => label.length > 0)).toBe(true);
    expect(new Set(labels).size).toBe(labels.length);
    expect(EMOJI_CATALOG.every((theme) => theme.theme.trim().length > 0 && theme.items.length > 0)).toBe(true);
  });
});

describe("EmojiPicker — accessibilité et clavier", () => {
  it("le bouton « Emoji » annonce son panneau (aria-haspopup=dialog, aria-expanded)", async () => {
    const user = userEvent.setup();
    render(<EmojiPicker onPick={vi.fn()} />);
    const trigger = screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesEmoji });

    expect(trigger).toHaveAttribute("aria-haspopup", "dialog");
    expect(trigger).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();

    await user.click(trigger);
    expect(trigger).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("dialog", { name: ADMIN_SECTIONS_TEXTS.releaseNotesEmojiDialog })).toBeInTheDocument();
  });

  it("le panneau groupe la grille par thème, chaque bouton nommé, et donne le focus au premier emoji", async () => {
    const { dialog } = await openPicker();

    const groups = within(dialog).getAllByRole("group");
    expect(groups).toHaveLength(EMOJI_CATALOG.length);
    expect(groups.every((group) => (group.getAttribute("aria-label") ?? "").trim().length > 0)).toBe(true);

    const buttons = emojiButtons(dialog);
    expect(buttons).toHaveLength(ALL_ITEMS.length);
    expect(buttons.map((button) => button.getAttribute("aria-label"))).toEqual(ALL_ITEMS.map((item) => item.label));
    expect(buttons.every((button) => button.getAttribute("type") === "button")).toBe(true);
    expect(buttons[0]).toHaveFocus();
    // Tabindex tournant : un seul emoji dans l'ordre de tabulation.
    expect(buttons.filter((button) => button.getAttribute("tabindex") === "0")).toHaveLength(1);
  });

  it("les flèches déplacent le focus (→ suivant, ← précédent, ↓ 8 plus loin, Fin/Début)", async () => {
    const { user, dialog } = await openPicker();
    const buttons = emojiButtons(dialog);

    await user.keyboard("{ArrowRight}");
    expect(buttons[1]).toHaveFocus();
    expect(buttons[1]).toHaveAttribute("tabindex", "0");
    await user.keyboard("{ArrowLeft}");
    expect(buttons[0]).toHaveFocus();
    await user.keyboard("{ArrowDown}");
    expect(buttons[8]).toHaveFocus();
    await user.keyboard("{ArrowUp}");
    expect(buttons[0]).toHaveFocus();
    await user.keyboard("{End}");
    expect(buttons[buttons.length - 1]).toHaveFocus();
    await user.keyboard("{Home}");
    expect(buttons[0]).toHaveFocus();
  });

  it("Échap ferme le panneau et rend le focus au bouton « Emoji »", async () => {
    const { user, trigger, onPick } = await openPicker();

    await user.keyboard("{Escape}");

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
    expect(trigger).toHaveAttribute("aria-expanded", "false");
    expect(onPick).not.toHaveBeenCalled();
  });

  it("Entrée sur « ballon de basket » transmet 🏀 une fois et ferme le panneau", async () => {
    const { user, dialog, onPick } = await openPicker();

    within(dialog).getByRole("button", { name: basketLabel() }).focus();
    await user.keyboard("{Enter}");

    expect(onPick).toHaveBeenCalledTimes(1);
    expect(onPick).toHaveBeenCalledWith("\u{1F3C0}");
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("un clic transmet le caractère et ferme le panneau", async () => {
    const { user, dialog, onPick } = await openPicker();

    await user.click(within(dialog).getByRole("button", { name: basketLabel() }));

    expect(onPick).toHaveBeenCalledTimes(1);
    expect(onPick).toHaveBeenCalledWith("\u{1F3C0}");
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });
});
