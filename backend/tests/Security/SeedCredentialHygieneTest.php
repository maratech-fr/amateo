<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * AUD-SEC-29 — CANARI de credentials de seed. Le dépôt est PUBLIC : les anciens mots de passe
 * dev du seed BCCL (le gestionnaire principal et le co-gestionnaire retiré) vivent déjà, en clair,
 * dans l'HISTORIQUE git public — on ne réécrit pas l'historique, mais ils ne doivent JAMAIS
 * revenir dans un fichier SUIVI. Comme {@see EnvHygieneTest}, c'est une garde statique.
 *
 * Plutôt que de recopier ces valeurs en clair (ce qui les réintroduirait dans un fichier suivi —
 * le mal que la garde combat), on épingle leur EMPREINTE sha256. La garde tokenise chaque fichier
 * suivi et compare l'empreinte de chaque jeton alphanumérique au jeu interdit : un fichier qui
 * reporterait l'une des valeurs rougit en nommant le fichier, jamais la valeur.
 */
#[Group('phase1')]
final class SeedCredentialHygieneTest extends TestCase
{
    /**
     * sha256 des anciens mots de passe dev du seed BCCL (gestionnaire principal : 11 caractères ;
     * co-gestionnaire retiré : 8 caractères). Les VALEURS ne sont volontairement nulle part ici.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_CREDENTIAL_HASHES = [
        '798cc8ddbd73b81a398d775e3a7acfcda0d0c7e790a1272fcdf8d9167f684cd0',
        'ec923b105f12f057b99e7c4671bb3ce6695a19e998b31d0bd249940ac9639b06',
    ];

    /** Longueur min/max d'un jeton candidat : couvre les deux valeurs épinglées, borne le travail. */
    private const int MIN_TOKEN_LENGTH = 6;

    private const int MAX_TOKEN_LENGTH = 40;

    /** Fichiers volumineux / binaires : rien d'humain à y lire, on les saute (travail borné). */
    private const int MAX_FILE_BYTES = 3_000_000;

    private const array SKIPPED_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg', 'pdf',
        'woff', 'woff2', 'ttf', 'eot', 'otf', 'zip', 'gz', 'tgz', 'bz2',
        'mp4', 'webm', 'mov', 'mp3', 'wav', 'avif',
    ];

    public function testNoTrackedFileCarriesAForbiddenSeedCredential(): void
    {
        $forbidden = array_fill_keys(self::FORBIDDEN_CREDENTIAL_HASHES, true);

        $offenders = [];
        foreach ($this->trackedFiles() as $relative => $absolute) {
            if (filesize($absolute) > self::MAX_FILE_BYTES) {
                continue;
            }
            $content = file_get_contents($absolute);
            if (false === $content || '' === $content) {
                continue;
            }

            $matches = [];
            preg_match_all('/[A-Za-z0-9]{' . self::MIN_TOKEN_LENGTH . ',' . self::MAX_TOKEN_LENGTH . '}/', $content, $matches);
            foreach ($matches[0] as $token) {
                if (isset($forbidden[hash('sha256', $token)])) {
                    $offenders[] = $relative;
                    break;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Un fichier suivi reporte un ancien mot de passe de seed BCCL (empreinte interdite). Retirez-le — la vraie valeur vit hors dépôt, dans le fichier local d\'identités.',
        );
    }

    /**
     * Les fichiers suivis du dépôt (git ls-files, depuis la racine), hors extensions binaires.
     *
     * @return array<string, string> chemin relatif au dépôt → chemin absolu
     */
    private function trackedFiles(): array
    {
        $repoRoot = realpath(__DIR__ . '/../../..');
        self::assertNotFalse($repoRoot, 'racine du dépôt introuvable');

        $out = [];
        exec('git -C ' . escapeshellarg($repoRoot) . ' ls-files 2>/dev/null', $out, $code);
        self::assertSame(0, $code, 'git ls-files doit énumérer les fichiers suivis (le canari ne vaut rien à vide)');
        self::assertNotEmpty($out, 'git ls-files n\'a renvoyé aucun fichier suivi');

        $files = [];
        foreach ($out as $relative) {
            $extension = strtolower(pathinfo($relative, \PATHINFO_EXTENSION));
            if (\in_array($extension, self::SKIPPED_EXTENSIONS, true)) {
                continue;
            }
            $absolute = $repoRoot . '/' . $relative;
            if (is_file($absolute)) {
                $files[$relative] = $absolute;
            }
        }

        return $files;
    }
}
