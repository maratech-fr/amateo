import { AlertTriangle, ArrowLeftRight, Check, Info, Lock, LockOpen, MapPinOff, Pencil, Trash2, Undo2, X } from "lucide-react";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { CtcMark } from "@/shared/components/ui/ctc-mark";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { frDateShortNoYear, frDateWeekdayNoYear } from "@/shared/lib/date";
import type { ReadState } from "@/shared/lib/readState";

import type { Conflict, Fixture, PlaceFixtureInput, TeamMatchHabit, Venue, VenueClosure, VenueMatchWindow, VenueUnavailability } from "./api";
import { isInEnvelope, isoWeekday } from "./lib/envelope";
import type { EnvelopeResult } from "./lib/envelope";
import { FIXTURE_STATUS_LABEL } from "./lib/fixtureStatusLabel";
import { applicableWindows, dateInsideClosure, hasWeeklyAccess, matchVenueIds, proposedOpeningRange, venueAccessError } from "./lib/matchAccess";
import { unplacedReasonLabel } from "./lib/unplacedReasonLabel";
import { OpenVenueDialog } from "./OpenVenueDialog";
import { MATCHES_TEXTS } from "./texts";

/**
 * The read state of the four club-owned guards the placement gesture leans on
 * (match access windows, venue unavailabilities, calendar venue closures, league
 * envelope). The gesture is SUSPENDED unless all four are `ready`: a `failed` first-load must never
 * fabricate « no window → nothing to enforce » and slip a match into a restricted
 * gym; a `loading` read is simply not ready yet. Derived by the page (readState).
 */
export interface PlacementGuards {
  state: ReadState;
  matchWindows: VenueMatchWindow[];
  unavailabilities: VenueUnavailability[];
  /** P4-300 — les fermetures de gymnase du calendrier : un gymnase fermé à la date du match refuse la pose. */
  closures: VenueClosure[];
  /** PR2 — les dates `Y-m-d` fériées (feed GET /api/public-holidays) : un match posé un jour férié se juge sur la ligne « Jours fériés », pas la fenêtre du jour de semaine. */
  holidays: string[];
  retry: () => void;
}

interface PlacementPanelProps {
  fixture: Fixture;
  venues: Venue[];
  /** D2 — the club-owned guards + their read state (suspends the gesture). */
  guards: PlacementGuards;
  /** P1-4 PR C — the team's habitual windows: prefill + hint, never a guard. */
  habits: TeamMatchHabit[];
  teamLabel: string;
  /** L'équipe du match est-elle « en entente (CTC) » ? Repère CtcMark devant le titre. */
  isCtc?: boolean;
  /** Le/les partenaire(s) de l'entente (nom accessible du repère) ; null hors entente. */
  ctcPartner?: string | null;
  categoryLabel: string;
  envelope: EnvelopeResult;
  busy: boolean;
  /** La durée du match de sa catégorie : elle borne la plage proposée par le raccourci d'ouverture. */
  matchMinutes: number;
  /** Les conflits du club : le panneau y lit l'entraînement qui occupe le gymnase de CE match. */
  conflicts: Conflict[];
  /** Le nom d'une équipe, pour nommer les séances du signal ; absent = les plages seules. */
  teamNameOf?: (teamId: string) => string | null;
  onClose: () => void;
  onPlace: (input: PlaceFixtureInput) => void;
  /** P1-4 PR E1 — manual loop on an already-placed match. */
  onUnplace: () => void;
  onToggleLock: () => void;
  onStartSwap: () => void;
  onEdit: () => void;
  onDelete: () => void;
  /** RMM-1 PR 1 — close the weekly loop: mark the placed match saisi dans FBI. */
  onSubmit: () => void;
  /** RMM-1 PR 1 — repair path: hand a SUBMITTED match back to PLACED. */
  onReopen: () => void;
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4 py-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium">{value}</span>
    </div>
  );
}

/** Envelope guidance line: HARD when the team maps, advisory reference otherwise. */
function EnvelopeHint({ envelope, kickoff }: { envelope: EnvelopeResult; kickoff: string }) {
  if (envelope.mapped && envelope.holiday) {
    return (
      <p className="flex items-center gap-1 text-xs text-muted-foreground">
        <Info className="size-3.5" />
        {MATCHES_TEXTS.leagueHolidayNote}
      </p>
    );
  }
  if (!envelope.mapped) {
    if (0 === envelope.windows.length) {
      return null;
    }
    const windows = envelope.windows.map((w) => `${w.kickoffMin}–${w.kickoffMax}`).join(", ");
    return <p className="text-xs text-muted-foreground">{MATCHES_TEXTS.panelEnvelopeHintIndicative(windows)}</p>;
  }
  const ok = isInEnvelope(envelope, kickoff);
  return (
    <p className={`flex items-center gap-1 text-xs ${ok ? "text-success" : "text-warning"}`}>
      {ok ? <Check className="size-3.5" /> : <AlertTriangle className="size-3.5" />}
      {ok ? MATCHES_TEXTS.panelEnvelopeOk : MATCHES_TEXTS.panelEnvelopeOut}
    </p>
  );
}

