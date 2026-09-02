import unittest

from ortools.sat.python import cp_model

from app.solver.objective import (
    BONUS_WEIGHT_NAMES,
    CHAINING_STABILITY_MULTIPLIER,
    CHAINING_TIER_WEIGHTS,
    LEVEL_2_OBJECTIVE_WEIGHTS,
    SCORE_FORMULA_VERSION,
    STABILITY_TERM_WEIGHT,
    add_level_2_objective,
    build_stability_terms,
)

EXPECTED_WEIGHTS = {
    "S": 10000,
    "A": 1000,
    "B": 100,
    "session_count": 20,
    # V10 — confort recalé SOUS le seuil d'une séance nue (21) : le remplissage prime.
    "preferred": 10,
    "avoided_venue": -10,
    "preferred_day": 5,
    "preferred_time": 5,
    "C": 10,
    "D": 1,
    "rest": 3,
    # P4-51 — plafond de jours PRÉFÉRÉ : malus par jour au-delà de maxDaysOverride.
    # 15 < 21 (valeur min d'une séance placée) : regrouper ne supprime jamais une séance.
    "overload_day": -15,
    "spacing": -2,
    # V9 — les 4 règles implicites réglées PREFERRED : malus −6 sur leur littéral de violation.
    "coach_rest_violation": -6,
    "salarie_violation": -6,
    "chain_violation": -6,
    "age_violation": -6,
    "consecutive_days_violation": -6,
    # V10 — malus par séance sous le quota hebdomadaire.
    "missing_session": -1000,
}


class LevelTwoObjectiveTest(unittest.TestCase):
    def solve(self, model: cp_model.CpModel) -> tuple[int, cp_model.CpSolver]:
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        return solver.Solve(model), solver

    def test_fixed_weights_and_formula_version_are_locked(self):
        self.assertEqual(EXPECTED_WEIGHTS, dict(LEVEL_2_OBJECTIVE_WEIGHTS))
        self.assertEqual("T24_LEVEL_2_FIXED_WEIGHTS_V13", SCORE_FORMULA_VERSION)

        with self.assertRaises(TypeError):
            LEVEL_2_OBJECTIVE_WEIGHTS["S"] = 1

    def test_comfort_weights_never_outrank_a_bare_session(self):
        """Garde arithmétique V10 : tout confort empilé reste SOUS une séance nue (§7.1).

        Ces asserts littéraux cassent un test NOMMÉ dès qu'un futur poids viole le seuil,
        avant même qu'un solve ne le révèle sur un dataset réel.
        """
        w = LEVEL_2_OBJECTIVE_WEIGHTS
        bare_session = w["D"] + w["session_count"]  # 1 + 20 = 21, la séance la moins chère
        # P1 — cumul max de conforts d'orientation < une séance nue.
        self.assertLess(w["preferred"] + w["preferred_day"] + w["preferred_time"], bare_session)
        # P2 — fuir un gymnase évité ne vaut jamais une séance.
        self.assertLess(abs(w["avoided_venue"]), bare_session)
        # P3/P4 — missing_session domine tout empilement de confort réaliste.
        self.assertGreaterEqual(abs(w["missing_session"]), 1000)

    def test_priority_tiers_have_expected_score_impact_order(self):
        for higher_tier, lower_tier in (("S", "A"), ("A", "B"), ("B", "C"), ("C", "D")):
            with self.subTest(higher_tier=higher_tier, lower_tier=lower_tier):
                model = cp_model.CpModel()
                higher = model.NewBoolVar(f"{higher_tier}_placed")
                lower = model.NewBoolVar(f"{lower_tier}_placed")
                model.AddExactlyOne(higher, lower)

                stats = add_level_2_objective(
                    model,
                    [
                        {"id": higher_tier, "var": higher, "priority_tier": higher_tier},
                        {"id": lower_tier, "var": lower, "priority_tier": lower_tier},
                    ],
                )
                status, solver = self.solve(model)

                self.assertEqual(cp_model.OPTIMAL, status)
                self.assertEqual(1, solver.Value(higher))
                self.assertEqual(0, solver.Value(lower))
                self.assertGreater(
                    stats.coefficient_by_assignment[higher_tier],
                    stats.coefficient_by_assignment[lower_tier],
                )

    def test_team_priority_tier_id_scores_each_placed_session(self):
        model = cp_model.CpModel()
        assignments = []
        teams = []

        for tier_id, tier_name in enumerate(("S", "A", "B", "C", "D"), start=1):
            variable = model.NewBoolVar(f"team_{tier_name}_placed")
            team_id = f"team-{tier_name}"
            assignments.append({"id": team_id, "var": variable, "team_id": team_id})
            teams.append({"id": team_id, "priority_tier_id": tier_id})

        stats = add_level_2_objective(model, assignments, teams=teams)

        for tier_name in ("S", "A", "B", "C", "D"):
            self.assertEqual(
                EXPECTED_WEIGHTS[tier_name],
                stats.coefficient_by_assignment[f"team-{tier_name}"],
            )

    def test_soft_constraint_respect_adds_fixed_bonuses(self):
        model = cp_model.CpModel()
        plain = model.NewBoolVar("plain_A_placed")
        with_bonuses = model.NewBoolVar("bonus_A_placed")
        model.AddExactlyOne(plain, with_bonuses)

        stats = add_level_2_objective(
            model,
            [
                {"id": "plain", "var": plain, "priority_tier": "A"},
                {
                    "id": "with-bonuses",
                    "var": with_bonuses,
                    "priority_tier": "A",
                    "soft_bonuses": BONUS_WEIGHT_NAMES,
                },
            ],
        )
        status, solver = self.solve(model)

        expected_bonus_score = EXPECTED_WEIGHTS["A"] + sum(EXPECTED_WEIGHTS[name] for name in BONUS_WEIGHT_NAMES)
        self.assertEqual(cp_model.OPTIMAL, status)
        self.assertEqual(EXPECTED_WEIGHTS["A"], stats.coefficient_by_assignment["plain"])
        # Ce test empile TOUS les noms de bonus, malus compris (avoided_venue, spacing,
        # overload_day, les 4 violations, et V10 missing_session −1000) : la somme est donc
        # NÉGATIVE sous V10 (−8 sans missing, très négative avec). La stack est correctement
        # additionnée (assert ci-dessus/dessous), mais le solveur préfère désormais la
        # variable NUE — la démonstration reste « les bonus s'ajoutent au coefficient »,
        # portée par l'égalité de coefficient, pas par le camp que l'optimiseur choisit.
        self.assertEqual(expected_bonus_score, stats.coefficient_by_assignment["with-bonuses"])
        self.assertLess(expected_bonus_score, EXPECTED_WEIGHTS["A"])
        self.assertEqual(1, solver.Value(plain))
        self.assertEqual(0, solver.Value(with_bonuses))

    def test_score_formula_version_must_match_fixed_weights(self):
        model = cp_model.CpModel()
        variable = model.NewBoolVar("placed")

        with self.assertRaises(ValueError):
            add_level_2_objective(
                model,
                [{"var": variable, "priority_tier": "S"}],
                score_formula_version="T24_LEVEL_2_FIXED_WEIGHTS_V0",
            )


