import { AlertTriangle, Link2 } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { dayLabelShort } from "@/shared/lib/days";
import { assignLanes, type LaneCell } from "@/shared/lib/gridLayout";
import { formatMinutes, formatTimeRange } from "@/shared/lib/time";
import { cn } from "@/shared/lib/utils";

import { END_MIN, gridTemplateColumns, ROW_H, START_MIN, STEP, WEEK } from "../lib/weekGrid";
import { PARC_IMPORT_TEXTS as T } from "./texts";

/**
 * Lot correctif import IA « G2 » PR1 (micro-décisions 9-10) — l'étape « Réservations » de l'assistant
 * d'import rend UNE grille par gymnase, empilées, au MÊME niveau que la grille du wizard
 * (`steps/ReservationGrid.tsx`) : géométrie partagée `wizard/lib/weekGrid.ts`, gouttière d'heures via
 * le foyer unique `formatMinutes` (`shared/lib/time.ts`), voies des cases qui se chevauchent via le
 * foyer partagé `shared/lib/gridLayout.ts::assignLanes`. Le front n'invente aucune règle : les
 * libellés et les RAISONS d'alerte sont des DONNÉES serveur, affichées verbatim.
 *
 *  - PLAGE DYNAMIQUE : chaque grille ne couvre que les heures OCCUPÉES de son gymnase (plus de vide
 *    pleine journée) ; repli sur la plage fixe 08–23 si le gymnase n'a aucune case ;
 *  - l'en-tête porte le nom du gymnase (donnée), suffixé « (à créer) » pour un gymnase pas encore
 *    existant (`gridVenueToCreate`) — le libellé de case ne RÉPÈTE donc pas ce nom ;
 *  - une CASE est un BOUTON (son nom accessible = le libellé) ; cliquer OUVRE l'éditeur de créneau
 *    pré-rempli (`onEditCase(index)`), la clé de case étant STABLE (`case:${index}`) ;
 *  - deux cases qui se chevauchent le même jour prennent des VOIES côte à côte (left/width inline),
 *    jamais superposées ;
 *  - une case EN ALERTE porte ⚠ ET la raison serveur dans son NOM ACCESSIBLE (`caseToVerify(raison)`),
 *    jamais la couleur seule (a11y) ; sa teinte d'alerte vit sur une surface opaque + anneau warning ;
 *  - un BANDEAU récapitulatif (`NoticeBanner` warning) chiffre les cases à vérifier et offre un
 *    bouton de saut par case en alerte (`goToLine` → `onEditCase(index)`).
 */
export interface ParcCaseGridVenue {
  name: string;
  /** Gymnase pas encore existant (sera créé à l'import) → en-tête suffixé « (à créer) ». */
  toCreate: boolean;
}

/** Une case dérivée (un créneau/une réservation), rattachée à son gymnase par le NOM. */
export interface ParcCaseGridCase {
  index: number;
  venueName: string;
  jour: number;
  debut: string;
  duree: number;
  /** Libellé affiché (donnée serveur). */
  libelle: string;
  /** Raison serveur si la case est en alerte (chevauchement, capacité…), sinon null/absente. */
  alerte?: string | null;
  /** Retours fondateur 2026-10-26 — la case est une MUTUALISATION (marquée lien + nom de groupe). */
  mutual?: boolean;
  /** Le nom du groupe mutualisé porté par la case marquée (donnée serveur), sinon null. */
  groupe?: string | null;
  /**
   * Finitions (captures 04/2026-10-26) — la case est une OUVERTURE de gymnase (étape Gymnases) : son
   * libellé est la PLAGE HORAIRE (« 16:00 – 17:30 ») lue du créneau, jamais l'indice BRUT serveur
   * « jour N 16:00 » (le jour vit déjà dans la colonne). Le bouton de saut du bandeau nomme EN PLUS le
   * jour en clair (`dayLabelShort`). Une réservation garde son libellé serveur (équipe · heure).
   */
  opening?: boolean;
}

interface CaseLaneCell extends LaneCell {
  readonly case: ParcCaseGridCase;
  readonly start: number;
  readonly col: number;
}

function minutesOf(hhmm: string): number | null {
  const m = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(hhmm);
  return null === m ? null : Number(m[1]) * 60 + Number(m[2]);
}

function isInAlert(c: ParcCaseGridCase): boolean {
  return "string" === typeof c.alerte && "" !== c.alerte;
}

/**
 * Le libellé de case SANS le nom de son gymnase — il est déjà en en-tête (`reservationLabel` serveur
 * = « équipe · gymnase heure »). On retire le nom puis on resserre les séparateurs orphelins ; si rien
 * ne reste, on garde le libellé entier (ne jamais rendre une case muette).
 */
