import { useLayoutEffect, useRef } from "react";

import type { Closure } from "@/features/cockpit/api";
import type { VenueMatchWindow } from "@/features/matches/api";
import { formatDuration } from "@/shared/lib/duration";
import { cn } from "@/shared/lib/utils";

import type { Venue, VenueTrainingSlot } from "../api";
import { fmtMinutes as fmt, hhmm, toMinutes as startMinutes } from "../lib/days";
import { closureForSlot, closureLabel } from "../lib/venueClosures";
import { END_MIN, gridTemplateColumns, ROW_H, START_MIN, STEP, WEEK } from "../lib/weekGrid";

interface Props {
  venue: Venue;
  slots: VenueTrainingSlot[];
  selectedSlotId: string | null;
  onAdd: (dayOfWeek: number, startTime: string) => void;
  onSelect: (slot: VenueTrainingSlot) => void;
  /**
   * Fenêtres d'accès MATCH du gymnase, rendues en fantôme derrière les créneaux.
   * Optionnel et vide par défaut : une fenêtre match est saison-globale (aucun
   * `schedulePlanId` sur `VenueMatchWindow`), donc l'éditeur de PÉRIODE ne la
   * passe pas — il n'a rien à montrer dont il puisse répondre.
   */
  matchWindows?: VenueMatchWindow[];
  /**
   * Fermetures de gymnase (P2-22, D1) — venue-scoped. Un créneau tombant un jour fermé est
   * rendu barré + libellé d'indispo (grain JOUR strict). Vide par défaut : la grille de la
   * SAISON (VenuesStep) n'a pas de fermeture datée à montrer.
   */
  closures?: Closure[];
}

