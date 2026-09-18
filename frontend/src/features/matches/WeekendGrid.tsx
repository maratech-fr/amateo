import { AlertTriangle, Bus, Car, CircleDashed, Clock, HelpCircle, Lock } from "lucide-react";
import { type UIEvent, useRef } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { tint } from "@/shared/lib/color";
import { cn } from "@/shared/lib/utils";

import type { WeekendCell, WeekendGridModel } from "./lib/weekendGrid";

/** lot 3 PR-3a — nom accessible d'un bloc extérieur : équipe, adversaire, jour + heure
 *  (ou « heure inconnue »), « heure estimée », trajet. Ordre stable, segments absents omis. */
function awayBlockName(cell: WeekendCell): string {
  const parts = [`${cell.teamLabel} à ${cell.opponentLabel}`];
  const when = true === cell.unknownHour ? "heure inconnue" : cell.kickoffLabel;
  parts.push(`${cell.awayWeekday ?? ""} ${when}`.trim());
  if (true === cell.estimated) {
    parts.push("heure estimée");
  }
  if (null !== cell.travelLabel && undefined !== cell.travelLabel) {
    parts.push(`${cell.travelLabel} de trajet`);
  }
  return parts.join(", ");
}

/**
 * VOCABULAIRE VISUEL des cases de la grille (à garder cohérent) :
 *  - **pointillé + translucide (opacity)** = « PAS un match » : un fantôme d'habitude,
 *    la fenêtre protégée d'une équipe dont le calendrier n'est pas encore connu.
 *  - **hachures (repeating-linear-gradient) + opaque + pastille « À confirmer »** =
 *    « un vrai match, EN ATTENTE » : un domicile dont le gymnase et l'heure ont été
 *    repris de l'import mais que le gestionnaire n'a pas encore confirmé (statut
 *    backend UNPLACED). Jamais d'opacité ni de pointillé ici — réservés au fantôme.
 *  - **fond teinté uni + éventuel cadenas** = un match RÉELLEMENT placé.
 */
const ROW_HEIGHT = 16; // px per 15-min step (1h = 64px)
const HEADER_ROW = "1.75rem";

/** hex colour → subtle translucent fill; non-hex falls back to no tint. */
interface WeekendGridProps {
  model: WeekendGridModel;
  /** P1-4 PR E1 — click a placed match to open its panel (ghosts stay inert). */
  onSelectFixture?: (fixtureId: string) => void;
  /** Highlighted cell (the fixture whose panel is open, or the swap source). */
  selectedFixtureId?: string | null;
  /**
   * RMM-1 PR4 (L6) — swap mode made VISIBLE on the grid. When non-null, the mode
   * is armed: cells whose fixtureId is in the set are the exchange CANDIDATES
   * (ring highlight + pointer), every other placed cell is dimmed so the eye
   * lands on the clickable targets. `null` = not in swap mode (nothing dimmed).
   */
  swapCandidateIds?: Set<string> | null;
}

