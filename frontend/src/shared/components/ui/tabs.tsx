import { type KeyboardEvent, type ReactNode, useRef } from "react";

import type { SurfaceSkin } from "@/shared/lib/surfaceSkin";
import { cn } from "@/shared/lib/utils";

export interface TabItem {
  id: string;
  label: string;
  icon?: React.ComponentType<{ className?: string; "aria-hidden"?: boolean }>;
}

/**
 * Deux PEAUX, un seul comportement. La console superadmin est sombre (`slate-950`,
 * `cyan-300`), l'application club suit les tokens du thème. Extraire le composant sans
 * cette distinction aurait repeint la console — on déplace la logique ARIA, pas le style.
 * La notion de peau elle-même vit dans `shared/lib/surfaceSkin.ts` (`SurfaceSkin`), pas
 * ici : elle est partagée avec les autres primitives à double peau (états vides…).
 */
const TAB_SKINS: Record<SurfaceSkin, { list: string; base: string; active: string; idle: string; panel: string }> = {
  console: {
    list: "border-white/10",
    base: "focus-visible:ring-console-accent/40 focus-visible:ring-offset-console-surface",
    active: "border-console-accent text-white",
    idle: "border-transparent text-console-text-dim hover:text-white hover:border-white/10",
    panel: "focus-visible:ring-console-accent/20",
  },
  app: {
    list: "border-border",
    base: "focus-visible:ring-ring/40 focus-visible:ring-offset-background",
    active: "border-accent font-medium text-foreground",
    idle: "border-transparent text-muted-foreground hover:border-border hover:text-foreground",
    panel: "focus-visible:ring-ring/20",
  },
};

export interface TabsProps {
  tabs: TabItem[];
  activeTab: string;
  onTabChange: (id: string) => void;
  ariaLabel: string;
  /** Prefix for tab/panel IDs — must be unique per tablist (avoids collisions with nested sub-tabs). */
  idPrefix: string;
  /** Peau : `app` par DÉFAUT (l'application club) ; la console superadmin doit passer
   *  `console` explicitement — l'oublier peint des tokens clairs sur fond sombre. */
  variant?: SurfaceSkin;
}

/**
 * WAI-ARIA tabs pattern with roving tabindex + automatic activation.
 *
 * - `role="tablist"` on the container, `role="tab"` on each button.
 * - Active tab has `tabIndex={0}`, others `-1` (roving tabindex).
 * - Arrow Left/Right move focus AND activate (circular), Home/End jump to first/last.
 * - Enter/Space activates the focused tab (redundant with auto-activation, but
 *   required by the WAI-ARIA spec for the manual-activation fallback).
 * - Tab key exits to the panel (browser default with roving tabindex).
 */
export function Tabs({ tabs, activeTab, onTabChange, ariaLabel, idPrefix, variant = "app" }: TabsProps) {
  const skin = TAB_SKINS[variant];
  const refs = useRef<Record<string, HTMLButtonElement | null>>({});

  const activeIndex = Math.max(
    0,
    tabs.findIndex((t) => t.id === activeTab),
  );

  function activateAndFocus(index: number) {
    const tab = tabs[index];
    if (!tab) return;
    onTabChange(tab.id);
    refs.current[tab.id]?.focus();
  }

  function onKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    const lastIndex = tabs.length - 1;

    switch (event.key) {
      case "ArrowRight":
        event.preventDefault();
        activateAndFocus(activeIndex + 1 > lastIndex ? 0 : activeIndex + 1);
        break;
      case "ArrowLeft":
        event.preventDefault();
        activateAndFocus(activeIndex - 1 < 0 ? lastIndex : activeIndex - 1);
        break;
      case "Home":
        event.preventDefault();
        activateAndFocus(0);
        break;
      case "End":
        event.preventDefault();
        activateAndFocus(lastIndex);
        break;
      case "Enter":
      case " ":
        event.preventDefault();
        onTabChange(tabs[activeIndex].id);
        break;
    }
  }

  return (
    <div role="tablist" aria-label={ariaLabel} className={cn("flex flex-wrap gap-1 border-b", skin.list)}>
      {tabs.map((tab) => {
        const isActive = tab.id === activeTab;
        const Icon = tab.icon;
        return (
          <button
            key={tab.id}
            ref={(el) => {
              refs.current[tab.id] = el;
            }}
            type="button"
            role="tab"
            id={`${idPrefix}-tab-${tab.id}`}
            aria-controls={`${idPrefix}-panel-${tab.id}`}
            aria-selected={isActive}
            tabIndex={isActive ? 0 : -1}
            onClick={() => onTabChange(tab.id)}
            onKeyDown={onKeyDown}
            className={cn(
              "flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2",
              skin.base,
              isActive ? skin.active : skin.idle,
            )}
          >
            {Icon ? <Icon className="size-4" aria-hidden={true} /> : null}
            {tab.label}
          </button>
        );
      })}
    </div>
  );
}

export interface TabPanelProps {
  tabId: string;
  idPrefix: string;
  active: boolean;
  children: ReactNode;
  /** Optional label for screen readers when the panel has no visible heading. */
  ariaLabel?: string;
  className?: string;
  variant?: SurfaceSkin;
}

export function TabPanel({ tabId, idPrefix, active, children, ariaLabel, className, variant = "app" }: TabPanelProps) {
  return (
    <div
      role="tabpanel"
      id={`${idPrefix}-panel-${tabId}`}
      aria-labelledby={`${idPrefix}-tab-${tabId}`}
      aria-label={ariaLabel}
      hidden={!active}
      tabIndex={active ? 0 : -1}
      className={cn("focus-visible:outline-none focus-visible:ring-2", TAB_SKINS[variant].panel, className)}
    >
      {children}
    </div>
  );
}