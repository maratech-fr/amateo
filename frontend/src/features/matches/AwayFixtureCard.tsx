import { Bus } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { formatDurationMinutes, formatMinutes, parseTime } from "@/shared/lib/time";

import type { Fixture, Team, TeamMatchHabit } from "./api";
import { awayHour } from "./lib/awayKickoff";
import { FIXTURE_STATUS_LABEL } from "./lib/fixtureStatusLabel";
import { matchMinutesOf } from "./lib/weekendGrid";

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
  onClose,
}: {
  fixture: Fixture;
  teams: Map<string, Team>;
  habits: TeamMatchHabit[];
  matchDurations: Map<string, number>;
  onClose: () => void;
}) {
  const teamLabel = teams.get(fixture.teamId)?.name ?? "Équipe ?";
  const { hour, estimated } = awayHour(fixture, habits);
  const duration = matchMinutesOf(fixture.teamId, teams, matchDurations);
  const travel = fixture.awayTravel;
  const place = travel?.venueLabel ?? fixture.fbiVenueLabel ?? travel?.city ?? null;
  const oneWay = travel?.oneWayMinutes ?? null;
  const approx = true === travel?.approximated ? "~" : "";

  // Départ ≈ coup d'envoi − trajet ; retour ≈ (coup d'envoi + durée) + trajet — arithmétique
  // d'AFFICHAGE, marquée « estimé ». Rendue seulement si l'heure ET le trajet sont connus.
  const kickoffMin = parseTime(hour);
  const departure = null !== oneWay && null !== kickoffMin ? formatMinutes(kickoffMin - oneWay) : null;
  const back = null !== oneWay && null !== kickoffMin ? formatMinutes(kickoffMin + duration + oneWay) : null;
  const showWindow = null !== departure && null !== back;

  const rows: { label: string; value: string }[] = [
    { label: "Adversaire", value: fixture.opponentLabel },
    { label: "Lieu", value: null !== place ? place : "Lieu inconnu" },
    { label: "Date", value: frDateWeekdayNoYear(fixture.matchDate) },
    { label: "Coup d'envoi", value: null !== hour ? `${hour}${estimated ? " (estimé)" : ""}` : "heure inconnue" },
    { label: "Durée du match", value: formatDurationMinutes(duration) },
    { label: "Trajet", value: null !== oneWay ? `${approx}${oneWay} min` : "trajet indisponible" },
    ...(showWindow ? [{ label: "Départ / retour estimés", value: `≈ ${departure} → ≈ ${back}` }] : []),
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
