import { ArrowRightLeft, CalendarClock, Check, MapPin } from "lucide-react";
import { useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { frDateWeekdayNoYear } from "@/shared/lib/date";

import type { AttachVenueLabelInput, Fixture, PendingDeviation, ResolveDeviationInput, Venue } from "./api";
import { DEPOSITED_WARNING, FIELD_LABEL, fieldConsequence, isDeposited } from "./lib/deviationConsequence";
import { FIXTURE_STATUS_LABEL } from "./lib/fixtureStatusLabel";
import { isUnattachedHome } from "./lib/reviewQueue";

const HOME_AWAY_LABEL = { HOME: "Domicile", AWAY: "Extérieur" } as const;

/** « FBI » (dépôt xlsx) ou « API FFBB » (canal API) — le nom humain de la source. */
function sourceLabel(channel: PendingDeviation["channel"]): string {
  return "FBI_XLSX" === channel ? "FBI" : "API FFBB";
}

/** L'état de TRAITEMENT en un mot (présentation pure) — « traité le … » horodaté. */
function treatmentLabel(fixture: Fixture): string {
  if ("REVIEWED" === fixture.reviewState) {
    return null !== fixture.reviewedAt ? `traité le ${frDateWeekdayNoYear(fixture.reviewedAt.slice(0, 10))}` : "traité";
  }
  return "NEW" === fixture.reviewState ? "nouveau" : "déphasé";
}

interface ReviewQueueRowProps {
  fixture: Fixture;
  /** Gymnases actifs du club — alimentent le sélecteur du geste « Rattacher ». */
  venues: Venue[];
  onValidateLine: (fixtureId: string) => void;
  onResolve: (input: ResolveDeviationInput) => void;
  onPlace: (fixture: Fixture) => void;
  onAttach: (input: AttachVenueLabelInput) => void;
  busy: boolean;
}

/**
 * PR-3b — UNE rencontre dans la file de traitement de l'onglet Importer.
 * Présentation pure : le libellé de statut et la conséquence par champ viennent de
 * `lib/` (aucun verdict recalculé). Une rencontre NEW se valide en un clic
 * (« garder l'app » implicite) ; une rencontre déphasée s'arbitre écart par écart
 * (Garder Amateo / Prendre la source), sauf les valeurs auto-appliquées hors
 * périmètre, qui se signalent puis se valident.
 */
export function ReviewQueueRow({ fixture, venues, onValidateLine, onResolve, onPlace, onAttach, busy }: ReviewQueueRowProps) {
  const arbitrable = fixture.pendingDeviations.filter((d) => !d.autoApplied);
  const autoApplied = fixture.pendingDeviations.filter((d) => d.autoApplied);
  // « Valider » en ligne quand il n'y a rien à arbitrer (NEW, ou seulement des
  // valeurs auto-appliquées à acquitter) — un OUT_OF_SYNC à écarts se tranche par champ.
  const canValidateLine = "REVIEWED" !== fixture.reviewState && 0 === arbitrable.length;

  return (
    <li className="flex flex-col gap-2 rounded-md border border-border bg-card p-3">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span className="text-sm font-medium tabular-nums">{frDateWeekdayNoYear(fixture.matchDate)}</span>
        <span className="text-sm">{HOME_AWAY_LABEL[fixture.homeAway]}</span>
        <span className="text-sm text-muted-foreground">vs {fixture.opponentLabel}</span>
        <StatusPill>{FIXTURE_STATUS_LABEL[fixture.status]}</StatusPill>
        <span className="text-xs text-muted-foreground">{treatmentLabel(fixture)}</span>
        <span className="ml-auto flex items-center gap-2">
          <Button variant="ghost" size="sm" onClick={() => onPlace(fixture)}>
            <MapPin className="size-3.5" />
            Placer
          </Button>
          {canValidateLine ? (
            <Button variant="outline" size="sm" disabled={busy} onClick={() => onValidateLine(fixture.id)}>
              <Check className="size-3.5" />
              Valider
            </Button>
          ) : null}
        </span>
      </div>

      {/* Domicile importé sans gymnase : réparation contextuelle, ton NEUTRE (jamais warning). */}
      {isUnattachedHome(fixture) ? <AttachVenueBlock fixture={fixture} venues={venues} onAttach={onAttach} busy={busy} /> : null}

      {/* Valeurs imposées hors périmètre pendant que le match était traité. */}
      {autoApplied.map((d) => (
        <div key={`auto-${d.field}`} className="flex items-start gap-2 rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm">
          <CalendarClock className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
          <span>
            La source a déplacé ce match ({FIELD_LABEL[d.field].toLowerCase()}) au {d.appValue ?? "—"}.
          </span>
        </div>
      ))}

      {/* Écarts à arbitrer : deux colonnes Amateo / source, une décision par champ. */}
      {arbitrable.map((d) => {
        const consequence = fieldConsequence(d.field);
        const src = sourceLabel(d.channel);
        return (
          <div key={`dev-${d.field}`} className="flex flex-col gap-2 rounded-md border border-border bg-muted/30 px-3 py-2">
            <div className="flex items-center gap-2 text-sm font-medium">
              <ArrowRightLeft className="size-4 text-muted-foreground" aria-hidden="true" />
              {FIELD_LABEL[d.field]}
            </div>
            <div className="grid grid-cols-2 gap-3 text-sm">
              <div className="flex flex-col">
                <span className="text-xs uppercase tracking-wide text-muted-foreground">Amateo</span>
                <span className="tabular-nums">{d.appValue ?? "—"}</span>
              </div>
              <div className="flex flex-col">
                <span className="text-xs uppercase tracking-wide text-muted-foreground">{src}</span>
                <span className="tabular-nums">{d.sourceValue ?? "—"}</span>
              </div>
            </div>
            {isDeposited(fixture.status) ? <p className="text-xs text-warning">{DEPOSITED_WARNING}</p> : null}
            <div className="flex flex-col gap-2 sm:flex-row">
              <div className="flex flex-1 flex-col gap-1">
                <Button variant="outline" size="sm" disabled={busy} onClick={() => onResolve({ fixtureId: fixture.id, field: d.field, choice: "keep_app" })}>
                  Garder Amateo
                </Button>
                <span className="text-xs text-muted-foreground">{consequence.keepApp}</span>
              </div>
              <div className="flex flex-1 flex-col gap-1">
                <Button variant="outline" size="sm" disabled={busy} onClick={() => onResolve({ fixtureId: fixture.id, field: d.field, choice: "take_source" })}>
                  Prendre {src}
                </Button>
                <span className="text-xs text-muted-foreground">{consequence.takeFile}</span>
              </div>
            </div>
          </div>
        );
      })}
    </li>
  );
}

/**
 * P4-187b — le geste « Rattacher » d'un domicile importé sans gymnase. Replié
 * (`attachVenueId === null`) : le libellé FBI brut + un bouton discret. Déplié : un
 * `VenueSelect` (pré-sélectionné sur `suggestedVenueId` s'il figure dans la liste,
 * sinon le placeholder) + Confirmer / Annuler. On envoie le libellé BRUT
 * (`fbiVenueLabel`), le serveur le normalise ; l'invalidation fait disparaître le
 * bloc au succès (le prédicat `isUnattachedHome` devient faux), le bloc reste
 * ouvert sur un 422. Ton NEUTRE : c'est une réparation, pas une alerte.
 */
function AttachVenueBlock({ fixture, venues, onAttach, busy }: { fixture: Fixture; venues: Venue[]; onAttach: (input: AttachVenueLabelInput) => void; busy: boolean }) {
  // null = replié ; "" = déplié sans choix (placeholder) ; id = déplié avec choix.
  const [attachVenueId, setAttachVenueId] = useState<string | null>(null);

  // Garde de type ET défensive : le bloc n'est monté que pour un `isUnattachedHome`,
  // donc `fbiVenueLabel` est un libellé — on le narrow pour l'envoyer BRUT.
  const label = fixture.fbiVenueLabel;
  if (null === label) {
    return null;
  }

  const open = (): void => {
    const suggested = fixture.suggestedVenueId;
    setAttachVenueId(null !== suggested && venues.some((v) => v.id === suggested) ? suggested : "");
  };

  return (
    <div className="flex flex-col gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-sm">
      {null === attachVenueId ? (
        <div className="flex flex-wrap items-center gap-2">
          <MapPin className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          <span className="text-muted-foreground">{label} · non rattaché</span>
          <Button variant="outline" size="sm" className="ml-auto" onClick={open}>
            Rattacher
          </Button>
        </div>
      ) : (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <VenueSelect
            venues={venues}
            value={attachVenueId}
            onValueChange={setAttachVenueId}
            placeholder="Choisir un gymnase"
            wrapperClassName="sm:flex-1"
            aria-label={`Choisir le gymnase pour la rencontre du ${frDateWeekdayNoYear(fixture.matchDate)} contre ${fixture.opponentLabel}`}
          />
          <div className="flex gap-2">
            <Button variant="default" size="sm" disabled={"" === attachVenueId || busy} onClick={() => onAttach({ venueId: attachVenueId, label })}>
              Confirmer
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setAttachVenueId(null)}>
              Annuler
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
