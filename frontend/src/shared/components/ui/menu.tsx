import { createContext, type ReactNode, type RefObject, useContext, useEffect, useId, useRef, useState } from "react";
import { NavLink } from "react-router";

import { cn } from "@/shared/lib/utils";

const MenuCloseContext = createContext<() => void>(() => {});

interface MenuProps {
  /** Accessible label for the trigger button (e.g. "Menu du compte"). */
  label: string;
  /** Trigger content (usually an icon). */
  trigger: ReactNode;
  children: ReactNode;
  className?: string;
  /**
   * Overrides the trigger's SIZING (default `size-10`, meant for icon-only
   * triggers). A text trigger (season switcher) needs an auto width — without
   * it the label overflows the fixed 40px box and overlaps its neighbours.
   */
  triggerClassName?: string;
  /**
   * APG menu button: activating an item returns focus to the trigger (like
   * Escape). Default `true`. Set `false` only when the selection deliberately
   * moves focus elsewhere and the trigger will be unmounted (a caller that then
   * focuses the replacement itself).
   */
  restoreFocusOnSelect?: boolean;
  /**
   * Optional forwarded ref to the trigger button — lets a caller focus the
   * trigger after it re-renders (e.g. a status pill that becomes a « Traiter »
   * button once its resolution is cleared). Merged with the internal ref used
   * for focus restoration.
   */
  triggerRef?: RefObject<HTMLButtonElement | null>;
}

const ITEM_SELECTOR = '[role="menuitem"]';

/**
 * Accessible dropdown menu (APG menu-button pattern, kept minimal — the
 * project ships no dropdown primitive). Opens focusing the first item;
 * Arrow Up/Down roam the items; Escape or Tab close and restore focus to the
 * trigger; an outside click closes. No external dependency.
 */
export function Menu({ label, trigger, children, className, triggerClassName, restoreFocusOnSelect = true, triggerRef: externalTriggerRef }: MenuProps) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const menuId = useId();

  // Merge the internal trigger ref (focus restoration) with the caller's, if any.
  const setTriggerNode = (node: HTMLButtonElement | null): void => {
    triggerRef.current = node;
    if (undefined !== externalTriggerRef) {
      externalTriggerRef.current = node;
    }
  };

  const close = (restoreFocus = false) => {
    setOpen(false);
    if (restoreFocus) {
      triggerRef.current?.focus();
    }
  };

  // Move focus into the panel when it opens.
  useEffect(() => {
    if (open) {
      panelRef.current?.querySelector<HTMLElement>(ITEM_SELECTOR)?.focus();
    }
  }, [open]);

  // Close on outside click while open.
  useEffect(() => {
    if (!open) {
      return;
    }
    const onPointer = (e: MouseEvent) => {
      if (null !== rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", onPointer);
    return () => document.removeEventListener("mousedown", onPointer);
  }, [open]);

  const items = (): HTMLElement[] => Array.from(panelRef.current?.querySelectorAll<HTMLElement>(ITEM_SELECTOR) ?? []);

  const onPanelKeyDown = (e: React.KeyboardEvent) => {
    if ("Escape" === e.key) {
      e.preventDefault();
      close(true);
      return;
    }
    if ("Tab" === e.key) {
      // Leaving the menu with the keyboard closes it (no focus trap).
      close(false);
      return;
    }
    if ("ArrowDown" === e.key || "ArrowUp" === e.key) {
      e.preventDefault();
      const list = items();
      if (0 === list.length) {
        return;
      }
      const idx = list.indexOf(document.activeElement as HTMLElement);
      const next = "ArrowDown" === e.key ? (idx + 1) % list.length : (idx - 1 + list.length) % list.length;
      list[next]?.focus();
    }
  };

  return (
    <div ref={rootRef} className={cn("relative", className)}>
      <button
        ref={setTriggerNode}
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => setOpen((v) => !v)}
        className={cn(
          "inline-flex items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background [&_svg]:size-5",
          triggerClassName ?? "size-10",
        )}
      >
        {trigger}
      </button>
      {open ? (
        <div
          ref={panelRef}
          id={menuId}
          role="menu"
          tabIndex={-1}
          aria-label={label}
          onKeyDown={onPanelKeyDown}
          className="absolute right-0 z-50 mt-1 min-w-44 rounded-md border border-border bg-background p-1 shadow-lg"
        >
          <MenuCloseContext.Provider value={() => close(restoreFocusOnSelect)}>{children}</MenuCloseContext.Provider>
        </div>
      ) : null}
    </div>
  );
}

interface MenuItemBaseProps {
  icon?: ReactNode;
  children: ReactNode;
  className?: string;
}

interface MenuActionProps extends MenuItemBaseProps {
  onSelect?: () => void;
  /** Non-interactive row (button only): dimmed, no onSelect, does not close the menu. */
  disabled?: boolean;
  to?: undefined;
}

interface MenuLinkProps extends MenuItemBaseProps {
  /** Render as a NavLink (active route → aria-current) instead of a button. */
  to: string;
  onSelect?: undefined;
}

const ITEM_CLASS =
  "flex w-full items-center gap-2 rounded-sm px-2.5 py-1.5 text-left text-sm text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:bg-muted aria-[current=page]:bg-muted aria-[current=page]:font-medium [&_svg]:size-4 [&_svg]:shrink-0 [&_svg]:text-muted-foreground";

/** A row inside a Menu — a button (onSelect) or, with `to`, a NavLink. Closing the menu is handled here. */
export function MenuItem({ icon, children, className, ...rest }: MenuActionProps | MenuLinkProps) {
  const close = useContext(MenuCloseContext);

  if (undefined !== rest.to) {
    return (
      <NavLink to={rest.to} end role="menuitem" onClick={() => close()} className={cn(ITEM_CLASS, className)}>
        {icon}
        {children}
      </NavLink>
    );
  }

  return (
    <button
      type="button"
      role="menuitem"
      disabled={rest.disabled}
      onClick={() => {
        rest.onSelect?.();
        close();
      }}
      className={cn(ITEM_CLASS, "disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent", className)}
    >
      {icon}
      {children}
    </button>
  );
}
