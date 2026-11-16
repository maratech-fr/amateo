"""Promesse : un commit de `main` n'est déclaré vert (donc déployable, `isCiGreen`) que si la PR
mergée qui le porte a joué le gate COMPLET — son run de PR le plus récent, chaque job hors
« Lot Gate » et « Main Verdict » réussi. Un job sauté vaut succès pour un check requis : c'est le
trou que ce verdict ferme après le merge (plan CI 2026-11-15, § 9.4).

Lancé tel quel par le step `ci-verdict self-test` du job `lint` : `python3 -I scripts/ci-verdict-test.py`
(stdlib seule, aucun réseau, aucun Docker). `ci-verdict.py` porte un tiret : le module est chargé
PAR SON CHEMIN (importlib), jamais par `import`.

Contrat lu ici (plan § 12, contrats de la PR 1) :
- `verdict(repo, sha, gh, sleep=time.sleep, attempts=6, wait_seconds=20) -> tuple[int, str]` ;
  `gh(chemin) -> JSON déjà décodé` (un appel lève une exception = échec réseau) ;
- trois lectures, reconnues ici par leur chemin : `…/commits/<sha>/pulls` (liste de PR),
  `…/runs?…head_sha=<tête>…` (`{"workflow_runs": [...]}`), `…/runs/<id>/jobs…` (`{"jobs": [...]}`) ;
- une liste de PR ou de runs VIDE se relit `attempts` fois, espacées de `wait_seconds` (décalage
  d'indexation de GitHub juste après le merge) ; trois échecs réseau donnent le code 2 ;
- codes : 0 prouvé ; 1 non prouvé ; 2 GitHub injoignable, ou entrée mal formée (aucun appel) ;
- `EXEMPT_JOBS = ("Lot Gate", "Main Verdict")` ; messages mot pour mot du plan.

Ce que ce test NE prouve PAS : le câblage du job `main-verdict` (`CiWorkflowRegimeTest`), ni
l'appel réel à l'API GitHub (premier push sur `main` après le merge de la PR 1).
"""

from __future__ import annotations

import importlib.util
import re
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "scripts" / "ci-verdict.py"


def _load():
    if not SCRIPT.is_file():
        return None, f"{SCRIPT} absent"
    spec = importlib.util.spec_from_file_location("ci_verdict", SCRIPT)
    if spec is None or spec.loader is None:
        return None, f"{SCRIPT} illisible"
    module = importlib.util.module_from_spec(spec)
    sys.modules["ci_verdict"] = module
    spec.loader.exec_module(module)
    return module, None


M, LOAD_ERROR = _load()

REPO = "exemple/depot"
SHA = "c0ffee" + "0" * 34
HEAD = "ab12cd3" + "f" * 33
LOT_HEAD = "1234567" + "e" * 33

# Les 16 jobs du gate complet d'une PR vers `main` : les 15 checks requis et la perf du moteur.
FULL_GATE = (
    "Backend Tests (suite + couverture)",
    "Blocking Tests",
    "Build Docker Images",
    "Dependency Audit",
    "E2E (Playwright)",
    "Engine Perf Gate",
    "Engine Tests",
    "Engine semantics",
    "Frontend (build + unit)",
    "Functional Tests (Behat)",
    "Lint",
    "PHPStan & CS-Fixer",
    "Preflight",
    "Rector (style gate)",
    "Secrets Scan (gitleaks)",
    "Semgrep (security gate)",
)

MSG_PROVED = "Verdict : la PR #12 a joué le gate complet (run 4370, 16 jobs réussis)."
MSG_NO_PR = (
    "Aucune PR mergée vers main ne porte ce commit après 2 minutes d'attente : le gate complet n'est pas prouvé. "
    "Si la PR vient d'être mergée, relance ce job (« Re-run jobs ») : le run garde son identifiant."
)
MSG_NO_RUN = "La PR #12 n'a aucun run ci.yml sur sa tête ab12cd3 après 2 minutes d'attente : le gate complet n'est pas prouvé. Relance ce job."
MSG_ONE_SKIPPED = "La PR #12 n'a pas joué le gate complet : « Backend Tests (suite + couverture) » est sauté (run 4370)."
MSG_THREE = (
    "La PR #12 n'a pas joué le gate complet : 3 jobs ne sont pas réussis "
    "(« E2E (Playwright) » sauté, « Lint » en échec, « Rector (style gate) » annulé) (run 4370)."
)
MSG_NO_GATE_JOB = "La PR #12 n'a joué aucun job du gate complet : le gate complet n'est pas prouvé (run 4370)."
MSG_UNREACHABLE ="GitHub injoignable après 3 essais : relance ce job."


