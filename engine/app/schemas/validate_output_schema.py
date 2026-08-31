from __future__ import annotations

from pydantic import Field

from app.schemas.output_schema import SerializableModel, SolverMetricsSchema


class AssignmentViolationSchema(SerializableModel):
    """Une regle HARD que le candidat casserait, NOMMEE et exploitable par l'UI.

    Un « non » sans motif est inutilisable (c'est le coeur de la valeur de F2) :
    `rule` est un code machine stable, `message` la phrase prete a afficher, et
    les champs d'entite servent au surlignage (equipe/coach/gymnase fautifs).
    ``conflicting_team_id`` porte l'equipe DEJA en place qui entre en conflit
    (« le coach Dupont a deja les U15 a 20h »).
    """

    # Motifs servis (liste INDICATIVE, pas une contrainte de schema : `rule` est un `str`
    # libre, c'est voulu — un nouveau motif ne doit pas casser le contrat) :
    # coach_no_overlap | team_no_overlap | coach_player_no_overlap | venue_capacity |
    # coach_no_rest_day | one_session_per_day | time_window | day_rule |
    # coach_unavailable | forbidden_venue | forced_venue | slot_unavailable |
    # baseline_infeasible | unknown_hard_conflict | shared_block_broken |
    # team_link_broken | travel_time_infeasible (ENG-36) | venue_minimum_infeasible (P4-152)
    rule: str
    message: str
    team_id: str | None = Field(default=None, alias="teamId")
    coach_id: str | None = Field(default=None, alias="coachId")
    venue_id: str | None = Field(default=None, alias="venueId")
    day_of_week: int | None = Field(default=None, alias="dayOfWeek")
    start_time: str | None = Field(default=None, alias="startTime")
    conflicting_team_id: str | None = Field(default=None, alias="conflictingTeamId")


class CompromiseSchema(SerializableModel):
    """UN compromis de confort qu'un déplacement ACCEPTÉ change (P2-32).

    ``family`` est l'une des familles v1 (liste fermée) ; ``effect`` = ``broken`` (une
    préférence honorée avant ne l'est plus) ou ``gained`` (l'inverse). ``message`` est la phrase
    française prête à afficher — AUCUN identifiant interne. Les champs d'entité (tels que le
    moteur les émet, ceux que le club possède déjà) servent au surlignage côté UI.
    """

    # chaining | venue_preference | day_preference | time_preference | match_rest |
    # spacing | coach_day_cap | implicit_rule | team_link
    family: str
    # broken | gained
    effect: str
    message: str
    team_id: str | None = Field(default=None, alias="teamId")
    coach_id: str | None = Field(default=None, alias="coachId")
    venue_id: str | None = Field(default=None, alias="venueId")
    day_of_week: int | None = Field(default=None, alias="dayOfWeek")
    start_time: str | None = Field(default=None, alias="startTime")


class ValidateAssignmentsOutputSchema(SerializableModel):
    """Le verdict moteur sur le candidat : valide, et sinon POURQUOI (nomme)."""

    valid: bool
    violations: list[AssignmentViolationSchema] = Field(default_factory=list)
    # Le DELTA de confort d'un candidat ACCEPTÉ (P2-32) — rempli SEULEMENT quand ``valid=true``.
    # Sur un refus, la liste reste vide : le chemin REFUS du verdict est inchangé.
    compromises: list[CompromiseSchema] = Field(default_factory=list)
    metrics: SolverMetricsSchema | None = None
