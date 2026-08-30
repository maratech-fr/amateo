"""P4-152 — le VERDICT de déplacement (`/validate-assignments`) honore le PLANCHER de gymnase.

Axe §7.1 « backend↔engine contract » : parité génération ⇄ verdict pour la contrainte FACILITY
« au moins N séances au gymnase V » (``minAtVenueId`` + ``minAtVenueCount``). Avant ce lot, cette
famille était posée en dur sur ``/generate`` (``add_venue_minimum_constraints``) mais JAMAIS
reconstruite par le verdict : un déplacement manuel faisant passer un gymnase SOUS son plancher
était jugé « valide » à tort (« déclaré ≠ effectif », même famille qu'ENG-36).

Ce lot pose deux choses : (1) la contrainte HARD dans ``_apply_hard`` (parité, gardée par
``test_hard_layer_parity_registry``) et (2) le miroir déterministe ``_venue_minimum_move_violation``
qui NOMME le refus avant le solve — le HARD seul ne suffit pas (les autres créneaux restent libres,
le solveur tiendrait le plancher avec une séance fantôme et conclurait « valide »).

Falsifié dans les DEUX sens, avec deux TÉMOINS :

  * BRISE — un déplacement qui retire à l'équipe sa dernière séance sous le plancher est REFUSÉ,
    motif NOMMÉ ``venue_minimum_infeasible`` nommant le gymnase et le plancher. Falsification :
    sans le miroir, ce refus n'aurait pas ce nom (au mieux ``unknown_hard_conflict``) ;
  * TÉMOIN 1 (ne brise pas) — le même club, mais l'équipe garde assez de séances au gymnase après
    le déplacement → ACCEPTÉ, aucun refus plancher ;
  * TÉMOIN 2 (LE PIÈGE : planning DÉJÀ en infraction) — un plancher déjà cassé AVANT le
    déplacement (généré avant la contrainte, ou créneau supprimé) ne doit JAMAIS enfermer le
    gestionnaire : le déplacement reste ACCEPTÉ, jamais un blocage total.

Pipeline RÉEL (``validate_assignment``), jamais un mock.
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import make_team, make_venue, team_constraint


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(payload))


def _template(team_id: str, venue_id: str, day: int, start: str) -> dict[str, Any]:
    return {
        "id": f"tpl-{team_id}-{venue_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "NONE",
    }


def _ref(team_id: str, venue_id: str, day: int, start: str) -> dict[str, Any]:
    """L'état « avant » (origine de la source), en forme de candidat (pas de id/lockLevel)."""
    return {
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
    }


def _floor(team_id: str, venue_id: str, count: int) -> dict[str, Any]:
    """La contrainte wizard « au moins N séances au gymnase V » (FACILITY HARD)."""
    return team_constraint(
        constraint_id=f"floor-{team_id}-{venue_id}",
        team_id=team_id,
        family="FACILITY",
        rule_type="HARD",
        config={"minAtVenueId": venue_id, "minAtVenueCount": count},
        name="au moins N au gymnase",
    )


# V1 porte trois jours (lun/mer/ven 18:00), V2 un seul (mer 18:00) : la destination du déplacement.
# ``name`` des gymnases = "V1"/"V2" (make_venue), NOMMÉS tels quels dans le message de refus.
_V1_SLOTS = [(1, "18:00"), (3, "18:00"), (5, "18:00")]
_V2_SLOTS = [(3, "18:00")]


def _base_payload(*, slot_templates: list[dict[str, Any]], reference: dict[str, Any] | None) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "clubId": "c",
        "seasonId": "s",
        "venues": [make_venue("V1", _V1_SLOTS), make_venue("V2", _V2_SLOTS)],
        "teams": [make_team("t1", sessions_per_week=3)],
        "coaches": [],
        "constraints": [_floor("t1", "V1", 2)],
        "slotTemplates": slot_templates,
        # Le candidat : t1 vers V2 mercredi 18:00 (il QUITTE V1 mercredi — cf. reference).
        "candidate": {
            "teamId": "t1",
            "venueId": "V2",
            "dayOfWeek": 3,
            "startTime": "18:00",
            "durationMinutes": 90,
        },
    }
    if reference is not None:
        payload["reference"] = reference
    return payload


