import { AlertTriangle, ChevronDown, Search } from "lucide-react";
import { type ReactNode, useEffect, useId, useLayoutEffect, useRef, useState } from "react";

import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { cn, stripDiacritics } from "@/shared/lib/utils";

/** One selectable row. `value` is opaque (the caller encodes/decodes it). */
export interface ListboxOption {
  value: string;
  label: string;
  /** Leading glyph (aria-hidden). Mutually chosen with `swatch` by the caller. */
  icon?: ReactNode;
  /** Leading colour dot (aria-hidden), rendered via `VenueSwatch`. `null` = neutral dot. */
  swatch?: string | null;
  /** Right-aligned tabular count, e.g. "reste 2 créneaux". */
  count?: string;
  /** Second line: a precision or a reason (also the disabled motive). */
  sub?: string;
  /** Reachable by keyboard but inert: Enter/Space/click are no-ops, list stays open. */
  disabled?: boolean;
  /** Native tooltip on the row. */
  title?: string;
}

/** A titled group of options (APG listbox `role="group"`). */
export interface ListboxGroup {
  id: string | number;
  label: string;
  swatch?: string | null;
  /** Short badge glyph beside the title (e.g. the tier letter). */
  badge?: string;
  icon?: ReactNode;
  options: ListboxOption[];
}

interface ListboxProps {
  value: string;
  onValueChange: (value: string) => void;
  /** Flat list — mutually exclusive with `groups`. */
  options?: ListboxOption[];
  /** Grouped list — takes precedence over `options`. */
  groups?: ListboxGroup[];
  /**
   * Head options rendered before the list (after the selectable placeholder), ALWAYS visible:
   * they escape the search filter and do not count toward the search threshold (they are
   * navigation aids like "Tous les gymnases", not results). See the search decision below.
   */
  leadingOptions?: ListboxOption[];
  placeholder?: string;
  disabled?: boolean;
  /** Tooltip on the trigger's value (falls back to the visible text). */
  title?: string;
  /**
   * Accessible name of the in-panel search field (its `aria-label`, and its visible placeholder).
   * Only surfaces when the search field is shown (≥ 8 real options). Never the placeholder alone
   * (axe accepts a placeholder-only name, an AT does not — AGENTS.md). Default "Rechercher".
   */
  searchLabel?: string;
  id?: string;
  /** Applied to the trigger button (width/height overrides). */
  className?: string;
  autoFocus?: boolean;
  "aria-label"?: string;
  "aria-labelledby"?: string;
  "aria-describedby"?: string;
}

const MAX_LIST_PX = 256; // max-h-64 (16rem)

/** At/above this many REAL options (placeholder + leadingOptions excluded) the panel grows an
 *  in-panel search field. Below it the panel is byte-identical to the pre-P4-198 listbox. */
const SEARCH_THRESHOLD = 8;

const POPUP_FRAME = "absolute z-50 w-full rounded-md border border-border bg-card text-card-foreground shadow";

