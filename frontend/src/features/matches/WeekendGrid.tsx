import { AlertTriangle, Lock } from "lucide-react";
import { type UIEvent, useRef } from "react";

import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { tint } from "@/shared/lib/color";
import { cn } from "@/shared/lib/utils";

import type { WeekendGridModel } from "./lib/weekendGrid";

const ROW_HEIGHT = 16; // px per 15-min step (1h = 64px)
const HEADER_ROW = "1.75rem";

/** hex colour → subtle translucent fill; non-hex falls back to no tint. */
interface WeekendGridProps {
  model: WeekendGridModel;
  /** P1-4 PR E1 — click a placed match to open its panel (ghosts stay inert). */
  onSelectFixture?: (fixtureId: string) => void;
  /** Highlighted cell (the fixture whose panel is open, or the swap source). */
  selectedFixtureId?: string | null;
}

/** The placed home matches of one weekend on a dated venue grid (each block = 2h15 footprint). */
export function WeekendGrid({ model, onSelectFixture, selectedFixtureId = null }: WeekendGridProps) {
  const { columns, dateGroups, rows, cells, empty } = model;
  const gridRef = useRef<HTMLDivElement>(null);

  if (empty || 0 === columns.length) {
    return <EmptyBlock>Aucun match placé sur ce week-end.</EmptyBlock>;
  }

  const gridTemplateColumns = `3.25rem repeat(${columns.length}, minmax(6rem, 1fr))`;
  const gridTemplateRows = `${HEADER_ROW} ${HEADER_ROW} repeat(${rows.length}, ${ROW_HEIGHT}px)`;

  function onScroll(event: UIEvent<HTMLDivElement>) {
    const el = gridRef.current;
    if (null !== el) {
      el.style.setProperty("--sx", `${event.currentTarget.scrollLeft}px`);
      el.style.setProperty("--sy", `${event.currentTarget.scrollTop}px`);
    }
  }

  const freezeX = { transform: "translateX(var(--sx, 0))" };
  const freezeY = { transform: "translateY(var(--sy, 0))" };
  const freezeXY = { transform: "translate(var(--sx, 0), var(--sy, 0))" };

  return (
    <div className="h-full overflow-auto rounded-lg border border-border bg-card" onScroll={onScroll}>
      <div ref={gridRef} className="grid text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
        <div className="z-40 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: "1 / 3", ...freezeXY }} />

        {dateGroups.map((group) => (
          <div
            key={`date-${group.dateKey}`}
            className="z-30 border-b border-l border-border bg-muted px-2 py-1 text-center font-semibold capitalize"
            style={{ gridColumn: `${group.startColumn} / span ${group.span}`, gridRow: 1, ...freezeY }}
          >
            {group.label}
          </div>
        ))}

        {columns.map((column, i) => (
          <div
            key={column.key}
            className="z-30 flex items-center justify-center gap-1 truncate border-b border-l border-border bg-card px-1 text-center text-muted-foreground"
            style={{ gridColumn: 2 + i, gridRow: 2, ...freezeY }}
            title={column.label}
          >
            {null !== column.color ? <VenueSwatch color={column.color} /> : null}
            <span className="truncate">{column.label}</span>
          </div>
        ))}

        {rows.map((row, i) => (
          <div key={`time-${i}`} className="z-20 border-r border-border bg-card px-1 text-right text-[10px] text-muted-foreground" style={{ gridColumn: 1, gridRow: 3 + i, ...freezeX }}>
            {row.label}
          </div>
        ))}

        {rows.map((row, i) => (
          <div key={`line-${i}`} className={cn("border-l", row.major ? "border-b border-border/70" : "border-b border-border/30")} style={{ gridColumn: `2 / span ${columns.length}`, gridRow: 3 + i }} />
        ))}

        {cells.map((cell) => {
          const clickable = !cell.ghost && undefined !== onSelectFixture;
          const Tag = clickable ? "button" : "div";
          return (
            <Tag
              key={cell.key}
              {...(clickable ? { type: "button" as const, onClick: () => onSelectFixture(cell.fixtureId) } : {})}
              title={
                cell.ghost
                  ? `Habitude ${cell.teamLabel} · ${cell.venueLabel} · ${cell.footprintLabel} — fenêtre protégée (calendrier pas encore connu)`
                  : `${cell.teamLabel} vs ${cell.opponentLabel} · ${cell.venueLabel} · ${cell.footprintLabel}`
              }
              className={cn(
                "z-10 m-px flex flex-col items-start overflow-hidden rounded border-l-4 px-1 py-0.5 text-left leading-tight",
                cell.outOfEnvelope ? "ring-1 ring-warning" : "",
                // P1-4 PR C — habit ghost: translucent + dashed, visibly NOT a match.
                cell.ghost ? "border border-dashed border-border opacity-60" : "",
                clickable ? "cursor-pointer hover:brightness-95 dark:hover:brightness-110" : "",
                cell.fixtureId === selectedFixtureId ? "ring-2 ring-accent" : "",
              )}
              style={{
                gridColumn: cell.gridColumn,
                gridRow: `${cell.gridRowStart} / span ${cell.gridRowSpan}`,
                justifySelf: "start",
                width: `${100 / cell.laneCount}%`,
                transform: `translateX(${cell.lane * 100}%)`,
                borderLeftColor: cell.venueColor ?? "var(--accent)",
                backgroundColor: cell.ghost ? "transparent" : (tint(cell.venueColor) ?? "var(--muted)"),
              }}
            >
              <span className="flex w-full items-center gap-1 font-medium">
                <span className="truncate">{cell.ghost ? `Habitude ${cell.teamLabel}` : cell.teamLabel}</span>
                {/* P1-4 PR E1 — padlock: MANUAL anchor, the solver never moves it. */}
                {cell.locked ? <Lock aria-label="Ancre manuelle" className="ml-auto size-3 shrink-0 text-muted-foreground" /> : null}
                {cell.outOfEnvelope ? <AlertTriangle className={cn("size-3 shrink-0 text-warning", cell.locked ? "" : "ml-auto")} /> : null}
              </span>
              <span className="truncate text-[10px] text-muted-foreground">
                {cell.ghost ? `${cell.kickoffLabel} · fenêtre protégée` : `${cell.kickoffLabel} · ${cell.opponentLabel}`}
              </span>
              {/* RMM-1 PR3 (L7) — n° de rencontre : repère discret, jamais une clé (fait #2 §4). */}
              {null !== cell.externalRef ? <span className="text-[10px] tabular-nums text-muted-foreground/80">n° {cell.externalRef}</span> : null}
            </Tag>
          );
        })}
      </div>
    </div>
  );
}
