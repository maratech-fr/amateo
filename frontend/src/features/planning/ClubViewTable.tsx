import { Lock, LockOpen } from "lucide-react";
import { useEffect } from "react";

import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { tint } from "@/shared/lib/color";
import { cn } from "@/shared/lib/utils";

import type { ClubViewEntry, ClubViewModel } from "./lib/clubView";
import { LOCK_LENS_META } from "./lib/lockLens";
import type { TargetMode } from "./WeekGrid";

interface ClubViewTableProps {
  model: ClubViewModel;
  selectedSlotId: string | null;
  onSelectSlot: (slotId: string) => void;
  highlightSlotIds?: Set<string>;
  /** Absent = lecture seule : le cadenas reste un indicateur passif (comme `WeekGrid`). */
  onToggleLock?: (slotId: string) => void;
  lockLens?: boolean;
  targetMode?: TargetMode;
  onPickTarget?: (cellSlotId: string) => void;
  onCancelTarget?: () => void;
}

/**
 * P3-20 — « la vue par club » : le tableau équipes × jours que seuls les exports PDF/XLS
 * savaient produire (`PdfGenerator::buildMatrixSection`, feuille « Équipes × jours »), mis à
 * l'écran comme 5ᵉ vue du planning. Le modèle vient de `lib/clubView.ts` ; ce composant
 * n'ajoute aucune règle, il rend.
 *
 * ⚑ **Une vue différente, les mêmes gestes** (décision fondateur 2026-08-18) : ce composant
 * porte EXACTEMENT le contrat de props de `WeekGrid` — sélection, cadenas en un clic (bouton
 * FRÈRE, jamais imbriqué), lentille de verrous, surlignage de conflit prioritaire, mode cible,
 * `data-slot-id` pour le clic-diagnostic. La page passe les mêmes handlers aux deux vues :
 * aucun rail de retouche n'est dupliqué.
 *
 * ⚠ **La seule limite, assumée et DITE à l'écran** : en mode cible, une case (équipe, jour)
 * VIDE n'est pas une destination — un couple équipe/jour ne porte ni gymnase ni heure, et les
 * fenêtres libres n'appartiennent à aucune équipe (elles n'entrent donc pas dans ce modèle).
 * Désigner une séance EXISTANTE, elle, fonctionne : la destination se déduit de son placement.
 * Le bandeau renvoie vers une vue temporelle pour poser sur du libre, plutôt que de laisser
 * cliquer dans le vide.
 */