/**
 * Accessible single-select listbox (APG listbox pattern), built in-house because the
 * project ships no rich-option select. Used where a native `<select>` cannot carry a
 * colour dot, a right-aligned count, a second reason line, or a keyboard-reachable but
 * inert (disabled) option — see `TeamSelect`. The plain native `<select>` (`select.tsx`)
 * stays the house of the ~20 simple pickers (days, statuses, category…).
 *
 * Interaction decisions (this docblock is their single home):
 *
 * - **Roving `tabIndex`, not `aria-activedescendant`** (patron `menu.tsx`): each
 *   `role="option"` carries `tabIndex={-1}` and receives real DOM `focus()`. Arrows /
 *   Home / End / typeahead move focus; disabled options are focusable too (WCAG lets a
 *   listbox expose an unavailable option, and a manager must be able to READ its reason).
 *
 * - **The popover lives in the tree, not a portal**, so it inherits the modal's stacking
 *   and its keydown handling reaches the modal — which forces the next two decisions.
 *
 * - **Escape closes the list only, and `stopPropagation()`s.** The keydown handler is a
 *   NATIVE bubble listener on the popup container (not React's delegated handler) so its
 *   ordering against the modal's own native listener (`useModalA11y.ts`, attached on the
 *   dialog panel) is deterministic: the container is a descendant of the panel, so a native
 *   bubble listener there fires FIRST and `stopPropagation()` keeps the event from reaching
 *   the panel. Without this, Escape inside the list would ALSO close the surrounding modal —
 *   the manager would lose the whole dialog trying to dismiss a dropdown.
 *
 * - **Tab closes without selecting, and lets the event pass.** A listbox is not a menu:
 *   leaving it with Tab must not commit the merely-highlighted option (that is what Enter is
 *   for), and it must not trap focus — Tab has to keep flowing to the next control (the modal
 *   focus-trap then does its job). So Tab restores focus to the trigger, closes, and does NOT
 *   preventDefault/stopPropagation.
 *
 * - **Vertical flip is measured once at open** against the nearest scrollable ancestor (the
 *   modal body), viewport as fallback: open upward only when the space below is too small AND
 *   there is more room above. Recomputed on `resize` while open, never on scroll. In jsdom
 *   every rect is 0, so the flip is inert there and is proven in Playwright instead.
 *
 * - **Search field for long lists (P4-198), NOT an editable combobox.** At ≥ 8 real options
 *   the panel grows a text filter. The trigger stays a real `button[aria-haspopup="listbox"]`
 *   whose accessible name is "label + selected value" (18 test files and screen-reader users
 *   read the current value off it) and the roving-focus model is untouched — an editable
 *   combobox (`role="combobox"` + `aria-activedescendant` on an input) would replace both, for
 *   no a11y gain, so it is deliberately rejected (design pass, ui-ux-pro-max, 2026-09-14). Since
 *   an `<input>` is not a valid child of `role="listbox"`, the panel becomes a wrapper
 *   (`[search] + [div role="listbox"]`); the keydown listener migrates to that wrapper and
 *   guards events coming FROM the field (Space, Home/End, characters stay caret edits; ArrowDown
 *   enters the list; Enter picks the first non-disabled match when a query is typed). Filtering
 *   is accent-insensitive, AND across whitespace tokens, over `label + sub`; the placeholder and
 *   `leadingOptions` never filter out; a fully-filtered group hides its header too; a polite
 *   sr-only region announces the result count and a visible empty state names the query. The
 *   filter clears on every close. Below the threshold the panel is byte-identical (initial focus
 *   on the selected option). jsdom cannot measure this pass beyond the DOM; contrast/reflow stay
 *   in Playwright.
 */
