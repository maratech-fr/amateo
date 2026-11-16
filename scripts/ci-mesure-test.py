"""Promesse : `make ci-mesure` est le compteur d'alerte du quota de minutes hébergées — il lit
l'usage FACTURÉ par GitHub depuis le jour de renouvellement (jamais une somme recalculée),
sort 3 à 75 % et 4 à 90 % des 3 000 minutes pour que la réserve bascule à temps, sort 2 quand
la facturation est illisible plutôt que d'afficher un faux compte, dit lequel des trois cas
explique un plafond de temps rouge, et mesure la part de test d'une PR (plan CI 2026-11-15,
§ 8, § 9.6, § 10 ; contrats de la PR 2, § 12).

Lancé tel quel : `python3 -I scripts/ci-mesure-test.py` (stdlib seule, aucun réseau, aucun
Docker ; la CLI joue contre un faux `gh`). `ci-mesure.py` porte un tiret : le module est chargé
PAR SON CHEMIN (importlib), jamais par `import`.

Contrat lu ici. Le plan ne donne que les phrases et les codes ; les fonctions ci-dessous sont
des COUTURES de test, écrites dans le brief de `neo` :
- `cycle_start(today: date, cycle_day: int) -> date` : dernier jour de renouvellement <= today ;
- `billed_minutes(usage: dict, since: date) -> int` : somme des `quantity` des `usageItems` de
  `sku` « Actions Linux » datés de `since` ou après (forme de l'API
  `organizations/<org>/settings/billing/usage`) ; `ValueError` sur une réponse sans `usageItems` ;
- `quota_verdict(minutes: int, since: date, quota: int = 3000) -> tuple[int, str]` : codes 0, 3
  (>= 75 %), 4 (>= 90 %) ;
- `diagnose(run_id, suite, seconds, recent, older, margin) -> str` : les trois cas du § 8
  (correction 1 de la relecture ciblée : la PR qui grossit elle-même la suite) ;
- `classify_path(path) -> "test" | "produit" | None` et `part_line(test_lines, product_lines) -> str` ;
- CLI : `--cycle-day N` ; code 2 si `gh` est absent, non authentifié, ou si la facturation ne se
  lit pas ; sinon le code du quota.

- relevé hebdomadaire : conclusion du dernier run terminé de `quality-weekly.yml`, lue par
  `gh run list --workflow quality-weekly.yml … --json …` (sortie JSON brute) ; `failure` = ligne
  « Relevé hebdomadaire … rouge … Ligne de roadmap à ouvrir dans la prochaine PR. » ; aucun run =
  « aucun relevé hebdomadaire encore joué », sans erreur.

Ce que ce test NE prouve PAS : la lecture réelle de l'API de facturation (première lecture
après le merge, plan § 11 PR 2), le 9ᵉ décile, la ligne de stockage, le rappel des variables à
supprimer au renouvellement — affichés si les données le permettent, sans test (décision de
l'orchestrateur).
"""

from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from datetime import date, datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "scripts" / "ci-mesure.py"


def _load():
    if not SCRIPT.is_file():
        return None, f"{SCRIPT} absent"
    spec = importlib.util.spec_from_file_location("ci_mesure", SCRIPT)
    if spec is None or spec.loader is None:
        return None, f"{SCRIPT} illisible"
    module = importlib.util.module_from_spec(spec)
    sys.modules["ci_mesure"] = module
    spec.loader.exec_module(module)
    return module, None


M, LOAD_ERROR = _load()

CYCLE = date(2026, 12, 4)
LOT_SWITCH = "Seuil de réserve franchi : demander au fondateur gh variable set CI_RUNS_ON_LOT --body amateo."
MSG_77 = "Minutes facturées depuis le 2026-12-04 : 2 310 sur 3 000 (77 %). " + LOT_SWITCH
MSG_40 = "Minutes facturées depuis le 2026-12-04 : 1 200 sur 3 000 (40 %)."
MSG_NOISE_OR_PR = (
    "Run 4370, suite behat : 912 s ; médiane des 10 derniers runs 655 s ; des 10 précédents 652 s. "
    "Tendance + 0,5 %, excès + 39 % pour une marge de 25 % : relance une fois ; encore rouge, c'est cette PR."
)
MSG_PART = "Part de test : 1 310 lignes de test pour 420 de produit (76 %)."


