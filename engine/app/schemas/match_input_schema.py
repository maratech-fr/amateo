from __future__ import annotations

from datetime import date, time

from pydantic import BaseModel, ConfigDict, Field, model_validator

# P1-4 PR D — match-placement solve (ADR-0003). SEPARATE schemas from the
# weekly training solve: matches carry REAL dates where /generate reasons in
# dayOfWeek templates. One CONTRACT_VERSION covers both endpoints.
#
# Input bombs are bounded at the boundary (same A10 philosophy as
# input_schema.py): the problem is tiny by design, the caps are generous.
MAX_MATCHES = 2000
MAX_MATCH_VENUES = 50
MAX_MATCH_TEAMS = 200
MAX_TEAM_LINKS = 400
MAX_TRAINING_OCCUPANCIES = 20000
MAX_PLAYERS_PER_TEAM = 60  # a shared-player roster stays small; generous cap (mirror of coaches)
MAX_WINDOWS_PER_VENUE = 50
MAX_LEAGUE_WINDOWS_PER_TEAM = 50  # mirror of MAX_WINDOWS_PER_VENUE — a team's league envelope
MAX_UNAVAILABILITIES_PER_VENUE = 100
MAX_CLUB_RULES = 200  # a club's hand-entered match rules stay small; generous cap
MAX_COACH_UNAVAILABILITIES = 500  # several ranges per coach per day are allowed; generous cap
MAX_TIMEOUT_SECONDS = 60