function withoutVenue(libelle: string, venueName: string): string {
  if ("" === venueName) {
    return libelle;
  }
  const stripped = libelle
    .split(venueName)
    .join("")
    .replace(/\s+/g, " ")
    .replace(/^[\s·]+|[\s·]+$/g, "")
    .trim();
  return "" === stripped ? libelle : stripped;
}

/**
 * Le texte d'une case DANS la grille. Une OUVERTURE (`opening`) se dit par sa PLAGE HORAIRE — foyer
 * `shared/lib/time.ts::formatTimeRange` (début ET fin), jamais l'indice brut « jour N » — le jour
 * étant déjà en colonne ; repli sur le libellé serveur si l'heure est illisible. Une réservation
 * garde son libellé serveur sans le gymnase (déjà en en-tête).
 */
function caseGridText(c: ParcCaseGridCase): string {
  if (true !== c.opening) {
    return withoutVenue(c.libelle, c.venueName);
  }
  const range = formatTimeRange(c.debut, c.duree);
  return "" === range ? withoutVenue(c.libelle, c.venueName) : range;
}

/**
 * Le texte du bouton de SAUT (bandeau) — hors colonne, donc il nomme EN PLUS le jour en clair
 * (`dayLabelShort`) pour une ouverture (« Mer 16:00 – 17:30 »), jamais « jour N ».
 */
function caseJumpText(c: ParcCaseGridCase): string {
  if (true !== c.opening) {
    return withoutVenue(c.libelle, c.venueName);
  }
  const range = formatTimeRange(c.debut, c.duree);
  return "" === range ? withoutVenue(c.libelle, c.venueName) : `${dayLabelShort(c.jour)} ${range}`.trim();
}

/** Le texte VISIBLE d'une case : celle d'un groupe porte le nom du groupe devant ses équipes. */
function caseVisibleText(c: ParcCaseGridCase): string {
  const text = caseGridText(c);
  return true === c.mutual && null != c.groupe && "" !== c.groupe ? `${c.groupe} · ${text}` : text;
}

/**
 * L'infobulle d'une case : « Nom · début → fin ». Le gymnase et l'heure du libellé serveur en sortent,
 * ils y feraient doublon. Une ouverture garde sa plage horaire, déjà complète.
 */
function caseTooltip(c: ParcCaseGridCase, start: number): string {
  const display = caseVisibleText(c);
  if (true === c.opening) {
    return display;
  }
  const name = display.replace(/[\s·]*\d{1,2}:\d{2}$/, "");
  return T.caseTooltip("" === name ? display : name, formatMinutes(start), formatMinutes(start + c.duree));
}

/** La pause de midi, teintée comme dans la grille des gymnases du wizard (`VenueAvailabilityGrid`). */
const isPause = (m: number): boolean => m >= 12 * 60 && m < 14 * 60;

