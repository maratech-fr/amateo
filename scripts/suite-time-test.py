"""Promesse : une suite de tests ne grossit plus en silence — chaque suite (backend, behat, e2e,
frontend, engine) a un plafond de temps versionné dans `test-time-ceiling.json`, dépassé il
rougit le job sur la VM, et ce plafond ne remonte JAMAIS sans la ligne de décision du fondateur,
datée, dans le corps de la PR (plan CI 2026-11-15, § 8 ; contrats de la PR 2, § 12).

Lancé tel quel : `python3 -I scripts/suite-time-test.py` (stdlib seule, aucun réseau, aucun
Docker ; `git` pour le seul cas de bout en bout de `--never-rises`). `suite-time.py` porte un
tiret : le module est chargé PAR SON CHEMIN (importlib), jamais par `import`.

Contrat lu ici (plan § 12, contrats de la PR 2 ; les deux points marqués « couture » sont des
choix de test, écrits dans le brief de `neo`) :
- `verdict(suite, seconds, ceiling, enforce) -> tuple[bool, str]` — quatre régimes : sous les
  trois quarts du plafond, sous le plafond, au-dessus (rouge seulement si `enforce`), runner
  hébergé (indicatif, toujours vert) ; messages mot pour mot du plan ;
- couture : `never_rises(before: dict, after: dict, body: str) -> list[str]` — les refus, vide si
  la PR passe ; `before`/`after` = contenu décodé de `test-time-ceiling.json` à la base et à la tête ;
- CLI `--suite <nom> --seconds <entier>` : lit `test-time-ceiling.json` à la RACINE du dépôt
  (dossier parent de `scripts/`, quel que soit le dossier courant — le step Vitest tourne dans
  `frontend/`) ; `RUNNER_ENVIRONMENT=self-hosted` = verdict, sinon indicatif ; code 1 au-dessus du
  plafond sur la VM, 0 sinon, non nul pour une suite absente du fichier ;
- CLI `--never-rises <réf git>` : compare le fichier à cette réf (`git show`) au fichier sur
  disque ; corps de la PR dans `PR_BODY` ; code 1 sur un refus, 0 sinon.

Ce que ce test NE prouve PAS : l'appel réel en fin de chaque suite et dans `lint`/`lot-gate`
(`CiWorkflowRegimeTest`), ni la valeur des plafonds (déclarée sans test, plan § 12).
"""

from __future__ import annotations

import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "scripts" / "suite-time.py"
CEILING_FILE = ROOT / "test-time-ceiling.json"
SUITES = ("backend", "behat", "e2e", "frontend", "engine")


def _load():
    if not SCRIPT.is_file():
        return None, f"{SCRIPT} absent"
    spec = importlib.util.spec_from_file_location("suite_time", SCRIPT)
    if spec is None or spec.loader is None:
        return None, f"{SCRIPT} illisible"
    module = importlib.util.module_from_spec(spec)
    sys.modules["suite_time"] = module
    spec.loader.exec_module(module)
    return module, None


M, LOAD_ERROR = _load()

MSG_UNDER = "Plafond de temps : la suite behat a duré 652 s (plafond 815 s)."
MSG_LOWER_IT = (
    "Plafond de temps : la suite behat a duré 590 s (plafond 815 s). "
    "La durée est sous les trois quarts du plafond : abaisse-le au prochain bilan de lot."
)
MSG_OVER = (
    "Plafond de temps : la suite behat a duré 912 s (plafond 815 s, + 97 s). "
    "Lance make ci-mesure RUN=<id> : il dit si la base a dérivé, si la suite est au plafond, ou s'il faut relancer une fois. "
    "Un plafond ne remonte que sur décision du fondateur, écrite et datée dans le corps de la PR."
)
MSG_HOSTED = "Plafond de temps : la suite behat a duré 688 s sur un runner hébergé : indicatif, aucun verdict."
MSG_RISE = "test-time-ceiling.json : le plafond behat passe de 815 à 870 s sans la ligne de décision datée dans le corps de la PR."
MSG_GONE = "test-time-ceiling.json : la suite behat a disparu du fichier."

DECISION = "Plafond behat remonté de 815 à 870 s : décision du fondateur du 2026-11-20."
# Forme de la ligne et du refus pour la marge : choix de test (le plan ne l'écrit pas), dans le brief de `neo`.
MSG_MARGIN_RISE = "test-time-ceiling.json : la marge behat passe de 25 à 30 % sans la ligne de décision datée dans le corps de la PR."
MARGIN_DECISION = "Marge behat remontée de 25 à 30 % : décision du fondateur du 2026-11-20."


def ceilings(**overrides):
    """Un `test-time-ceiling.json` décodé : les cinq suites, marge 25 %."""
    base = {"backend": 1000, "behat": 815, "e2e": 635, "frontend": 230, "engine": 60}
    base.update(overrides)
    return {suite: {"ceiling": value, "margin": 25} for suite, value in base.items()}


class SuiteTimeCase(unittest.TestCase):
    def setUp(self):
        if M is None:
            self.fail(f"scripts/suite-time.py introuvable : {LOAD_ERROR}")