def item(day, quantity, sku="Actions Linux"):
    return {
        "date": day,
        "product": "actions",
        "sku": sku,
        "quantity": quantity,
        "unitType": "Minutes" if sku.startswith("Actions L") else "GigabyteHours",
        "pricePerUnit": 0.008,
        "grossAmount": 0,
        "discountAmount": 0,
        "netAmount": 0,
        "organizationName": "exemple",
        "repositoryName": "exemple/depot",
    }


class MesureCase(unittest.TestCase):
    def setUp(self):
        if M is None:
            self.fail(f"scripts/ci-mesure.py introuvable : {LOAD_ERROR}")


class BillingCycle(MesureCase):
    def test_a_cycle_that_does_not_start_on_the_first(self):
        """Les minutes incluses se renouvellent au jour du cycle de facturation, pas le 1ᵉʳ (§ 9.6, `CYCLE_DAY`)."""
        cases = {
            (date(2026, 12, 10), 4): date(2026, 12, 4),
            (date(2026, 12, 4), 4): date(2026, 12, 4),
            (date(2026, 12, 2), 4): date(2026, 11, 4),
            (date(2027, 1, 2), 4): date(2026, 12, 4),
            (date(2026, 12, 10), 1): date(2026, 12, 1),
        }
        for (today, day), expected in cases.items():
            with self.subTest(today=today.isoformat(), cycle_day=day):
                self.assertEqual(expected, M.cycle_start(today, day))

    def test_billed_minutes_read_the_actions_linux_line_since_the_renewal(self):
        """Seule la ligne « Actions Linux » compte, depuis le renouvellement : ni le stockage, ni le cycle précédent."""
        usage = {
            "usageItems": [
                item("2026-12-04", 1000),
                item("2026-12-05T00:00:00Z", 1310),
                item("2026-12-03", 999),
                item("2026-12-05", 42, sku="Actions storage"),
            ]
        }
        self.assertEqual(2310, M.billed_minutes(usage, CYCLE))

    def test_an_unreadable_billing_answer_raises(self):
        """Une réponse sans `usageItems` n'est jamais lue comme zéro minute."""
        for label, answer in (("message d'erreur", {"message": "Must have admin rights"}), ("vide", {})):
            with self.subTest(cas=label), self.assertRaises(ValueError):
                M.billed_minutes(answer, CYCLE)


class Reserve(MesureCase):
    def test_under_three_quarters_exits_zero(self):
        self.assertEqual((0, MSG_40), M.quota_verdict(1200, CYCLE))
        self.assertEqual(0, M.quota_verdict(2249, CYCLE)[0])

    def test_seventy_five_percent_exits_three_and_asks_for_the_lot_switch(self):
        """À 75 % (2 250 minutes) : code 3, l'orchestrateur n'ouvre plus de PR de lot et demande `CI_RUNS_ON_LOT` au fondateur."""
        self.assertEqual(3, M.quota_verdict(2250, CYCLE)[0])
        self.assertEqual((3, MSG_77), M.quota_verdict(2310, CYCLE))

    def test_ninety_percent_exits_four_and_asks_for_the_verdict_switch(self):
        """À 90 % (2 700 minutes) : code 4, le verdict de `main` part aussi sur la VM (`CI_RUNS_ON_VERDICT`)."""
        for minutes in (2700, 2950, 3100):
            with self.subTest(minutes=minutes):
                code, message = M.quota_verdict(minutes, CYCLE)
                self.assertEqual(4, code)
                self.assertTrue(message.startswith("Minutes facturées depuis le 2026-12-04 : "), message)
                self.assertIn("gh variable set CI_RUNS_ON_VERDICT --body amateo", message)


# Médianes sans ambiguïté sur 10 valeurs (les deux du milieu égales).
RECENT_655 = [650, 652, 654, 655, 655, 655, 655, 658, 660, 662]
OLDER_652 = [645, 648, 650, 652, 652, 652, 652, 655, 657, 660]