export function VenueAvailabilityGrid({ venue, slots, selectedSlotId, onAdd, onSelect, matchWindows = [], closures = [] }: Props) {
  const color = venue.color ?? "var(--accent)";

  // La plage verticale est l'UNION de la plage de saisie (08h→23h) et de ce que les
  // créneaux occupent réellement.
  //
  // ⚠ Deux tentatives ratées avant celle-ci, gardées en mémoire parce qu'elles disent ce
  // qu'il ne faut PAS faire. (1) Laisser la ligne se calculer librement : un créneau à
  // 07:00 donnait `grid-row: -2`, que CSS compte depuis la FIN de la grille — le bloc
  // s'affichait à 22:45 sous le libellé « 07:00 ». (2) BORNER la ligne : le bloc venait
  // alors se poser sur la première ligne, recouvrant quatre cellules vides qui ne
  // répondaient plus au clic, et masquant purement et simplement un créneau légitime de
  // 08:00 rendu avant lui — un créneau qui part au solveur et devient inatteignable à
  // l'écran. Borner déplace le mensonge, il ne l'enlève pas.
  //
  // Étendre la grille est la seule réponse qui ne cache ni ne déplace rien — et c'est déjà
  // ce que fait `ReservationGrid`, dont la plage suit ses données.
  //
  // Les fenêtres MATCH entrent dans cette union au même titre que les créneaux : une
  // fenêtre 07:00→09:00 hors plage rejouerait mot pour mot le `grid-row: -2` ci-dessus,
  // à ceci près qu'un fantôme relogé ment sans qu'on puisse le cliquer pour s'en rendre
  // compte. Ce qui est rendu borne la grille — sans exception.
  const starts = [...slots.map((s) => startMinutes(s.startTime)), ...matchWindows.map((w) => startMinutes(w.startTime))];
  const ends = [...slots.map((s) => startMinutes(s.startTime) + s.durationMinutes), ...matchWindows.map((w) => startMinutes(w.endTime))];
  const gridStart = Math.min(START_MIN, ...starts.map((m) => Math.floor(m / 60) * 60));
  const gridEnd = Math.max(END_MIN, ...ends.map((m) => Math.ceil(m / 60) * 60));
  const rows = Array.from({ length: (gridEnd - gridStart) / STEP }, (_, i) => gridStart + i * STEP);
  const gridTemplateRows = `1.5rem repeat(${rows.length}, ${ROW_H}px)`;

  // P4-107 (4ᵉ tranche) — **la vue s'ouvre sur la bande UTILE, sans que rien ne soit masqué.**
  //
  // La plage reste 08:00→23:00 (étendue si besoin) parce qu'on CRÉE ici des créneaux au clic :
  // rogner rendrait 09:00 inatteignable, et P4-37 interdit de cacher ce qui existe. Mais sur un
  // club réel tout se passe entre 17:30 et 21:00 : l'écran s'ouvrait donc sur neuf heures vides,
  // et la bande utile était coupée en bas.
  //
  // ⚠ Trois précautions, chacune pour une raison :
  //  1. `useLayoutEffect` et non `useEffect` : la position est posée AVANT la peinture, sinon on
  //     voit la grille à 08:00 puis sauter.
  //  2. **Jamais `smooth`.** Au montage, l'utilisateur n'a jamais vu 08:00 — il n'y a aucun saut
  //     à adoucir, et animer fabriquerait le « forced scroll effect » que le corpus de design
  //     interdit. Rien ne bouge, donc rien à annoncer non plus : le DOM est identique, seul
  //     l'offset diffère, et la gouttière d'heures collante affiche « 17:30 » — elle dit à elle
  //     seule qu'on n'est pas au début.
  //  3. **On ne reprend JAMAIS la main** sur un défilement déjà fait par l'utilisateur : le
  //     repositionnement ne se rejoue qu'au changement de GYMNASE (chaque gymnase a sa propre
  //     bande utile), gardé par un ref — un state re-rendrait pour rien.
  const scrollBoxRef = useRef<HTMLDivElement>(null);
  const positionedFor = useRef<string | null>(null);
  const firstSlotMin = slots.length > 0 ? Math.min(...slots.map((s) => startMinutes(s.startTime))) : null;
  useLayoutEffect(() => {
    const box = scrollBoxRef.current;
    if (null === box || positionedFor.current === venue.id) {
      return;
    }
    positionedFor.current = venue.id;
    // Grille vide : rien à viser, on reste en haut (c'est aussi ce que voit un club neuf).
    if (null === firstSlotMin) {
      return;
    }
    // Une heure de marge au-dessus du premier créneau : on veut voir qu'il y a « avant ».
    const target = Math.max(0, ((firstSlotMin - 60 - gridStart) / STEP) * ROW_H);
    box.scrollTop = target;
  }, [venue.id, firstSlotMin, gridStart]);

  return (
    // P4-37 — la grille DÉFILE en interne au lieu de pousser la page. Elle n'avait aucune
    // borne verticale : passer de 22h à 23h l'aurait allongée d'autant, aggravant un
    // problème qui existait déjà. `max-h` + `overflow-y-auto` la bornent au viewport.
    <div ref={scrollBoxRef} className="max-h-[min(70vh,40rem)] overflow-auto rounded-lg border border-border bg-card">
      <div className="grid text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
        {/* Coin figé sur les DEUX axes : il masque la gouttière quand elle défile sous
            l'en-tête, sinon les heures passeraient par-dessus les noms de jours. */}
        <div className="sticky left-0 top-0 z-40 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: 1 }} />
        {WEEK.map((d, i) => (
          <div key={d.n} className="sticky top-0 z-30 border-b border-l border-border bg-card py-0.5 text-center font-medium" style={{ gridColumn: 2 + i, gridRow: 1 }}>
            {d.label}
          </div>
        ))}

        {/* Time gutter — label on the hour */}
        {rows.map((m, i) => (
          // Empilement STRICT, sans égalité : créneaux (z-10) < gouttière (z-20) <
          // en-têtes de jours (z-30) < coin (z-40). À égalité, c'est l'ordre du DOM qui
          // tranche — la gouttière était à z-10 comme les créneaux, rendus APRÈS elle,
          // donc ils repeignaient par-dessus les heures pendant le défilement horizontal
          // que la 7ᵉ colonne rend maintenant courant sur petit écran.
          // La gouttière reste lisible pendant le défilement HORIZONTAL : sans `sticky`,
          // les heures disparaissaient sous les colonnes dès qu'on faisait défiler.
          <div key={`t${m}`} className="sticky left-0 z-20 border-r border-border bg-card pr-1 text-right text-[10px] text-muted-foreground" style={{ gridColumn: 1, gridRow: 2 + i }}>
            {0 === m % 60 ? fmt(m) : ""}
          </div>
        ))}

        {/* Empty clickable cells */}
        {WEEK.map((d, di) =>
          rows.map((m, ri) => (
            <button
              key={`c${d.n}-${m}`}
              type="button"
              aria-label={`${d.label} ${fmt(m)}`}
              onClick={() => onAdd(d.n, fmt(m))}
              className={cn(
                // Frontière de JOUR épaissie (retour fondateur 2026-08-05 : isoler un
                // jour d'un coup d'œil) + pause méridienne 12h-14h en fond rosé —
                // TEINTE DE FOND seulement : les créneaux (z-10, fond opaque) passent
                // devant et gardent leur couleur.
                "border-l border-t border-border/40 hover:bg-muted",
                0 === m % 60 ? "border-t-border/70" : "",
                "border-l-2 border-l-border",
                m >= 12 * 60 && m < 14 * 60 ? "bg-destructive/5 hover:bg-muted" : "",
              )}
              style={{ gridColumn: 2 + di, gridRow: 2 + ri }}
            />
          )),
        )}

        {/* Fenêtres d'accès MATCH — fantôme, jamais un objet manipulable.
            Rendues APRÈS les cellules vides (elles se voient par-dessus) mais AVANT les
            créneaux, qui restent à z-10 et passent donc devant : un créneau ne peut pas
            être avalé par une plage match qui l'englobe.
            `pointer-events-none` est le cœur du compromis : un entraînement peut être
            accordé DANS la plage match, donc les cellules dessous doivent rester
            posables — le clic traverse le fantôme et atteint la cellule.
            `aria-hidden` sans perte : `MatchWindowsEditor` liste ces mêmes fenêtres en
            texte intégral juste sous la grille, avec leur bouton de suppression. Un
            lecteur d'écran n'y perd rien ; il éviterait ici un doublon décoratif. */}
        {matchWindows.map((window) => {
          const di = WEEK.findIndex((d) => d.n === window.dayOfWeek);
          if (di < 0) {
            return null;
          }
          const startRow = 2 + Math.round((startMinutes(window.startTime) - gridStart) / STEP);
          const span = Math.max(1, Math.round((startMinutes(window.endTime) - startMinutes(window.startTime)) / STEP));
          return (
            <div
              key={`mw-${window.id}`}
              aria-hidden="true"
              className="pointer-events-none z-0 m-px overflow-hidden rounded border border-dashed border-accent/60 px-1 pt-0.5 text-[10px] font-medium leading-tight text-accent"
              style={{
                gridColumn: 2 + di,
                gridRow: `${startRow} / span ${span}`,
                // Hachures : le motif dit « réservé ailleurs » sans jamais ressembler à un
                // créneau posé, qui lui est un aplat opaque.
                backgroundImage:
                  "repeating-linear-gradient(45deg, color-mix(in oklch, var(--accent) 18%, transparent) 0 4px, transparent 4px 9px)",
              }}
            >
              match
            </div>
          );
        })}

        {/* Slots */}
        {slots.map((slot) => {
          const di = WEEK.findIndex((d) => d.n === slot.dayOfWeek);
          if (di < 0) {
            return null;
          }
          // La grille couvrant toute la plage occupée, la ligne et la hauteur se calculent
          // sans borne : le bloc tombe où il est et mesure ce qu'il dure.
          const startRow = 2 + Math.round((startMinutes(slot.startTime) - gridStart) / STEP);
          const span = Math.max(1, Math.round(slot.durationMinutes / STEP));
          const visibleLabel = `${hhmm(slot.startTime)}${slot.capacity > 1 ? ` ·${slot.capacity}` : ""}`;
          // P2-22 D1 — le créneau tombe-t-il un jour fermé ? Barré + libellé d'indispo, jamais
          // une bande de remplacement (grain JOUR strict, lu de `weekdays`).
          const closedBy = closureForSlot(slot, closures);
          const closedText = closedBy ? closureLabel(closedBy) : "";
          return (
            <button
              key={slot.id}
              type="button"
              onClick={() => onSelect(slot)}
              title={`${hhmm(slot.startTime)} · ${formatDuration(slot.durationMinutes)} · cap ${slot.capacity} — cliquer pour modifier`}
              // Le `title` n'est PAS le nom accessible et ne s'affiche jamais au doigt : la
              // durée n'était lisible qu'à la souris. ⚠ Le nom accessible doit CONTENIR le
              // texte visible (WCAG 2.5.3) — sans quoi une commande vocale prononçant ce
              // qui est écrit à l'écran n'atteint plus le bouton. D'où le texte visible en
              // tête, mot pour mot, complété ensuite (le libellé d'indispo compris).
              aria-label={`${visibleLabel} · ${WEEK[di]?.label ?? ""} ${formatDuration(slot.durationMinutes)} · capacité ${slot.capacity}${closedBy ? ` · ${closedText}` : ""} — modifier`}
              className={cn(
                // Full border + OPAQUE fill so a slot is always clearly bounded —
                // the old semi-transparent var(--muted) fill was identical to the
                // empty cells' hover:bg-muted, so hovering the grid made slots
                // "vanish" into the highlighted cells (reliability bug).
                "z-10 m-px flex flex-col items-start overflow-hidden rounded border border-border border-l-4 px-1 text-left text-[10px] font-medium leading-tight hover:ring-1 hover:ring-accent",
                slot.id === selectedSlotId ? "ring-2 ring-accent" : "",
                closedBy ? "line-through" : "",
              )}
              style={{ gridColumn: 2 + di, gridRow: `${startRow} / span ${span}`, borderLeftColor: color, backgroundColor: `color-mix(in oklch, ${color} 30%, var(--card))` }}
            >
              <span>{visibleLabel}</span>
              {closedBy ? <span className="w-full truncate">{closedText}</span> : null}
            </button>
          );
        })}
      </div>
    </div>
  );
}
