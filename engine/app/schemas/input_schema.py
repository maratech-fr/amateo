from __future__ import annotations

from datetime import time
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

# A10 (DoS "generation bomb" guard): bound every input list so an oversized payload is
# rejected with 422 at the boundary, before CP-SAT builds the model. Values are generous
# (~10x a large FFBB club) — they only trip on a genuine bomb. The backend enforces the
# same caps plus an n_teams x n_venues product pre-check BEFORE dispatch; this schema is
# the defense-in-depth last line. Availability slots are bounded BOTH per-venue and in
# total (a model_validator sums across venues, so 50 venues x 1000 can't smuggle 50k slots).
MAX_VENUES = 50
MAX_TEAMS = 200
MAX_COACHES = 200
# NB: `constraints` has NO cap (ENG-23) — its expanded size = raw(<=500) x teams(<=200)
# after the backend fans out CLUB-scoped rules, so no fixed number can both bound a bomb
# and never false-block a legit club; see the field comment on ScheduleInputSchema.
MAX_SLOT_TEMPLATES = 2000
MAX_PRIORITY_TIERS = 20
MAX_SLOTS_PER_VENUE = 1000
MAX_SLOTS_TOTAL = 3000
MAX_TAGS_PER_TEAM = 50
# P2-27 — mutualisation : plafonds du bloc `sharedTrainings`. 50 groupes (~10x un gros club
# FFBB), 2..10 équipes par groupe (cap technique fondateur, minimum métier 2). ⚠ La FORME
# (2..10, doublons) est bornée à la saisie backend, mais le NOMBRE de groupes ne l'est PAS
# (mesuré 2026-08-22) : ce cap au bord est la SEULE borne du compte — dette P4, roadmap.
MAX_SHARED_TRAINING_GROUPS = 50
MIN_TEAMS_PER_SHARED_GROUP = 2
MAX_TEAMS_PER_SHARED_GROUP = 10
# Lot PASSERELLES — plafond du bloc `teamLinks` : 50 passerelles (~10x un gros club FFBB).
# Défense en profondeur : le backend borne à la SAISIE (TeamLinkStateProcessor::MAX_TEAM_LINKS,
# miroir manuel de cette constante), ceci est la dernière ligne au bord.
MAX_TEAM_LINKS = 50
# P2-53 RMM-8 PR-2 — plafond du bloc `venueTravelTimes` : une ligne par COUPLE de gymnases du
# club. 150 ≈ le cap métier de l'autofill (120 = 16 gymnases × 15 / 2) plus de la marge. Défense
# en profondeur au bord : le backend borne déjà la saisie (VenueTravelTimeAutofillService).
MAX_VENUE_TRAVEL_TIMES = 150