/** Le signal d'un match posé : un entraînement d'une autre équipe occupe son gymnase. Il ne bloque rien. */
function TrainingOverlapNote({ fixtureId, conflicts, venues, teamNameOf }: { fixtureId: string; conflicts: Conflict[]; venues: Venue[]; teamNameOf?: (teamId: string) => string | null }) {
  const conflict = conflicts.find((c) => "VENUE_TRAINING_OVERLAP" === c.type && c.fixture?.fixtureId === fixtureId);
  const trainings = conflict?.trainings ?? [];
  if (undefined === conflict || 0 === trainings.length) {
    return null;
  }
  const venue = venues.find((v) => v.id === conflict.venueId)?.name ?? MATCHES_TEXTS.panelFallbackVenue;
  const one = 1 === trainings.length;
  const details = trainings
    .map((t) => {
      const name = teamNameOf?.(t.teamId) ?? null;
      const range = `${t.start} – ${t.end}`;
      return null === name ? range : `${range}${one ? "," : ""} ${name}`;
    })
    .join(", ");
  const phrase = one ? MATCHES_TEXTS.trainingOverlapOne(venue, details) : 2 === trainings.length ? MATCHES_TEXTS.trainingOverlapTwo(venue, details) : MATCHES_TEXTS.trainingOverlapMany(trainings.length, venue, details);
  return (
    <p className="mt-2 flex items-start gap-1 text-xs text-muted-foreground">
      <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
      <span>{phrase}</span>
    </p>
  );
}

/**
 * Place / re-place a home fixture and drive the manual loop (P1-4 PR E1):
 * unplace, lock/hand back to the solver, swap, edit, delete. Two HARD guards on
 * the placement gesture itself: the league envelope (when the team maps) and the
 * club's capacity data (PR B). RMM-1 PR 1 closes the weekly loop: a PLACED match
 * can be marked « Saisi dans FBI » (SUBMITTED, anchored). Both SUBMITTED and
 * VALIDATED (batch « validé ligue », lot L) keep the « Corriger » repair path
 * back to PLACED — a batch bascule can rest on an auto-matched venue, so the
 * manager must always be able to unwind it.
 */
