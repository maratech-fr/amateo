import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Select } from "@/shared/components/ui/select";
import { formatDuration } from "@/shared/lib/duration";

import { durationOptions } from "../lib/days";
import { pastMidnightMessage } from "../lib/slotOverlap";

const CAP_HINT = "Nombre d'équipes pouvant s'entraîner en même temps sur ce créneau (2 = terrain coupé en deux).";

/** Sentinelle de l'option « Autre… » du sélecteur de durée (D8). */
const OTHER_DURATION = "__other__";

/**
 * Sélecteur de durée de créneau, foyer unique des quatre sélecteurs (saison/période × barre « À
 * poser »/éditeur). L'option « Autre… » en FIN de liste ouvre une saisie libre par pas de 15 min
 * (min 15) ; quand l'heure de début est connue (`startTime`), la fin ne peut pas dépasser minuit.
 * Le bouton de confirmation DIT son motif de désactivation (`disabledReason`, A11Y-30).
 *
 * CHOISIR se restreint, NOMMER jamais : la liste offerte garde les durées non standard déjà
 * présentes (`present`), comme le faisait `durationOptions`.
 */
export function DurationSelect({
  value,
  onChange,
  present = [],
  startTime,
  wrapperClassName,
  className,
  "aria-label": ariaLabel = "Durée",
}: {
  value: number;
  onChange: (minutes: number) => void;
  /** Durées non standard à garder OFFERTES (p.ex. la valeur stockée du créneau). */
  present?: number[];
  /** HH:MM de début si connu — valide « début + durée ≤ minuit » (sinon la pose le fera plus tard). */
  startTime?: string;
  wrapperClassName?: string;
  className?: string;
  "aria-label"?: string;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(String(value));
  const parsed = Number(draft);
  let reason: string | null = null;
  if (!Number.isFinite(parsed) || parsed < 15) {
    reason = "La durée minimale est de 15 minutes.";
  } else if (0 !== parsed % 15) {
    reason = "La durée doit être un multiple de 15 minutes (15, 30, 45…).";
  } else if (undefined !== startTime) {
    // `pastMidnightMessage` nomme l'heure et la durée, ou null si le créneau tient dans la journée.
    reason = pastMidnightMessage(startTime, parsed);
  }
  const apply = (): void => {
    if (null !== reason) {
      return;
    }
    onChange(parsed);
    setEditing(false);
  };

  return (
    <div className="inline-flex flex-col gap-1">
      <Select
        aria-label={ariaLabel}
        wrapperClassName={wrapperClassName}
        className={className}
        value={editing ? OTHER_DURATION : String(value)}
        onChange={(e) => {
          if (OTHER_DURATION === e.target.value) {
            setDraft(String(value));
            setEditing(true);
            return;
          }
          setEditing(false);
          onChange(Number(e.target.value));
        }}
      >
        {durationOptions(value, ...present).map((d) => (
          <option key={d} value={d}>
            {formatDuration(d)}
          </option>
        ))}
        <option value={OTHER_DURATION}>Autre…</option>
      </Select>
      {editing ? (
        <div className="flex flex-col gap-1">
          <div className="flex items-center gap-2">
            <Input
              type="number"
              min={15}
              step={15}
              aria-label="Durée en minutes"
              className="w-24"
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
            />
            <span className="text-xs text-muted-foreground">min</span>
            <Button type="button" size="sm" disabled={null !== reason} disabledReason={reason ?? undefined} onClick={apply}>
              OK
            </Button>
          </div>
          {null !== reason ? (
            <p role="alert" className="text-xs text-destructive">
              {reason}
            </p>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

/**
 * Sélecteur de capacité — seul un gymnase divisible (canSplit) accueille 2 ou 3
 * équipes (terrains en travers — retour fondateur 2026-08-05 : ADN se divise en 3) ;
 * sinon la capacité vaut toujours 1 et le contrôle disparaît.
 *
 * Extrait dans ce module partagé pour que l'éditeur de période le réutilise sans importer
 * `VenuesStep` (qui importe déjà `PeriodVenues` — l'import inverse serait circulaire). Son
 * absence dans l'éditeur de période dégradait en silence un créneau divisible à 1 terrain
 * (revue #8 PR-B round 2).
 */
export function CapacitySelect({ value, onChange, canSplit, className }: { value: number; onChange: (n: number) => void; canSplit: boolean; className?: string }) {
  if (!canSplit) {
    return null;
  }
  return (
    <Select aria-label="Capacité" title={CAP_HINT} className={className} value={value} onChange={(e) => onChange(Number(e.target.value))}>
      <option value={1}>1 équipe (terrain entier)</option>
      <option value={2}>2 équipes (terrain divisé en 2)</option>
      <option value={3}>3 équipes (terrain divisé en 3)</option>
    </Select>
  );
}

/**
 * Guidance affichée dès qu'une capacité ≥ 2 est CHOISIE (valeur du select, pas la valeur
 * enregistrée) : c'est au moment où le gestionnaire crée un créneau partagé qu'il faut lui
 * dire où se choisit QUI le partage — sans réservation, le système associe les équipes
 * lui-même (la capacité dit combien, jamais avec qui — décision P3-8). Module partagé pour
 * la même raison que `CapacitySelect` : les éditeurs de saison ET de période l'affichent.
 * ⚠ « le système », jamais « le solveur » : vocabulaire gestionnaire (docs/glossary.md).
 */
export function SharedSlotHint({ capacity }: { capacity: number }) {
  if (capacity < 2) {
    return null;
  }
  return (
    <NoticeBanner
      tone="muted"
      className="mt-3"
      message={
        <>
          Créneau partagé : choisissez les {capacity} équipes qui l'occuperont en les réservant (étape Contraintes, onglet Réserver). Sans réservation, le système associera les équipes lui-même.
        </>
      }
    />
  );
}

/** Longueur maxi du libellé de groupe — porte d'entrée alignée sur `Assert\Length(max:40)` du backend. */
export const GROUP_LABEL_MAX = 40;

/**
 * Libellé de groupe (« CEC3 ») d'un créneau MUTUALISÉ (P2-17). Facultatif, ≤ 40. N'apparaît
 * QUE lorsque la capacité choisie est ≥ 2 : le backend refuse (422) un libellé sur un créneau
 * non partageable, donc l'écran ne l'offre jamais là — le champ disparaît si l'on redescend à
 * une seule équipe, et l'éditeur n'envoie alors aucun libellé (voir `save`). Purement
 * esthétique : titre la carte fusionnée de la vue planning gymnase, ne rejoint jamais le solveur.
 */
export function GroupLabelField({ capacity, value, onChange }: { capacity: number; value: string; onChange: (v: string) => void }) {
  if (capacity < 2) {
    return null;
  }
  return (
    <label className="mt-3 block text-xs text-muted-foreground">
      Libellé de groupe (optionnel)
      <Input
        aria-label="Libellé de groupe"
        className="mt-0.5 h-9 w-52"
        maxLength={GROUP_LABEL_MAX}
        placeholder="CEC3"
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
      <span className="mt-1 block text-[11px]">Nom affiché sur la carte du planning côté gymnase quand ces équipes partagent le créneau.</span>
    </label>
  );
}