class TestVenueMinimumVerdict:
    def test_move_below_floor_is_refused_and_names_the_venue(self) -> None:
        """BRISE : t1 a 2 séances à V1 (plancher 2, SATISFAIT) ; déplacer celle du mercredi vers V2
        laisserait 1 séance à V1 (< 2) → REFUS, motif NOMMÉ ``venue_minimum_infeasible``.

        La baseline EXCLUT déjà la source (t1 @ V1 lundi seul) ; ``reference`` = t1 @ V1 mercredi,
        l'origine. Falsifie : sans le miroir ``_venue_minimum_move_violation``, le HARD posé dans
        ``_apply_hard`` laisserait le solveur placer une séance fantôme à V1 et conclure « valide ».
        On vérifie donc le NOM du motif et le gymnase nommé, pas seulement ``valid is False``."""
        result = _run(
            _base_payload(
                slot_templates=[_template("t1", "V1", 1, "18:00")],  # baseline : 1 séance à V1 (lundi)
                reference=_ref("t1", "V1", 3, "18:00"),  # source déplacée : V1 mercredi
            )
        )
        assert result["valid"] is False, f"passer sous le plancher (2→1 à V1) doit être REFUSÉ; got {result}"
        assert result["violations"], "un refus doit rester explicable (violation nommée)"
        violation = result["violations"][0]
        assert violation["rule"] == "venue_minimum_infeasible", (
            f"le refus doit NOMMER le plancher, pas retomber sur unknown_hard_conflict; got {result['violations']}"
        )
        assert violation["venue_id"] == "V1"
        assert violation["team_id"] == "t1"
        # Message ACTIONNABLE : gymnase, équipe, plancher exigé (2) et état résultant (1) nommés.
        message = violation["message"]
        assert "V1" in message and "t1" in message, f"le message doit nommer gymnase + équipe; got {message!r}"
        assert "2" in message and "1" in message, f"le message doit dire le plancher et le reste; got {message!r}"

    def test_move_keeping_floor_is_accepted(self) -> None:
        """TÉMOIN 1 : t1 a 3 séances à V1 (lun/mer/ven, plancher 2) ; déplacer celle du mercredi vers
        V2 laisse encore 2 séances à V1 (lun + ven) → ACCEPTÉ, aucun refus plancher."""
        result = _run(
            _base_payload(
                # baseline : 2 séances à V1 (lundi + vendredi), la source (mercredi) exclue
                slot_templates=[_template("t1", "V1", 1, "18:00"), _template("t1", "V1", 5, "18:00")],
                reference=_ref("t1", "V1", 3, "18:00"),
            )
        )
        assert result["valid"] is True, f"garder 2 séances à V1 respecte le plancher → accepté; got {result}"
        assert all(v.get("rule") != "venue_minimum_infeasible" for v in result.get("violations", [])), (
            f"aucun refus plancher attendu; got {result.get('violations')}"
        )

    def test_already_broken_floor_never_locks_the_manager(self) -> None:
        """TÉMOIN 2 (LE PIÈGE) : le plancher est DÉJÀ cassé AVANT le déplacement — t1 n'a qu'UNE
        séance à V1 (généré avant la contrainte, ou créneau supprimé). Déplacer cette dernière
        séance de V1 vers V2 la ferait passer de 1 à 0 : le plancher était DÉJÀ violé, le
        déplacement n'en est pas la cause. Le gestionnaire doit pouvoir bouger → ACCEPTÉ, jamais un
        blocage total (condition d'arrêt fondateur).

        Falsifie le piège : un miroir naïf (« état final < plancher → refus ») refuserait ici et
        ENFERMERAIT le gestionnaire. Le garde ``current >= minimum`` du miroir l'en empêche."""
        result = _run(
            _base_payload(
                slot_templates=[],  # baseline vide : la seule séance à V1 est la source déplacée
                reference=_ref("t1", "V1", 3, "18:00"),  # source : l'unique séance à V1
            )
        )
        assert result["valid"] is True, f"un plancher DÉJÀ cassé ne doit pas enfermer le gestionnaire; got {result}"
        assert all(v.get("rule") != "venue_minimum_infeasible" for v in result.get("violations", [])), (
            f"le déplacement ne doit PAS être imputé d'un plancher déjà cassé; got {result.get('violations')}"
        )