/** The placed home matches of one weekend on a dated venue grid (each block = 2h15 footprint). */
export function WeekendGrid({ model, onSelectFixture, selectedFixtureId = null, swapCandidateIds = null }: WeekendGridProps) {
  const { columns, dateGroups, rows, cells, empty } = model;
  const gridRef = useRef<HTMLDivElement>(null);

  if (empty || 0 === columns.length) {
    return <EmptyBlock>Aucun match placé sur ce week-end.</EmptyBlock>;
  }

  // Colonne « Extérieur » plus large (8rem) que les gymnases (6rem) : elle porte trois lignes.
  const gridTemplateColumns = `3.25rem ${columns.map((c) => (true === c.away ? "minmax(8rem, 1fr)" : "minmax(6rem, 1fr)")).join(" ")}`;
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
    <div data-testid="weekend-grid" className="h-full overflow-auto rounded-lg border border-border bg-card" onScroll={onScroll}>
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
            title={true === column.away ? "À l'extérieur" : column.label}
          >
            {/* lot 3 PR-3a — la colonne « Extérieur » : icône bus, jamais de pastille de gymnase. */}
            {true === column.away ? (
              <Bus className="size-3 shrink-0" aria-hidden="true" />
            ) : null !== column.color ? (
              <VenueSwatch color={column.color} />
            ) : null}
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
          // RMM-1 PR4 (L6) — le mode échange se VOIT : les candidates portent
          // l'anneau + le curseur, les autres cellules placées s'estompent.
          const swapArmed = null !== swapCandidateIds;

          // lot 3 PR-3a — un bloc EXTÉRIEUR (colonne « Extérieur ») : même <button> que le
          // domicile, fond muted uni, rail muted-foreground, tout le texte `text-foreground`.
          // En mode échange il est INERTE (estompé, sans handler) : on n'échange que des domiciles.
          if (true === cell.away) {
            const awayClickable = undefined !== onSelectFixture && !swapArmed;
            const AwayTag = awayClickable ? "button" : "div";
            const name = awayBlockName(cell);
            return (
              <AwayTag
                key={cell.key}
                {...(awayClickable ? { type: "button" as const, onClick: () => onSelectFixture(cell.fixtureId) } : {})}
                data-fixture-id={cell.fixtureId}
                data-away="true"
                aria-label={name}
                title={name}
                className={cn(
                  "z-10 m-px flex flex-col items-start overflow-hidden rounded border border-border border-l-4 border-l-muted-foreground bg-muted px-1 py-0.5 text-left leading-tight text-foreground",
                  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
                  awayClickable ? "cursor-pointer hover:brightness-95 dark:hover:brightness-110" : "",
                  swapArmed ? "opacity-40" : "",
                )}
                style={{
                  gridColumn: cell.gridColumn,
                  gridRow: `${cell.gridRowStart} / span ${cell.gridRowSpan}`,
                  justifySelf: "start",
                  width: `${100 / cell.laneCount}%`,
                  transform: `translateX(${cell.lane * 100}%)`,
                }}
              >
                <span className="flex w-full items-center gap-1 text-xs font-medium">
                  <Bus className="size-3 shrink-0" aria-hidden="true" />
                  <span className="truncate">{cell.teamLabel}</span>
                  {true === cell.estimated ? <Clock aria-label="Heure estimée" className="ml-auto size-3 shrink-0" /> : null}
                  {true === cell.unknownHour ? <HelpCircle aria-label="Heure inconnue" className="ml-auto size-3 shrink-0" /> : null}
                </span>
                <span className="truncate text-[10px]">
                  {(true === cell.unknownHour ? "heure inconnue" : cell.kickoffLabel) + ` · à ${cell.opponentLabel}`}
                </span>
                {null !== cell.travelLabel && undefined !== cell.travelLabel ? (
                  <span className="flex items-center gap-1 text-[10px]">
                    <Car className="size-3 shrink-0" aria-hidden="true" />
                    <span className="tabular-nums">{cell.travelLabel}</span>
                  </span>
                ) : null}
              </AwayTag>
            );
          }

          // Case « À confirmer » : même case, même colonne, fond hachuré + rail couleur
          // gymnase + pastille warning. Un vrai match en attente (opaque), jamais un
          // fantôme (pointillé/translucide) — cf. le docblock « VOCABULAIRE VISUEL ».
          if (cell.toConfirm) {
            const confirmClickable = undefined !== onSelectFixture; // jamais un ghost
            const ConfirmTag = confirmClickable ? "button" : "div";
            const venueColor = cell.venueColor ?? "var(--accent)";
            const confirmDimmed = swapArmed && cell.fixtureId !== selectedFixtureId;
            // Nom accessible = les textes visibles, verbatim, + le contexte (gymnase, jour).
            const confirmName = `${cell.teamLabel} – ${cell.opponentLabel}, ${cell.weekday ?? ""} ${cell.kickoffLabel}, ${cell.venueLabel}. À confirmer : gymnase et heure repris de l'import, rencontre pas encore placée.`;
            return (
              <ConfirmTag
                key={cell.key}
                {...(confirmClickable ? { type: "button" as const, onClick: () => onSelectFixture(cell.fixtureId) } : {})}
                data-fixture-id={cell.fixtureId}
                data-to-confirm="true"
                aria-label={confirmName}
                title={confirmName}
                className={cn(
                  "z-10 m-px flex flex-col items-start gap-0.5 overflow-hidden rounded border border-border border-l-4 bg-card px-1 py-0.5 text-left leading-tight text-foreground",
                  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
                  confirmClickable ? "cursor-pointer hover:brightness-95 dark:hover:brightness-110" : "",
                  cell.fixtureId === selectedFixtureId ? "ring-2 ring-accent" : "",
                  // Estompage de cellule = `grayscale`, jamais `opacity` (l'opacité sur le texte de la
                // case tombe sous AA — A11Y-22) : on désature la teinte de gymnase pour désigner l'œil.
                confirmDimmed ? "grayscale" : "",
                )}
                style={{
                  gridColumn: cell.gridColumn,
                  gridRow: `${cell.gridRowStart} / span ${cell.gridRowSpan}`,
                  justifySelf: "start",
                  width: `${100 / cell.laneCount}%`,
                  transform: `translateX(${cell.lane * 100}%)`,
                  borderLeftColor: venueColor,
                  backgroundImage: `repeating-linear-gradient(45deg, color-mix(in oklch, ${venueColor} 22%, transparent) 0 4px, transparent 4px 9px)`,
                }}
              >
                <span className="flex w-full items-center font-medium">
                  <span className="truncate">{cell.teamLabel}</span>
                </span>
                <StatusPill variant="warning" className="px-1 py-0 text-[10px]" icon={<CircleDashed className="size-3 text-warning" aria-hidden="true" />}>
                  À confirmer
                </StatusPill>
                <span className="truncate text-[10px] text-foreground">{`${cell.kickoffLabel} · ${cell.opponentLabel}`}</span>
                {null !== cell.externalRef ? <span className="text-[10px] tabular-nums text-foreground">n° {cell.externalRef}</span> : null}
              </ConfirmTag>
            );
          }

          const clickable = !cell.ghost && undefined !== onSelectFixture;
          const Tag = clickable ? "button" : "div";
          const isSwapCandidate = swapArmed && !cell.ghost && swapCandidateIds.has(cell.fixtureId);
          const isSwapSource = swapArmed && cell.fixtureId === selectedFixtureId;
          const swapDimmed = swapArmed && !isSwapCandidate && !isSwapSource;
          return (
            <Tag
              key={cell.key}
              {...(clickable ? { type: "button" as const, onClick: () => onSelectFixture(cell.fixtureId) } : {})}
              {...(cell.ghost ? {} : { "data-fixture-id": cell.fixtureId })}
              {...(isSwapCandidate ? { "data-swap-candidate": "true" } : {})}
              title={
                cell.ghost
                  ? `Habitude ${cell.teamLabel} · ${cell.venueLabel} · ${cell.footprintLabel} — fenêtre protégée (calendrier pas encore connu)`
                  : `${cell.teamLabel} vs ${cell.opponentLabel} · ${cell.venueLabel} · ${cell.footprintLabel}`
              }
              className={cn(
                "z-10 m-px flex flex-col items-start overflow-hidden rounded border-l-4 px-1 py-0.5 text-left leading-tight",
                cell.outOfEnvelope ? "ring-1 ring-warning" : "",
                // P1-4 PR C — fantôme d'habitude : pointillé + fond transparent, visiblement PAS un
                // match. La dé-emphase se porte par la GRAISSE (font-normal), jamais par l'opacité sur
                // du texte (A11Y-22) ; les cases réelles gardent leur `font-medium`.
                cell.ghost ? "border border-dashed border-border font-normal" : "",
                clickable ? "cursor-pointer hover:brightness-95 dark:hover:brightness-110" : "",
                cell.fixtureId === selectedFixtureId ? "ring-2 ring-accent" : "",
                // Candidate d'échange : anneau accent net (affordance « clique-moi »).
                isSwapCandidate ? "ring-2 ring-accent ring-offset-1 ring-offset-background" : "",
                // Hors du couple source/candidates : on estompe (pas d'animation —
                // reduced-motion + on ne fait clignoter aucune cellule).
                swapDimmed ? "grayscale" : "",
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
                {/* Nom accessible explicite (patron du cadenas voisin) : l'info « hors fenêtre
                    ligue » ne peut reposer sur l'icône + la couleur seules (A11Y-17). */}
                {cell.outOfEnvelope ? <AlertTriangle aria-label="Hors fenêtre ligue" className={cn("size-3 shrink-0 text-warning", cell.locked ? "" : "ml-auto")} /> : null}
              </span>
              {/* A11Y-22 — fantôme (fond transparent) : `text-muted-foreground` plein ; case RÉELLE
                  (fond `tint(venueColor)`) : `text-foreground` (recette P4-180, comme « À confirmer »).
                  Plus aucune opacité sur le texte. */}
              <span className={cn("truncate text-[10px]", cell.ghost ? "text-muted-foreground" : "text-foreground")}>
                {cell.ghost ? `${cell.kickoffLabel} · fenêtre protégée` : `${cell.kickoffLabel} · ${cell.opponentLabel}`}
              </span>
              {/* RMM-1 PR3 (L7) — n° de rencontre : repère discret, jamais une clé (fait #2 §4). */}
              {null !== cell.externalRef ? <span className={cn("text-[10px] tabular-nums", cell.ghost ? "text-muted-foreground" : "text-foreground")}>n° {cell.externalRef}</span> : null}
            </Tag>
          );
        })}
      </div>
    </div>
  );
}
