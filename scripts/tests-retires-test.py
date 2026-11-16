"""Promesse : une PR de réduction ne retire jamais en silence un test du sensible — la liste des
titres disparus entre la base et la tête est mécanique, bloc englobant compris, et tout titre du
sensible disparu fait sortir l'outil en 1 ; les lignes de produit qui perdent leur couverture
sont nommées fichier par fichier (plan CI 2026-11-15, § 5.7 et § 5.2 ; contrats de la PR 2, § 12).

Lancé tel quel : `python3 -I scripts/tests-retires-test.py` (stdlib seule, aucun réseau, aucun
Docker). Le script est joué par sa CLI, sur des fichiers temporaires.

Contrat lu ici (plan § 5.7, § 12) :
- `--titres <avant> <après>` : un titre par ligne, tel que le rend son lanceur (`npx vitest list`,
  `vendor/bin/phpunit --list-tests`, `npx playwright test --list`, lignes « Scénario » des
  features) ; imprime les titres disparus ; code 1 si l'un d'eux est du sensible, 0 sinon ;
- sensible : l'un des mots `membre`, `member`, `gestionnaire`, `consultation`, `lecture seule`,
  `read-only`, `session`, `parité`, `parity`, `isolation`, `tenant`, `autre club`, `abus`,
  `abuse`, `403` (casse ignorée), tout test de `backend/tests/Security/`, tout test du moteur ;
- `--couverture <avant> <après>` (lcov ou clover) : les lignes couvertes avant et plus après,
  par fichier.

Précision ajoutée par ce test (écrite dans le brief de `neo`) : la position `fichier:ligne:colonne`
que `playwright --list` imprime est ignorée — sinon retirer un test décale tous les suivants, qui
paraîtraient disparus.

Ce que ce test NE prouve PAS : que les listes passées sont complètes (c'est le lanceur qui les
rend), ni le tableau « promesse retirée → ce qui la garde » que `tank` écrit à la main.
"""

from __future__ import annotations

import os
import re
import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "scripts" / "tests-retires.py"

SENSITIVE_WORDS = (
    "membre", "member", "gestionnaire", "consultation", "lecture seule", "read-only", "session",
    "parité", "parity", "isolation", "tenant", "autre club", "abus", "abuse", "403",
)  # fmt: skip

VITEST_BEFORE = """
src/features/planning/PlanningPage.test.tsx > PlanningPage > ouvre la version chargée
src/features/planning/PlanningPage.test.tsx > PlanningPage — Membre > ne voit aucun bouton d'édition
src/features/planning/PlanningPage.test.tsx > PlanningPage > affiche le titre de la période
src/lib/clock.test.ts > clock > rend la date simulée
"""


class RetiresCase(unittest.TestCase):
    def setUp(self):
        if not SCRIPT.is_file():
            self.fail(f"scripts/tests-retires.py introuvable : {SCRIPT}")
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def file(self, name, text):
        path = Path(self.tmp.name) / name
        path.write_text(textwrap.dedent(text).lstrip("\n"), encoding="utf-8")
        return str(path)

    def run_cli(self, *args):
        env = {"PATH": os.environ.get("PATH", ""), "LANG": "C.UTF-8", "PYTHONIOENCODING": "utf-8"}
        return subprocess.run([sys.executable, "-I", str(SCRIPT), *args], capture_output=True, text=True, env=env, cwd=self.tmp.name, timeout=60, check=False)

    def titres(self, before, after):
        return self.run_cli("--titres", self.file("avant.txt", before), self.file("apres.txt", after))


class SensitiveTitles(RetiresCase):
    def test_a_member_test_removed_from_a_screen_file_that_stays_is_seen(self):
        """Bloc englobant compris : le test « Membre » retiré d'un fichier d'écran qui reste rougit, et il est nommé."""
        after = "\n".join(line for line in VITEST_BEFORE.splitlines() if "Membre" not in line)
        done = self.titres(VITEST_BEFORE, after)

        self.assertEqual(1, done.returncode, done.stdout + done.stderr)
        self.assertIn("PlanningPage — Membre > ne voit aucun bouton d'édition", done.stdout)

    def test_each_sensitive_word_reddens_whatever_its_case(self):
        """Chaque mot de la liste du § 5.7, casse ignorée, dans le titre ou dans le bloc."""
        for word in SENSITIVE_WORDS:
            for shown in (word, word.upper()):
                with self.subTest(mot=shown):
                    before = f"src/a.test.tsx > Bloc {shown} > un test\nsrc/a.test.tsx > Bloc > un autre test\n"
                    done = self.titres(before, "src/a.test.tsx > Bloc > un autre test\n")
                    self.assertEqual(1, done.returncode, done.stdout + done.stderr)

    def test_a_security_test_reddens_without_any_sensitive_word(self):
        """Tout test de `backend/tests/Security/` est du sensible, quel que soit son titre."""
        before = " - App\\Tests\\Security\\JwtCookieFlagsTest::testCookieIsHttpOnly\n - App\\Tests\\Unit\\Service\\NamingTest::testTrims\n"
        done = self.titres(before, " - App\\Tests\\Unit\\Service\\NamingTest::testTrims\n")

        self.assertEqual(1, done.returncode, done.stdout + done.stderr)
        self.assertIn("JwtCookieFlagsTest::testCookieIsHttpOnly", done.stdout)

    def test_an_engine_test_reddens_without_any_sensitive_word(self):
        """Tout test du moteur est du sensible (identifiant pytest `fichier.py::test`)."""
        before = "tests/test_solver.py::test_two_teams_never_share_a_slot\ntests/test_solver.py::test_budget\n"
        done = self.titres(before, "tests/test_solver.py::test_budget\n")

        self.assertEqual(1, done.returncode, done.stdout + done.stderr)
        self.assertIn("test_two_teams_never_share_a_slot", done.stdout)