def pull(number, *, base="main", merge_sha=SHA, head=HEAD, merged=True):
    return {
        "number": number,
        "state": "closed" if merged else "open",
        "merged_at": "2026-11-15T10:00:00Z" if merged else None,
        "merge_commit_sha": merge_sha,
        "base": {"ref": base},
        "head": {"sha": head, "ref": f"feat/branche-{number}"},
    }


def run(run_id, *, number, created_at, head=HEAD):
    return {
        "id": run_id,
        "run_number": number,
        "run_attempt": 1,
        "name": "CI",
        "path": ".github/workflows/ci.yml",
        "event": "pull_request",
        "status": "completed",
        "head_sha": head,
        "created_at": created_at,
        "updated_at": created_at,
    }


def jobs(overrides=None, *, gate=FULL_GATE, lot="skipped"):
    """Les jobs d'un run de PR vers `main` : chaque job du gate réussi sauf `overrides`, « Lot Gate » sauté."""
    overrides = overrides or {}
    listed = [{"name": "Lot Gate", "status": "completed", "conclusion": lot}]
    listed += [{"name": name, "status": "completed", "conclusion": overrides.get(name, "success")} for name in gate]
    return sorted(listed, key=lambda j: j["name"])


def light_run_jobs():
    """Un run du régime de lot : « Lot Gate » réussi, tout le gate complet sauté."""
    return jobs({name: "skipped" for name in FULL_GATE}, lot="success")


class FakeGitHub:
    """Répond aux trois lectures du verdict ; enregistre chaque chemin demandé."""

    def __init__(self, pulls, runs, jobs_by_run, *, empty_pulls=0, empty_runs=0, failures=0):
        self.pulls = pulls
        self.runs = runs
        self.jobs_by_run = jobs_by_run
        self.empty_pulls = empty_pulls
        self.empty_runs = empty_runs
        self.failures = failures
        self.calls = []

    def __call__(self, path):
        self.calls.append(path)
        if self.failures > 0:
            self.failures -= 1
            raise ConnectionError("GitHub injoignable (simulé)")
        if re.search(r"commits/[0-9a-f]{40}/pulls", path):
            if self.empty_pulls > 0:
                self.empty_pulls -= 1
                return []
            return self.pulls
        found = re.search(r"runs/(\d+)/jobs", path)
        if found:
            listed = self.jobs_by_run[int(found.group(1))]
            return {"total_count": len(listed), "jobs": listed}
        if "runs" in path:
            if self.empty_runs > 0:
                self.empty_runs -= 1
                return {"total_count": 0, "workflow_runs": []}
            return {"total_count": len(self.runs), "workflow_runs": self.runs}
        raise AssertionError(f"lecture inattendue : {path}")

    def count(self, pattern):
        return sum(1 for path in self.calls if re.search(pattern, path))


class Sleeps:
    def __init__(self):
        self.calls = []

    def __call__(self, seconds):
        self.calls.append(seconds)


def gate_run():
    return [run(4370, number=900, created_at="2026-11-15T09:40:00Z")], {4370: jobs()}


class VerdictCase(unittest.TestCase):
    def setUp(self):
        if M is None:
            self.fail(f"scripts/ci-verdict.py introuvable : {LOAD_ERROR}")
        self.sleeps = Sleeps()

    def verdict(self, gh, repo=REPO, sha=SHA):
        return M.verdict(repo, sha, gh, sleep=self.sleeps)


