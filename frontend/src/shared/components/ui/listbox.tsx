import { AlertTriangle, ChevronDown } from "lucide-react";
import { type ReactNode, useEffect, useId, useLayoutEffect, useRef, useState } from "react";

import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { cn } from "@/shared/lib/utils";

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
  placeholder?: string;
  disabled?: boolean;
  /** Tooltip on the trigger's value (falls back to the visible text). */
  title?: string;
  id?: string;
  /** Applied to the trigger button (width/height overrides). */
  className?: string;
  autoFocus?: boolean;
  "aria-label"?: string;
  "aria-labelledby"?: string;
  "aria-describedby"?: string;
}

const MAX_LIST_PX = 256; // max-h-64 (16rem)

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
 *   NATIVE bubble listener on the listbox container (not React's delegated handler) so its
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
 */
export function Listbox({
  value,
  onValueChange,
  options,
  groups,
  placeholder,
  disabled = false,
  title,
  id,
  className,
  autoFocus,
  "aria-label": ariaLabel,
  "aria-labelledby": ariaLabelledby,
  "aria-describedby": ariaDescribedby,
}: ListboxProps) {
  const [open, setOpen] = useState(false);
  const [dropUp, setDropUp] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const listRef = useRef<HTMLDivElement>(null);
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
  const leading: ListboxOption | null = placeholder !== undefined ? { value: "", label: placeholder } : null;

  const optionEls = (): HTMLElement[] => Array.from(listRef.current?.querySelectorAll<HTMLElement>('[role="option"]') ?? []);

  const close = (restoreFocus: boolean) => {
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
    const opts = optionEls();
    const initial = opts.find((o) => o.dataset.value === value) ?? opts.find((o) => "true" !== o.dataset.disabled) ?? opts[0];
    initial?.focus();
    initial?.scrollIntoView?.({ block: "nearest" });

    const trigger = triggerRef.current;
    const listEl = listRef.current;
    if (trigger && listEl) {
      const rect = trigger.getBoundingClientRect();
      const needed = Math.min(listEl.scrollHeight, MAX_LIST_PX);
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
        setOpen(false);
      }
    };
    const onResize = () => {
      const trigger = triggerRef.current;
      const listEl = listRef.current;
      if (!trigger || !listEl) {
        return;
      }
      const rect = trigger.getBoundingClientRect();
      const needed = Math.min(listEl.scrollHeight, MAX_LIST_PX);
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

  // Keyboard handling as a NATIVE listener on the container (see the Escape decision above).
  useEffect(() => {
    const listEl = listRef.current;
    if (!open || !listEl) {
      return;
    }
    const onKeyDown = (e: KeyboardEvent) => {
      const opts = optionEls();
      if (0 === opts.length) {
        return;
      }
      const idx = opts.indexOf(document.activeElement as HTMLElement);
      switch (e.key) {
        case "Escape":
          e.preventDefault();
          e.stopPropagation();
          close(true);
          return;
        case "Tab":
          // Close without selecting, and let the event pass (no preventDefault).
          triggerRef.current?.focus();
          setOpen(false);
          return;
        case "ArrowDown":
          e.preventDefault();
          opts[Math.min(idx + 1, opts.length - 1)]?.focus();
          return;
        case "ArrowUp":
          e.preventDefault();
          opts[Math.max(idx - 1, 0)]?.focus();
          return;
        case "Home":
          e.preventDefault();
          opts[0]?.focus();
          return;
        case "End":
          e.preventDefault();
          opts[opts.length - 1]?.focus();
          return;
        case "Enter":
        case " ":
          e.preventDefault();
          if (idx >= 0) {
            selectEl(opts[idx]);
          }
          return;
        default:
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
    listEl.addEventListener("keydown", onKeyDown);
    return () => listEl.removeEventListener("keydown", onKeyDown);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const renderOptions = (opts: ListboxOption[]): ReactNode => opts.map((opt) => <Option key={opt.value} opt={opt} selected={opt.value === value} onPick={selectEl} />);

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
        onClick={() => setOpen((o) => !o)}
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
      {open ? (
        <div
          ref={listRef}
          role="listbox"
          tabIndex={-1}
          aria-labelledby={labelId}
          className={cn(
            "absolute z-50 max-h-64 w-full overflow-y-auto rounded-md border border-border bg-card py-1 text-card-foreground shadow",
            dropUp ? "bottom-full mb-1" : "top-full mt-1",
          )}
        >
          {leading ? (
            <ul role="presentation" className="m-0 list-none p-0">
              {renderOptions([leading])}
            </ul>
          ) : null}
          {groups
            ? groups.map((g) => <Group key={g.id} group={g} renderOptions={renderOptions} />)
            : (
                <ul role="presentation" className="m-0 list-none p-0">
                  {renderOptions(options ?? [])}
                </ul>
              )}
        </div>
      ) : null}
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

function Option({ opt, selected, onPick }: { opt: ListboxOption; selected: boolean; onPick: (el: HTMLElement) => void }) {
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
        <span id={labelId} className={cn("block truncate font-medium", selected && "text-accent")}>
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