class RedCeilingDiagnosis(MesureCase):
    def test_the_base_has_drifted(self):
        """Tendance au-dessus de 5 % : la base a dérivé, quelle que soit la durée du run — on accélère ou on fusionne."""
        message = M.diagnose(4370, "behat", 820, [700] * 10, [650] * 10, 25)

        self.assertTrue(message.startswith("Run 4370, suite behat : 820 s ; médiane des 10 derniers runs 700 s ; des 10 précédents 650 s. Tendance + 7,7 %"), message)
        self.assertIn("la base a dérivé", message.lower())
        self.assertNotIn("relance", message)

    def test_the_suite_reached_its_ceiling_by_accumulation(self):
        """Tendance sous 5 %, run à moins d'une marge de la médiane récente : la suite est au plafond par accumulation."""
        message = M.diagnose(4370, "behat", 830, [790] * 10, [785] * 10, 25)

        self.assertTrue(message.startswith("Run 4370, suite behat : 830 s ; médiane des 10 derniers runs 790 s ; des 10 précédents 785 s. Tendance + 0,6 %"), message)
        self.assertIn("au plafond par accumulation", message)
        self.assertNotIn("relance", message)

    def test_noise_or_the_pull_request_itself_grew_the_suite(self):
        """Tendance sous 5 %, excès au-delà de la marge : la médiane récente ne contient pas encore l'ajout de la PR — relancer une fois ; encore rouge, c'est elle."""
        self.assertEqual(MSG_NOISE_OR_PR, M.diagnose(4370, "behat", 912, RECENT_655, OLDER_652, 25))


class TestShare(MesureCase):
    def test_test_and_product_paths_are_told_apart(self):
        """La part de test d'une PR compte les lignes de test contre les lignes de produit, la doc dans aucune des deux."""
        cases = {
            "backend/tests/Unit/Service/ExempleTest.php": "test",
            "backend/features/plan-de-periode-en-overlay.feature": "test",
            "frontend/src/features/planning/PlanningPage.test.tsx": "test",
            "frontend/src/lib/clock.test.ts": "test",
            "frontend/tests/e2e/planning.spec.ts": "test",
            "engine/tests/test_solver.py": "test",
            "scripts/ci-mesure-test.py": "test",
            "backend/src/Service/Exemple.php": "produit",
            "frontend/src/features/planning/PlanningPage.tsx": "produit",
            "frontend/src/lib/clock.ts": "produit",
            "engine/app/solver.py": "produit",
            "docs/testing/tests-par-parcours.md": None,
            "specs/evolution/roadmap.md": None,
        }
        for path, expected in cases.items():
            with self.subTest(path=path):
                self.assertEqual(expected, M.classify_path(path))

    def test_the_share_line(self):
        self.assertEqual(MSG_PART, M.part_line(1310, 420))


FAKE_GH = """#!{python} -I
import json, sys
data = json.load(open({data!r}, encoding="utf-8"))
args = sys.argv[1:]
if data.get("unauthenticated"):
    sys.stderr.write("To get started with GitHub CLI, please run:  gh auth login\\n")
    sys.exit(4)
if args[:2] == ["auth", "status"]:
    sys.exit(0)
if args[:2] == ["repo", "view"]:
    print(json.dumps({{"nameWithOwner": "exemple/depot", "owner": {{"login": "exemple"}}}}))
    sys.exit(0)
if args[:2] == ["run", "list"]:
    # Relevé hebdomadaire : seul `--workflow quality-weekly.yml` a des runs ; sortie JSON brute (pas de --jq).
    weekly = data.get("weekly", []) if "quality-weekly.yml" in args else []
    print(json.dumps(weekly))
    sys.exit(0)
path = next((a for a in args[1:] if not a.startswith("-")), "") if args[:1] == ["api"] else ""
if "settings/billing/usage" in path:
    if data.get("billing") is None:
        sys.stderr.write("gh: Not Found (HTTP 404)\\n")
        sys.exit(1)
    print(json.dumps(data["billing"]))
    sys.exit(0)
if "/jobs" in path:
    print(json.dumps({{"total_count": 0, "jobs": []}}))
    sys.exit(0)
if "actions/" in path and "runs" in path:
    print(json.dumps({{"total_count": 0, "workflow_runs": []}}))
    sys.exit(0)
sys.stderr.write("gh: Not Found (HTTP 404)\\n")
sys.exit(1)
"""