export function ClubViewTable({ model, selectedSlotId, onSelectSlot, highlightSlotIds, onToggleLock, lockLens = false, targetMode, onPickTarget, onCancelTarget }: ClubViewTableProps) {
  const targetActive = true === targetMode?.active;
  const isMoveVariant = "move" === targetMode?.variant;
  const targetSourceId = targetMode?.sourceSlotId ?? null;

  // Échap sort du mode cible sans rien toucher — écoute globale, comme la grille (marche quel
  // que soit le focus, et n'impose aucun rôle interactif au tableau).
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

  if (0 === model.dayColumns.length) {
    return <EmptyBlock>Aucun créneau à afficher pour cette sélection.</EmptyBlock>;
  }

  const allEntries = model.groups.flatMap((g) => g.rows.flatMap((r) => r.cells.flatMap((c) => c.entries)));
  // Mêmes priorités visuelles que la grille : surlignage CONFLIT > mode cible > lentille.
  const highlighting = null != highlightSlotIds && highlightSlotIds.size > 0 && allEntries.some((e) => highlightSlotIds.has(e.slotId));
  const lensActive = lockLens && !highlighting && !targetActive;

  const isSource = (slotId: string): boolean => targetActive && null !== targetSourceId && slotId === targetSourceId;
  // Une séance VERROUILLÉE n'est pas une cible d'éviction (D3 : le backend répondrait 422).
  const targetDisabled = (entry: ClubViewEntry): boolean => targetActive && isMoveVariant && entry.locked && entry.slotId !== targetSourceId;

  const renderEntry = (entry: ClubViewEntry, teamLabel: string) => {
    const dimmed = highlighting && !(highlightSlotIds?.has(entry.slotId) ?? false);
    const selected = entry.slotId === selectedSlotId;
    const disabled = targetDisabled(entry);
    const base = `${teamLabel} · ${entry.venueLabel} · ${entry.startLabel}–${entry.endLabel}`;
    const LensIcon = lensActive && null !== entry.lockOrigin ? LOCK_LENS_META[entry.lockOrigin].Icon : null;

    return (
      // Wrapper positionné : la carte et le CADENAS sont deux boutons FRÈRES (un `<button>` ne
      // peut pas en contenir un autre). `group` scope le survol/focus du cadenas à cette carte.
      <div
        key={entry.slotId}
        data-slot-id={entry.slotId}
        data-target-source={isSource(entry.slotId) ? "true" : undefined}
        className={cn(
          "group relative flex overflow-hidden rounded border-l-4 transition hover:ring-1 hover:ring-accent",
          selected ? "ring-2 ring-accent" : "",
          dimmed ? "opacity-30" : "",
          isSource(entry.slotId) ? "animate-pulse ring-2 ring-accent" : "",
          lensActive && null === entry.lockOrigin ? "opacity-40" : "",
          lensActive && null !== entry.lockOrigin ? LOCK_LENS_META[entry.lockOrigin].ringClass : "",
        )}
        style={{ borderLeftColor: entry.venueColor ?? "var(--accent)", backgroundColor: tint(entry.venueColor) ?? "var(--muted)" }}
      >
        <button
          type="button"
          onClick={() => (targetActive ? onPickTarget?.(entry.slotId) : onSelectSlot(entry.slotId))}
          disabled={disabled}
          title={disabled ? "Ce créneau est verrouillé — déverrouillez-le d'abord" : base}
          className="flex min-w-0 flex-1 items-center gap-1 px-1.5 py-1 pr-6 text-left leading-tight disabled:cursor-not-allowed disabled:opacity-60"
        >
          {null !== LensIcon ? (
            <span data-lens={entry.lockOrigin} aria-hidden="true" className={cn("shrink-0", LOCK_LENS_META[entry.lockOrigin!].textClass)}>
              <LensIcon className="size-3" />
            </span>
          ) : null}
          <span className="truncate font-medium">{entry.venueLabel}</span>
          <span className="shrink-0 text-[10px] text-muted-foreground">{entry.startLabel}</span>
        </button>
        {undefined === onToggleLock ? (
          entry.locked ? <Lock aria-hidden="true" className="pointer-events-none absolute bottom-0.5 right-0.5 size-3 text-muted-foreground" /> : null
        ) : (
          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              onToggleLock(entry.slotId);
            }}
            aria-label={`${entry.locked ? "Déverrouiller" : "Verrouiller"} ${teamLabel}`}
            title={`${entry.locked ? "Déverrouiller" : "Verrouiller"} ${teamLabel}`}
            className={cn(
              "absolute bottom-0.5 right-0.5 z-20 flex size-6 items-center justify-center rounded text-muted-foreground transition hover:bg-accent/20 hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent",
              entry.locked ? "" : "opacity-0 group-hover:opacity-100 group-focus-within:opacity-100",
            )}
          >
            {entry.locked ? <Lock className="size-3" /> : <LockOpen className="size-3" />}
          </button>
        )}
      </div>
    );
  };

  return (
    <div className="flex h-full flex-col gap-2">
      {targetActive ? (
        // La limite est ANNONCÉE, pas découverte par un clic sans effet.
        <p role="status" className="shrink-0 rounded-md border border-accent/40 bg-surface-accent px-3 py-1.5 text-xs leading-tight text-foreground">
          Désignez une séance existante pour {isMoveVariant ? "l'évincer" : "la remplacer"}. Pour poser sur un <strong>créneau libre</strong>, repassez en vue « Par gymnase » ou « Par jour » — un couple équipe/jour ne désigne ni gymnase ni horaire.
        </p>
      ) : null}
      <div className="min-h-0 flex-1 overflow-auto rounded-lg border border-border bg-card">
        <table className="w-full border-collapse text-xs">
          <thead>
            <tr>
              {/* En-tête gelée : la colonne d'équipes reste lisible sur une semaine large. */}
              <th scope="col" className="sticky left-0 top-0 z-30 w-40 border-b border-r border-border bg-muted px-2 py-1 text-left font-semibold">
                Équipe
              </th>
              {model.dayColumns.map((column) => (
                <th key={column.day} scope="col" className="sticky top-0 z-20 border-b border-l border-border bg-muted px-2 py-1 text-center font-semibold">
                  {column.label}
                </th>
              ))}
            </tr>
          </thead>
          {model.groups.map((group, gi) => (
            <tbody key={group.label ?? `flat-${gi}`}>
              {null !== group.label ? (
                <tr>
                  {/* AUD-FRT-26 — rowgroup, pas colgroup : cet en-tête ouvre un <tbody> et chapeaute
                      les LIGNES qui suivent (les équipes du rang), pas des colonnes. */}
                  <th scope="rowgroup" colSpan={1 + model.dayColumns.length} className="border-b border-border bg-muted/40 px-2 py-0.5 text-left text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {group.label}
                  </th>
                </tr>
              ) : null}
              {group.rows.map((row) => (
                <tr key={row.teamId} className="align-top">
                  <th scope="row" className="sticky left-0 z-10 border-b border-r border-border bg-card px-2 py-1 text-left font-medium">
                    <span className="block truncate">{row.teamLabel}</span>
                    {0 === row.sessionCount ? <span className="block text-[10px] font-normal text-warning">aucune séance</span> : null}
                  </th>
                  {row.cells.map((cell) => (
                    <td key={cell.day} className="border-b border-l border-border p-0.5">
                      <div className="flex flex-col gap-0.5">{cell.entries.map((entry) => renderEntry(entry, row.teamLabel))}</div>
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          ))}
        </table>
      </div>
    </div>
  );
}
