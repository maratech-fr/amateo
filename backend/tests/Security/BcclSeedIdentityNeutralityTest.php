<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Seed\BcclSeedIdentities;
use App\Seed\BcclSeedProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le dépôt est PUBLIC : pour le club pilote (seed BCCL), il ne porte que des libellés de coach
 * NEUTRES — « Coach » suivi du numéro de la clé interne du seeder (c07 → « Coach 07 »). Les vraies
 * identités vivent hors dépôt, dans le fichier local gitignoré lu par {@see BcclSeedIdentities}.
 *
 * Garde STATIQUE par LISTE AUTORISÉE (aucun dictionnaire de noms interdits : il réintroduirait au
 * dépôt ce qu'elle combat). RÈGLE ABSOLUE : aucune assertion n'affiche une valeur lue — chaque
 * méthode collecte des emplacements `fichier:ligne` et exige une liste vide ; un rouge nomme
 * l'endroit, jamais le nom.
 *
 * Ce que cette garde NE voit PAS (tenu par le script local du fondateur, la revue et le critère de
 * la PR de balayage) : un prénom sans le mot « Coach », un libellé en minuscules, un fichier hors
 * de la liste nominative, des identités injectées par le fichier local (voulu), les images, les
 * snapshots, l'historique git.
 *
 * Falsifiée dans les deux sens : un surnom à majuscule à la place d'un « Coach NN » du seeder ou
 * d'un fichier listé rougit en nommant `fichier:ligne` ; un libellé numéroté hors plage du seed
 * (« Coach » + 31) reste vert dans un fichier listé ; 29 entrées dans le seeder rougissent.
 */
#[Group('phase1')]
final class BcclSeedIdentityNeutralityTest extends TestCase
{
    private const string SEEDER = 'backend/src/Seed/BcclSeeder.php';

    private const int EXPECTED_COACHES = 30;

    /** Fixtures moteur qui transcrivent le club pilote : leurs `firstName` sont des libellés neutres. */
    private const array ENGINE_FIXTURES = [
        'engine/tests/fixtures/bccl_2026_08_15.json',
        'engine/tests/fixtures/overlap_shared_coach.json',
    ];

    /**
     * Fichiers liés au seed BCCL qui ne doivent porter aucun « Coach <Nom> » littéral. Liste
     * NOMINATIVE : un fichier renommé ou supprimé rougit (une garde qui ne lit rien ne garde rien).
     */
    private const array BCCL_BOUND_FILES = [
        self::SEEDER,
        'backend/tests/Integration/Seed/BcclSeederIdempotenceTest.php',
        'backend/tests/Behat/ConflictTruthContext.php',
        'backend/tests/Unit/Service/MatchConflictDetectorTest.php',
        'frontend/tests/e2e/matches.spec.ts',
        'engine/tests/fixtures/bccl_2026_08_15.json',
        'engine/tests/fixtures/overlap_shared_coach.json',
        'docs/archive/plannings-bccl-2026-08-31.md',
    ];

    /** « Coach » suivi d'un mot à majuscule qui n'est PAS un numéro à deux chiffres. */
    private const string NAMED_COACH_LITERAL = '/\bCoach (?!\d{2}\b)\p{Lu}\p{L}+/u';

    /**
     * Le seeder définit exactement 30 coachs, 30 clés distinctes, chacun libellé par sa clé
     * (`firstName` = « Coach » + numéro de clé, `lastName` vide).
     */
    public function testSeederDefinesExactlyThirtyCoachesLabelledByTheirKey(): void
    {
        $lines = $this->readLines(self::SEEDER);

        $start = null;
        foreach ($lines as $index => $line) {
            if (1 === preg_match('/^\s*\$newCoachesData\s*=\s*\[\s*$/', $line)) {
                $start = $index;

                break;
            }
        }
        self::assertNotNull($start, 'bloc $newCoachesData introuvable dans ' . self::SEEDER . ' (renommé ? la garde ne lit plus rien)');

        $entryPattern = "/\\[\\s*'key'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'firstName'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'lastName'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\]/";

        $keys = [];
        $offenders = [];
        $closed = false;
        for ($index = $start + 1, $count = \count($lines); $index < $count; ++$index) {
            $line = $lines[$index];
            if (1 === preg_match('/^\s*\];\s*$/', $line)) {
                $closed = true;

                break;
            }
            if (1 !== preg_match('/[\'"]key[\'"]\s*=>/', $line)) {
                continue;
            }
            $location = self::SEEDER . ':' . ($index + 1);
            if (1 !== preg_match($entryPattern, $line, $m)) {
                // Entrée d'une forme non reconnue : on ne sait pas la lire, donc on ne la garde pas.
                $offenders[] = $location;

                continue;
            }
            $key = self::unquote($m[1]);
            $keys[] = $key;
            $expected = 1 === preg_match('/^c(\d{2})$/', $key, $k) ? 'Coach ' . $k[1] : null;
            if (null === $expected || self::unquote($m[2]) !== $expected || '' !== self::unquote($m[3])) {
                $offenders[] = $location;
            }
        }
        self::assertTrue($closed, 'bloc $newCoachesData non refermé dans ' . self::SEEDER);

        self::assertSame(
            self::EXPECTED_COACHES,
            \count($keys),
            \sprintf('%d entrées attendues dans $newCoachesData, %d lues', self::EXPECTED_COACHES, \count($keys)),
        );
        self::assertSame(
            self::EXPECTED_COACHES,
            \count(array_unique($keys)),
            \sprintf('%d clés distinctes attendues dans $newCoachesData', self::EXPECTED_COACHES),
        );
        self::assertSame([], $offenders, 'entrée de coach hors libellé neutre (« Coach » + numéro de sa clé, nom vide) — emplacements seuls, jamais la valeur');
    }

    /**
     * Les fixtures moteur du club pilote ne portent que des `firstName` neutres (« Coach » + deux
     * chiffres) — et en portent au moins un chacune (une fixture vidée ne garde rien).
     */
    public function testEngineFixturesCarryOnlyNeutralCoachLabels(): void
    {
        $offenders = [];
        foreach (self::ENGINE_FIXTURES as $relative) {
            $read = 0;
            foreach ($this->readLines($relative) as $index => $line) {
                if (0 === preg_match_all('/"firstName"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/', $line, $matches)) {
                    continue;
                }
                foreach ($matches[1] as $literal) {
                    ++$read;
                    $value = json_decode($literal, false, 2, \JSON_THROW_ON_ERROR);
                    if (!\is_string($value) || 1 !== preg_match('/^Coach \d{2}$/u', $value)) {
                        $offenders[] = $relative . ':' . ($index + 1);
                    }
                }
            }
            if (0 === $read) {
                $offenders[] = $relative . ': aucun firstName lu (la garde ne lit plus rien)';
            }
        }

        self::assertSame([], $offenders, 'firstName hors libellé neutre (« Coach » + deux chiffres) dans une fixture moteur du club pilote — emplacements seuls, jamais la valeur');
    }

    /**
     * Les fichiers liés au seed BCCL (liste nominative, chacun DOIT exister) ne portent aucun
     * « Coach <Nom> » littéral.
     */
    public function testBcclBoundFilesCarryNoNamedCoachLiteral(): void
    {
        $offenders = [];
        foreach (self::BCCL_BOUND_FILES as $relative) {
            if (!is_file($this->repoRoot() . '/' . $relative)) {
                $offenders[] = $relative . ': fichier absent (renommé ? mettre la liste nominative à jour)';

                continue;
            }
            foreach ($this->readLines($relative) as $index => $line) {
                if (1 === preg_match(self::NAMED_COACH_LITERAL, $line)) {
                    $offenders[] = $relative . ':' . ($index + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'libellé « Coach <Nom> » dans un fichier lié au seed BCCL — utiliser « Coach » + numéro de clé ; emplacements seuls, jamais la valeur');
    }

    /**
     * Sans fichier d'identités local (CI, autre dev, dépôt public), le profil dev n'injecte rien :
     * ni noms de coachs, ni co-gestionnaires.
     */
    public function testDevProfileWithoutLocalFileInjectsNothing(): void
    {
        $missing = BcclSeedIdentities::defaultPath(sys_get_temp_dir() . '/bccl-neutrality-' . bin2hex(random_bytes(6)));
        self::assertFalse(is_file($missing), 'le chemin de test du fichier d\'identités doit être absent');

        $profiles = [
            'dev() sans argument' => BcclSeedProfile::dev(),
            'dev() avec le fichier local absent' => BcclSeedProfile::dev(BcclSeedIdentities::loadFromFile($missing)),
        ];
        foreach ($profiles as $label => $profile) {
            // assertTrue sur une comparaison : un rouge ne doit jamais afficher les noms injectés.
            self::assertTrue(null === $profile->coachNames, $label . ' : aucun nom de coach injecté');
            self::assertTrue([] === $profile->additionalManagers, $label . ' : aucun co-gestionnaire injecté');
        }
    }

    /**
     * @return list<string>
     */
    private function readLines(string $relative): array
    {
        $path = $this->repoRoot() . '/' . $relative;
        self::assertFileExists($path, $relative . ' introuvable (renommé ? la garde ne lit plus rien)');
        $content = file_get_contents($path);
        self::assertNotFalse($content, $relative . ' illisible');

        return explode("\n", $content);
    }

    private function repoRoot(): string
    {
        $root = realpath(__DIR__ . '/../../..');
        self::assertNotFalse($root, 'racine du dépôt introuvable');

        return $root;
    }

    /** Déséchappe une chaîne PHP entre apostrophes (seuls \' et \\ y sont des échappements). */
    private static function unquote(string $raw): string
    {
        return strtr($raw, ['\\\\' => '\\', "\\'" => "'"]);
    }
}