class CliCase(MesureCase):
    def setUp(self):
        super().setUp()
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def run_cli(self, data, *args, with_gh=True):
        tmp = Path(self.tmp.name)
        bin_dir = tmp / "bin"
        bin_dir.mkdir(exist_ok=True)
        (tmp / "home").mkdir(exist_ok=True)
        data_file = tmp / "gh-data.json"
        data_file.write_text(json.dumps(data), encoding="utf-8")
        gh = bin_dir / "gh"
        if with_gh:
            gh.write_text(FAKE_GH.format(python=sys.executable, data=str(data_file)), encoding="utf-8")
            gh.chmod(0o700)
        env = {"PATH": str(bin_dir), "HOME": str(tmp / "home"), "LANG": "C.UTF-8", "PYTHONIOENCODING": "utf-8"}
        return subprocess.run([sys.executable, "-I", str(SCRIPT), *args], capture_output=True, text=True, env=env, cwd=str(tmp), timeout=120, check=False)


class Cli(CliCase):
    def test_missing_gh_exits_2(self):
        done = self.run_cli({}, "--cycle-day", "4", with_gh=False)

        self.assertEqual(2, done.returncode, done.stdout + done.stderr)

    def test_unauthenticated_gh_exits_2(self):
        done = self.run_cli({"unauthenticated": True}, "--cycle-day", "4")

        self.assertEqual(2, done.returncode, done.stdout + done.stderr)

    def test_unreadable_billing_exits_2_without_any_count(self):
        """Le jeton ne lit pas la facturation : code 2, et aucun compte de minutes affiché (jamais une somme recalculée à la place)."""
        done = self.run_cli({"billing": None}, "--cycle-day", "4")

        self.assertEqual(2, done.returncode, done.stdout + done.stderr)
        self.assertNotIn("Minutes facturées", done.stdout)

    def test_the_quota_code_is_the_exit_code(self):
        """De bout en bout : 2 310 minutes depuis le renouvellement (aujourd'hui) — code 3, la phrase de la réserve imprimée."""
        today = datetime.now(timezone.utc).date()
        billing = {"usageItems": [item(today.isoformat(), 2310)]}
        done = self.run_cli({"billing": billing}, "--cycle-day", str(today.day))

        self.assertEqual(3, done.returncode, done.stdout + done.stderr)
        self.assertIn(f"Minutes facturées depuis le {today.isoformat()} : 2 310 sur 3 000 (77 %). {LOT_SWITCH}", done.stdout)


def weekly_run(conclusion):
    return {"conclusion": conclusion, "status": "completed", "createdAt": "2026-12-07T05:00:00Z", "databaseId": 4400, "event": "schedule"}


class WeeklyCoverageReading(CliCase):
    """Le relevé hebdomadaire se lit dans la conclusion du dernier run terminé de `quality-weekly.yml`
    (décision de l'orchestrateur, PR 2) : `failure` = un plancher est franchi."""

    def run_weekly(self, runs):
        today = datetime.now(timezone.utc).date()
        billing = {"usageItems": [item(today.isoformat(), 100)]}
        return self.run_cli({"billing": billing, "weekly": runs}, "--cycle-day", str(today.day))

    def test_a_red_weekly_run_prints_the_roadmap_line_to_open(self):
        """Relevé rouge : la ligne de roadmap à ouvrir dans la prochaine PR est imprimée."""
        done = self.run_weekly([weekly_run("failure")])

        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        lines = [line for line in done.stdout.splitlines() if line.startswith("Relevé hebdomadaire")]
        self.assertEqual(1, len(lines), done.stdout)
        self.assertIn("rouge", lines[0])
        self.assertIn("Ligne de roadmap à ouvrir dans la prochaine PR.", lines[0])

    def test_a_green_weekly_run_prints_no_roadmap_line(self):
        done = self.run_weekly([weekly_run("success")])

        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        self.assertNotIn("Ligne de roadmap", done.stdout)
        self.assertNotIn("rouge", done.stdout)

    def test_no_weekly_run_yet_says_so_without_error(self):
        """Aucun relevé encore joué (premier lundi pas encore passé) : une ligne qui le dit, code 0."""
        done = self.run_weekly([])

        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        self.assertIn("aucun relevé hebdomadaire encore joué", done.stdout.lower())
        self.assertNotIn("Ligne de roadmap", done.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=1)