export function Listbox({
  value,
  onValueChange,
  options,
  groups,
  leadingOptions,
  placeholder,
  disabled = false,
  title,
  searchLabel = "Rechercher",
  id,
  className,
  autoFocus,
  "aria-label": ariaLabel,
  "aria-labelledby": ariaLabelledby,
  "aria-describedby": ariaDescribedby,
}: ListboxProps) {
  const [open, setOpen] = useState(false);
  const [dropUp, setDropUp] = useState(false);
  const [query, setQuery] = useState("");
  const triggerRef = useRef<HTMLButtonElement>(null);
  const popupRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const fallbackLabelId = useId();
  const valueId = useId();

  // The label to reference: a caller-provided labelledby wins; otherwise the aria-label is
  // rendered as an sr-only span so the trigger's accessible name can be "label + value"
  // (an aria-label alone on the button would OVERRIDE and hide the read value).
  const labelId = ariaLabelledby ?? (ariaLabel !== undefined ? fallbackLabelId : undefined);
  const triggerLabelledby = [labelId, valueId].filter(Boolean).join(" ") || undefined;

  const realOptions: ListboxOption[] = groups ? groups.flatMap((g) => g.options) : (options ?? []);
  const selected = "" !== value ? realOptions.find((o) => o.value === value) : undefined;
  const hasValue = selected !== undefined;
  const displayText = hasValue ? selected.label : (placeholder ?? "");
  // The placeholder doubles as a selectable leading option (value "") so a picker can be CLEARED —
  // parity with the native <select> this replaces (FfbbEngagements clears a suggestion, Reconciliation
  // « Ne pas créer »). It never appears in the trigger's chosen glyph (that stays the muted empty text).
  const placeholderOption: ListboxOption | null = placeholder !== undefined ? { value: "", label: placeholder } : null;

  // Search: only above the threshold, and only counting REAL options (placeholder + leadingOptions
  // are navigation aids, never results). The query filters `label + sub`, accent-insensitive, AND
  // across whitespace tokens; the placeholder and leadingOptions always stay; empty groups collapse.
  const searchable = realOptions.length >= SEARCH_THRESHOLD;
  const tokens = searchable ? stripDiacritics(query).toLowerCase().trim().split(/\s+/).filter(Boolean) : [];
  const matches = (opt: ListboxOption): boolean => {
    if (0 === tokens.length) {
      return true;
    }
    const hay = stripDiacritics(`${opt.label} ${opt.sub ?? ""}`).toLowerCase();
    return tokens.every((t) => hay.includes(t));
  };
  const filteredGroups = groups?.map((g) => ({ ...g, options: g.options.filter(matches) })).filter((g) => g.options.length > 0);
  const filteredFlat = groups ? undefined : (options ?? []).filter(matches);
  const filteredReal: ListboxOption[] = filteredGroups ? filteredGroups.flatMap((g) => g.options) : (filteredFlat ?? []);
  const resultCount = filteredReal.length;
  const liveText = 0 === tokens.length ? "" : 0 === resultCount ? "Aucun résultat" : `${resultCount} résultat${resultCount > 1 ? "s" : ""}`;

  const optionEls = (): HTMLElement[] => Array.from(popupRef.current?.querySelectorAll<HTMLElement>('[role="option"]') ?? []);

  // Closing always drops the filter (selection, Escape, Tab, outside click, trigger toggle): a
  // reopened picker starts fresh. Runs in event handlers, never in an effect.
  const close = (restoreFocus: boolean) => {
    setQuery("");
    setOpen(false);
    if (restoreFocus) {
      triggerRef.current?.focus();
    }
  };

  const selectEl = (el: HTMLElement) => {
    if ("true" === el.dataset.disabled) {
      return; // reachable, but inert
    }
    onValueChange(el.dataset.value ?? "");
    close(true);
  };

  // Initial focus + scroll-into-view + flip measurement, before paint.
  useLayoutEffect(() => {
    if (!open) {
      return;
    }
    if (searchable) {
      searchRef.current?.focus();
    } else {
      const opts = optionEls();
      const initial = opts.find((o) => o.dataset.value === value) ?? opts.find((o) => "true" !== o.dataset.disabled) ?? opts[0];
      initial?.focus();
      initial?.scrollIntoView?.({ block: "nearest" });
    }

    const trigger = triggerRef.current;
    const popupEl = popupRef.current;
    if (trigger && popupEl) {
      const rect = trigger.getBoundingClientRect();
      const needed = Math.min(popupEl.scrollHeight, MAX_LIST_PX);
      const scroller = scrollableAncestor(trigger);
      const bounds = scroller ? scroller.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
      const below = bounds.bottom - rect.bottom;
      const above = rect.top - bounds.top;
      setDropUp(below < needed && above > below);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  // Outside click + resize (flip recompute), only while open.
  useEffect(() => {
    if (!open) {
      return;
    }
    const onPointer = (e: MouseEvent) => {
      const root = triggerRef.current?.parentElement;
      if (root && !root.contains(e.target as Node)) {
        close(false);
      }
    };
    const onResize = () => {
      const trigger = triggerRef.current;
      const popupEl = popupRef.current;
      if (!trigger || !popupEl) {
        return;
      }
      const rect = trigger.getBoundingClientRect();
      const needed = Math.min(popupEl.scrollHeight, MAX_LIST_PX);
      const scroller = scrollableAncestor(trigger);
      const bounds = scroller ? scroller.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
      setDropUp(bounds.bottom - rect.bottom < needed && rect.top - bounds.top > bounds.bottom - rect.bottom);
    };
    document.addEventListener("mousedown", onPointer);
    window.addEventListener("resize", onResize);
    return () => {
      document.removeEventListener("mousedown", onPointer);
      window.removeEventListener("resize", onResize);
    };
  }, [open]);

  // Keyboard handling as a NATIVE listener on the popup container (see the Escape decision above).
  // When the search field is present it lives inside this container, so events raised there bubble
  // here — hence the `fromField` guard: characters, Space and Home/End must stay caret edits.
  useEffect(() => {
    const popupEl = popupRef.current;
    if (!open || !popupEl) {
      return;
    }
    const onKeyDown = (e: KeyboardEvent) => {
      const opts = optionEls();
      const fromField = null !== searchRef.current && e.target === searchRef.current;
      const idx = opts.indexOf(document.activeElement as HTMLElement);
      switch (e.key) {
        case "Escape":
          e.preventDefault();
          e.stopPropagation();
          close(true);
          return;
        case "Tab":
          // Close without selecting, and let the event pass (no preventDefault).
          close(true);
          return;
        case "ArrowDown":
          e.preventDefault();
          if (fromField) {
            opts[0]?.focus(); // enter the list from the field
            return;
          }
          opts[Math.min(idx + 1, opts.length - 1)]?.focus();
          return;
        case "ArrowUp":
          e.preventDefault();
          if (fromField) {
            opts[opts.length - 1]?.focus();
            return;
          }
          opts[Math.max(idx - 1, 0)]?.focus();
          return;
        case "Home":
          if (fromField) {
            return; // caret to line start
          }
          e.preventDefault();
          opts[0]?.focus();
          return;
        case "End":
          if (fromField) {
            return; // caret to line end
          }
          e.preventDefault();
          opts[opts.length - 1]?.focus();
          return;
        case "Enter":
          e.preventDefault();
          if (fromField) {
            // Decision 4: with a query typed, commit the first non-disabled MATCH (leading
            // rows — placeholder / leadingOptions — are skipped); with no query, do nothing.
            if ((searchRef.current?.value ?? "").trim().length > 0) {
              const first = opts.find((o) => "true" !== o.dataset.leading && "true" !== o.dataset.disabled);
              if (first) {
                selectEl(first);
              }
            }
            return;
          }
          if (idx >= 0) {
            selectEl(opts[idx]);
          }
          return;
        case " ":
          if (fromField) {
            return; // a space in the query
          }
          e.preventDefault();
          if (idx >= 0) {
            selectEl(opts[idx]);
          }
          return;
        default:
          if (fromField) {
            return; // let the character reach the input
          }
          if (1 === e.key.length && !e.ctrlKey && !e.metaKey && !e.altKey) {
            const ch = e.key.toLowerCase();
            for (let i = 1; i <= opts.length; i++) {
              const cand = opts[(idx + i) % opts.length];
              if ((cand.dataset.label ?? "").startsWith(ch)) {
                e.preventDefault();
                cand.focus();
                break;
              }
            }
          }
      }
    };
    popupEl.addEventListener("keydown", onKeyDown);
    return () => popupEl.removeEventListener("keydown", onKeyDown);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const renderOptions = (opts: ListboxOption[]): ReactNode => opts.map((opt) => <Option key={opt.value} opt={opt} selected={opt.value === value} onPick={selectEl} />);

  const hasLeading = placeholderOption !== null || (leadingOptions !== undefined && leadingOptions.length > 0);
  const listContent: ReactNode = (
    <>
      {hasLeading ? (
        <ul role="presentation" className="m-0 list-none p-0">
          {placeholderOption ? <Option opt={placeholderOption} selected={"" === value} onPick={selectEl} leading /> : null}
          {(leadingOptions ?? []).map((opt) => (
            <Option key={opt.value} opt={opt} selected={opt.value === value} onPick={selectEl} leading />
          ))}
        </ul>
      ) : null}
      {filteredGroups
        ? filteredGroups.map((g) => <Group key={g.id} group={g} renderOptions={renderOptions} />)
        : (
            <ul role="presentation" className="m-0 list-none p-0">
              {renderOptions(filteredFlat ?? [])}
            </ul>
          )}
    </>
  );

  return (
    <div className="relative w-full">
      {ariaLabel !== undefined && ariaLabelledby === undefined ? (
        <span id={fallbackLabelId} className="sr-only">
          {ariaLabel}
        </span>
      ) : null}
      <button
        ref={triggerRef}
        type="button"
        id={id}
        // eslint-disable-next-line jsx-a11y/no-autofocus
        autoFocus={autoFocus}
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-labelledby={triggerLabelledby}
        aria-describedby={ariaDescribedby}
        onClick={() => (open ? close(false) : setOpen(true))}
        onKeyDown={(e) => {
          if ("ArrowDown" === e.key || "ArrowUp" === e.key) {
            e.preventDefault();
            setOpen(true);
          }
        }}
        style={{ touchAction: "manipulation" }}
        className={cn(
          "flex h-9 w-full items-center gap-2 rounded-md border border-input bg-background px-3 text-left text-sm outline-none focus:ring-2 focus:ring-ring",
          disabled && "cursor-not-allowed opacity-50",
          className,
        )}
      >
        {hasValue ? <Glyph icon={selected.icon} swatch={selected.swatch} /> : null}
        <span id={valueId} title={title ?? displayText} className={cn("min-w-0 flex-1 truncate", !hasValue && "text-muted-foreground")}>
          {displayText}
        </span>
        <ChevronDown aria-hidden className="size-4 shrink-0 text-muted-foreground" />
      </button>
      {open
        ? searchable
          ? (
              <div ref={popupRef} className={cn(POPUP_FRAME, dropUp ? "bottom-full mb-1" : "top-full mt-1")}>
                <div className="border-b border-border p-1">
                  <div className="flex items-center gap-2 rounded-sm bg-background px-2 focus-within:ring-2 focus-within:ring-ring">
                    <Search aria-hidden className="size-4 shrink-0 text-muted-foreground" />
                    <input
                      ref={searchRef}
                      type="text"
                      value={query}
                      onChange={(e) => setQuery(e.target.value)}
                      aria-label={searchLabel}
                      placeholder={searchLabel}
                      className="h-8 w-full border-0 bg-transparent p-0 text-sm outline-none placeholder:text-muted-foreground"
                    />
                  </div>
                </div>
                <div role="listbox" tabIndex={-1} aria-labelledby={labelId} className="max-h-64 overflow-y-auto py-1">
                  {listContent}
                </div>
                <span aria-live="polite" className="sr-only">
                  {liveText}
                </span>
                {tokens.length > 0 && 0 === resultCount ? <div className="px-3 py-2 text-sm text-muted-foreground">{`Aucun résultat pour « ${query} »`}</div> : null}
              </div>
            )
          : (
              <div ref={popupRef} role="listbox" tabIndex={-1} aria-labelledby={labelId} className={cn(POPUP_FRAME, "max-h-64 overflow-y-auto py-1", dropUp ? "bottom-full mb-1" : "top-full mt-1")}>
                {listContent}
              </div>
            )
        : null}
    </div>
  );
}

function scrollableAncestor(el: HTMLElement): HTMLElement | null {
  let node = el.parentElement;
  while (node) {
    const style = window.getComputedStyle(node);
    if (/(auto|scroll|overlay)/.test(style.overflowY) && node.scrollHeight > node.clientHeight) {
      return node;
    }
    node = node.parentElement;
  }
  return null;
}

function Glyph({ icon, swatch }: { icon?: ReactNode; swatch?: string | null }) {
  if (icon !== undefined) {
    return (
      <span aria-hidden className="flex size-5 shrink-0 items-center justify-center [&_svg]:size-4 [&_svg]:text-muted-foreground">
        {icon}
      </span>
    );
  }
  if (swatch !== undefined) {
    return <VenueSwatch color={swatch} className="size-2.5" />;
  }
  return null;
}

function Group({ group, renderOptions }: { group: ListboxGroup; renderOptions: (opts: ListboxOption[]) => ReactNode }) {
  const titleId = useId();
  return (
    <ul role="group" aria-labelledby={titleId} className="m-0 list-none p-0">
      <li role="presentation" id={titleId} className="flex items-center gap-1.5 px-3 pb-0.5 pt-1.5 text-xs font-semibold text-muted-foreground">
        <Glyph icon={group.icon} swatch={group.swatch} />
        {/* Badge + label share ONE flow so the accessible name reads "S Fanion" (a real space between). */}
        <span>
          {group.badge !== undefined ? <span className="font-semibold text-foreground">{group.badge}</span> : null}
          {group.badge !== undefined ? " " : ""}
          {group.label}
        </span>
      </li>
      {renderOptions(group.options)}
    </ul>
  );
}

function Option({ opt, selected, onPick, leading }: { opt: ListboxOption; selected: boolean; onPick: (el: HTMLElement) => void; leading?: boolean }) {
  const labelId = useId();
  const countId = useId();
  const subId = useId();
  const describedBy = [opt.count !== undefined ? countId : null, opt.sub !== undefined ? subId : null].filter(Boolean).join(" ") || undefined;

  return (
    // Keyboard (select/roam/typeahead) is handled by the listbox container's roving handler, not
    // per-option — hence no per-element key handler. See the component docblock.
    // eslint-disable-next-line jsx-a11y/click-events-have-key-events
    <li
      role="option"
      tabIndex={-1}
      data-value={opt.value}
      data-label={opt.label.toLowerCase()}
      data-disabled={opt.disabled ? "true" : undefined}
      // `leading` (placeholder / leadingOptions) is skipped by the search field's Enter shortcut,
      // which commits the first real MATCH, never a navigation aid.
      data-leading={leading ? "true" : undefined}
      aria-selected={selected}
      aria-disabled={opt.disabled ? true : undefined}
      aria-labelledby={labelId}
      aria-describedby={describedBy}
      title={opt.title}
      onClick={(e) => onPick(e.currentTarget)}
      className={cn(
        "grid min-h-11 cursor-pointer grid-cols-[20px_1fr_auto] items-center gap-x-2 px-3 py-1.5 outline-none hover:bg-muted focus:bg-muted",
        opt.disabled && "cursor-not-allowed opacity-50",
      )}
    >
      <span aria-hidden className="flex size-5 items-center justify-center [&_svg]:size-4">
        {opt.disabled ? <AlertTriangle className="text-warning" /> : <Glyph icon={opt.icon} swatch={opt.swatch} />}
      </span>
      <span className="min-w-0">
        {/* Selection is conveyed by aria-selected + a heavier weight — NOT `text-accent`: as text on
            the `bg-muted` highlight it drops to ~4.35:1 (< AA 4.5), AGENTS.md gotcha #11. */}
        <span id={labelId} className={cn("block truncate", selected ? "font-semibold" : "font-medium")}>
          {opt.label}
        </span>
        {opt.sub !== undefined ? (
          <span id={subId} className="block text-xs text-foreground">
            {opt.sub}
          </span>
        ) : null}
      </span>
      {opt.count !== undefined ? (
        <span id={countId} className="text-xs tabular-nums text-foreground">
          {opt.count}
        </span>
      ) : null}
    </li>
  );
}