class ProvedGate(VerdictCase):
    def test_a_squash_merge_whose_run_played_the_full_gate_is_proved(self):
        """Squash : la PR #12, base `main`, porte ce commit ; son run a réussi ses 16 jobs."""
        runs, by_run = gate_run()
        gh = FakeGitHub([pull(12)], runs, by_run)

        self.assertEqual((0, MSG_PROVED), self.verdict(gh))
        self.assertEqual(1, gh.count(r"/runs/\d+/jobs"), "Une seule lecture des jobs : celle du run retenu.")
        self.assertTrue(any(HEAD in path for path in gh.calls if "/jobs" not in path and "/pulls" not in path), "Les runs se lisent sur la tête de la PR mergée.")

    def test_the_merge_commit_of_a_final_lot_pr_is_proved_by_the_pr_to_main(self):
        """Merge commit (PR finale d'un lot) : seule la PR mergée vers `main` dont le commit de merge est ce commit compte."""
        runs, by_run = gate_run()
        pulls = [
            pull(7, base="lot/essai", merge_sha="d" * 40, head=LOT_HEAD),
            pull(13, merge_sha="e" * 40, head=LOT_HEAD, merged=False),
            pull(12),
        ]
        gh = FakeGitHub(pulls, runs, by_run)

        self.assertEqual((0, MSG_PROVED), self.verdict(gh))
        self.assertFalse(any(LOT_HEAD in path for path in gh.calls), "Les PR du lot et la PR ouverte ne sont jamais lues comme preuve.")

    def test_the_exempt_jobs_are_the_lot_gate_and_the_verdict_itself(self):
        """« Lot Gate » est sauté sur une PR vers `main`, « Main Verdict » ne tourne que sur `main` : seuls dispensés."""
        self.assertEqual(("Lot Gate", "Main Verdict"), tuple(M.EXEMPT_JOBS))


class IndexingDelay(VerdictCase):
    def test_an_empty_pull_list_then_filled_is_proved(self):
        """Décalage d'indexation : la liste des PR vide au premier essai, remplie ensuite — prouvé, pas un faux rouge."""
        runs, by_run = gate_run()
        gh = FakeGitHub([pull(12)], runs, by_run, empty_pulls=2)

        self.assertEqual((0, MSG_PROVED), self.verdict(gh))
        self.assertEqual(3, gh.count(r"/pulls"))
        self.assertEqual([20, 20], self.sleeps.calls, "Deux attentes de 20 s avant la troisième lecture.")

    def test_a_pull_list_empty_to_the_end_is_not_proved_and_says_rerun(self):
        """Aucune PR après six essais (2 minutes) : code 1, le message dit de relancer le job."""
        runs, by_run = gate_run()
        gh = FakeGitHub([pull(12)], runs, by_run, empty_pulls=99)

        self.assertEqual((1, MSG_NO_PR), self.verdict(gh))
        self.assertEqual(6, gh.count(r"/pulls"), "Six lectures, pas une de plus.")
        self.assertTrue(self.sleeps.calls and set(self.sleeps.calls) == {20}, self.sleeps.calls)
        self.assertIn(len(self.sleeps.calls), (5, 6))

    def test_a_run_list_empty_then_filled_is_proved(self):
        """Même décalage sur la liste des runs de la PR : réessayée, puis prouvée."""
        runs, by_run = gate_run()
        gh = FakeGitHub([pull(12)], runs, by_run, empty_runs=1)

        self.assertEqual((0, MSG_PROVED), self.verdict(gh))
        self.assertEqual([20], self.sleeps.calls)

    def test_no_run_on_the_head_is_not_proved_and_says_rerun(self):
        """Aucun run ci.yml sur la tête de la PR après six essais : code 1, tête abrégée, relance."""
        runs, by_run = gate_run()
        gh = FakeGitHub([pull(12)], runs, by_run, empty_runs=99)

        self.assertEqual((1, MSG_NO_RUN), self.verdict(gh))
        self.assertEqual(0, gh.count(r"/jobs"))


