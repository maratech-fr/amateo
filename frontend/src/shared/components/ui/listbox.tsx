import { AlertTriangle, ChevronDown, Search } from "lucide-react";
import { type CSSProperties, type ReactNode, useEffect, useId, useLayoutEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

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

/** Gap between the trigger and the panel (the former `mt-1`), in px. */
const POPUP_GAP_PX = 4;

/** `fixed` + `z-[100]`: the panel is portaled to `document.body` and must sit above a modal
 *  (`z-[90]`, `modal.tsx`) — the same layer as the toaster. Width/position come from the
 *  trigger's rect (inline style), never from a CSS parent. */
const POPUP_FRAME = "fixed z-[100] rounded-md border border-border bg-card text-card-foreground shadow";

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
 * - **The panel is PORTALED to `document.body`, in `position: fixed`** — it overflows a modal
 *   or any scrolling ancestor exactly like a native `<select>` does. It used to live in the
 *   tree (`absolute` under the trigger), which made it inherit every ancestor's `overflow`:
 *   inside the short « Accès match » modal (9 venues → search field) the panel was born in an
 *   almost-empty `overflow-y-auto` body and got clipped — the manager had to scroll the modal
 *   to scroll the list (founder, 2026-09-14: « c'est ridicule »). Consequences, all handled
 *   here: the trigger links the detached panel with `aria-controls`; the outside-click test
 *   counts the panel as « inside » and a `mousedown` in the panel never reaches `document`
 *   (a host with its own outside-click — `menu.tsx`, `ExportMenu` — must not close); stacking is explicit (`z-[100]`, above `modal.tsx`'s
 *   `z-[90]`); position/width are re-measured from the trigger's rect on `resize` and on any
 *   `scroll` (capture) while open. Since the panel is no longer a DOM descendant of the modal
 *   panel, the modal's native keydown listener (`useModalA11y.ts`) never sees the list's keys:
 *   Escape/Tab below are decided solely here.
 *
 * - **Escape closes the list only, and `stopPropagation()`s.** The keydown handler is a
 *   NATIVE listener on the panel container (not React's delegated handler — a React synthetic
 *   event would bubble through the portal to the trigger's React ancestors). Escape must never
 *   ALSO close the surrounding modal — the manager would lose the whole dialog trying to
 *   dismiss a dropdown.
 *
 * - **Tab closes without selecting, and lets the event pass.** A listbox is not a menu:
 *   leaving it with Tab must not commit the merely-highlighted option (that is what Enter is
 *   for), and it must not trap focus. Tab restores focus to the trigger BEFORE the default
 *   action runs, so sequential navigation continues from the trigger — inside the host dialog.
 *   (Known edge: the modal focus-trap does not see this keydown; a trigger that is the LAST
 *   focusable of a dialog would let Tab leave it. No such dialog exists — every modal ends
 *   with its footer buttons.)
 *
 * - **Vertical flip is measured at open against the VIEWPORT** (fixed positioning escapes
 *   every scroll container, so the viewport is the only bound that matters): open upward only
 *   when the space below is too small AND there is more room above. Recomputed on `resize`
 *   and `scroll` while open. In jsdom every rect is 0, so the flip is inert there and is
 *   proven in Playwright instead (`listbox.spec.ts`).
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
  // Inline geometry of the portaled panel (left/width from the trigger, top OR bottom by flip).
  const [popupStyle, setPopupStyle] = useState<CSSProperties>({});
  const [query, setQuery] = useState("");
  const triggerRef = useRef<HTMLButtonElement>(null);
  const popupRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const fallbackLabelId = useId();
  const valueId = useId();
  const popupId = useId();

  // Place the portaled panel under (or above) the trigger, from its viewport rect. Called
  // before paint at open, then on resize/scroll while open. Pure DOM measurement — inert in
  // jsdom (every rect is 0 → `top: 4px`).
  const place = (): void => {
    const trigger = triggerRef.current;
    const popupEl = popupRef.current;
    if (!trigger || !popupEl) {
      return;
    }
    const rect = trigger.getBoundingClientRect();
    const needed = popupEl.offsetHeight || Math.min(popupEl.scrollHeight, MAX_LIST_PX);
    const below = window.innerHeight - rect.bottom;
    const above = rect.top;
    const dropUp = below < needed && above > below;
    setPopupStyle({
      left: rect.left,
      width: rect.width,
      ...(dropUp ? { bottom: window.innerHeight - rect.top + POPUP_GAP_PX } : { top: rect.bottom + POPUP_GAP_PX }),
    });
  };

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

    place();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  // Outside click + resize/scroll (re-placement), only while open. The portaled panel is NOT a
  // DOM descendant of the trigger's root: a click inside it (search field, option) must count
  // as « inside ». Scroll is listened in capture so any scrolling ancestor re-anchors the panel.
  useEffect(() => {
    if (!open) {
      return;
    }
    const onPointer = (e: MouseEvent) => {
      const root = triggerRef.current?.parentElement;
      const target = e.target as Node;
      if (root && !root.contains(target) && !popupRef.current?.contains(target)) {
        close(false);
      }
    };
    document.addEventListener("mousedown", onPointer);
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, true);
    return () => {
      document.removeEventListener("mousedown", onPointer);
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
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
    // A mousedown INSIDE the portaled panel stops here: the panel is not a DOM descendant of
    // the host that opened it, so any document-level « click outside » listener (`menu.tsx`,
    // `ExportMenu.tsx`…) would otherwise read a click on an option as a click OUTSIDE its root
    // and close the host under the manager's hand. `click` is left alone (the options pick on
    // React `onClick`, delivered through the portal container).
    const onMouseDown = (e: MouseEvent) => e.stopPropagation();
    popupEl.addEventListener("keydown", onKeyDown);
    popupEl.addEventListener("mousedown", onMouseDown);
    return () => {
      popupEl.removeEventListener("keydown", onKeyDown);
      popupEl.removeEventListener("mousedown", onMouseDown);
    };
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
    <div className="w-full">
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
        aria-controls={open ? popupId : undefined}
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
        ? createPortal(
          searchable
          ? (
              <div ref={popupRef} id={popupId} style={popupStyle} className={POPUP_FRAME}>
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
              <div ref={popupRef} id={popupId} style={popupStyle} role="listbox" tabIndex={-1} aria-labelledby={labelId} className={cn(POPUP_FRAME, "max-h-64 overflow-y-auto py-1")}>
                {listContent}
              </div>
            ),
          document.body,
        )
        : null}
    </div>
  );
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