class OrdinaryTitles(RetiresCase):
    def test_a_removed_ordinary_title_is_listed_and_exits_zero(self):
        """Un titre ordinaire disparu : listé (c'est la preuve jointe à la PR), code 0."""
        after = "\n".join(line for line in VITEST_BEFORE.splitlines() if "affiche le titre" not in line)
        done = self.titres(VITEST_BEFORE, after)

        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        self.assertIn("affiche le titre de la période", done.stdout)
        self.assertNotIn("ouvre la version chargée", done.stdout)

    def test_a_sensitive_title_still_present_is_not_reported(self):
        """Seuls les titres DISPARUS comptent : un test Membre gardé, l'ordre changé, n'est rien."""
        after = "\n".join(reversed(VITEST_BEFORE.strip().splitlines()))
        done = self.titres(VITEST_BEFORE, after)

        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        self.assertNotIn("Membre", done.stdout)

    def test_behat_scenario_lines_are_compared_without_their_indentation(self):
        """Les lignes « Scénario » des features : l'indentation ne fait pas un titre nouveau."""
        before = "  Scénario: Un membre ne voit que le planning de son club\n  Scénario: La période se prolonge\n"
        after = "    Scénario: Un membre ne voit que le planning de son club\n"
        done = self.titres(before, after)

        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        self.assertIn("La période se prolonge", done.stdout)

    def test_a_playwright_position_shift_is_not_a_removal(self):
        """`playwright --list` imprime `fichier:ligne:col` : retirer un test décale les suivants, qui ne sont pas disparus pour autant."""
        before = textwrap.dedent("""
            [chromium] › planning.spec.ts:12:3 › Planning › affiche la grille
            [chromium] › planning.spec.ts:40:3 › Planning › un membre ne voit pas « Générer »
            [chromium] › planning.spec.ts:70:3 › Planning › un autre geste
        """)
        after = textwrap.dedent("""
            [chromium] › planning.spec.ts:12:3 › Planning › un membre ne voit pas « Générer »
            [chromium] › planning.spec.ts:42:3 › Planning › un autre geste
        """)
        done = self.titres(before, after)

        self.assertEqual(0, done.returncode, done.stdout + done.stderr)
        self.assertIn("affiche la grille", done.stdout)
        self.assertNotIn("un autre geste", done.stdout)


class LostCoverage(RetiresCase):
    def lost_lines(self, stdout, name):
        return [line for line in stdout.splitlines() if name in line]

    def test_lcov_lines_that_lose_their_coverage_are_named_by_file(self):
        """lcov : 115 et 116 couvertes avant, plus après — nommées sur la ligne de `clock.ts` ; 300, toujours couverte, ne l'est pas ; un fichier intact n'apparaît pas."""
        before = """
            SF:src/lib/clock.ts
            DA:115,3
            DA:116,3
            DA:300,1
            end_of_record
            SF:src/lib/dates.ts
            DA:10,2
            end_of_record
        """
        after = """
            SF:src/lib/clock.ts
            DA:115,0
            DA:116,0
            DA:300,4
            end_of_record
            SF:src/lib/dates.ts
            DA:10,2
            end_of_record
        """
        done = self.run_cli("--couverture", self.file("avant.lcov", before), self.file("apres.lcov", after))

        clock = self.lost_lines(done.stdout, "clock.ts")
        self.assertTrue(clock, done.stdout + done.stderr)
        self.assertTrue(any(re.search(r"\b115\b.*\b116\b", line) for line in clock), clock)
        self.assertFalse(any(re.search(r"\b300\b", line) for line in clock), clock)
        self.assertEqual([], self.lost_lines(done.stdout, "dates.ts"))

    def test_clover_lines_that_lose_their_coverage_are_named_by_file(self):
        """clover (PHPUnit et Vitest) : même preuve, fichier lu dans l'attribut `path`."""
        before = """
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage><project>
              <file name="Clock.php" path="/app/backend/src/Service/Clock.php">
                <line num="20" type="stmt" count="5"/>
                <line num="21" type="stmt" count="5"/>
                <line num="30" type="stmt" count="1"/>
              </file>
            </project></coverage>
        """
        after = """
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage><project>
              <file name="Clock.php" path="/app/backend/src/Service/Clock.php">
                <line num="20" type="stmt" count="0"/>
                <line num="21" type="stmt" count="0"/>
                <line num="30" type="stmt" count="2"/>
              </file>
            </project></coverage>
        """
        done = self.run_cli("--couverture", self.file("avant.xml", before), self.file("apres.xml", after))

        clock = self.lost_lines(done.stdout, "Clock.php")
        self.assertTrue(clock, done.stdout + done.stderr)
        self.assertTrue(any(re.search(r"\b20\b.*\b21\b", line) for line in clock), clock)
        self.assertFalse(any(re.search(r"\b30\b", line) for line in clock), clock)


if __name__ == "__main__":
    unittest.main(verbosity=1)
