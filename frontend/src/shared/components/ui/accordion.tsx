import { ChevronDown } from "lucide-react";
import { type ReactNode, useId, useState } from "react";

import { cn } from "@/shared/lib/utils";

interface AccordionSectionProps {
  title: ReactNode;
  /** Open on first render. Uncontrolled thereafter (ignored when `open` is set). */
  defaultOpen?: boolean;
  /**
   * Controlled mode (opt-in, backward compatible): when provided, the section
   * reflects `open` and reports intent through `onToggle` instead of holding its
   * own state — for a caller that mirrors the open one in the URL (`?equipe=`).
   * Omit both to keep the uncontrolled behaviour.
   */
  open?: boolean;
  onToggle?: (next: boolean) => void;
  children: ReactNode;
  className?: string;
}

/**
 * Reusable collapsible section: a header button toggles the body.
 * aria-expanded + aria-controls wire the header to its region.
 */
export function AccordionSection({ title, defaultOpen = false, open: controlledOpen, onToggle, children, className }: AccordionSectionProps) {
  const [uncontrolledOpen, setUncontrolledOpen] = useState(defaultOpen);
  const isControlled = undefined !== controlledOpen;
  const open = isControlled ? controlledOpen : uncontrolledOpen;
  const bodyId = useId();

  const toggle = (): void => {
    const next = !open;
    if (isControlled) {
      onToggle?.(next);
    } else {
      setUncontrolledOpen(next);
      onToggle?.(next);
    }
  };

  return (
    <div className={cn("rounded-lg border border-border", className)}>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={open ? bodyId : undefined}
        onClick={toggle}
        className="group flex w-full items-center justify-between gap-2 rounded-lg px-4 py-3 text-left text-sm font-semibold transition-colors hover:bg-accent/10 hover:text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
      >
        {title}
        <ChevronDown className={cn("size-4 shrink-0 text-muted-foreground transition-transform group-hover:text-accent", open ? "rotate-180" : "")} />
      </button>
      {open ? (
        <div id={bodyId} className="border-t border-border px-4 py-4">
          {children}
        </div>
      ) : null}
    </div>
  );
}