class SerializableModel(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class MatchAccessWindowSchema(SerializableModel):
    """A venue's MATCH access window (city-hall grant): ISO day + same-day range."""

    day_of_week: int = Field(alias="dayOfWeek", ge=1, le=7)
    start: time
    end: time


class MatchVenueUnavailabilitySchema(SerializableModel):
    """All-circumstances closure — inclusive date bounds."""

    start_date: date = Field(alias="startDate")
    end_date: date = Field(alias="endDate")


class MatchVenueSchema(SerializableModel):
    id: str
    name: str = ""
    match_windows: list[MatchAccessWindowSchema] = Field(
        default_factory=list, alias="matchWindows", max_length=MAX_WINDOWS_PER_VENUE
    )
    unavailabilities: list[MatchVenueUnavailabilitySchema] = Field(
        default_factory=list, max_length=MAX_UNAVAILABILITIES_PER_VENUE
    )


class LeagueKickoffWindowSchema(SerializableModel):
    """League-imposed kickoff window for a team (resolved by the backend): the
    KICKOFF must fall inside; the day is part of the rule."""

    day_of_week: int = Field(alias="dayOfWeek", ge=1, le=7)
    kickoff_min: time = Field(alias="kickoffMin")
    kickoff_max: time = Field(alias="kickoffMax")


class ClubRuleSchema(SerializableModel):
    """A club-wide MATCH rule (P4-272 ③): a kickoff constraint over some ISO days.

    ``ruleType`` HARD (the solver HONOURS it — the kickoff must fall in the range on
    every day it covers) | PREFERRED (a penalty W_CLUB_RULE — the solver avoids
    violating it, never blocks). ``kickoffMin``/``kickoffMax`` each bound the kickoff
    and are each optional: « pas après 21h » = max only. A missing bound is OPEN on
    that side. The backend refuses a rule with neither bound; a defensive empty rule
    here is simply a no-op (accepts everything). Friendlies are exempt structurally
    (never handed to the solver).
    """

    rule_type: str = Field(alias="ruleType")  # HARD | PREFERRED
    days_of_week: list[int] = Field(default_factory=list, alias="daysOfWeek", max_length=7)
    kickoff_min: time | None = Field(default=None, alias="kickoffMin")
    kickoff_max: time | None = Field(default=None, alias="kickoffMax")


class CoachUnavailabilitySchema(SerializableModel):
    """A coach's UNAVAILABILITY window (P4-272 ⑤): a range of kickoff instants over some
    ISO days when the coach cannot be there. ALWAYS SOFT — a TO_PLACE match of a team the
    coach coaches, whose kickoff falls INSIDE the window on a covered day, is penalised
    W_COACH_UNAVAILABLE (a nudge, never a block). ``kickoffMin``/``kickoffMax`` each bound
    the window and are each optional: « indispo avant 12h » = max only; a missing bound is
    OPEN on that side. Several ranges per coach and per day are legitimate. The backend
    refuses a window with neither bound; a defensive empty window here is a no-op (matches
    nothing meaningful). Friendlies are exempt structurally (never handed to the solver).
    """

    coach_id: str = Field(alias="coachId")
    days_of_week: list[int] = Field(default_factory=list, alias="daysOfWeek", max_length=7)
    kickoff_min: time | None = Field(default=None, alias="kickoffMin")
    kickoff_max: time | None = Field(default=None, alias="kickoffMax")


class TeamHabitSchema(SerializableModel):
    """The team's habitual window — a kickoff INSTANT, venue optional."""

    day_of_week: int = Field(alias="dayOfWeek", ge=1, le=7)
    kickoff: time
    venue_id: str | None = Field(default=None, alias="venueId")


class TeamCoachRefSchema(SerializableModel):
    coach_id: str = Field(alias="coachId")
    role: str = "MAIN"  # MAIN | ASSISTANT


class MatchTeamSchema(SerializableModel):
    id: str
    name: str = ""
    # [] = the team does not map to a league envelope → NO league HARD (the
    # backend already emitted an INFO diagnostic saying so).
    league_windows: list[LeagueKickoffWindowSchema] = Field(
        default_factory=list, alias="leagueWindows", max_length=MAX_LEAGUE_WINDOWS_PER_TEAM
    )
    habits: list[TeamHabitSchema] = Field(default_factory=list, max_length=7)
    coaches: list[TeamCoachRefSchema] = Field(default_factory=list, max_length=20)
    # Active shared PLAYERS (CoachPlayerMembership) of the team — person ids, no
    # role (P4-240 ③). A player is a person occupied by the team's match exactly
    # like a coach, weighted W_COACH_MAIN (SOFT — ADR-0003). The backend already
    # drops anyone who ALSO coaches this team (the coach role wins, parité
    # MatchConflictDetector), so a person appears here XOR in `coaches`. OMITTED ⇒
    # [] (an old payload keeps the coach-only behaviour).
    players: list[str] = Field(default_factory=list, max_length=MAX_PLAYERS_PER_TEAM)
    # Per-category durations (P4-203) resolved by the backend
    # (MatchDurationResolver). OMITTED ⇒ the documented defaults, so an old
    # payload keeps the previous behaviour: the venue holds the match only
    # ([kickoff, kickoff + matchMinutes]), the person window keeps the warm-up.
    match_minutes: int = Field(default=105, alias="matchMinutes", ge=1)
    warmup_minutes: int = Field(default=30, alias="warmupMinutes", ge=0)
    # Venues this team is FORBIDDEN to play at (P4-272 ④ — scope TEAM HARD rules,
    # resolved by the backend). The solver removes them from the team's domain (see
    # _candidate_kickoffs); a domain emptied by them alone is `team_venue_forbidden`.
    # A manual placement in a forbidden venue is PERMITTED — the radar signals it, the
    # solver never puts one there. OMITTED ⇒ [] (an old payload keeps the previous
    # behaviour: no venue forbidden).
    forbidden_venue_ids: list[str] = Field(default_factory=list, alias="forbiddenVenueIds", max_length=MAX_MATCH_VENUES)


class MatchSchema(SerializableModel):
    """One dated match.

    kind:
    - TO_PLACE — HOME, UNPLACED (or SOLVER-placed being re-solved): the solver
      picks (venue, kickoff). `currentVenueId`/`currentKickoff` carry the
      previous SOLVER placement for the stability bonus + hint.
    - FIXED — HOME already anchored (manual placement / submitted / validated):
      consumes its venue slot, NEVER moves.
    - AWAY — informative only, and IGNORED by the placement solver since P4-240 ③
      (décision B): it occupies no venue and no longer projects a person window
      either (« c'est la vie »). It still feeds `team_dates` (a team away a given
      day frees its habit protection). `kickoff` may be the real hour or
      the habit estimation (kickoffEstimated).
    """

    id: str
    team_id: str = Field(alias="teamId")
    match_date: date = Field(alias="date")
    kind: str = "TO_PLACE"  # TO_PLACE | FIXED | AWAY
    venue_id: str | None = Field(default=None, alias="venueId")
    kickoff: time | None = None
    # Transporté par le contrat mais NON consommé par le solveur : décision produit en attente
    # (peser un clash de coach sur une heure ESTIMÉE moins qu'un clash sur une heure CERTAINE).
    # En l'état, une heure estimée pèse autant qu'une heure réelle dans les termes de coach.
    kickoff_estimated: bool = Field(default=False, alias="kickoffEstimated")
    current_venue_id: str | None = Field(default=None, alias="currentVenueId")
    current_kickoff: time | None = Field(default=None, alias="currentKickoff")
    # D3 — trajet aller-retour vers l'adversaire (minutes, 2 × aller simple), AWAY
    # seulement. TRANSPORTÉ par le contrat mais NON CONSOMMÉ par le solveur depuis
    # P4-240 ③ (décision B) : le placement IGNORE désormais toute empreinte personne
    # d'un match EXTÉRIEUR (« c'est la vie ; le radar signale le conflit, on gère
    # après »). Le champ reste sur le fil (le radar et la fiche s'en servent encore,
    # et un re-bump serait gratuit). 0 = inconnu / non AWAY. Borne haute = 24 h.
    round_trip_minutes: int = Field(default=0, ge=0, le=1440, alias="roundTripMinutes")

    @model_validator(mode="after")
    def _fixed_is_anchored(self) -> MatchSchema:
        if self.kind == "FIXED" and (self.venue_id is None or self.kickoff is None):
            msg = "a FIXED match must carry venueId and kickoff"
            raise ValueError(msg)
        if self.kind not in {"TO_PLACE", "FIXED", "AWAY"}:
            msg = f"unknown match kind {self.kind!r}"
            raise ValueError(msg)
        return self


class TeamLinkSchema(SerializableModel):
    team_a_id: str = Field(alias="teamAId")
    team_b_id: str = Field(alias="teamBId")
    type: str = "NOT_SIMULTANEOUS"  # NOT_SIMULTANEOUS | BACK_TO_BACK


class TrainingOccupancySchema(SerializableModel):
    """A dated training session projected by the backend from the EFFECTIVE
    schedule (ADR-0002 rules live backend-side — the engine stays flat)."""

    occupancy_date: date = Field(alias="date")
    start: time
    end: time
    coach_id: str = Field(alias="coachId")


class MatchPlacementInputSchema(SerializableModel):
    # Fallback quand le champ est OMIS ; le backend l'envoie TOUJOURS, donc ce
    # défaut n'est jamais la valeur du fil. On l'aligne néanmoins sur le contrat
    # courant pour qu'aucun lecteur ne le prenne pour une version concurrente.
    # L'autorité reste `engine/CONTRACT_VERSION`, comparée au MAJOR à l'entrée ;
    # gardé par test_schema_version_defaults_match_contract_version.
    version: str = "1.1"
    club_id: str = Field(alias="clubId")
    season_id: str = Field(alias="seasonId")
    solver_seed: int = Field(default=42, alias="solverSeed")
    # Budget PAR SEMAINE ISO (ENG-50) : le moteur découpe la demande semaine par
    # semaine et accorde ce budget À CHACUNE. 30 s par défaut ; valeur = plafond,
    # capée à 60 s — chaque semaine est minuscule (~10^4 booléens), un budget long
    # ne masque qu'un bug de modélisation. Le backend envoie 35 (par semaine).
    solver_timeout_seconds: int = Field(default=30, alias="solverTimeoutSeconds", ge=1, le=MAX_TIMEOUT_SECONDS)
    matches: list[MatchSchema] = Field(default_factory=list, max_length=MAX_MATCHES)
    venues: list[MatchVenueSchema] = Field(default_factory=list, max_length=MAX_MATCH_VENUES)
    teams: list[MatchTeamSchema] = Field(default_factory=list, max_length=MAX_MATCH_TEAMS)
    team_links: list[TeamLinkSchema] = Field(default_factory=list, alias="teamLinks", max_length=MAX_TEAM_LINKS)
    training_occupancies: list[TrainingOccupancySchema] = Field(
        default_factory=list, alias="trainingOccupancies", max_length=MAX_TRAINING_OCCUPANCIES
    )
    # P4-272 ③ — the club's MATCH rules (HARD honoured / PREFERRED penalised),
    # applied to every non-friendly TO_PLACE match. OMITTED ⇒ [] (an old payload
    # keeps the previous behaviour).
    club_rules: list[ClubRuleSchema] = Field(default_factory=list, alias="clubRules", max_length=MAX_CLUB_RULES)
    # P4-272 ⑤ — the club's coach UNAVAILABILITIES (always SOFT), penalising a TO_PLACE
    # match whose kickoff falls in a coach's window on a covered day, for a coach of that
    # match's team. OMITTED ⇒ [] (an old payload keeps the previous behaviour).
    coach_unavailabilities: list[CoachUnavailabilitySchema] = Field(
        default_factory=list, alias="coachUnavailabilities", max_length=MAX_COACH_UNAVAILABILITIES
    )
