<?php

declare(strict_types=1);

namespace App\Service;

use App\Export\MatchesExportData;
use App\Export\MatchesExportPlace;
use App\Export\MatchesExportRow;
use App\Export\MatchesExportTeamSection;
use App\Support\FrenchDate;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * La liste des matchs pour les parents, en PDF portrait. Le worker écrit un fichier au nom
 * aléatoire, on en rend les octets et on le supprime : ce document n'a aucune adresse à deviner
 * et ne survit pas à la réponse (un orphelin de crash part à la purge sous 1 h).
 */
final readonly class MatchesPdfGenerator
{
    private const string NOTHING = 'Aucun match sur cette période.';

    public function __construct(
        private PdfGenerator $pdfGenerator,
    ) {}

    /** @return string les octets du PDF */
    public function generate(MatchesExportData $data): string
    {
        $filename = \sprintf('matchs-%s-%s.pdf', $data->clubId, Uuid::v4()->toRfc4122());
        $this->pdfGenerator->renderDocument($this->buildHtml($data), $filename, false, true);

        $path = PdfGenerator::outputDir() . '/' . $filename;
        $bytes = @file_get_contents($path);
        // `$filename` est composé ici (deux uuid) : aucun séparateur ne peut y entrer.
        @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
        if (false === $bytes) {
            throw new RuntimeException('Le fichier du worker est illisible.');
        }

        return $bytes;
    }

    public function buildHtml(MatchesExportData $data): string
    {
        $title = 'Matchs ' . FrenchDate::range($data->from, $data->to);
        if (null !== $data->teamScopeName) {
            $title .= ' — ' . $data->teamScopeName;
        }

        $logo = null === $data->logoDataUri ? '' : \sprintf('<img class="logo" src="%s" alt="">', $this->escape($data->logoDataUri));
        $legend = $data->hasTravel ? '<p class="legend">Temps de trajet estimé en voiture depuis le siège du club.</p>' : '';

        $body = '';
        foreach ($data->sections as $section) {
            // Une seule équipe : le titre du document la nomme déjà.
            $body .= $this->section($section, null === $data->teamScopeName);
        }
        if ([] === $data->sections) {
            $body = \sprintf('<p class="none">%s</p>', self::NOTHING);
        }

        return \sprintf(
            '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>%1$s</title><style>
                * { box-sizing: border-box; }
                body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 10px; color: #111; font-size: 11px; }
                header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; border-bottom: 2px solid #111; padding-bottom: 8px; }
                header .logo { height: 40px; width: 40px; object-fit: contain; }
                header h1 { font-size: 16px; margin: 0; }
                header .meta { font-size: 11px; color: #444; margin-top: 2px; }
                .legend { font-size: 10px; color: #444; margin: 0 0 12px; }
                h2 { font-size: 13px; margin: 16px 0 6px; break-after: avoid; }
                table { width: 100%%; border-collapse: collapse; }
                thead { display: table-header-group; }
                tr { break-inside: avoid; }
                th, td { border: 1px solid #bbb; padding: 4px 5px; text-align: left; vertical-align: top; }
                th { background: #f0f0f0; }
                td.date, td.time { white-space: nowrap; }
                .none { color: #444; font-style: italic; margin: 4px 0 0; }
            </style></head><body><header>%2$s<div><h1>%1$s</h1><div class="meta">%3$s · Édité le %4$s</div></div></header>%5$s%6$s</body></html>',
            $this->escape($title),
            $logo,
            $this->escape($data->clubName),
            $this->escape(FrenchDate::long($data->editedOn)),
            $legend,
            $body,
        );
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function section(MatchesExportTeamSection $section, bool $withHeading): string
    {
        $heading = $withHeading ? \sprintf('<h2>%s</h2>', $this->escape($section->teamName)) : '';
        if ([] === $section->rows) {
            return $heading . \sprintf('<p class="none">%s</p>', self::NOTHING);
        }

        $rows = '';
        foreach ($section->rows as $row) {
            $rows .= \sprintf(
                '<tr><td class="date">%s</td><td class="time">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->escape(FrenchDate::shortDay($row->date)),
                $this->escape($this->timeCell($row)),
                $this->escape($row->opponent . ($row->friendly ? ' (amical)' : '')),
                $this->escape($this->whereLabel($row->where)),
                $this->escape($this->placeCell($row->place)),
            );
        }

        return $heading . '<table><thead><tr><th>Date</th><th>Heure</th><th>Adversaire</th><th>Où</th><th>Lieu</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function timeCell(MatchesExportRow $row): string
    {
        if (!$row->toConfirm) {
            return $row->kickoff ?? '';
        }

        return null === $row->kickoff ? 'à confirmer' : $row->kickoff . ' · à confirmer';
    }

    private function whereLabel(string $where): string
    {
        return match ($where) {
            'away' => 'Extérieur',
            'neutral' => 'Terrain neutre',
            default => 'Domicile',
        };
    }

    private function placeCell(?MatchesExportPlace $place): string
    {
        if (!$place instanceof MatchesExportPlace) {
            return '—';
        }
        $cell = null === $place->address ? $place->name : $place->name . ' — ' . $place->address;

        return null === $place->travelMinutes ? $cell : \sprintf('%s · %d min en voiture', $cell, $place->travelMinutes);
    }
}
