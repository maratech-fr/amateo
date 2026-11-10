<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Tout guichet qui met une génération en file marque l'activité du club : un fichier de `src/`
 * qui écrit `new GenerateScheduleMessage` appelle aussi `markGeneration(` (service
 * `ClubActivityTrace`, horloge réelle, dans la transaction du guichet). Un cinquième guichet
 * demain ne peut pas oublier la trace que lit la colonne « Dernière activité » de la console.
 *
 * Patron : `ClubDayIsNotRebuiltTest` (garde statique sur `src/`). La preuve COMPORTEMENTALE
 * des trois guichets d'aujourd'hui (Générer, Régénérer, Combler) est
 * `Security/ClubActivityTraceTest` (k1-k3) ; cette garde n'en est que le filet pour le suivant.
 */
#[Group('phase1')]
final class GenerationMarksClubActivityTest extends TestCase
{
    /** Témoin positif : un balayage qui ne trouverait plus aucun guichet ne prouverait rien. */
    private const int KNOWN_DISPATCHERS_AT_LEAST = 3;

    public function testEveryGenerationDispatcherMarksTheClubActivity(): void
    {
        $dispatchers = [];
        $offenders = [];
        $root = realpath(__DIR__ . '/../../../src');
        self::assertIsString($root);

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $source = (string) file_get_contents((string) $file->getRealPath());
            if (!str_contains($source, 'new GenerateScheduleMessage')) {
                continue;
            }
            $relative = substr((string) $file->getRealPath(), \strlen($root) + 1);
            $dispatchers[] = $relative;
            if (!str_contains($source, 'markGeneration(')) {
                $offenders[] = $relative;
            }
        }

        self::assertGreaterThanOrEqual(self::KNOWN_DISPATCHERS_AT_LEAST, \count($dispatchers), \sprintf(
            'Le balayage ne trouve que %d guichet(s) qui écrivent `new GenerateScheduleMessage` (attendu ≥ %d) : le motif ne voit plus rien, la garde ne prouve plus rien.',
            \count($dispatchers),
            self::KNOWN_DISPATCHERS_AT_LEAST,
        ));

        sort($offenders);
        self::assertSame([], $offenders, \sprintf(
            "Ces guichets mettent une génération en file sans marquer l'activité du club :\n  - %s\n\n"
            . "Appeler ClubActivityTrace::markGeneration(\$clubId) dans la transaction du guichet,\n"
            . 'après ses gardes — sinon la console dit « Jamais » d\'un club qui génère.',
            implode("\n  - ", $offenders),
        ));
    }
}
