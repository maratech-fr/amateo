<?php

declare(strict_types=1);

namespace App\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * Fabrique des exports FBI DATÉS pour les tests de fraîcheur : un export réel porte
 * `dcterms:created` dans `docProps/core.xml` (instant UTC, forme `2026-11-10T09:38:31Z`
 * qu'écrit FBI), et c'est cette date — jamais la date des entrées zip — que la garde
 * de fraîcheur lit.
 *
 *  - {@see fbiExport} : un classeur au format réel (en-têtes FBI), daté de `$exportedAt`
 *    (null = à l'instant réel) ;
 *  - {@see withExportedAt} : une COPIE dont `core.xml` est réécrit à `$at`, ou RETIRÉ
 *    (part, relation et type) quand `$at` est null — le « classeur refait sans docProps » ;
 *  - {@see withCorePartOfSize} : une COPIE dont `core.xml` porte une date fraîche mais
 *    pèse exactement `$bytes` octets décompressés (la borne de lecture de la garde).
 *
 * Les fichiers créés sont listés dans {@see $fbiExportFiles} ; la classe hôte appelle
 * {@see removeFbiExports} dans son tearDown.
 */
trait WritesFbiExports
{
    /** @var list<string> */
    private array $fbiExportFiles = [];

    /**
     * @param list<list<string>> $rows lignes au format réel : Division, N° de match, Equipe 1, Equipe 2, Date, Heure, Salle
     */
    private function fbiExport(array $rows, ?DateTimeImmutable $exportedAt = null): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'N° de match ', 'Equipe 1', 'Equipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );
        $path = $this->newFbiExportPath();
        new Xlsx($spreadsheet)->save($path);
        $this->rewriteCorePart($path, $this->coreXml($exportedAt ?? new DateTimeImmutable));

        return $path;
    }

    private function withExportedAt(string $sourcePath, ?DateTimeImmutable $at): string
    {
        $path = $this->copyFbiExport($sourcePath);
        if ($at instanceof DateTimeImmutable) {
            $this->rewriteCorePart($path, $this->coreXml($at));

            return $path;
        }

        $zip = $this->openFbiZip($path);
        $zip->deleteName('docProps/core.xml');
        $rels = $zip->getFromName('_rels/.rels');
        if (\is_string($rels)) {
            $zip->addFromString('_rels/.rels', (string) preg_replace('#<Relationship[^>]*Target="docProps/core\.xml"[^>]*/>#', '', $rels));
        }
        $types = $zip->getFromName('[Content_Types].xml');
        if (\is_string($types)) {
            $zip->addFromString('[Content_Types].xml', (string) preg_replace('#<Override[^>]*PartName="/docProps/core\.xml"[^>]*/>#', '', $types));
        }
        $zip->close();

        return $path;
    }

    private function withCorePartOfSize(string $sourcePath, int $bytes): string
    {
        $path = $this->copyFbiExport($sourcePath);
        $xml = $this->coreXml(new DateTimeImmutable);
        $padding = $bytes - \strlen($xml) - \strlen('<!---->');
        if ($padding < 0) {
            throw new RuntimeException(\sprintf('core.xml ne tient pas en %d octets', $bytes));
        }
        // Le commentaire de remplissage se glisse AVANT la balise fermante : la date
        // reste lisible, seul le poids de la part change.
        $padded = str_replace('</cp:coreProperties>', '<!--' . str_repeat('x', $padding) . '--></cp:coreProperties>', $xml);
        \assert(\strlen($padded) === $bytes);
        $this->rewriteCorePart($path, $padded);

        return $path;
    }

    private function removeFbiExports(): void
    {
        foreach ($this->fbiExportFiles as $file) {
            @unlink($file);
        }
        $this->fbiExportFiles = [];
    }

    /** La part `core.xml` telle que FBI l'écrit (Apache POI), `created` en UTC à la seconde. */
    private function coreXml(DateTimeImmutable $created): string
    {
        $utc = $created->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Apache POI</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $utc . '</dcterms:created>'
            . '</cp:coreProperties>';
    }

    private function rewriteCorePart(string $path, string $xml): void
    {
        $zip = $this->openFbiZip($path);
        $zip->addFromString('docProps/core.xml', $xml);
        $zip->close();
    }

    private function copyFbiExport(string $sourcePath): string
    {
        $path = $this->newFbiExportPath();
        if (!copy($sourcePath, $path)) {
            throw new RuntimeException('copie impossible : ' . $sourcePath);
        }

        return $path;
    }

    private function newFbiExportPath(): string
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'fbix');
        $path = $base . '.xlsx';
        $this->fbiExportFiles[] = $base;
        $this->fbiExportFiles[] = $path;

        return $path;
    }

    private function openFbiZip(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if (true !== $zip->open($path)) {
            throw new RuntimeException('zip illisible : ' . $path);
        }

        return $zip;
    }
}
