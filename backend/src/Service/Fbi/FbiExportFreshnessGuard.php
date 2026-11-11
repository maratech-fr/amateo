<?php

declare(strict_types=1);

namespace App\Service\Fbi;

use App\Entity\Club;
use App\Exception\ImportRejectedException;
use App\Service\ClubDay;
use DateTimeImmutable;
use Exception;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use ZipArchive;

/**
 * Un export FBI de rencontres n'est déposé que s'il est FRAIS : le fichier fait foi sur les
 * matchs déjà dans FBI, un vieux fichier réécrirait donc des valeurs que FBI a changées depuis.
 *
 * Ce qu'on lit : `dcterms:created` de `docProps/core.xml`, lu brut dans le zip — FBI l'écrit à
 * l'export, Excel le conserve à l'enregistrement. Jamais la date des entrées zip (une copie les
 * rafraîchit), jamais PhpSpreadsheet (il rend « maintenant » pour une date absente). La taille
 * de la part se lit sur l'index du zip AVANT toute lecture : une part gonflée n'est jamais
 * chargée. Extraction par expression régulière, aucun parseur XML.
 *
 * L'âge se mesure sur l'horloge RÉELLE (un club de démo à date simulée dépose des fichiers
 * réels) ; la date du refus se dit à l'heure du club. Un fichier daté dans le futur passe :
 * garde-fou contre l'erreur, pas contre la fraude.
 */
final readonly class FbiExportFreshnessGuard
{
    public const int MAX_AGE_SECONDS = 86400;

    /** Marge entre l'analyse et l'import : les alias appariés ne s'écrivent qu'à l'import. */
    public const int IMPORT_GRACE_SECONDS = 900;

    public const int CORE_PART_MAX_BYTES = 65536;

    private const string CORE_PART = 'docProps/core.xml';

    public function __construct(
        #[Autowire(service: 'app.clock.real')]
        private ClockInterface $realClock,
        private ClubDay $clubDay,
    ) {}

    /** L'instant d'export lu dans le fichier, ou null s'il n'en porte pas de lisible. */
    public function exportedAt(string $path): ?DateTimeImmutable
    {
        $zip = new ZipArchive;
        if (true !== $zip->open($path, ZipArchive::RDONLY)) {
            return null;
        }

        try {
            $stat = $zip->statName(self::CORE_PART);
            if (false === $stat || $stat['size'] > self::CORE_PART_MAX_BYTES) {
                return null;
            }
            $xml = $zip->getFromName(self::CORE_PART);
        } finally {
            $zip->close();
        }

        if (!\is_string($xml)
            || 1 !== preg_match('#<dcterms:created\b[^>]*>\s*(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))\s*</dcterms:created>#', $xml, $m)) {
            return null;
        }

        try {
            return new DateTimeImmutable($m[1]);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Refuse (422) un fichier sans date d'export ou exporté depuis plus de 24 heures
     * (+ `$graceSeconds`) ; rend l'instant lu.
     */
    public function assertFresh(string $path, Club $club, int $graceSeconds = 0): DateTimeImmutable
    {
        $exportedAt = $this->exportedAt($path);
        if (!$exportedAt instanceof DateTimeImmutable) {
            throw ImportRejectedException::unprocessable('Ce fichier ne porte pas la date d\'export de FBI. Déposez le fichier téléchargé depuis FBI, pas une copie refaite.');
        }

        $age = $this->realClock->now()->getTimestamp() - $exportedAt->getTimestamp();
        if ($age > self::MAX_AGE_SECONDS + $graceSeconds) {
            $local = $exportedAt->setTimezone($this->clubDay->timezoneFor($club));

            throw ImportRejectedException::unprocessable(\sprintf('Ce fichier a été exporté de FBI le %s à %s, il y a plus de 24 heures. Téléchargez un nouvel export depuis FBI et déposez-le.', $local->format('d/m/Y'), $local->format('H:i')));
        }

        return $exportedAt;
    }
}
