import { ArrowLeftRight, Lock, LockOpen } from "lucide-react";
import { type UIEvent, useEffect, useRef } from "react";

import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { tint } from "@/shared/lib/color";
import { cn } from "@/shared/lib/utils";

import type { LockOrigin } from "./api";
import { isEmptySlotId } from "./lib/emptySlots";
import { DAYS, type GridModel } from "./lib/grid";
import { LOCK_LENS_META } from "./lib/lockLens";

/**
 * P2-30 (geste 1/2) — le mode cible « click-click ». Armé, la grille cesse d'être une simple
 * consultation : les cases VIDES deviennent des boutons « Placer ici », les séances occupées
 * des cibles d'éviction, la SOURCE est marquée. Deux variantes : `move` (déplacer un créneau
 * existant, `sourceSlotId` = ce créneau) et `place` (placer une séance à la dérive, pas de
 * source à marquer). Échap sort. Le PICK et l'annulation sont décidés PAR LA PAGE (elle porte
 * l'état, les verdicts moteur, l'éviction) — la grille ne fait que router les clics.
 */
export interface TargetMode {
  active: boolean;
  sourceSlotId: string | null;
  variant: "move" | "place";
}

const DAY_LABEL = new Map(DAYS.map((d) => [d.n, d.label]));

const ROW_HEIGHT = 16; // px per 15-min step (1h = 64px)
const HEADER_ROW = "1.75rem";

/** hex colour → subtle translucent fill; non-hex falls back to no tint. */
interface WeekGridProps {
  model: GridModel;
  selectedSlotId: string | null;
  onSelectSlot: (slotId: string) => void;
  highlightSlotIds?: Set<string>;
  /**
   * Verrouiller/déverrouiller un créneau depuis SA carte, en un clic (le cadenas est un
   * bouton FRÈRE de la carte — un `<button>` ne peut pas en contenir un autre). Absent =
   * lecture seule (planning validé/FAILED) : le cadenas d'un créneau verrouillé reste
   * affiché en INDICATEUR passif, jamais actionnable. Le handler (ConfirmDialog RÉSERVATION
   * compris) vit UNE fois côté page.
   */
  onToggleLock?: (slotId: string) => void;
  /**
   * Lentille verrous (PR 3) : quand active, les créneaux SANS verrou sont estompés et les
   * verrouillés portent l'anneau + l'icône de leur origine (MANUAL/RESERVATION/UNKNOWN,
   * `lib/lockLens`). ⚠ Le surlignage CONFLIT (`highlightSlotIds`) PRIME : tant qu'un conflit
   * règne, la lentille se tait — le rouge/ambre du conflit ne doit pas être brouillé.
   */
  lockLens?: boolean;
  /** P2-30 : le mode cible click-click. Absent/inactif = grille normale. */
  targetMode?: TargetMode;
  /**
   * P2-43 volet (v) — les couples (gymnase, jour) FERMÉS de la période, tels que le SERVEUR les
   * calcule (`computeClosedWindows`, clé `${venueId}|${jour ISO}` → résumé). MARQUER, pas masquer :
   * une fenêtre vide sur un couple fermé devient INERTE et NOMMÉE (« Fermé — … »), et n'est JAMAIS
   * une cible (offre fail-closed). Absent = aucune fermeture (socle, ou état pas encore résolu →
   * fail-open sur l'AFFICHAGE). Le front LIT cet état, il ne le re-dérive pas (règle d'or).
   */
  closedWindows?: Map<string, string>;
  /** Un clic sur une case (vide ou occupée) EN mode cible — l'id de la cible ; la page décide
   *  (déplacer, évincer, placer, ou annuler si c'est la source). */
  onPickTarget?: (cellSlotId: string) => void;
  /** Échap en mode cible : sortir sans rien toucher (le focus revient côté page). */
  onCancelTarget?: () => void;
  /**
   * P2-44 (PR-2) — met les créneaux VIDES en évidence (les « trous » à combler après une
   * transcription depuis le socle) : état visuel discret mais repérable ET nommé (a11y). N'affecte
   * JAMAIS une case FERMÉE (inerte depuis P2-43 v — elle sort avant cette branche). Absent/false =
   * rendu strictement inchangé.
   */
  emphasizeEmpty?: boolean;
  /**
   * P2-44 (PR-4) — les créneaux de PÉRIODE qui S'ÉCARTENT du planning de saison, servis par la
   * route `socle-deviation` : `slotId → libellé d'origine` (« Mar 18h30 Matéo », la place de
   * saison). Le backend a DÉJÀ décidé l'écart (règle d'or) ; la grille NOMME — un symbole ⇄
   * (violet, `--diff`) AVANT le nom d'équipe + l'origine en `sr-only`/`title`, jamais le mot
   * « déplacée » à l'écran. Le CONFLIT et la SÉLECTION priment sur l'anneau, le symbole reste.
   * Absent = aucun écart (vacance, `/planning` autonome, version non COMPLETED).
   */
  deviatedSlots?: Map<string, string>;
}