class VerdictOnTheVm(SuiteTimeCase):
    def test_under_the_ceiling_is_green_and_says_the_duration(self):
        """Sous le plafond (et au-dessus des trois quarts) : vert, durée et plafond dits."""
        self.assertEqual((True, MSG_UNDER), M.verdict("behat", 652, 815, True))

    def test_under_three_quarters_is_green_and_asks_to_lower_the_ceiling(self):
        """Sous les trois quarts du plafond : vert, et l'invitation à l'abaisser au bilan de lot (§ 8 « Comment il descend »)."""
        self.assertEqual((True, MSG_LOWER_IT), M.verdict("behat", 590, 815, True))

    def test_over_the_ceiling_on_the_vm_is_red_with_the_excess_and_the_diagnosis(self):
        """Au-dessus du plafond, sur la VM : rouge, l'excès en secondes, et `make ci-mesure RUN=<id>` pour trancher bruit ou dérive."""
        self.assertEqual((False, MSG_OVER), M.verdict("behat", 912, 815, True))

    def test_exactly_at_the_ceiling_is_green(self):
        """Le plafond est une borne atteignable : dépasser, c'est strictement au-dessus."""
        ok, message = M.verdict("behat", 815, 815, True)
        self.assertTrue(ok, message)


class IndicativeOnAHostedRunner(SuiteTimeCase):
    def test_a_hosted_runner_gives_a_duration_without_verdict(self):
        """Runner hébergé : la durée seule, indicative (patron `actWarningsRatchet.ts:54-63`)."""
        self.assertEqual((True, MSG_HOSTED), M.verdict("behat", 688, 815, False))

    def test_a_hosted_runner_never_reddens_even_above_the_ceiling(self):
        """Un runner hébergé est plus lent que la VM : jamais un rouge sur lui, même au-dessus du plafond."""
        ok, message = M.verdict("behat", 912, 815, False)
        self.assertTrue(ok, message)
        self.assertIn("indicatif, aucun verdict", message)


class NeverRises(SuiteTimeCase):
    def test_a_rise_without_the_line_is_refused(self):
        """Hausse sans la ligne de décision dans le corps de la PR : refusée, valeurs nommées."""
        self.assertEqual([MSG_RISE], M.never_rises(ceilings(), ceilings(behat=870), "Corps de PR sans décision."))

    def test_a_rise_with_the_dated_line_passes(self):
        """La ligne datée du fondateur, valeurs exactes : la hausse passe — au milieu du corps, fins de ligne CRLF de GitHub comprises."""
        body = "## Résumé\r\nUne PR.\r\n\r\n" + DECISION + "\r\n\r\nFin."
        self.assertEqual([], M.never_rises(ceilings(), ceilings(behat=870), body))

    def test_a_line_without_date_is_refused(self):
        """Sans la date de la réponse du fondateur, la ligne ne vaut rien (§ 8 : « la date est celle de sa réponse »)."""
        undated = "Plafond behat remonté de 815 à 870 s : décision du fondateur."
        self.assertEqual([MSG_RISE], M.never_rises(ceilings(), ceilings(behat=870), undated))

    def test_a_line_whose_values_do_not_match_the_file_is_refused(self):
        """La ligne autorise UNE hausse précise : une autre valeur de départ ou d'arrivée, ou une autre suite, ne l'autorise pas."""
        for label, line in (
            ("arrivée différente", "Plafond behat remonté de 815 à 900 s : décision du fondateur du 2026-11-20."),
            ("départ différent", "Plafond behat remonté de 800 à 870 s : décision du fondateur du 2026-11-20."),
            ("autre suite", "Plafond e2e remonté de 815 à 870 s : décision du fondateur du 2026-11-20."),
        ):
            with self.subTest(cas=label):
                self.assertEqual([MSG_RISE], M.never_rises(ceilings(), ceilings(behat=870), line))

    def test_a_suite_removed_from_the_file_is_refused(self):
        """Retirer une suite du fichier, c'est retirer son plafond : refusé."""
        after = ceilings()
        del after["behat"]
        self.assertEqual([MSG_GONE], M.never_rises(ceilings(), after, DECISION))

    def test_a_margin_rise_needs_the_dated_line_too(self):
        """Une marge relevée desserre le plafond comme un plafond relevé : même ligne datée exigée (décision de l'orchestrateur, PR 2)."""
        after = ceilings()
        after["behat"]["margin"] = 30

        self.assertEqual([MSG_MARGIN_RISE], M.never_rises(ceilings(), after, "Corps de PR sans décision."))
        self.assertEqual([MSG_MARGIN_RISE], M.never_rises(ceilings(), after, "Marge behat remontée de 25 à 30 % : décision du fondateur."))
        self.assertEqual([], M.never_rises(ceilings(), after, "Résumé.\r\n" + MARGIN_DECISION + "\r\n"))

    def test_a_lowered_or_unchanged_ceiling_passes_without_any_line(self):
        """Baisser (bilan de lot, PR 3 et 4) ou ne rien changer ne demande aucune décision."""
        self.assertEqual([], M.never_rises(ceilings(), ceilings(behat=700, e2e=555), ""))
        self.assertEqual([], M.never_rises(ceilings(), ceilings(), ""))


