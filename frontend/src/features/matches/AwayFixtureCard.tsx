import { Bus } from "lucide-react";
import type { ReactNode } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { OpponentLogo } from "@/shared/components/ui/opponent-logo";
import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { formatDurationMinutes, formatMinutes, parseTime } from "@/shared/lib/time";

import type { Fixture, Team, TeamMatchHabit } from "./api";
import { awayHour, awayTimeline } from "./lib/awayKickoff";
import { FIXTURE_STATUS_LABEL } from "./lib/fixtureStatusLabel";
import { opponentInitials } from "./lib/opponentInitials";
import { matchMinutesOf, warmupMinutesOf } from "./lib/weekendGrid";

/**
 * Correctif 3 (retour terrain 2026-09-27) — la FICHE d'un match à l'extérieur, en LECTURE
 * SEULE. Un extérieur IMPORTÉ (FBI/FFBB) ne se modifie pas ici (la fédération en est la
 * source) : cliquer dessus ouvre cette fiche — adversaire, lieu, date, coup d'envoi, durée,
 * trajet, statut — sans aucun bouton de modification (`WeekWorkbench` réserve l'édition aux
 * extérieurs saisis à la main, `lib/fixtureOrigin.ts`).
 *
 * PRÉSENTATION pure : chaque valeur est LUE (heure servie ou habitude via `awayHour`, durée
 * servie via `matchMinutesOf`, trajet aller `awayTravel.oneWayMinutes`). Le départ/retour sont
 * une simple ARITHMÉTIQUE D'AFFICHAGE (coup d'envoi ∓ trajet), annoncée « estimé » — on ne
 * recalcule AUCUN découpage serveur (🔴 `.claude/rules/frontend.md`).
 */
export function AwayFixtureCard({
  fixture,
  teams,
  habits,
  matchDurations,
  warmupDurations,
  onClose,
}: {
  fixture: Fixture;
  teams: Map<string, Team>;
  habits: TeamMatchHabit[];
  matchDurations: Map<string, number>;
  warmupDurations: Map<string, number>;
  onClose: () => void;
}) {
  const teamLabel = teams.get(fixture.teamId)?.name ?? "Équipe ?";
  const { hour, estimated } = awayHour(fixture, habits);
  const duration = matchMinutesOf(fixture.teamId, teams, matchDurations);
  const warmup = warmupMinutesOf(fixture.teamId, teams, warmupDurations);
  const travel = fixture.awayTravel;
  const place = travel?.venueLabel ?? fixture.fbiVenueLabel ?? travel?.city ?? null;
  const oneWay = travel?.oneWayMinutes ?? null;
  const approx = true === travel?.approximated ? "~" : "";

  // Départ ≈ coup d'envoi − échauffement − trajet ; retour ≈ (coup d'envoi + durée) + trajet —
  // même calcul que la grille (`awayTimeline`, foyer UNIQUE), marqué « estimé ». P4-240 ③ : le
  // DÉPART est estimé dès que l'heure est connue (l'échauffement compte toujours, décision C),
  // le RETOUR seulement si le trajet est connu. Arithmétique d'AFFICHAGE, aucune règle recalculée.
  const kickoffMin = parseTime(hour);
  const timeline = null !== kickoffMin ? awayTimeline(kickoffMin, duration, warmup, oneWay) : null;
  const hasTravel = null !== timeline && null !== timeline.oneWayMinutes;
  const departure = null !== timeline ? formatMinutes(timeline.departureMin) : null;
  const back = hasTravel ? formatMinutes(timeline.returnMin) : null;

  // C1 (P4-267) — l'adresse du gymnase (« 12 av. Jean Jaurès, 38500 Voiron »), servie par le lien
  // apparié quand la donnée fédérale la porte. Un lien ancien (sans rattrapage) ou un repli ville
  // n'en a pas : la ligne « Adresse » DISPARAÎT alors (jamais une ligne vide).
  const cityLine = [travel?.postalCode ?? null, travel?.city ?? null].filter((part): part is string => null !== part && "" !== part).join(" ");
  const addressLine = [travel?.address ?? null, "" !== cityLine ? cityLine : null].filter((part): part is string => null !== part && "" !== part).join(", ");

  // C7 (comme `AwayList`) — logo fédéral de l'adversaire, repli initiales en `md` (fiche = place lisible).
  const rows: { label: string; value: ReactNode }[] = [
    {
      label: "Adversaire",
      value: (
        <span className="inline-flex items-center gap-1.5">
          <OpponentLogo code={fixture.opponentOrganismeCode} hasLogo={null !== fixture.opponentOrganismeCode} initials={opponentInitials(fixture.opponentLabel)} size="md" />
          {fixture.opponentLabel}
        </span>
      ),
    },
    { label: "Lieu", value: null !== place ? place : "Lieu inconnu" },
    ...("" !== addressLine ? [{ label: "Adresse", value: addressLine }] : []),
    { label: "Date", value: frDateWeekdayNoYear(fixture.matchDate) },
    { label: "Coup d'envoi", value: null !== hour ? `${hour}${estimated ? " (estimé)" : ""}` : "heure inconnue" },
    { label: "Durée du match", value: formatDurationMinutes(duration) },
    { label: "Trajet", value: null !== oneWay ? `${approx}${oneWay} min` : "trajet indisponible" },
    ...(hasTravel
      ? [{ label: "Départ / retour estimés", value: `≈ ${departure} → ≈ ${back}` }]
      : null !== departure
        ? [{ label: "Départ estimé", value: `≈ ${departure}` }]
        : []),
    { label: "Statut", value: FIXTURE_STATUS_LABEL[fixture.status] },
  ];

  return (
    <Modal
      label={`Match à l'extérieur — ${teamLabel}`}
      title={
        <span className="flex items-center gap-2">
          <Bus className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          Match à l'extérieur
        </span>
      }
      size="sm"
      onClose={onClose}
      footer={
        <Button variant="outline" size="sm" onClick={onClose}>
          Fermer
        </Button>
      }
    >
      <p className="mb-3 text-sm text-muted-foreground">
        {teamLabel} joue à l'extérieur — cette rencontre vient de la fédération, elle est en lecture seule.
      </p>
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
        {rows.map((row) => (
          <div key={row.label} className="contents">
            <dt className="text-muted-foreground">{row.label}</dt>
            <dd className="text-foreground tabular-nums">{row.value}</dd>
          </div>
        ))}
      </dl>
    </Modal>
  );
}