class StabilityTermsTest(unittest.TestCase):
    """P3-21 — build_stability_terms : +1 par variable dont la clé figure dans
    previousAssignments, HARD (absent de x) ignoré, dédup, champ vide → []."""

    def _x(self) -> dict:
        model = cp_model.CpModel()
        # model.x keys : (team_id, venue_id, day_of_week, "HH:MM"), start déjà normalisé.
        return {
            ("t1", "v1", 1, "18:00"): model.NewBoolVar("a"),
            ("t1", "v1", 3, "18:00"): model.NewBoolVar("b"),
        }

    def test_empty_previous_yields_no_terms(self):
        self.assertEqual([], build_stability_terms(self._x(), []))
        self.assertEqual([], build_stability_terms(self._x(), None))

    def test_matching_key_gets_the_stability_weight(self):
        x = self._x()
        terms = build_stability_terms(x, [{"teamId": "t1", "venueId": "v1", "dayOfWeek": 1, "startTime": "18:00"}])
        self.assertEqual(1, len(terms))
        var, weight = terms[0]
        self.assertIs(x[("t1", "v1", 1, "18:00")], var)
        self.assertEqual(STABILITY_TERM_WEIGHT, weight)

    def test_start_time_is_normalised_like_model_x(self):
        # "18:00:00" et "18:0" doivent viser la même clé "18:00".
        x = self._x()
        terms = build_stability_terms(x, [{"teamId": "t1", "venueId": "v1", "dayOfWeek": 1, "startTime": "18:00:00"}])
        self.assertEqual(1, len(terms))

    def test_absent_key_hard_or_unknown_is_ignored(self):
        # (t1,v1,5,18:00) n'a PAS de variable (créneau HARD ou inexistant) → aucun terme.
        x = self._x()
        terms = build_stability_terms(x, [{"teamId": "t1", "venueId": "v1", "dayOfWeek": 5, "startTime": "18:00"}])
        self.assertEqual([], terms)

    def test_duplicate_previous_entries_are_deduplicated(self):
        x = self._x()
        prev = {"teamId": "t1", "venueId": "v1", "dayOfWeek": 1, "startTime": "18:00"}
        terms = build_stability_terms(x, [prev, dict(prev)])
        self.assertEqual(1, len(terms))

    def test_multiplier_dominates_the_maximum_stability_mass(self):
        # Séparation lexicographique : un seul point de chaînage (min = 1) prime la masse
        # MAX de stabilité (2000 entrées × poids 1). C'est la borne codée dans main._solve.
        max_stability_mass = 2000 * STABILITY_TERM_WEIGHT
        self.assertGreater(CHAINING_STABILITY_MULTIPLIER * min(CHAINING_TIER_WEIGHTS.values()), max_stability_mass)


if __name__ == "__main__":
    unittest.main()
