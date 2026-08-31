from __future__ import annotations

from datetime import time

from pydantic import Field

from app.schemas.input_schema import (
    MAX_SHARED_TRAINING_BLOCKS,
    MAX_SHARED_TRAINING_GROUPS,
    MAX_TEAM_LINKS,
    MAX_VENUE_TRAVEL_TIMES,
    CoachSchema,
    ConstraintV2Schema,
    ImplicitRulesSchema,
    PriorityTierSchema,
    ScheduleSlotTemplateSchema,
    SerializableModel,
    SharedTrainingBlockSchema,
    SharedTrainingGroupSchema,
    TeamLinkSchema,
    TeamSchema,
    VenueSchema,
    VenueTravelTimeSchema,
)

# P2-2 F2a — verdict moteur sur UN candidat de deplacement (mono-candidat, pas de
# batch : la decision fondateur exige un candidat par appel). Le vocabulaire est
# celui de /generate — on ne reinvente pas un dialecte : venues/teams/coaches/
# constraints/priorityTiers arrivent tels quels, et `slotTemplates` porte le
# planning courant a FIGER (baseline). Le candidat est la seule chose libre.
MAX_VENUES = 50
MAX_TEAMS = 200
MAX_COACHES = 200
MAX_SLOT_TEMPLATES = 2000
MAX_PRIORITY_TIERS = 20
# Budget de temps volontairement COURT (cible UX 2-3 s cote gestionnaire) : la
# baseline est entierement figee, le solveur ne cherche qu'a placer UN candidat
# deja epingle — un budget long ne masquerait qu'un defaut de modelisation.
MAX_TIMEOUT_SECONDS = 10


class CandidateAssignmentSchema(SerializableModel):
    """Le candidat a valider : une equipe vers un creneau vide cible.

    Le coach n'est pas porte ici — il est derive de l'equipe via les contraintes
    TEAM_COACH (meme source que /generate). La duree vient du creneau cible.
    """

    team_id: str = Field(alias="teamId")
    venue_id: str = Field(alias="venueId")
    day_of_week: int = Field(alias="dayOfWeek", ge=1, le=7)
    start_time: time = Field(alias="startTime")
    duration_minutes: int = Field(alias="durationMinutes", ge=1)


class ValidateAssignmentsInputSchema(SerializableModel):
    version: str = "2.4"
    club_id: str = Field(alias="clubId")
    season_id: str = Field(alias="seasonId")
    solver_seed: int = Field(default=42, alias="solverSeed")
    solver_timeout_seconds: int = Field(default=2, alias="solverTimeoutSeconds", ge=1, le=MAX_TIMEOUT_SECONDS)
    venues: list[VenueSchema] = Field(default_factory=list, max_length=MAX_VENUES)
    teams: list[TeamSchema] = Field(default_factory=list, max_length=MAX_TEAMS)
    coaches: list[CoachSchema] = Field(default_factory=list, max_length=MAX_COACHES)
    constraints: list[ConstraintV2Schema] = Field(default_factory=list)
    # Parite generation <=> verdict (P2-28) : le MEME reglage de regles implicites
    # s'applique au solve et au verdict — sans lui, un deplacement sur un planning
    # genere en PREFERRED serait juge en tout-HARD et refuse a tort. Optionnel :
    # absence = tout HARD, seuils par defaut (retro-compat 2.6).
    implicit_rules: ImplicitRulesSchema | None = Field(default=None, alias="implicitRules")
    # Le planning COURANT (HARD + non-HARD) : les verrous HARD restent pre-places
    # hors solveur (comme /generate), le reste est FIGE via add_fixed_slots.
    slot_templates: list[ScheduleSlotTemplateSchema] = Field(
        default_factory=list, alias="slotTemplates", max_length=MAX_SLOT_TEMPLATES
    )
    priority_tiers: list[PriorityTierSchema] = Field(
        default_factory=list, alias="priorityTiers", max_length=MAX_PRIORITY_TIERS
    )
    # P2-27 — parité génération ⇄ verdict : la déclaration de mutualisation entre AUSSI ici.
    # Un déplacement qui sort une équipe d'une case commune (EXACTEMENT K rompue) est refusé,
    # motif nommé. Absent/vide ⇒ aucun effet (rétro-compat).
    shared_trainings: list[SharedTrainingGroupSchema] = Field(
        default_factory=list, alias="sharedTrainings", max_length=MAX_SHARED_TRAINING_GROUPS
    )
    # P2-51 — le verdict accepte AUSSI le bloc `sharedBlocks` (parité de vocabulaire avec
    # /generate : le backend émet le même dialecte). ACCEPTÉ mais NON consommé en PR-2 : absent/vide
    # ⇒ aucun effet (rétro-compat) — la sémantique de déplacement-en-bloc est PR-3.
    shared_blocks: list[SharedTrainingBlockSchema] = Field(
        default_factory=list, alias="sharedBlocks", max_length=MAX_SHARED_TRAINING_BLOCKS
    )
    # Lot PASSERELLES — le verdict accepte AUSSI le bloc `teamLinks` (parité de vocabulaire avec
    # /generate). ACCEPTÉ mais NON consommé en PR-1. Absent/vide ⇒ aucun effet (rétro-compat).
    team_links: list[TeamLinkSchema] = Field(default_factory=list, alias="teamLinks", max_length=MAX_TEAM_LINKS)
    # P2-53 RMM-8 PR-2 — le verdict accepte AUSSI `venueTravelTimes` (parité de vocabulaire avec
    # /generate : le backend émet le même dialecte). P2-55 — désormais CONSOMMÉ par le verdict :
    # MANDATORY pose l'interdit dur (battement trop court ⇒ refus), PREFERRED remonte le battement
    # concédé en compromis nommé. Absent/vide ⇒ aucun effet (rétro-compat).
    venue_travel_times: list[VenueTravelTimeSchema] = Field(
        default_factory=list, alias="venueTravelTimes", max_length=MAX_VENUE_TRAVEL_TIMES
    )
    candidate: CandidateAssignmentSchema
    # P2-32 — l'état « AVANT » du candidat, pour le DELTA de compromis. Pour un DÉPLACEMENT le
    # backend y pose le placement d'origine de la source (même forme que ``candidate``) : « avant »
    # = baseline figée + ``reference``. Absent (une CRÉATION à la dérive) → « avant » = baseline
    # nue. N'entre JAMAIS dans le verdict booléen (feasibility) : il ne sert qu'à la lecture des
    # compromis post-acceptation.
    reference: CandidateAssignmentSchema | None = None
