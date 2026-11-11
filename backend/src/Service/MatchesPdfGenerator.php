<?php

declare(strict_types=1);

namespace App\Service;

use App\Export\MatchesExportData;
use App\Export\MatchesExportDaySection;
use App\Export\MatchesExportPlace;
use App\Export\MatchesExportRow;
use App\Export\MatchesExportTeamSection;
use App\Support\FrenchDate;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * La liste des matchs pour les parents, en PDF paysage, par équipe ou par jour. Coupures de page :
 * une page par équipe qui joue (les équipes sans match sur la période partagent un bloc final), ou
 * une page par semaine ISO ; une ligne n'est jamais coupée. Le worker écrit un fichier au nom
 * aléatoire, on en rend les octets et on le supprime : ce document n'a aucune adresse à deviner
 * et ne survit pas à la réponse (un orphelin de crash part à la purge sous 1 h).
 */
final readonly class MatchesPdfGenerator
{
    private const string NOTHING = 'Aucun match sur cette période.';

    private const string NOTHING_AT_HOME = 'Aucun match à domicile sur cette période.';

    public function __construct(
        private PdfGenerator $pdfGenerator,
    ) {}

    /** @return string les octets du PDF */
    public function generate(MatchesExportData $data): string
    {
        $filename = \sprintf('matchs-%s-%s.pdf', $data->clubId, Uuid::v4()->toRfc4122());
        $this->pdfGenerator->renderDocument($this->buildHtml($data), $filename, true, true);

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
        $title = ($data->homeOnly ? 'Matchs à domicile ' : 'Matchs ') . FrenchDate::range($data->from, $data->to);
        if (null !== $data->teamScopeName) {
            $title .= ' — ' . $data->teamScopeName;
        }
        $nothing = $data->homeOnly ? self::NOTHING_AT_HOME : self::NOTHING;

        $logo = null === $data->logoDataUri ? '' : \sprintf('<img class="logo" src="%s" alt="">', $this->escape($data->logoDataUri));
        $legend = $data->hasTravel ? '<p class="legend">Temps de trajet estimé en voiture depuis le siège du club.</p>' : '';

        $body = 'day' === $data->groupBy ? $this->dayPages($data) : $this->teamPages($data, $nothing);
        if ('' === $body) {
            $body = \sprintf('<p class="none">%s</p>', $nothing);
        }

        return \sprintf(
            '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>%1$s</title><style>
                * { box-sizing: border-box; }
                body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 10px; color: #111; font-size: 13.5px; line-height: 1.4; }
                header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; border-bottom: 2px solid #111; padding-bottom: 8px; }
                header .logo { height: 40px; width: 40px; object-fit: contain; }
                header h1 { font-size: 16px; margin: 0; }
                header .meta { font-size: 12px; color: #444; margin-top: 2px; }
                .legend { font-size: 12px; color: #444; margin: 0 0 12px; }
                h2 { font-size: 15px; margin: 16px 0 6px; break-after: avoid; }
                table { width: 100%%; border-collapse: collapse; table-layout: fixed; }
                col.date { width: 14%%; }
                col.time { width: 13%%; }
                col.opponent { width: 21%%; }
                col.where { width: 11%%; }
                col.place { width: 41%%; }
                col.team { width: 18%%; }
                col.venue { width: 37%%; }
                .page + .page { break-before: page; }
                thead { display: table-header-group; }
                tr { break-inside: avoid; }
                th, td { border: 1px solid #bbb; padding: 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
                th { background: #f0f0f0; }
                td.date { white-space: nowrap; }
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

    /**
     * Une page par équipe qui joue ; les équipes sans match sur la période n'ont pas de page à
     * elles : elles suivent, ensemble, dans un bloc final.
     */
    private function teamPages(MatchesExportData $data, string $nothing): string
    {
        // Une seule équipe : le titre du document la nomme déjà.
        $withHeading = null === $data->teamScopeName;
        $pages = '';
        $withoutMatch = '';
        foreach ($data->sections as $section) {
            if ([] === $section->rows) {
                $withoutMatch .= $this->section($section, $withHeading, $nothing);
            } else {
                $pages .= $this->page($this->section($section, $withHeading, $nothing));
            }
        }

        return $pages . ('' === $withoutMatch ? '' : $this->page($withoutMatch));
    }

    /** Une page par semaine ISO ; dans la semaine, un en-tête daté puis la table de chaque jour. */
    private function dayPages(MatchesExportData $data): string
    {
        $pages = '';
        foreach ($data->weeks as $week) {
            $days = '';
            foreach ($week->days as $day) {
                $days .= $this->day($day);
            }
            $pages .= $this->page($days);
        }

        return $pages;
    }

    /** Un bloc imprimé : tout bloc sauf le premier commence une page (`.page + .page`). */
    private function page(string $content): string
    {
        return '<section class="page">' . $content . '</section>';
    }

    private function day(MatchesExportDaySection $day): string
    {
        $rows = '';
        foreach ($day->rows as $row) {
            $rows .= \sprintf(
                '<tr><td class="time">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->escape($this->timeCell($row)),
                $this->escape($row->teamName),
                $this->escape($row->opponent . ($row->friendly ? ' (amical)' : '')),
                $this->escape($this->placeCell($row->place)),
                $this->escape($this->whereLabel($row->where)),
            );
        }

        return \sprintf('<h2>%s</h2>', $this->escape(ucfirst(FrenchDate::longDay($day->date))))
            . '<table><colgroup><col class="time"><col class="team"><col class="opponent"><col class="venue"><col class="where"></colgroup><thead><tr><th>Heure</th><th>Équipe</th><th>Adversaire</th><th>Gymnase</th><th>Où</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function section(MatchesExportTeamSection $section, bool $withHeading, string $nothing): string
    {
        $heading = $withHeading ? \sprintf('<h2>%s</h2>', $this->escape($section->teamName)) : '';
        if ([] === $section->rows) {
            return $heading . \sprintf('<p class="none">%s</p>', $nothing);
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

        return $heading . '<table><colgroup><col class="date"><col class="time"><col class="opponent"><col class="where"><col class="place"></colgroup><thead><tr><th>Date</th><th>Heure</th><th>Adversaire</th><th>Où</th><th>Lieu</th></tr></thead><tbody>' . $rows . '</tbody></table>';
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