class NotTheFullGate(VerdictCase):
    def test_one_skipped_job_is_not_proved(self):
        """Un job du gate sauté vaut succès pour un check requis, jamais pour le verdict."""
        gh = FakeGitHub([pull(12)], [run(4370, number=900, created_at="2026-11-15T09:40:00Z")], {4370: jobs({"Backend Tests (suite + couverture)": "skipped"})})

        self.assertEqual((1, MSG_ONE_SKIPPED), self.verdict(gh))

    def test_a_failed_job_is_not_proved(self):
        """Un job du gate en échec : non prouvé, le job nommé."""
        gh = FakeGitHub([pull(12)], [run(4370, number=900, created_at="2026-11-15T09:40:00Z")], {4370: jobs({"Lint": "failure"})})

        code, message = self.verdict(gh)
        self.assertEqual(1, code)
        self.assertTrue(message.startswith("La PR #12 n'a pas joué le gate complet : "), message)
        self.assertIn("« Lint »", message)
        self.assertIn("en échec", message)
        self.assertTrue(message.endswith("(run 4370)."), message)

    def test_three_jobs_not_successful_are_all_named(self):
        """Sauté, en échec, annulé : chacun nommé avec son état, dans un seul message."""
        listed = jobs({"E2E (Playwright)": "skipped", "Lint": "failure", "Rector (style gate)": "cancelled"})
        gh = FakeGitHub([pull(12)], [run(4370, number=900, created_at="2026-11-15T09:40:00Z")], {4370: listed})

        self.assertEqual((1, MSG_THREE), self.verdict(gh))

    def test_the_most_recent_run_being_light_is_not_proved(self):
        """Le run le plus récent de la PR est un run léger (gate complet sauté) : un run complet plus ancien ne compte pas."""
        older = run(4300, number=880, created_at="2026-11-15T08:00:00Z")
        newer = run(4370, number=900, created_at="2026-11-15T09:40:00Z")
        gh = FakeGitHub([pull(12)], [older, newer], {4300: jobs(), 4370: light_run_jobs()})

        code, message = self.verdict(gh)
        self.assertEqual(1, code)
        self.assertTrue(message.startswith("La PR #12 n'a pas joué le gate complet : 16 jobs ne sont pas réussis ("), message)
        self.assertIn("« Backend Tests (suite + couverture) » sauté", message)
        self.assertTrue(message.endswith("(run 4370)."), message)

    def test_a_run_without_any_job_of_the_full_gate_is_not_proved(self):
        """Base vide : un run dont les seuls jobs sont dispensés (ou sans aucun job) ne prouve jamais le gate complet."""
        for label, listed in (
            ("dispensés seuls", [{"name": "Lot Gate", "status": "completed", "conclusion": "success"}, {"name": "Main Verdict", "status": "completed", "conclusion": "success"}]),
            ("aucun job", []),
        ):
            with self.subTest(cas=label):
                gh = FakeGitHub([pull(12)], [run(4370, number=900, created_at="2026-11-15T09:40:00Z")], {4370: listed})

                self.assertEqual((1, MSG_NO_GATE_JOB), self.verdict(gh))

    def test_of_two_runs_the_most_recent_decides(self):
        """Deux runs (base changée, relance) : le plus récent, réussi, l'emporte sur un ancien rouge — quel que soit l'ordre de la liste."""
        newer = run(4370, number=900, created_at="2026-11-15T09:40:00Z")
        older = run(4300, number=880, created_at="2026-11-15T08:00:00Z")
        gh = FakeGitHub([pull(12)], [newer, older], {4300: jobs({"Lint": "failure"}), 4370: jobs()})

        self.assertEqual((0, MSG_PROVED), self.verdict(gh))


class Unreachable(VerdictCase):
    def test_three_network_failures_give_code_two(self):
        """GitHub injoignable trois fois : code 2, le message dit de relancer — jamais un faux « non prouvé »."""
        runs, by_run = gate_run()
        gh = FakeGitHub([pull(12)], runs, by_run, failures=99)

        self.assertEqual((2, MSG_UNREACHABLE), self.verdict(gh))
        self.assertEqual(3, len(gh.calls), "Trois essais, pas un de plus.")

    def test_two_network_failures_then_an_answer_is_proved(self):
        """Deux échecs réseau puis une réponse : le troisième essai suffit."""
        runs, by_run = gate_run()
        gh = FakeGitHub([pull(12)], runs, by_run, failures=2)

        self.assertEqual((0, MSG_PROVED), self.verdict(gh))

    def test_a_malformed_input_gives_code_two_without_any_call(self):
        """Dépôt ou commit mal formé : code 2 avant toute lecture."""
        for repo, sha in (("", SHA), ("sans-barre", SHA), (REPO, "pas-un-sha"), (REPO, "")):
            with self.subTest(repo=repo, sha=sha):
                runs, by_run = gate_run()
                gh = FakeGitHub([pull(12)], runs, by_run)

                code, _ = self.verdict(gh, repo=repo, sha=sha)
                self.assertEqual(2, code)
                self.assertEqual([], gh.calls)


if __name__ == "__main__":
    unittest.main(verbosity=1)