export function ParcCaseGrid({
  venues,
  cases,
  onEditCase,
}: {
  venues: ParcCaseGridVenue[];
  cases: ParcCaseGridCase[];
  onEditCase: (index: number) => void;
}) {
  const alertCases = cases.filter(isInAlert);

  return (
    <div className="flex flex-col gap-4">
      {alertCases.length > 0 ? (
        <NoticeBanner tone="warning" role="status" icon={<AlertTriangle className="size-4 text-warning" />} message={T.gridAlertSummary(alertCases.length)}>
          <div className="flex flex-wrap gap-2">
            {alertCases.map((c) => (
              <Button key={`jump:${c.index}`} type="button" size="sm" variant="outline" onClick={() => onEditCase(c.index)} aria-label={`${T.goToLine} — ${caseJumpText(c)}`}>
                {caseJumpText(c)}
              </Button>
            ))}
          </div>
        </NoticeBanner>
      ) : null}

      {venues.map((venue) => {
        const venueCases = cases.filter((c) => c.venueName === venue.name);

        // Voies des cases qui se chevauchent : assignLanes groupe par colonne (jour) et pose
        // lane/laneCount sur chaque cellule (left/width côte à côte ci-dessous).
        const laneCells: CaseLaneCell[] = [];
        for (const c of venueCases) {
          const col = WEEK.findIndex((d) => d.n === c.jour);
          const start = minutesOf(c.debut);
          if (col < 0 || null === start) {
            continue;
          }
          laneCells.push({ case: c, start, col, gridColumn: col + 2 });
        }
        assignLanes(laneCells.map((cell) => ({ startMin: cell.start, endMin: cell.start + cell.case.duree, cell })));

        // Plage verticale réduite à l'occupé (repli 08–23 si le gymnase n'a aucune case placée).
        const starts = laneCells.map((cell) => cell.start);
        const ends = laneCells.map((cell) => cell.start + cell.case.duree);
        const gridStart = laneCells.length > 0 ? Math.floor(Math.min(...starts) / 60) * 60 : START_MIN;
        const gridEnd = laneCells.length > 0 ? Math.ceil(Math.max(...ends) / 60) * 60 : END_MIN;
        const gridRows = Array.from({ length: Math.max(1, (gridEnd - gridStart) / STEP) }, (_, i) => gridStart + i * STEP);
        const gridTemplateRows = `1.5rem repeat(${gridRows.length}, ${ROW_H}px)`;

        return (
          <section key={venue.name} className="flex flex-col gap-2">
            <h4 className="text-sm font-semibold text-foreground">{venue.toCreate ? T.gridVenueToCreate(venue.name) : venue.name}</h4>
            <div className="overflow-x-auto rounded-lg border border-border bg-card">
              <div className="grid min-w-[36rem] text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
                <div className="sticky left-0 top-0 z-40 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: 1 }} />
                {WEEK.map((d, i) => (
                  <div key={d.n} className="sticky top-0 z-30 border-b border-l border-border bg-card py-0.5 text-center font-medium text-muted-foreground" style={{ gridColumn: 2 + i, gridRow: 1 }}>
                    {dayLabelShort(d.n)}
                  </div>
                ))}

                {/* Gouttière — libellé sur l'heure pleine, via le foyer unique `formatMinutes`. */}
                {gridRows.map((m, i) => (
                  <div key={`t${m}`} className="sticky left-0 z-20 border-r border-border bg-card pr-1 text-right text-[10px] text-muted-foreground" style={{ gridColumn: 1, gridRow: 2 + i }}>
                    {0 === m % 60 ? formatMinutes(m) : ""}
                  </div>
                ))}

                {/* Lignes de grille inertes. */}
                {WEEK.map((d, di) =>
                  gridRows.map((m, ri) => (
                    <div
                      key={`g${d.n}-${m}`}
                      data-pause={isPause(m) ? "true" : undefined}
                      className={cn("border-l border-t border-border/40", 0 === m % 60 ? "border-t-border/70" : "", isPause(m) ? "bg-surface-muted" : "")}
                      style={{ gridColumn: 2 + di, gridRow: 2 + ri }}
                      aria-hidden="true"
                    />
                  )),
                )}

                {/* Cases. */}
                {laneCells.map((cell) => {
                  const c = cell.case;
                  const rowStart = Math.max(0, Math.round((cell.start - gridStart) / STEP)) + 2;
                  const span = Math.max(1, Math.round(c.duree / STEP));
                  const laneCount = cell.laneCount ?? 1;
                  const lane = cell.lane ?? 0;
                  const inAlert = isInAlert(c);
                  const display = caseGridText(c);
                  const isMutual = true === c.mutual;
                  // Nom accessible : l'alerte prime (raison serveur), sinon une mutualisation se dit
                  // « Mutualisation · Groupe … · … » (lien + groupe), une réservation simple garde son
                  // libellé nu. Jamais la couleur/l'icône seules (a11y).
                  const ariaLabel = inAlert ? T.caseToVerify(c.alerte as string) : isMutual ? T.mutualisationCaseLabel(c.groupe ?? "", display) : undefined;
                  return (
                    <button
                      key={`case:${c.index}`}
                      type="button"
                      onClick={() => onEditCase(c.index)}
                      title={caseTooltip(c, cell.start)}
                      aria-label={ariaLabel}
                      style={{
                        gridColumn: cell.col + 2,
                        gridRow: `${rowStart} / span ${span}`,
                        justifySelf: "start",
                        width: `${100 / laneCount}%`,
                        left: `${(lane * 100) / laneCount}%`,
                      }}
                      className={cn(
                        "relative z-10 m-px flex min-h-0 flex-col items-start gap-0.5 overflow-hidden rounded border px-1 py-0.5 text-left text-[10px] leading-tight",
                        inAlert ? "border-warning bg-surface-warning text-foreground ring-2 ring-warning" : "border-border bg-card text-foreground hover:bg-muted",
                      )}
                    >
                      {inAlert ? <AlertTriangle aria-hidden className="size-3 shrink-0 text-warning" /> : isMutual ? <Link2 aria-hidden className="size-3 shrink-0 text-accent" /> : null}
                      <span className="w-full whitespace-normal break-words font-medium text-foreground">{caseVisibleText(c)}</span>
                    </button>
                  );
                })}
              </div>
            </div>
          </section>
        );
      })}
    </div>
  );
}