/**
 * The frozen header row + time column are kept in place by syncing a transform to
 * the scroll offset (CSS vars --sx/--sy). `position: sticky` can't be used here:
 * a sticky grid item is clamped to its own cell, so it detaches once that narrow
 * cell scrolls out of view.
 */
export function WeekGrid({ model, selectedSlotId, onSelectSlot, highlightSlotIds, onToggleLock, lockLens = false, targetMode, onPickTarget, onCancelTarget, closedWindows, emphasizeEmpty = false, deviatedSlots }: WeekGridProps) {
  const { columns, dayGroups, rows, cells } = model;
  const gridRef = useRef<HTMLDivElement>(null);

  // P2-30 — état du mode cible (dérivé des props). `targetActive` gouverne le comportement de
  // TOUTE la grille : les cases deviennent des cibles, la lentille se tait, Échap sort.
  const targetActive = true === targetMode?.active;
  const targetSourceId = targetMode?.sourceSlotId ?? null;
  const isMoveVariant = "move" === targetMode?.variant;
  const isSource = (slotId: string): boolean => targetActive && null !== targetSourceId && slotId === targetSourceId;
  // P2-43 : le résumé de fermeture d'un couple (gymnase, jour), ou `undefined` s'il est ouvert.
  const closedReasonOf = (venueId: string, day: number): string | undefined => closedWindows?.get(`${venueId}|${day}`);
  // Une séance VERROUILLÉE n'est pas une cible d'ÉVICTION (D3 : le verrou est souverain — le
  // backend répondrait 422 target_locked). Un couple FERMÉ ne l'est pas non plus (P2-43, offre
  // fail-closed : le moteur refuserait). On les écarte d'emblée, sauf la source elle-même.
  const targetDisabled = (slotId: string, locked: boolean, closedReason: string | undefined): boolean =>
    targetActive && slotId !== targetSourceId && ((isMoveVariant && locked) || undefined !== closedReason);
  // Le routage d'un clic de carte : en mode cible → pick (sauf case inerte) ; sinon → sélection.
  const activateSlot = (slotId: string, locked: boolean, closedReason: string | undefined): void => {
    if (!targetActive) {
      onSelectSlot(slotId);
      return;
    }
    if (targetDisabled(slotId, locked, closedReason)) {
      return;
    }
    onPickTarget?.(slotId);
  };
  const cardTitle = (slotId: string, locked: boolean, closedReason: string | undefined, base: string): string =>
    targetDisabled(slotId, locked, closedReason)
      ? (undefined !== closedReason ? `Fermé — ${closedReason}` : "Ce créneau est verrouillé — déverrouillez-le d'abord")
      : base;
  // Échap sort du mode cible sans rien toucher (écoute globale : marche quel que soit le focus,
  // et n'impose pas de rôle interactif à la grille — a11y).
  useEffect(() => {
    if (!targetActive) {
      return;
    }
    const handler = (event: KeyboardEvent): void => {
      if ("Escape" === event.key) {
        event.preventDefault();
        onCancelTarget?.();
      }
    };
    document.addEventListener("keydown", handler);
    return () => document.removeEventListener("keydown", handler);
  }, [targetActive, onCancelTarget]);

  // Lentille : l'icône de catégorie (F1), coin bas-GAUCHE de la carte (retour fondateur : le
  // haut-gauche empiétait sur le nom d'équipe ; le cadenas garde le haut-droit). Décorative
  // (aria-hidden) : la couleur ET la légende du panneau portent le sens ; le `data-lens` sert
  // au repérage par les tests.
  const renderLensBadge = (origin: LockOrigin) => {
    const meta = LOCK_LENS_META[origin];
    const Icon = meta.Icon;
    return (
      // Aligné sur le cadenas (retour fondateur) : l'icône du bouton (12 px dans une boîte
      // de 24 px à bottom-0.5) a son centre à 14 px du bas → le badge nu (12 px) se pose à
      // bottom-2 (8 px) pour le même centre. Pas de boîte : une boîte de 24 px chevauchait
      // le cadenas sur les cartes étroites (demi-colonnes).
      <span data-lens={origin} aria-hidden="true" className={cn("pointer-events-none absolute bottom-2 left-1 z-20", meta.textClass)}>
        <Icon className="size-3" />
      </span>
    );
  };

  // Variante EN LIGNE pour les rangées de carte fusionnée (retour fondateur : l'absolu y
  // chevauchait le nom du membre) — l'icône vit dans le flux, avant le nom d'équipe.
  const renderLensInline = (origin: LockOrigin) => {
    const meta = LOCK_LENS_META[origin];
    const Icon = meta.Icon;
    return (
      <span data-lens={origin} aria-hidden="true" className={cn("shrink-0", meta.textClass)}>
        <Icon className="size-3" />
      </span>
    );
  };

  // P2-44 (PR-4) — le symbole d'ÉCART au socle : une pastille ⇄ violette (fond `--diff`), AVANT le
  // nom d'équipe, dans le flux du bouton. Auto-explicite (décision fondateur : pas de légende, pas
  // de tooltip porteur de sens) ; le SENS accessible vit en `sr-only` + le title de la carte. Le
  // mot « déplacée » ne paraît JAMAIS à l'écran. `origin` = la place de saison (« Mar 18h30 Matéo »).
  const renderDeviationChip = (origin: string) => (
    <>
      <span aria-hidden="true" className="shrink-0 rounded-sm bg-diff px-0.5 text-diff-foreground">
        <ArrowLeftRight className="size-3" />
      </span>
      <span className="sr-only">déplacée — en saison : {origin}</span>
    </>
  );

  // Le cadenas d'une carte : un bouton FRÈRE, en surimpression coin haut-droit. Éditable
  // (onToggleLock fourni) → bascule le verrou sans sélectionner (zone de clic ≥ 24px,
  // aria-label nommant l'équipe, visible en permanence si verrouillé, sinon au survol/focus).
  // Lecture seule → simple indicateur passif quand le créneau est verrouillé.
  const renderLock = (slotId: string, teamLabel: string, locked: boolean) => {
    if (undefined === onToggleLock) {
      return locked ? <Lock aria-hidden="true" className="pointer-events-none absolute bottom-1 right-1 size-3 text-muted-foreground" /> : null;
    }
    return (
      <button
        type="button"
        onClick={(event) => {
          event.stopPropagation();
          onToggleLock(slotId);
        }}
        aria-label={`${locked ? "Déverrouiller" : "Verrouiller"} ${teamLabel}`}
        title={`${locked ? "Déverrouiller" : "Verrouiller"} ${teamLabel}`}
        className={cn(
          // Bas-droit (retour fondateur) : symétrique du badge de lentille (bas-gauche), le
          // haut de carte reste au nom d'équipe.
          "absolute bottom-0.5 right-0.5 z-20 flex size-6 items-center justify-center rounded text-muted-foreground transition hover:bg-accent/20 hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent",
          locked ? "" : "opacity-0 group-hover:opacity-100 group-focus-within:opacity-100",
        )}
      >
        {locked ? <Lock className="size-3" /> : <LockOpen className="size-3" />}
      </button>
    );
  };

  if (0 === columns.length) {
    return <EmptyBlock>Aucun créneau à afficher pour cette sélection.</EmptyBlock>;
  }

  const gridTemplateColumns = `3.25rem repeat(${columns.length}, minmax(4.25rem, 1fr))`;
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
        {/* Corner — frozen both axes */}
        <div className="z-40 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: "1 / 3", ...freezeXY }} />

        {/* Day headers — frozen top, span the day's used resource sub-columns */}
        {dayGroups.map((group) => (
          <div
            key={`day-${group.day}`}
            className="z-30 border-b border-l border-border bg-muted px-2 py-1 text-center font-semibold"
            style={{ gridColumn: `${group.startColumn} / span ${group.span}`, gridRow: 1, ...freezeY }}
          >
            {group.label}
          </div>
        ))}

        {/* Resource sub-headers — frozen top */}
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

        {/* Time gutter — frozen left, label only on half-hours */}
        {rows.map((row, i) => (
          <div
            key={`time-${i}`}
            className="z-20 border-r border-border bg-card px-1 text-right text-[10px] text-muted-foreground"
            style={{ gridColumn: 1, gridRow: 3 + i, ...freezeX }}
          >
            {row.label}
          </div>
        ))}

        {/* Row grid lines — stronger on the hour */}
        {rows.map((row, i) => (
          <div
            key={`line-${i}`}
            className={cn("border-l", row.major ? "border-b border-border/70" : "border-b border-border/30")}
            style={{ gridColumn: `2 / span ${columns.length}`, gridRow: 3 + i }}
          />
        ))}

        {/* Slots — overlapping ones share the column in side-by-side lanes.
            Only dim when the highlight set actually matches a visible cell — else
            a highlight of empty-slot ids left over from the gymnase view would dim
            the ENTIRE coach/equipe grid (their ids never match). */}
        {(() => {
          // Une carte fusionnée (P2-17 D4) porte PLUSIEURS séances : le surlignage et l'estompe
          // considèrent tous ses membres, pas le seul `slotId` de tête.
          const cellIds = (c: (typeof cells)[number]): string[] => (null !== c.groupLabel ? c.members.map((m) => m.slotId) : [c.slotId]);
          const highlighting = null != highlightSlotIds && highlightSlotIds.size > 0 && cells.some((c) => cellIds(c).some((id) => highlightSlotIds.has(id)));
          // Priorités visuelles : surlignage CONFLIT > mode cible > lentille. La lentille se
          // tait sous un conflit ET tant que le mode cible est armé (comme `highlighting`).
          const lensActive = lockLens && !highlighting && !targetActive;
          return cells.map((cell) => {
          const dimmed = highlighting && !cellIds(cell).some((id) => highlightSlotIds?.has(id) ?? false);
          // Empty slots = defined venue windows the solver left unfilled. Muted,
          // dashed, labelled "vide", not selectable — but still highlightable so a
          // "créneau vide" warning can draw the eye to them.
          if (isEmptySlotId(cell.slotId)) {
            const flagged = highlighting && highlightSlotIds.has(cell.slotId);
            const emptyStyle = {
              gridColumn: cell.gridColumn,
              gridRow: `${cell.gridRowStart} / span ${cell.gridRowSpan}`,
              justifySelf: "start" as const,
              width: `${100 / cell.laneCount}%`,
              transform: `translateX(${cell.lane * 100}%)`,
            };
            const closedReason = closedReasonOf(cell.venueId, cell.day);
            // P2-43 volet (v) — MARQUER, pas masquer (« on annonce, on ne cache pas ») : une
            // fenêtre vide sur un couple fermé est INERTE et NOMMÉE, jamais un bouton « Placer ici »
            // (l'offre est fail-closed, quel que soit l'état armé). La colonne n'est masquée
            // (côté page) que si le gymnase est ENTIÈREMENT fermé ET sans aucune séance placée.
            if (undefined !== closedReason) {
              const dayLabel = DAY_LABEL.get(cell.day) ?? "";
              return (
                <div
                  key={cell.key}
                  title={`Fermé — ${closedReason}`}
                  aria-label={`${cell.venueLabel}, ${dayLabel} ${cell.startLabel}–${cell.endLabel} — fermé : ${closedReason}`}
                  className={cn(
                    "z-10 m-px flex items-center justify-center overflow-hidden rounded border border-dashed border-muted-foreground/30 bg-muted/40 px-1 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground/60",
                    dimmed ? "opacity-30" : "",
                    flagged ? "border-warning ring-2 ring-warning text-warning" : "",
                  )}
                  style={emptyStyle}
                >
                  fermé
                </div>
              );
            }
            // P2-30 — armé, une fenêtre vide devient un VRAI bouton « Placer ici » (focusable,
            // aria-label nommant gymnase + jour + horaire), cible d'un déplacement/placement.
            if (targetActive) {
              const dayLabel = DAY_LABEL.get(cell.day) ?? "";
              return (
                <button
                  key={cell.key}
                  type="button"
                  onClick={() => onPickTarget?.(cell.slotId)}
                  aria-label={`Placer ici — ${cell.venueLabel}, ${dayLabel} ${cell.startLabel}–${cell.endLabel}`}
                  className={cn(
                    "z-10 m-px flex items-center justify-center overflow-hidden rounded border border-dashed border-accent/60 bg-accent/5 px-1 py-0.5 text-[10px] font-medium uppercase tracking-wide text-accent transition hover:border-accent hover:bg-accent/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent",
                    flagged ? "border-warning ring-2 ring-warning text-warning" : "",
                  )}
                  style={emptyStyle}
                >
                  vide
                </button>
              );
            }
            // P2-44 — la mise en évidence des vides cède le pas au surlignage CONFLIT (`flagged`) :
            // deux anneaux ne se superposent pas. NOMMÉE (aria-label) pour être repérable à l'AT.
            const emphasized = emphasizeEmpty && !flagged;
            return (
              <div
                key={cell.key}
                title={`Créneau vide · ${cell.venueLabel} · ${cell.startLabel}–${cell.endLabel}`}
                aria-label={emphasized ? `Créneau vide à combler · ${cell.venueLabel} · ${cell.startLabel}–${cell.endLabel}` : undefined}
                className={cn(
                  "z-10 m-px flex items-center justify-center overflow-hidden rounded border border-dashed border-muted-foreground/40 px-1 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground/70 transition",
                  dimmed ? "opacity-30" : "",
                  flagged ? "border-warning ring-2 ring-warning text-warning" : "",
                  // Une fenêtre vide n'a aucun verrou : sous la lentille, elle s'estompe.
                  lensActive ? "opacity-40" : "",
                  // Discret mais repérable : bordure pleine + fond teinté accent + texte accent.
                  emphasized ? "border-solid border-accent bg-accent/10 text-accent" : "",
                )}
                style={emptyStyle}
              >
                vide
              </div>
            );
          }
          // Carte FUSIONNÉE (vue gymnase, créneau mutualisé libellé — P2-17 D4) : le libellé
          // titre la carte, chaque équipe reste un bouton cliquable (mêmes interactions par
          // équipe que sur une carte séparée).
          if (null !== cell.groupLabel) {
            // Lentille sur carte fusionnée (retour fondateur) : si TOUS les membres partagent
            // le même type de verrou, UN SEUL picto au niveau de la carte (bas-gauche, comme
            // une carte simple) ; sinon le picto ne vit que sur les rangées verrouillées.
            const origins = cell.members.map((m) => m.lockOrigin);
            const uniformOrigin = lensActive && origins.length > 0 && null !== origins[0] && origins.every((o) => o === origins[0]) ? origins[0] : null;
            // P2-43 — une carte fusionnée déjà PLACÉE sur un couple depuis fermé (cas périmé) :
            // ses membres cessent d'être des cibles d'éviction (défense en profondeur de l'offre).
            const cellClosedReason = closedReasonOf(cell.venueId, cell.day);
            return (
              <div
                key={cell.key}
                className={cn(
                  "relative z-10 m-px flex flex-col overflow-hidden rounded border-l-4 text-left leading-tight transition",
                  dimmed ? "opacity-30" : "",
                  null !== uniformOrigin ? LOCK_LENS_META[uniformOrigin].ringClass : "",
                )}
                style={{
                  gridColumn: cell.gridColumn,
                  gridRow: `${cell.gridRowStart} / span ${cell.gridRowSpan}`,
                  justifySelf: "start",
                  width: `${100 / cell.laneCount}%`,
                  transform: `translateX(${cell.lane * 100}%)`,
                  borderLeftColor: cell.venueColor ?? "var(--accent)",
                  backgroundColor: tint(cell.venueColor) ?? "var(--muted)",
                }}
              >
                <span className="truncate px-1 pt-0.5 text-[10px] font-semibold uppercase tracking-wide">{cell.groupLabel}</span>
                {cell.members.map((member) => {
                  const memberSelected = member.slotId === selectedSlotId;
                  // P2-44 PR-4 — écart au socle, par membre. L'anneau `diff` cède à la sélection
                  // et à un anneau de lentille (le symbole, lui, reste dans tous les cas).
                  const memberDeviatedOrigin = deviatedSlots?.get(member.slotId);
                  const memberLensRing = lensActive && null === uniformOrigin && null !== member.lockOrigin;
                  return (
                    // Wrapper positionné : le cadenas est un bouton FRÈRE du bouton d'équipe
                    // (jamais imbriqué). `group` scope le survol/focus au membre. La lentille
                    // agit PAR MEMBRE : chaque équipe d'une carte fusionnée porte son propre verrou.
                    <div
                      key={member.slotId}
                      className={cn(
                        "group relative flex w-full items-center",
                        lensActive && null === member.lockOrigin ? "opacity-40" : "",
                        // Anneau par membre seulement en situation MIXTE — uniforme = anneau de carte.
                        memberLensRing && null !== member.lockOrigin ? LOCK_LENS_META[member.lockOrigin].ringClass : "",
                        // Écart au socle : anneau `diff`, sauf si sélection ou anneau de lentille prime.
                        undefined !== memberDeviatedOrigin && !memberSelected && !memberLensRing ? "ring-1 ring-diff" : "",
                      )}
                    >
                      <button
                        type="button"
                        data-slot-id={member.slotId}
                        data-target-source={isSource(member.slotId) ? "true" : undefined}
                        onClick={() => activateSlot(member.slotId, member.locked, cellClosedReason)}
                        disabled={targetDisabled(member.slotId, member.locked, cellClosedReason)}
                        title={cardTitle(member.slotId, member.locked, cellClosedReason, `${member.teamLabel} · ${cell.groupLabel} · ${cell.venueLabel} · ${member.coachLabel} · ${cell.startLabel}–${cell.endLabel}${undefined !== memberDeviatedOrigin ? ` · en saison : ${memberDeviatedOrigin}` : ""}`)}
                        className={cn(
                          "flex w-full items-center gap-1 px-1 py-0.5 pr-6 text-left font-medium hover:ring-1 hover:ring-accent disabled:cursor-not-allowed disabled:opacity-50",
                          memberSelected ? "ring-1 ring-accent" : "",
                          isSource(member.slotId) ? "animate-pulse ring-2 ring-accent" : "",
                        )}
                      >
                        {undefined !== memberDeviatedOrigin ? renderDeviationChip(memberDeviatedOrigin) : null}
                        {lensActive && null === uniformOrigin && null !== member.lockOrigin ? renderLensInline(member.lockOrigin) : null}
                        <span className="truncate">{member.teamLabel}</span>
                      </button>
                      {renderLock(member.slotId, member.teamLabel, member.locked)}
                    </div>
                  );
                })}
                {null !== uniformOrigin ? renderLensBadge(uniformOrigin) : null}
              </div>
            );
          }
          const selected = cell.slotId === selectedSlotId;
          const closedReason = closedReasonOf(cell.venueId, cell.day);
          // P2-44 PR-4 — écart au socle. Le CONFLIT prime : une carte occupée qui fait partie du
          // surlignage conflit n'a JAMAIS porté d'anneau (le surlignage = l'estompe des autres,
          // cf. `dimmed`), elle n'en gagne pas un `diff` non plus. La SÉLECTION prime
          // (`ring-accent`), un anneau de lentille prime — mais le symbole ⇄ reste dans tous les
          // cas (source auto-explicite, même principe que la lentille, cf. props `lockLens`/
          // `highlightSlotIds` ci-dessus).
          const deviatedOrigin = deviatedSlots?.get(cell.slotId);
          const deviated = undefined !== deviatedOrigin;
          const flagged = highlighting && (highlightSlotIds?.has(cell.slotId) ?? false);
          const lensRing = lensActive && null !== cell.lockOrigin;
          return (
            // Wrapper positionné : la CARTE (bouton de sélection) et le CADENAS (bouton de
            // verrou) sont deux boutons FRÈRES — jamais l'un dans l'autre (HTML invalide). Le
            // `data-slot-id` + l'estompe portent sur le wrapper (cible du scroll/surlignage) ;
            // `group` scope le survol/focus du cadenas à cette carte.
            <div
              key={cell.key}
              data-slot-id={cell.slotId}
              data-target-source={isSource(cell.slotId) ? "true" : undefined}
              className={cn(
                "group relative z-10 m-px flex overflow-hidden rounded border-l-4 transition",
                "hover:ring-1 hover:ring-accent",
                selected ? "ring-2 ring-accent" : "",
                dimmed ? "opacity-30" : "",
                // Mode cible : la SOURCE pulse et porte un anneau distinctif.
                isSource(cell.slotId) ? "animate-pulse ring-2 ring-accent" : "",
                // Lentille : sans verrou → estompé ; verrouillé → anneau de sa catégorie.
                lensActive && null === cell.lockOrigin ? "opacity-40" : "",
                lensActive && null !== cell.lockOrigin ? LOCK_LENS_META[cell.lockOrigin].ringClass : "",
                // Écart au socle : anneau `diff`, sauf conflit (aucun anneau, comme toute carte
                // occupée surlignée), sélection ou anneau de lentille ; le symbole ⇄, lui, reste.
                deviated && !flagged && !selected && !lensRing ? "ring-1 ring-diff" : "",
              )}
              style={{
                gridColumn: cell.gridColumn,
                gridRow: `${cell.gridRowStart} / span ${cell.gridRowSpan}`,
                justifySelf: "start",
                width: `${100 / cell.laneCount}%`,
                transform: `translateX(${cell.lane * 100}%)`,
                borderLeftColor: cell.venueColor ?? "var(--accent)",
                backgroundColor: tint(cell.venueColor) ?? "var(--muted)",
              }}
            >
              <button
                type="button"
                onClick={() => activateSlot(cell.slotId, cell.locked, closedReason)}
                disabled={targetDisabled(cell.slotId, cell.locked, closedReason)}
                title={cardTitle(cell.slotId, cell.locked, closedReason, `${cell.teamLabel} · ${cell.venueLabel} · ${cell.coachLabel} · ${cell.startLabel}–${cell.endLabel}${deviated ? ` · en saison : ${deviatedOrigin}` : ""}`)}
                className="flex min-w-0 flex-1 flex-col items-start px-1 py-0.5 text-left leading-tight disabled:cursor-not-allowed disabled:opacity-60"
              >
                <span className="flex w-full items-center gap-1 pr-5 font-medium">
                  {undefined !== deviatedOrigin ? renderDeviationChip(deviatedOrigin) : null}
                  <span className="truncate">{cell.primaryLabel}</span>
                  {/* Opaque accent chip: the translucent bg-accent/20 was dark-on-dark
                      in dark mode (sub-AA). Solid bg-accent reuses the AA-guaranteed
                      accent↔accent-foreground pairing (A11Y-06). */}
                  {cell.roleTag ? <span className="shrink-0 rounded-sm bg-accent px-1 text-[10px] text-accent-foreground">{cell.roleTag}</span> : null}
                </span>
                <span className="truncate text-[10px] text-muted-foreground">{cell.secondaryLabel}</span>
              </button>
              {renderLock(cell.slotId, cell.teamLabel, cell.locked)}
              {lensActive && null !== cell.lockOrigin ? renderLensBadge(cell.lockOrigin) : null}
            </div>
          );
          });
        })()}
      </div>
    </div>
  );
}
