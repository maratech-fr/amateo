from __future__ import annotations

from datetime import time

from pydantic import Field

from app.schemas.output_schema import DiagnosticSchema, SerializableModel, SolverMetricsSchema


class MatchPlacementSchema(SerializableModel):
    match_id: str = Field(alias="matchId")
    venue_id: str = Field(alias="venueId")
    kickoff: time


class UnplacedMatchSchema(SerializableModel):
    """A match the solver could NOT place — never an error: the named reason is
    the « ask your derogation early » signal (ADR-0003, articulated with
    ADR-0001: nothing is relaxed, the impossible is spelled out)."""

    match_id: str = Field(alias="matchId")
    # no_access_window | no_league_intersection | venue_unavailable | venue_full | not_selected
    # (venue_full = plus aucun créneau licite libre ; not_selected = un créneau licite restait
    # libre mais le solveur ne l'a pas retenu dans le temps imparti — cf. REASON_MESSAGES).
    reason: str
    message: str


class MatchPlacementOutputSchema(SerializableModel):
    status: str = "completed"
    placements: list[MatchPlacementSchema] = Field(default_factory=list)
    unplaced: list[UnplacedMatchSchema] = Field(default_factory=list)
    diagnostics: list[DiagnosticSchema] = Field(default_factory=list)
    metrics: SolverMetricsSchema | None = None
