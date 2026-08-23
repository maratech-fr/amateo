import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { tint } from "@/shared/lib/color";

import type { Team, Venue } from "./api";
import type { TypicalWeekendModel } from "./lib/typicalWeekend";

const ROW_HEIGHT = 16; // px per 15-min step — same scale as the dated grid
const HEADER_ROW = "1.75rem";
const DAY_LABELS: Record<number, string> = { 6: "Samedi", 7: "Dimanche" };

interface TypicalWeekendGridProps {
  model: TypicalWeekendModel;
  venues: Map<string, Venue>;
  teams: Map<string, Team>;
}

/**
 * P1-4 PR E2 — the « week-end type »: every team's habitual window on a
 * date-less Sat/Sun × venues grid. READ-ONLY — the manager's ideal template;
 * habits are edited in « Habitudes & passerelles ».
 */
export function TypicalWeekendGrid({ model, venues, teams }: TypicalWeekendGridProps) {
  const { columns, blocks, venueless, startMin, endMin, empty } = model;

  if (empty) {
    return <EmptyBlock>Aucune habitude déclarée — le week-end type se construit dans « Habitudes & passerelles ».</EmptyBlock>;
  }

  const stepMin = 15;
  const rows = Math.max(1, (endMin - startMin) / stepMin);
  const dayGroups = ([6, 7] as const)
    .map((day) => ({ day, count: columns.filter((c) => c.dayOfWeek === day).length }))
    .filter((g) => g.count > 0);

  return (
    <div className="flex h-full flex-col gap-2">
      {columns.length > 0 ? (
        <div className="overflow-auto rounded-lg border border-border bg-card">
          <div
            className="grid text-xs"
            style={{
              gridTemplateColumns: `3.25rem repeat(${columns.length}, minmax(6rem, 1fr))`,
              gridTemplateRows: `${HEADER_ROW} ${HEADER_ROW} repeat(${rows}, ${ROW_HEIGHT}px)`,
            }}
          >
            <div className="border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: "1 / 3" }} />

            {dayGroups.map((group, i) => {
              const start = 2 + dayGroups.slice(0, i).reduce((acc, g) => acc + g.count, 0);
              return (
                <div key={group.day} className="border-b border-l border-border bg-muted px-2 py-1 text-center font-semibold" style={{ gridColumn: `${start} / span ${group.count}`, gridRow: 1 }}>
                  {DAY_LABELS[group.day]}
                </div>
              );
            })}

            {columns.map((column, i) => {
              // §6bis (gênant) — l'en-tête de gymnase gagne le `title` de secours que
              // sa jumelle datée (WeekendGrid) portait déjà : un nom tronqué reste
              // lisible au survol, désormais que le gabarit est un écran de plein droit.
              const venueName = venues.get(column.venueId)?.name ?? "Gymnase ?";
              return (
                <div key={column.key} className="flex items-center justify-center gap-1 truncate border-b border-l border-border bg-card px-1 text-muted-foreground" style={{ gridColumn: 2 + i, gridRow: 2 }} title={venueName}>
                  {null !== (venues.get(column.venueId)?.color ?? null) ? <VenueSwatch color={venues.get(column.venueId)?.color ?? ""} /> : null}
                  <span className="truncate">{venueName}</span>
                </div>
              );
            })}

            {Array.from({ length: rows }, (_, i) => (
              <div key={`t-${i}`} className="border-r border-border bg-card px-1 text-right text-[10px] text-muted-foreground" style={{ gridColumn: 1, gridRow: 3 + i }}>
                {0 === (startMin + i * stepMin) % 60 ? `${String(Math.floor((startMin + i * stepMin) / 60)).padStart(2, "0")}h` : ""}
              </div>
            ))}

            {blocks.map((block) => {
              const columnIndex = columns.findIndex((c) => c.key === block.columnKey);
              const venue = venues.get(columns[columnIndex]?.venueId ?? "");
              return (
                <div
                  key={block.key}
                  title={`${teams.get(block.teamId)?.name ?? "Équipe ?"} · ${block.kickoff} · ${venue?.name ?? "?"}`}
                  className="z-10 m-px flex flex-col items-start overflow-hidden rounded border-l-4 px-1 py-0.5 text-left leading-tight"
                  style={{
                    gridColumn: 2 + columnIndex,
                    gridRow: `${3 + (block.startMin - startMin) / stepMin} / span ${Math.max(1, (block.endMin - block.startMin) / stepMin)}`,
                    justifySelf: "start",
                    width: `${100 / block.laneCount}%`,
                    transform: `translateX(${block.lane * 100}%)`,
                    borderLeftColor: venue?.color ?? "var(--accent)",
                    backgroundColor: tint(venue?.color ?? null) ?? "var(--muted)",
                  }}
                >
                  <span className="w-full truncate font-medium">{teams.get(block.teamId)?.name ?? "Équipe ?"}</span>
                  <span className="truncate text-[10px] text-muted-foreground">{block.kickoff}</span>
                </div>
              );
            })}
          </div>
        </div>
      ) : null}

      {venueless.length > 0 ? (
        <p className="text-xs text-muted-foreground">
          Sans gymnase :{" "}
          {venueless
            .map((h) => `${teams.get(h.teamId)?.name ?? "?"} · ${6 === h.dayOfWeek ? "sam" : "dim"} ${h.kickoffTime}`)
            .join(" · ")}
        </p>
      ) : null}
    </div>
  );
}