class SerializableModel(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class VenueTrainingSlotSchema(SerializableModel):
    day_of_week: int = Field(alias="dayOfWeek")
    start_time: str = Field(alias="startTime")  # "19:00"
    duration_minutes: int = Field(alias="durationMinutes")
    capacity: int = Field(default=1, ge=1)


class VenueSchema(SerializableModel):
    id: str
    name: str
    is_external: bool = Field(default=False, alias="isExternal")
    color: str | None = None
    latitude: str | None = None
    longitude: str | None = None
    source: str = ""
    external_ref: str | None = Field(default=None, alias="externalRef")
    is_active: bool = Field(default=False, alias="isActive")
    parent_venue_id: str | None = Field(default=None, alias="parentVenueId")
    training_slots: list[VenueTrainingSlotSchema] = Field(
        default_factory=list, alias="trainingSlots", max_length=MAX_SLOTS_PER_VENUE
    )


class PriorityTierSchema(SerializableModel):
    id: int
    label: str
    or_tools_weight: int = Field(alias="orToolsWeight")
    default_min_sessions: int = Field(alias="defaultMinSessions")


class TeamSchema(SerializableModel):
    id: str
    sport_category_id: str = Field(alias="sportCategoryId")
    """Age range from SportCategory. Used for age-ascending constraint. None = constraint skipped."""
    ageMin: int | None = None
    ageMax: int | None = None
    priority_tier_id: int = Field(alias="priorityTierId")
    name: str
    gender: str | None = None
    level: str | None = None
    sessions_per_week: int = Field(alias="sessionsPerWeek")
    min_sessions_override: int | None = Field(default=None, alias="minSessionsOverride")
    match_day: int | None = Field(default=None, alias="matchDay")
    forced_venue_id: str | None = Field(default=None, alias="forcedVenueId")
    is_active: bool = Field(default=False, alias="isActive")
    parent_team_id: str | None = Field(default=None, alias="parentTeamId")
    ffbb_team_id: str | None = Field(default=None, alias="ffbbTeamId")
    tags: list[str] = Field(default_factory=list, max_length=MAX_TAGS_PER_TEAM)


class CoachSchema(SerializableModel):
    id: str
    first_name: str = Field(alias="firstName")
    last_name: str = Field(alias="lastName")
    email: str | None = None
    phone: str | None = None
    max_days_override: int | None = Field(default=None, alias="maxDaysOverride")
    acceptable_late_minutes: int | None = Field(default=None, alias="acceptableLateMinutes")
    is_active: bool = Field(default=False, alias="isActive")
    parent_coach_id: str | None = Field(default=None, alias="parentCoachId")
    is_employee: bool = Field(default=False, alias="isEmployee")
    # P2-53 RMM-8 PR-2 — le coach est-il VÉHICULÉ ? Décide de SON barème de trajet
    # (véhiculé → temps voiture, sinon temps à pied) dans la règle implicite `travelTime`.
    # Défaut False (à pied) : conservateur, et absent d'un vieux payload ⇒ à pied.
    is_vehicled: bool = Field(default=False, alias="isVehicled")


class ConstraintV2Schema(BaseModel):
    """Unified v2 constraint schema that accepts both v2 and legacy v1 formats.

    V2 constraints use scope/family/ruleType/config.
    Legacy v1 constraints (TEAM_COACH, COACH_PLAYER_UNAVAILABILITY, PRIORITY_TIER)
    use teamId/type/severity/value/metadata.
    Both formats are accepted so the engine can handle mixed constraint arrays.
    """

    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    id: str
    # V2 unified fields
    scope: str | None = None
    scope_target_id: str | None = Field(default=None, alias="scopeTargetId")
    family: str | None = None
    rule_type: str | None = Field(default=None, alias="ruleType")
    name: str | None = None
    config: dict[str, object] = Field(default_factory=dict)
    sort_order: int = Field(default=0, alias="sortOrder")
    is_active: bool = Field(default=True, alias="isActive")
    # Legacy v1 fields (still sent by backend for TEAM_COACH, etc.)
    team_id: str | None = Field(default=None, alias="teamId")
    type: str | None = None
    severity: str | None = None
    value: str | int | float | bool | None = None
    metadata: dict[str, object] = Field(default_factory=dict)


class CoachRestDayRuleSchema(SerializableModel):
    """Implicit rule 3b — chaque coach garde au moins ``minRestDays`` jours de repos
    (lun-ven). ``intensity=HARD`` la pose en contrainte dure (défaut, comportement
    historique) ; ``PREFERRED`` la retire du dur et la pénalise dans l'objectif. La
    violation est TOUJOURS diagnostiquée post-solve, quel que soit le cran."""

    intensity: Literal["HARD", "PREFERRED"] = "HARD"
    min_rest_days: int = Field(default=1, ge=1, le=4, alias="minRestDays")


class IntensityRuleSchema(SerializableModel):
    """Une règle implicite à cran seul (pas de seuil réglable) : 3c distribution
    salariés, 12 âge croissant."""

    intensity: Literal["HARD", "PREFERRED"] = "HARD"


class MaxConsecutiveSessionsRuleSchema(SerializableModel):
    """Implicit rule 3d — une personne n'enchaîne pas ``maxConsecutive`` créneaux
    dos-à-dos. Défaut 3 = comportement historique (« jamais 3 dos-à-dos »)."""

    intensity: Literal["HARD", "PREFERRED"] = "HARD"
    max_consecutive: int = Field(default=3, ge=2, le=6, alias="maxConsecutive")


class MaxConsecutiveDaysRuleSchema(SerializableModel):
    """Implicit rule 3e (P2-42) — une ÉQUIPE ne s'entraîne pas ``maxConsecutiveDays``
    jours de suite.

    ⚠ À ne PAS confondre avec :attr:`MaxConsecutiveSessionsRuleSchema`, dont le nom est
    presque le même et le sujet complètement différent : celle-là contraint une PERSONNE
    sur des créneaux dos-à-dos DANS LA MÊME JOURNÉE (fin d'un créneau == début du suivant).
    Celle-ci contraint une ÉQUIPE sur des JOURS d'une semaine. Sujet différent, axe
    différent — l'audit ALIGN-08 a montré qu'on pouvait croire le besoin couvert en lisant
    le seul nom de l'autre.

    Défaut 3 = le besoin BCCL littéral (« pas 3 entraînements d'affilée »). 2 permet à un
    club d'exiger un jour de creux entre deux séances ; au-delà de 5 la règle n'a plus de
    prise sur une semaine de 7 jours.
    """

    intensity: Literal["HARD", "PREFERRED"] = "HARD"
    max_consecutive_days: int = Field(default=3, ge=2, le=5, alias="maxConsecutiveDays")


class TravelTimeRuleSchema(SerializableModel):
    """P2-53 RMM-8 PR-2 — la règle implicite « temps de trajet entre gymnases ».

    Elle naît OPT-IN : le backend n'émet ce bloc QUE si le club a AU MOINS une ligne de matrice
    de trajet (activation au premier geste, jamais un changement silencieux — précédent P2-42).
    Son intensité gouverne le second terme (le BATTEMENT entre deux séances enchaînées à des
    gymnases différents) : ``PREFERRED`` (défaut) → un battement trop court est une violation
    SOFT nommée dans les compromis ; ``MANDATORY`` → il est INTERDIT DUR (diagnostic
    ``travel_time_infeasible``). Le premier terme, le DÉPARTAGE « moindre trajet », s'applique
    dès que la règle est active, quel que soit le cran (arbitrage fondateur : un PLUS, préférable
    en cas d'égalité, jamais dominant). ``default_minutes`` = le barème appliqué à un couple de
    gymnases jamais arbitré (défaut 20)."""

    intensity: Literal["PREFERRED", "MANDATORY"] = "PREFERRED"
    default_minutes: int = Field(default=20, ge=0, le=600, alias="defaultMinutes")


class ImplicitRulesSchema(SerializableModel):
    """Réglage par club des 4 règles implicites « bien-être ». Chaque champ est
    optionnel : absent = défaut (HARD, seuils historiques). L'ABSENCE TOTALE du bloc
    (``implicit_rules=None``) = défauts partout, donc payload historique byte-identique."""

    coach_rest_day: CoachRestDayRuleSchema | None = Field(default=None, alias="coachRestDay")
    salarie_distribution: IntensityRuleSchema | None = Field(default=None, alias="salarieDistribution")
    max_consecutive_sessions: MaxConsecutiveSessionsRuleSchema | None = Field(
        default=None, alias="maxConsecutiveSessions"
    )
    age_ascending: IntensityRuleSchema | None = Field(default=None, alias="ageAscending")
    max_consecutive_days: MaxConsecutiveDaysRuleSchema | None = Field(default=None, alias="maxConsecutiveDays")
    # P2-53 RMM-8 PR-2 — trajet entre gymnases. Absent (défaut) = règle inactive, comme une
    # règle opt-in éteinte : ni départage ni battement. Le moteur lit l'absence comme « pas de
    # matrice, pas de règle » (patron `maxConsecutiveDays`).
    travel_time: TravelTimeRuleSchema | None = Field(default=None, alias="travelTime")


class PreviousAssignmentSchema(SerializableModel):
    """P3-21 — un placement de la génération PRÉCÉDENTE, pour le terme de stabilité
    (convergence moteur). Chaque entrée désigne un créneau ``(teamId, venueId, dayOfWeek,
    startTime)`` que la régénération est encouragée à reproduire, en DÉPARTAGEANT les ex
    æquo exacts de score — jamais en arbitrant. Le champ est INERTE tant que le backend ne
    l'émet pas (patron ``implicitRules``) : absent/vide ⇒ chemin byte-identique."""

    team_id: str = Field(alias="teamId")
    venue_id: str = Field(alias="venueId")
    day_of_week: int = Field(alias="dayOfWeek", ge=1, le=7)
    start_time: str = Field(alias="startTime")  # "19:00"


class SharedTrainingGroupSchema(SerializableModel):
    """P2-27 — mutualisation : N équipes déclarées s'entraîner ENSEMBLE.

    ``common_sessions`` = le nombre EXACT de séances partagées (même gymnase, même jour,
    même heure) — ni « au moins » ni « au plus » : la déclaration dit combien de séances sont
    communes, le solveur les réifie DANS LES DEUX SENS (chaque membre présent ⇔ séance
    comptée) puis pose l'égalité. ``team_ids`` : de 2 (minimum métier) à 10 équipes (cap
    technique). Un bloc ``sharedTrainings`` absent/vide ⇒ chemin de code byte-identique
    (patron ``implicitRules``/``previousAssignments``) : aucun ``y`` posé, goldens inchangés.
    """

    id: str
    team_ids: list[str] = Field(
        alias="teamIds",
        min_length=MIN_TEAMS_PER_SHARED_GROUP,
        max_length=MAX_TEAMS_PER_SHARED_GROUP,
    )
    common_sessions: int = Field(alias="commonSessions", ge=1)


class TeamLinkSchema(SerializableModel):
    """Lot PASSERELLES — deux équipes déclarées partager des joueurs (« passerelle »).

    ``intensity`` gouverne le CÔTÉ ENTRAÎNEMENT uniquement : ``PREFERRED`` (objectif souple)
    ou ``MANDATORY`` (contrainte dure). En PR-1 le bloc est ACCEPTÉ mais PAS consommé : aucun
    ``y`` posé, goldens inchangés. Un bloc ``teamLinks`` absent/vide ⇒ chemin byte-identique
    (patron ``sharedTrainings``).
    """

    id: str
    team_a_id: str = Field(alias="teamAId")
    team_b_id: str = Field(alias="teamBId")
    intensity: Literal["PREFERRED", "MANDATORY"] = "PREFERRED"


class VenueTravelTimeSchema(SerializableModel):
    """P2-53 RMM-8 PR-2 — le barème de trajet ENTRE DEUX GYMNASES d'un club.

    Deux minutes par couple : le temps ACCEPTABLE en VOITURE (``driving_minutes``) et À PIED
    (``walking_minutes``), chacune NULLABLE (null = ce couple/mode jamais arbitré → le moteur
    applique le défaut ``travelTime.default_minutes`` = 20). Le backend émet les couples TRIÉS
    (``venue_a_id`` < ``venue_b_id``, ordre lexical) ; la lecture moteur est symétrique. Un bloc
    ``venueTravelTimes`` absent/vide ⇒ chemin byte-identique (patron ``teamLinks``) : aucune
    variable de trajet posée, goldens inchangés."""

    venue_a_id: str = Field(alias="venueAId")
    venue_b_id: str = Field(alias="venueBId")
    driving_minutes: int | None = Field(default=None, alias="drivingMinutes")
    walking_minutes: int | None = Field(default=None, alias="walkingMinutes")


class ScheduleSlotTemplateSchema(SerializableModel):
    id: str
    team_id: str = Field(alias="teamId")
    venue_id: str = Field(alias="venueId")
    coach_id: str | None = Field(default=None, alias="coachId")
    day_of_week: int = Field(alias="dayOfWeek")
    start_time: time = Field(alias="startTime")
    duration_minutes: int = Field(alias="durationMinutes")
    lock_level: str = Field(default="NONE", alias="lockLevel")
    pending_constraint_suggestion: dict[str, object] | None = Field(
        default=None,
        alias="pendingConstraintSuggestion",
    )


class ScheduleInputSchema(SerializableModel):
    version: str = "2.0"
    club_id: str = Field(alias="clubId")
    season_id: str = Field(alias="seasonId")
    schedule_name: str | None = Field(default=None, alias="scheduleName")
    solver_seed: int = Field(default=42, alias="solverSeed")
    solver_timeout_seconds: int = Field(default=650, alias="solverTimeoutSeconds")
    venues: list[VenueSchema] = Field(default_factory=list, max_length=MAX_VENUES)
    teams: list[TeamSchema] = Field(default_factory=list, max_length=MAX_TEAMS)
    coaches: list[CoachSchema] = Field(default_factory=list, max_length=MAX_COACHES)
    # ENG-23: NO per-list cap — the backend fans out 1 CLUB rule into N per-team rows, so the
    # expanded count (raw<=500 x teams<=200) can't be reconciled with a fixed max_length without
    # false-blocking a legit club. Real bounds: backend RAW cap + nginx 20m body + solver timeout.
    constraints: list[ConstraintV2Schema] = Field(default_factory=list)
    slot_templates: list[ScheduleSlotTemplateSchema] = Field(
        default_factory=list, alias="slotTemplates", max_length=MAX_SLOT_TEMPLATES
    )
    priority_tiers: list[PriorityTierSchema] = Field(
        default_factory=list, alias="priorityTiers", max_length=MAX_PRIORITY_TIERS
    )
    # Réglage par club des 4 règles implicites (bien-être). None = tout HARD, seuils
    # historiques — un payload sans ce bloc est byte-identique à l'ancien contrat.
    implicit_rules: ImplicitRulesSchema | None = Field(default=None, alias="implicitRules")
    # P3-21 — placements de la génération précédente pour le terme de stabilité. Absent/vide
    # ⇒ chemin de code byte-identique (patron implicitRules) : goldens et score inchangés. Cap
    # 2000 = miroir de MAX_SLOT_TEMPLATES (un placement par séance, même ordre de grandeur).
    previous_assignments: list[PreviousAssignmentSchema] = Field(
        default_factory=list, alias="previousAssignments", max_length=MAX_SLOT_TEMPLATES
    )
    # P2-27 — mutualisation : groupes d'équipes déclarées ensemble (EXACTEMENT K séances
    # communes chacun). Absent/vide ⇒ chemin byte-identique (patron implicitRules). Cap 50
    # groupes = défense en profondeur au bord (le backend borne déjà à la saisie).
    shared_trainings: list[SharedTrainingGroupSchema] = Field(
        default_factory=list, alias="sharedTrainings", max_length=MAX_SHARED_TRAINING_GROUPS
    )
    # Lot PASSERELLES — passerelles déclarées (deux équipes partageant des joueurs). ACCEPTÉ mais
    # NON consommé en PR-1 : absent/vide ⇒ payload byte-identique (patron sharedTrainings), goldens
    # et score inchangés. Cap 50 miroité à la saisie backend (TeamLinkStateProcessor).
    team_links: list[TeamLinkSchema] = Field(default_factory=list, alias="teamLinks", max_length=MAX_TEAM_LINKS)
    # P2-53 RMM-8 PR-2 — matrice de trajet entre gymnases (barème voiture/à pied par couple).
    # Absent/vide ⇒ payload byte-identique (patron teamLinks), goldens inchangés. Cap 150 =
    # défense en profondeur au bord (le backend borne déjà la matrice à la saisie/autofill).
    venue_travel_times: list[VenueTravelTimeSchema] = Field(
        default_factory=list, alias="venueTravelTimes", max_length=MAX_VENUE_TRAVEL_TIMES
    )

    @model_validator(mode="after")
    def _bound_total_slots(self) -> ScheduleInputSchema:
        # Per-venue max_length alone would let 50 venues x 1000 smuggle 50k slots to CP-SAT.
        total_slots = sum(len(v.training_slots) for v in self.venues)
        if total_slots > MAX_SLOTS_TOTAL:
            raise ValueError(f"too many availability slots: {total_slots} (max {MAX_SLOTS_TOTAL})")
        return self
