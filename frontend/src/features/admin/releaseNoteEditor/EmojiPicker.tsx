import { type KeyboardEvent, useEffect, useId, useRef, useState } from "react";

import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { EMOJI_CATALOG } from "./emojiCatalog";

/** Nombre de colonnes de la grille : ↑/↓ sautent d'autant. */
const COLUMNS = 8;

const ITEMS = EMOJI_CATALOG.flatMap((theme) => theme.items);

/** Rang, dans la grille aplatie, du premier emoji de chaque thème. */
const THEME_OFFSETS = EMOJI_CATALOG.map((_, themeIndex) => EMOJI_CATALOG.slice(0, themeIndex).reduce((sum, theme) => sum + theme.items.length, 0));

/**
 * Le bouton « Emoji » de la barre d'outils et son panneau d'insertion (`role="dialog"`, non
 * modal) : une grille par thème, chaque emoji nommé en français ; à l'ouverture le focus va au
 * premier emoji, les flèches le déplacent (tabindex tournant, 8 colonnes), Début/Fin vont aux
 * bouts, Échap ferme et rend le focus au bouton. Un clic ou Entrée transmet le caractère à
 * `onPick` et ferme le panneau — l'appelant remet le focus dans l'éditeur et insère au curseur.
 *
 * Pas `Listbox`/`Menu` partagés : ce n'est ni un choix de valeur ni un menu d'actions, mais une
 * grille d'insertion propre à l'éditeur de la console.
 */
export function EmojiPicker({ onPick }: { onPick: (char: string) => void }) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const buttonsRef = useRef<(HTMLButtonElement | null)[]>([]);
  const panelId = useId();

  useEffect(() => {
    if (!open) return;
    buttonsRef.current[0]?.focus();
    // Un clic hors du panneau le ferme (sans voler le focus de ce qui a été cliqué).
    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, [open]);

  function toggle() {
    setActive(0);
    setOpen((value) => !value);
  }

  function moveTo(index: number) {
    const next = Math.max(0, Math.min(ITEMS.length - 1, index));
    setActive(next);
    buttonsRef.current[next]?.focus();
  }

  function onGridKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    const moves: Record<string, number> = {
      ArrowRight: active + 1,
      ArrowLeft: active - 1,
      ArrowDown: active + COLUMNS,
      ArrowUp: active - COLUMNS,
      Home: 0,
      End: ITEMS.length - 1,
    };
    if ("Escape" === event.key) {
      event.preventDefault();
      setOpen(false);
      triggerRef.current?.focus();
      return;
    }
    const target = moves[event.key];
    if (undefined === target) return;
    event.preventDefault();
    moveTo(target);
  }

  function pick(char: string) {
    setOpen(false);
    onPick(char);
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        ref={triggerRef}
        type="button"
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        onMouseDown={(event) => event.preventDefault()}
        onClick={toggle}
        className="h-9 rounded px-2 text-sm text-console-text hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-console-accent/40"
      >
        {ADMIN_SECTIONS_TEXTS.releaseNotesEmoji}
      </button>
      {open ? (
        <div
          id={panelId}
          role="dialog"
          aria-label={ADMIN_SECTIONS_TEXTS.releaseNotesEmojiDialog}
          className="absolute left-0 top-full z-20 mt-1 max-h-72 w-80 space-y-2 overflow-y-auto rounded-lg border border-white/15 bg-console-surface p-2 shadow-lg"
        >
          {EMOJI_CATALOG.map((theme, themeIndex) => (
            <div key={theme.theme} role="group" aria-label={theme.theme}>
              <p aria-hidden="true" className="px-1 pb-1 text-xs text-console-text-dim">
                {theme.theme}
              </p>
              <div className="grid grid-cols-8 gap-0.5">
                {theme.items.map((item, itemIndex) => {
                  const position = (THEME_OFFSETS[themeIndex] ?? 0) + itemIndex;
                  return (
                    <button
                      key={item.char}
                      ref={(element) => {
                        buttonsRef.current[position] = element;
                      }}
                      type="button"
                      aria-label={item.label}
                      title={item.label}
                      tabIndex={position === active ? 0 : -1}
                      onFocus={() => setActive(position)}
                      onKeyDown={onGridKeyDown}
                      onClick={() => pick(item.char)}
                      className="flex size-8 items-center justify-center rounded text-lg hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-console-accent/40"
                    >
                      {item.char}
                    </button>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      ) : null}
    </div>
  );
}
