"""The three input schemas' ``version`` DEFAULT must track ``engine/CONTRACT_VERSION``.

The backend always stamps the field, so these defaults are never the wire value —
but a stale default (``2.0`` / ``2.4`` / an old ``2.21``) reads like a competing
contract to anyone opening the schema. This pins all three to the single source of
truth so a bump that forgets one of them fails loudly here.

Read the constant at call time (no ``default_factory``: the schemas must not import
``app.main`` — that would create a schemas→main import cycle)."""

from __future__ import annotations

from app.main import read_contract_version
from app.schemas.input_schema import ScheduleInputSchema
from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema


def test_schema_version_defaults_match_contract_version() -> None:
    contract = read_contract_version()
    fields = {
        "ScheduleInputSchema": ScheduleInputSchema.model_fields["version"].default,
        "MatchPlacementInputSchema": MatchPlacementInputSchema.model_fields["version"].default,
        "ValidateAssignmentsInputSchema": ValidateAssignmentsInputSchema.model_fields["version"].default,
    }
    stale = {name: value for name, value in fields.items() if value != contract}
    assert not stale, f"version default(s) out of sync with CONTRACT_VERSION={contract!r}: {stale}"