export function PlacementPanel({
  fixture,
  venues,
  guards,
  habits,
  teamLabel,
  isCtc,
  ctcPartner,
  categoryLabel,
  envelope,
  busy,
  matchMinutes,
  conflicts,
  teamNameOf,
  onClose,
  onPlace,
  onUnplace,
  onToggleLock,
  onStartSwap,
  onEdit,
  onDelete,
  onSubmit,
  onReopen,
}: PlacementPanelProps) {
  const { matchWindows, unavailabilities, closures, holidays } = guards;
  const guardsReady = "ready" === guards.state;
  // Masquer n'est légitime que pour un CHOIX (§7.2.3) : le sélecteur n'offre
  // que les gymnases de match — mais seulement si le club a déclaré des
  // fenêtres quelque part (sinon liste complète, donnée non adoptée). Tant que
  // les gardes ne sont pas prêtes, on garde la liste COMPLÈTE (ne jamais filtrer
  // sur une lecture en cours / en échec).
  // Y restent toujours le gymnase du match (jamais remplacé en silence) et ceux qu'une ouverture
  // accueille ce jour-là.
  const isFriendly = null === fixture.competitionId;
  const adopted = hasWeeklyAccess(matchWindows);
  const isHoliday = holidays.includes(fixture.matchDate);
  const matchIds = matchVenueIds(matchWindows);
  const openedThatDay = new Set(matchWindows.filter((w) => w.openingDate === fixture.matchDate).map((w) => w.venueId));
  const selectableVenues = !guardsReady || !adopted ? venues : venues.filter((v) => matchIds.has(v.id) || v.id === fixture.venueId || openedThatDay.has(v.id));
  const noAccessSubFor = (venueId: string): string | undefined =>
    guardsReady && adopted && !isFriendly && 0 === applicableWindows(venueId, fixture.matchDate, isHoliday, matchWindows).length ? MATCHES_TEXTS.venueNoAccessThatDay : undefined;

  // P4-300 — grisé : l'option d'un gymnase FERMÉ ou INDISPONIBLE à la date du match est inerte,
  // avec une sous-ligne qui dit pourquoi (miroir du refus serveur). Tant que les gardes ne sont
  // pas prêtes, on ne grise rien (ne jamais décider sur une lecture en cours / en échec).
  const blockedSubFor = (venueId: string): string | undefined => {
    if (!guardsReady) {
      return undefined;
    }
    const unavailability = unavailabilities.find((u) => u.venueId === venueId && fixture.matchDate >= u.startDate && fixture.matchDate <= u.endDate);
    if (undefined !== unavailability) {
      const label = null !== unavailability.label ? ` — ${unavailability.label}` : "";
      return `Indisponible du ${frDateShortNoYear(unavailability.startDate)} au ${frDateShortNoYear(unavailability.endDate)}${label}`;
    }
    const closure = closures.find((c) => c.venueId === venueId && dateInsideClosure(fixture.matchDate, c.startDate, c.endDate));
    if (undefined !== closure) {
      return `Fermée du ${frDateShortNoYear(closure.startDate)} au ${frDateShortNoYear(closure.endDate)} — ${closure.title}`;
    }
    return undefined;
  };

  // P1-4 PR C — the team's habit on the MATCH's weekday prefills the empty
  // fields (venue must survive the selectable filter). Guards stay sovereign:
  // a habit prefills, it never unlocks.
  const habit = habits.find((h) => h.teamId === fixture.teamId && h.dayOfWeek === isoWeekday(fixture.matchDate)) ?? null;

  const [venueId, setVenueId] = useState(() => {
    const habitVenue = null !== habit && null !== habit.venueId && selectableVenues.some((v) => v.id === habit.venueId) ? habit.venueId : "";
    const initial = fixture.venueId ?? ("" !== habitVenue ? habitVenue : (selectableVenues[0]?.id ?? ""));
    return "" === initial || selectableVenues.some((v) => v.id === initial) ? initial : (selectableVenues[0]?.id ?? "");
  });
  const [kickoff, setKickoff] = useState(fixture.kickoffTime ?? habit?.kickoffTime ?? "");
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [openingVenue, setOpeningVenue] = useState<Venue | null>(null);

  const placed = "PLACED" === fixture.status;
  // Saisi dans FBI ET Attesté ligue (bascule « validé ligue » en lot — lot L — ou
  // réconciliation d'import) : tous deux ancrés et sortis de l'édition, mais tous
  // deux gardent « Corriger » pour repasser en Placé. Une bascule en lot peut
  // s'appuyer sur un gymnase apparié automatiquement : jamais de cul-de-sac.
  const submitted = "SUBMITTED" === fixture.status;
  const validated = "VALIDATED" === fixture.status;
  const locked = placed && "SOLVER" !== fixture.placementSource;

  // Un amical (competitionId null) n'obéit à aucune enveloppe ligue ni à aucun
  // créneau de match (P4-193, fondateur 2026-09-10) : son placement est LIBRE. Le
  // gymnase indisponible reste le seul refus dur — pour tout le monde.
  const hasKickoff = "" !== kickoff;
  // P4-272 ① — la fenêtre de ligue ne BLOQUE plus la pose manuelle (ligue, club et
  // équipe se comportent pareil : le gestionnaire assume). L'avertissement reste
  // visible (`EnvelopeHint`) et le radar continue de signaler ; le solveur garde,
  // lui, la ligue en HARD. Le gymnase indisponible reste le seul refus dur.
  const venueName = venues.find((v) => v.id === venueId)?.name ?? MATCHES_TEXTS.panelFallbackVenue;
  const accessIssue = "" === venueId ? null : venueAccessError(venueId, venueName, fixture.matchDate, kickoff, matchWindows, unavailabilities, isFriendly, closures, holidays);
  const accessBlocked = null !== accessIssue && "error" === accessIssue.level;
  const unchanged = placed && venueId === (fixture.venueId ?? "") && kickoff === (fixture.kickoffTime ?? "");
  // « Confirmer ce placement » : la rencontre est UNPLACED mais porte DÉJÀ un gymnase et
  // une heure (repris de l'import) et le formulaire les montre encore tels quels — le
  // geste ne CHOISIT rien, il valide ce qui est proposé. Dès qu'une valeur change, le
  // bouton redevient « Placer » (un vrai choix). Sans gymnase/heure d'origine, rien à
  // confirmer.
  const isImportConfirm =
    "UNPLACED" === fixture.status &&
    null !== fixture.venueId &&
    null !== fixture.kickoffTime &&
    venueId === fixture.venueId &&
    kickoff === fixture.kickoffTime;
  // Le raccourci ne répond qu'à un refus d'ACCÈS : une ouverture ne lève ni indisponibilité ni fermeture.
  const canOpenVenue = guardsReady && null !== accessIssue && ("no_window" === accessIssue.reason || "outside_window" === accessIssue.reason);
  const venueToOpen = venues.find((v) => v.id === venueId) ?? null;
  const openingRange = proposedOpeningRange(kickoff, matchMinutes);
  const canPlace = "" !== venueId && hasKickoff && !accessBlocked && !busy && !unchanged && guardsReady;

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle className="flex items-center gap-1.5">
          {locked ? <Lock aria-label={MATCHES_TEXTS.panelAnchorLock} className="size-3.5 text-muted-foreground" /> : null}
          {true === isCtc ? <CtcMark partner={ctcPartner} /> : null}
          {teamLabel}
        </CardTitle>
        <button type="button" onClick={onClose} aria-label={MATCHES_TEXTS.panelClose} className="rounded p-1 text-muted-foreground hover:text-foreground">
          <X className="size-4" />
        </button>
      </CardHeader>
      <CardContent className="pt-0">
        <Row label={MATCHES_TEXTS.panelCategoryRow} value={categoryLabel} />
        <Row label={MATCHES_TEXTS.panelOpponentRow} value={fixture.opponentLabel} />
        <Row label={MATCHES_TEXTS.panelDateRow} value={frDateWeekdayNoYear(fixture.matchDate)} />
        <TrainingOverlapNote fixtureId={fixture.id} conflicts={conflicts} venues={venues} teamNameOf={teamNameOf} />

        {submitted ? (
          <div className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
            <div>
              <p className="text-sm font-medium">{FIXTURE_STATUS_LABEL.SUBMITTED}</p>
              <p className="mt-1 text-xs text-muted-foreground">{MATCHES_TEXTS.panelSubmittedNote}</p>
            </div>
            <Button variant="ghost" size="sm" disabled={busy} className="self-start text-muted-foreground" onClick={onReopen}>
              <Undo2 className="size-3.5" />
              Corriger — repasser en Placé
            </Button>
          </div>
        ) : validated ? (
          <div className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
            <div>
              <p className="text-sm font-medium">{FIXTURE_STATUS_LABEL.VALIDATED}</p>
              <p className="mt-1 text-xs text-muted-foreground">{MATCHES_TEXTS.panelValidatedNote}</p>
            </div>
            <Button variant="ghost" size="sm" disabled={busy} className="self-start text-muted-foreground" onClick={onReopen}>
              <Undo2 className="size-3.5" />
              Corriger — repasser en Placé
            </Button>
          </div>
        ) : (
          <div className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
            {placed ? (
              <Button variant="outline" size="sm" disabled={busy} className="border-success/40 text-success hover:bg-success/10" onClick={onSubmit}>
                <Check className="size-3.5" />
                {MATCHES_TEXTS.panelMarkSubmitted}
              </Button>
            ) : null}
            <div className="grid grid-cols-2 gap-2">
              <VenueSelect
                aria-label={MATCHES_TEXTS.panelVenueField}
                placeholder={MATCHES_TEXTS.panelVenuePlaceholder}
                venues={selectableVenues.map((v) => {
                  const blocked = blockedSubFor(v.id);
                  return { id: v.id, name: v.name, color: v.color, sub: blocked ?? noAccessSubFor(v.id), disabled: undefined !== blocked };
                })}
                value={venueId}
                onValueChange={setVenueId}
              />
              <Input aria-label={MATCHES_TEXTS.panelKickoffField} type="time" value={kickoff} onChange={(e) => setKickoff(e.target.value)} />
            </div>

            {/* P2-52 — rappel de la raison PERSISTANTE « le gymnase n'est plus affilié » : INFO
                calme (muted + MapPinOff), tournée vers le prochain geste. Re-placer efface la
                raison côté serveur — c'est exactement ce que ce panneau fait. */}
            {"venue_lost" === fixture.unplacedReason ? (
              <p className="flex items-start gap-1 text-xs text-muted-foreground">
                <MapPinOff className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                {MATCHES_TEXTS.panelVenueLostHint(unplacedReasonLabel(fixture.unplacedReason) ?? "")}
              </p>
            ) : null}

            {null !== habit ? (
              <p className="text-xs text-muted-foreground">
                {MATCHES_TEXTS.panelHabit(habit.kickoffTime, null !== habit.venueId ? (venues.find((v) => v.id === habit.venueId)?.name ?? "?") : null)}
              </p>
            ) : null}
            {hasKickoff ? <EnvelopeHint envelope={envelope} kickoff={kickoff} /> : null}
            {/* D2 — le geste s'appuie sur quatre lectures du club (accès match,
                indisponibilités, fermetures du calendrier, enveloppe ligue). Tant qu'elles ne sont pas prêtes,
                le placement est SUSPENDU : un échec ne doit jamais se lire « aucune
                restriction ». Les autres gestes (dé-placer, verrouiller, échanger,
                modifier, supprimer) restent actifs. */}
            {"failed" === guards.state ? (
              <LoadErrorHint onRetry={guards.retry}>
                {MATCHES_TEXTS.panelGuardsFailed}
              </LoadErrorHint>
            ) : "loading" === guards.state ? (
              <p className="flex items-center gap-1 text-xs text-muted-foreground">
                <Spinner className="size-3.5" /> {MATCHES_TEXTS.panelGuardsLoading}
              </p>
            ) : null}
            {guardsReady && null !== accessIssue ? (
              "error" === accessIssue.level ? (
                <p className="flex items-center gap-1 text-xs text-warning">
                  <AlertTriangle className="size-3.5" />
                  {accessIssue.message}
                </p>
              ) : (
                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                  <Info className="size-3.5" />
                  {accessIssue.message}
                </p>
              )
            ) : null}

            {canOpenVenue && null !== venueToOpen ? (
              <Button variant="outline" size="sm" onClick={() => setOpeningVenue(venueToOpen)}>
                {MATCHES_TEXTS.openVenueAction}
              </Button>
            ) : null}

            {isImportConfirm ? <p className="text-xs text-muted-foreground">{MATCHES_TEXTS.panelImportConfirmHint}</p> : null}
            <Button size="sm" disabled={!canPlace} onClick={() => onPlace({ venueId, kickoffTime: kickoff })}>
              {isImportConfirm ? MATCHES_TEXTS.panelConfirmImport : placed ? MATCHES_TEXTS.panelMove : MATCHES_TEXTS.panelPlace}
            </Button>

            {placed ? (
              <div className="grid grid-cols-2 gap-1.5">
                <Button variant="outline" size="sm" disabled={busy} onClick={onUnplace}>
                  <Undo2 className="size-3.5" />
                  {MATCHES_TEXTS.panelUnplace}
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={busy}
                  title={locked ? MATCHES_TEXTS.panelUnlockHint : MATCHES_TEXTS.panelLockHint}
                  onClick={onToggleLock}
                >
                  {locked ? <LockOpen className="size-3.5" /> : <Lock className="size-3.5" />}
                  {locked ? MATCHES_TEXTS.panelUnlock : MATCHES_TEXTS.panelLock}
                </Button>
                <Button variant="outline" size="sm" disabled={busy} className="col-span-2" onClick={onStartSwap}>
                  <ArrowLeftRight className="size-3.5" />
                  {MATCHES_TEXTS.panelSwap}
                </Button>
              </div>
            ) : null}

            <div className="grid grid-cols-2 gap-1.5 border-t border-border pt-2">
              <Button variant="outline" size="sm" disabled={busy} onClick={onEdit}>
                <Pencil className="size-3.5" />
                {MATCHES_TEXTS.panelEdit}
              </Button>
              <Button variant="outline" size="sm" disabled={busy} onClick={() => setConfirmDelete(true)}>
                <Trash2 className="size-3.5" />
                {MATCHES_TEXTS.panelDelete}
              </Button>
            </div>
          </div>
        )}
      </CardContent>

      <ConfirmDialog
        open={confirmDelete}
        title={MATCHES_TEXTS.panelDeleteTitle(fixture.opponentLabel)}
        description={MATCHES_TEXTS.panelDeleteDescription}
        confirmLabel={MATCHES_TEXTS.panelDelete}
        destructive
        onConfirm={() => {
          setConfirmDelete(false);
          onDelete();
        }}
        onCancel={() => setConfirmDelete(false)}
      />

      {null !== openingVenue ? (
        <OpenVenueDialog
          venue={openingVenue}
          matchDate={fixture.matchDate}
          defaultStart={openingRange.start}
          defaultEnd={openingRange.end}
          onClose={() => setOpeningVenue(null)}
          onOpened={() => undefined}
        />
      ) : null}
    </Card>
  );
}