class TheRealCeilingFile(unittest.TestCase):
    def test_the_file_holds_the_five_suites_each_with_a_ceiling_and_a_margin(self):
        """Contrat `{"behat": {"ceiling": N, "margin": 25}, …}` pour les cinq suites — les valeurs, elles, sont déclarées sans test."""
        self.assertTrue(CEILING_FILE.is_file(), f"{CEILING_FILE} absent")
        data = json.loads(CEILING_FILE.read_text(encoding="utf-8"))

        self.assertEqual(sorted(SUITES), sorted(data))
        for suite in SUITES:
            with self.subTest(suite=suite):
                self.assertEqual({"ceiling", "margin"}, set(data[suite]))
                self.assertIsInstance(data[suite]["ceiling"], int)
                self.assertIsInstance(data[suite]["margin"], int)
                self.assertGreater(data[suite]["ceiling"], 0)
                self.assertGreater(data[suite]["margin"], 0)


class Cli(SuiteTimeCase):
    """Le script copié dans un faux dépôt : il lit le plafond à SA racine, jamais au dossier courant."""

    def setUp(self):
        super().setUp()
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = Path(self.tmp.name) / "depot"
        (self.repo / "scripts").mkdir(parents=True)
        (self.repo / "frontend").mkdir()
        shutil.copy(SCRIPT, self.repo / "scripts" / "suite-time.py")
        self.write(ceilings())

    def write(self, data):
        (self.repo / "test-time-ceiling.json").write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")

    def run_cli(self, *args, runner="self-hosted", body=None, cwd=None):
        env = {"PATH": os.environ.get("PATH", ""), "LANG": "C.UTF-8", "PYTHONIOENCODING": "utf-8", "HOME": self.tmp.name}
        if runner is not None:
            env["RUNNER_ENVIRONMENT"] = runner
        if body is not None:
            env["PR_BODY"] = body
        return subprocess.run(
            [sys.executable, "-I", str(self.repo / "scripts" / "suite-time.py"), *args],
            capture_output=True,
            text=True,
            env=env,
            cwd=str(cwd or self.repo / "frontend"),
            timeout=60,
            check=False,
        )

    def git(self, *args):
        done = subprocess.run(
            ["git", "-c", "user.name=essai", "-c", "user.email=essai@example.invalid", "-c", "commit.gpgsign=false", *args],
            capture_output=True,
            text=True,
            cwd=str(self.repo),
            timeout=60,
            check=False,
        )
        self.assertEqual(0, done.returncode, done.stderr)
        return done.stdout.strip()

    def test_over_the_ceiling_on_the_vm_exits_one_with_the_message(self):
        """Sur la VM, au-dessus du plafond : le job rougit (code 1), le message dit quoi faire — lancé depuis `frontend/`."""
        done = self.run_cli("--suite", "behat", "--seconds", "912")

        self.assertEqual(1, done.returncode, done.stdout + done.stderr)
        self.assertIn(MSG_OVER, done.stdout + done.stderr)

    def test_over_the_ceiling_on_a_hosted_runner_exits_zero(self):
        """Runner hébergé : code 0 quoi qu'il arrive, durée indicative."""
        done = self.run_cli("--suite", "behat", "--seconds", "912", runner="github-hosted")

        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        self.assertIn("sur un runner hébergé : indicatif, aucun verdict.", done.stdout + done.stderr)

    def test_under_the_ceiling_on_the_vm_exits_zero(self):
        done = self.run_cli("--suite", "behat", "--seconds", "652")

        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        self.assertIn(MSG_UNDER, done.stdout + done.stderr)

    def test_a_suite_missing_from_the_file_never_passes_silently(self):
        """Une coquille de nom de suite dans ci.yml ne doit jamais donner un vert sans mesure."""
        done = self.run_cli("--suite", "behta", "--seconds", "10")

        self.assertNotEqual(0, done.returncode, done.stdout + done.stderr)

    def test_never_rises_compares_the_base_ref_to_the_file_on_disk(self):
        """De bout en bout : la base porte 815, la tête 870 ; sans la ligne, code 1 ; avec, code 0."""
        self.git("init", "-q", "-b", "main")
        self.git("add", "test-time-ceiling.json")
        self.git("commit", "-q", "-m", "base")
        self.write(ceilings(behat=870))

        refused = self.run_cli("--never-rises", "main", body="Pas de décision.", cwd=self.repo)
        self.assertEqual(1, refused.returncode, refused.stdout + refused.stderr)
        self.assertIn(MSG_RISE, refused.stdout + refused.stderr)

        allowed = self.run_cli("--never-rises", "main", body="Résumé.\r\n" + DECISION + "\r\n", cwd=self.repo)
        self.assertEqual(0, allowed.returncode, allowed.stdout + allowed.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=1)
