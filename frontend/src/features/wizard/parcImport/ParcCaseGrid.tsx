import { AlertTriangle } from "lucide-react";

import { dayLabelShort } from "@/shared/lib/days";
import { cn } from "@/shared/lib/utils";

import { gridTemplateColumns, gridTemplateRows, START_MIN, STEP, WEEK } from "../lib/weekGrid";
import { PARC_IMPORT_TEXTS as T } from "./texts";

/**
 * Lot correctif import IA « G2 » PR1 (micro-décisions 9-10) — l'étape « Réservations » de l'assistant
 * d'import rend UNE grille par gymnase (géométrie partagée `wizard/lib/weekGrid.ts`), empilées. Le
 * front n'invente aucune règle : les libellés et les RAISONS d'alerte sont des DONNÉES serveur,
 * affichées verbatim.
 *
 *  - l'en-tête porte le nom du gymnase (donnée), suffixé « (à créer) » pour un gymnase pas encore
 *    existant (`gridVenueToCreate`) ;
 *  - une CASE est un BOUTON (son nom accessible = le libellé) ; cliquer OUVRE l'éditeur de créneau
 *    pré-rempli (`onEditCase(index)`), la clé de case étant STABLE (`case:${index}`) ;
 *  - une case EN ALERTE porte ⚠ ET la raison serveur dans son NOM ACCESSIBLE (`caseToVerify(raison)`),
 *    jamais la couleur seule (a11y) ; sa teinte d'alerte vit sur une surface opaque + anneau warning.
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
}

function minutesOf(hhmm: string): number | null {
  const m = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(hhmm);
  return null === m ? null : Number(m[1]) * 60 + Number(m[2]);
}

export function ParcCaseGrid({
  venues,
  cases,
  onEditCase,
}: {
  venues: ParcCaseGridVenue[];
  cases: ParcCaseGridCase[];
  onEditCase: (index: number) => void;
}) {
  return (
    <div className="flex flex-col gap-4">
      {venues.map((venue) => {
        const venueCases = cases.filter((c) => c.venueName === venue.name);
        return (
          <section key={venue.name} className="flex flex-col gap-2">
            <h4 className="text-sm font-semibold text-foreground">{venue.toCreate ? T.gridVenueToCreate(venue.name) : venue.name}</h4>
            <div className="overflow-x-auto">
              <div className="grid min-w-[36rem] gap-px" style={{ gridTemplateColumns, gridTemplateRows }}>
                <div aria-hidden="true" />
                {WEEK.map((d) => (
                  <div key={d.n} className="text-center text-xs font-medium text-muted-foreground">
                    {dayLabelShort(d.n)}
                  </div>
                ))}
                {venueCases.map((c) => {
                  const col = WEEK.findIndex((d) => d.n === c.jour);
                  const start = minutesOf(c.debut);
                  if (col < 0 || null === start) {
                    return null;
                  }
                  const rowStart = Math.max(0, Math.round((start - START_MIN) / STEP)) + 2;
                  const span = Math.max(1, Math.round(c.duree / STEP));
                  const inAlert = "string" === typeof c.alerte && "" !== c.alerte;
                  return (
                    <button
                      key={`case:${c.index}`}
                      type="button"
                      onClick={() => onEditCase(c.index)}
                      aria-label={inAlert ? T.caseToVerify(c.alerte as string) : undefined}
                      style={{ gridColumn: col + 2, gridRow: `${rowStart} / span ${span}` }}
                      className={cn(
                        "flex min-h-0 flex-col items-start gap-0.5 overflow-hidden rounded border px-1 py-0.5 text-left text-xs",
                        inAlert ? "border-warning bg-surface-warning text-foreground ring-2 ring-warning" : "border-border bg-card text-foreground hover:bg-muted",
                      )}
                    >
                      {inAlert ? <AlertTriangle aria-hidden className="size-3 text-warning" /> : null}
                      <span className="truncate">{c.libelle}</span>
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
