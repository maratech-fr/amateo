<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Export\ScheduleExportData;
use App\Export\ScheduleExportDataProvider;
use App\Support\FrenchNameOrder;
use DateInterval;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Flat, Excel-idiomatic export of a schedule: one row per training slot
 * (Jour, Début, Fin, Gymnase, Équipe, Catégorie, Coach), optionally scoped to a
 * single venue. Kept as a data table (not a visual grid) so a manager can sort
 * and filter it in Excel.
 */
class SpreadsheetGenerator
{
    /**
     * Les colonnes du tableau — foyer unique (audit D-18, 2026-08-09).
     *
     * ⚑ L'ordre des en-têtes était tenu ici, et celui des VALEURS par deux tuples positionnels
     * (placements, fenêtres vides) plus une plage `'A1:G1'` écrite en dur. Quatre listes à
     * garder d'accord, dont trois par la seule position.
     *
     * ⚠ **La divergence produit un fichier parfaitement valide.** Insérer une colonne ici
     * décale les valeurs d'un cran sans rien casser : Excel s'ouvre, les colonnes ont leurs
     * titres, et le gestionnaire lit des heures sous « Gymnase ». Aucune erreur, aucun test
     * rouge — un export faux qui se donne pour bon.
     *
     * Les lignes sont désormais construites PAR NOM et projetées ici : ajouter une colonne
     * rend une cellule vide (visible), plus jamais un décalage (invisible).
     */
    private const HEADERS = ['Jour', 'Début', 'Fin', 'Gymnase', 'Groupe', 'Équipe', 'Catégorie', 'Coach'];

    public function __construct(
        private readonly ScheduleExportDataProvider $exportData,
    ) {}

    /** @return string binary .xlsx content */
    public function generate(Schedule $schedule, ?string $venueId = null): string
    {
        return $this->render($this->exportData->load($schedule, $venueId), $venueId);
    }

    /**
     * Construit le classeur complet depuis les données déjà chargées — le seam testable :
     * `ScheduleExportDataProvider` est `final` (indoublable), la donnée, elle, se fabrique.
     *
     * @return string binary .xlsx content
     */
    public function render(ScheduleExportData $data, ?string $scopeVenueId = null): string
    {
        $venueName = static fn (string $id): string => $data->venues[$id]['name'] ?? '';

        // One sortable row per placement AND per empty window (team = "(vide)"), so
        // defined-but-unfilled windows appear in the table like the on-screen grid.
        /** @var list<array{day:int, start:string, venue:string, cells:array<string, string>}> $rows */
        $rows = [];
        foreach ($data->slots as $slot) {
            $start = $slot->getStartTime();
            $end = $start->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'));
            $rows[] = [
                'day' => $slot->getDayOfWeek(), 'start' => $start->format('H:i'), 'venue' => $venueName($slot->getVenueId()),
                'cells' => [
                    'Jour' => ScheduleExportData::DAY_LABELS[$slot->getDayOfWeek()] ?? '',
                    'Début' => $start->format('H:i'),
                    'Fin' => $end->format('H:i'),
                    'Gymnase' => $venueName($slot->getVenueId()),
                    // Libellé de groupe (« CEC3 ») du créneau, vide s'il n'en porte pas. Projeté PAR
                    // NOM comme le reste : une colonne qu'aucune ligne ne renseigne sort vide.
                    'Groupe' => $data->groupLabels[$slot->getVenueId() . '|' . $slot->getDayOfWeek() . '|' . $start->format('H:i')] ?? '',
                    'Équipe' => $data->teamNames[$slot->getTeamId()] ?? '',
                    'Catégorie' => $data->teamCategories[$slot->getTeamId()] ?? '',
                    'Coach' => null !== $slot->getCoachId() ? ($data->coachNames[$slot->getCoachId()] ?? '') : '',
                ],
            ];
        }
        foreach ($data->emptySlots as $window) {
            $end = $window->startTime->add(new DateInterval('PT' . $window->durationMinutes . 'M'));
            $rows[] = [
                'day' => $window->dayOfWeek, 'start' => $window->startTime->format('H:i'), 'venue' => $venueName($window->venueId),
                'cells' => [
                    'Jour' => ScheduleExportData::DAY_LABELS[$window->dayOfWeek] ?? '',
                    'Début' => $window->startTime->format('H:i'),
                    'Fin' => $end->format('H:i'),
                    'Gymnase' => $venueName($window->venueId),
                    'Équipe' => '(vide)',
                ],
            ];
        }
        // Lot 4bis — un CRÉNEAU LIBRE réservé : une ligne occupée dont la colonne « Équipe » porte
        // son libellé (ex. « Loto du club »), jamais « (vide) » — la place est prise.
        foreach ($data->freeSlots as $window) {
            $end = $window->startTime->add(new DateInterval('PT' . $window->durationMinutes . 'M'));
            $rows[] = [
                'day' => $window->dayOfWeek, 'start' => $window->startTime->format('H:i'), 'venue' => $venueName($window->venueId),
                'cells' => [
                    'Jour' => ScheduleExportData::DAY_LABELS[$window->dayOfWeek] ?? '',
                    'Début' => $window->startTime->format('H:i'),
                    'Fin' => $end->format('H:i'),
                    'Gymnase' => $venueName($window->venueId),
                    'Équipe' => $window->label,
                ],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['day'], $a['start'], $a['venue']] <=> [$b['day'], $b['start'], $b['venue']]);

        $spreadsheet = new Spreadsheet;
        // Injection de formule XLSX (revue sécurité) : tout le contenu de ce
        // classeur est de la DONNÉE club (équipes, coachs, gymnases, libellés de
        // groupe) — un « =HYPERLINK(...) » saisi comme libellé deviendrait une
        // formule vivante à l'ouverture dans Excel. Le binder force TOUTES les
        // cellules en chaînes : couvre la colonne nouvelle ET les préexistantes.
        $spreadsheet->setValueBinder(new StringValueBinder);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Planning');

        foreach (self::HEADERS as $i => $label) {
            $sheet->setCellValue([$i + 1, 1], $label);
        }
        $headerStyle = $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex(\count(self::HEADERS)) . '1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');

        $row = 2;
        foreach ($rows as $r) {
            // Projection PAR NOM : une colonne qu'aucune ligne ne renseigne sort vide, jamais
            // décalée. Une clé qui ne serait plus une colonne disparaîtrait en silence — c'est
            // ce que `SpreadsheetColumnsAreProjectedByNameTest` interdit.
            foreach (self::HEADERS as $i => $column) {
                $sheet->setCellValue([$i + 1, $row], $r['cells'][$column] ?? '');
            }
            ++$row;
        }

        foreach (range(1, \count(self::HEADERS)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A2');

        // Second sheet, added AFTER "Planning" is complete so that sheet is never touched.
        $this->appendTeamDayMatrix($spreadsheet, $data, $scopeVenueId);

        return $this->toBinary($spreadsheet);
    }

    /**
     * Second sheet — a team × day matrix answering "l'équipe U13 s'entraîne quand ?"
     * at a glance : rows = teams, columns = the days actually used, cell = venue + time.
     *
     * No coach on purpose : it already lives in the Planning sheet and here it would only
     * clutter the scan (founder decision).
     *
     * Empty windows (`$data->emptySlots`) belong to no team, so they NEVER enter the matrix ;
     * they stay in the Planning sheet as "(vide)" rows.
     *
     * **Toujours ajoutée** (décision fondateur 2026-08-21). Elle l'était auparavant seulement
     * quand les placements couvraient ≥ 2 gymnases, au motif qu'elle « lève l'ambiguïté sur le
     * gymnase » — mais c'est la justification d'un DÉCLENCHEUR, pas la raison d'être de la vue.
     * Les deux feuilles répondent à deux questions distinctes : Planning dit « qui occupe quel
     * gymnase ce jour-là », la matrice dit « quand s'entraîne CETTE équipe », une ligne à lire.
     * Un club mono-gymnase a le même besoin de donner à chaque équipe sa ligne.
     *
     * ⚠ **Ce seuil rendait un SECOND service, qui devait donc être repris ailleurs** : il
     * garantissait qu'un export limité à UN gymnase n'atteigne jamais cette feuille. D'où le
     * paramètre de portée ci-dessous — sans lui, un export scopé aurait listé toutes les
     * équipes de la saison, et celles qui s'entraînent AILLEURS seraient passées pour des
     * équipes sans entraînement, sur un document remis aux familles. Même règle et même raison
     * que `PdfGenerator::buildMatrixSection`, qui la porte depuis P3-20.
     *
     * ⚠ Same D-18 discipline as the Planning sheet : the model is indexed by (team, day) and
     * PROJECTED onto ordered rows/columns — never written positionally. A day column and its
     * cells are always looked up by the SAME day key, so no silent column-shift can happen.
     */
    private function appendTeamDayMatrix(Spreadsheet $spreadsheet, ScheduleExportData $data, ?string $scopeVenueId = null): void
    {
        $venueName = static fn (string $id): string => $data->venues[$id]['name'] ?? '';

        // Les LIGNES dépendent de la PORTÉE. Export « tous les gymnases » : TOUTES les équipes
        // de la saison — une équipe sans séance est un trou du planning, la première chose que
        // le gestionnaire doit voir, jamais une ligne masquée. Export limité à UN gymnase :
        // seules les équipes qui y ont une séance, sinon une équipe qui s'entraîne ailleurs
        // passerait pour une équipe sans entraînement.
        $placedTeamIds = [];
        foreach ($data->slots as $slot) {
            $placedTeamIds[$slot->getTeamId()] = true;
        }
        // P4-106 (décision fondateur 2026-08-18) : AUCUNE colonne « Rang ». Le rang est une
        // information de GESTION — un export part au gymnase et aux familles, la priorisation
        // interne des équipes n'a rien à y faire. L'ordre des lignes (catégorie puis nom) ne
        // dépend pas du rang, il n'y a donc rien à compenser.
        /** @var array<string, array{name: string, category: string}> $teams */
        $teams = [];
        foreach ($data->teamNames as $teamId => $name) {
            if (null !== $scopeVenueId && !isset($placedTeamIds[$teamId])) {
                continue;
            }
            $teams[$teamId] = ['name' => $name, 'category' => $data->teamCategories[$teamId] ?? ''];
        }

        // Model indexed by (team, day), built from the placements.
        /** @var array<string, array<int, list<array{start: string, text: string}>>> $matrix */
        $matrix = [];
        $daysUsed = [];
        foreach ($data->slots as $slot) {
            $day = $slot->getDayOfWeek();
            $start = $slot->getStartTime()->format('H:i');
            // Never drop a slot silently : a team training twice the same day keeps BOTH entries.
            $matrix[$slot->getTeamId()][$day][] = ['start' => $start, 'text' => trim($venueName($slot->getVenueId()) . ' · ' . $start)];
            $daysUsed[$day] = true;
        }
        // Defence : a placement whose team is absent from teamNames (an anomaly) still keeps a
        // row — a slot never vanishes in silence.
        foreach (array_keys($matrix) as $teamId) {
            $teams[$teamId] ??= ['name' => $data->teamNames[$teamId] ?? '', 'category' => $data->teamCategories[$teamId] ?? ''];
        }

        // Columns = only the days actually used, kept in DAY_LABELS order (a training week is
        // usually Mon–Sat ; empty day columns would only add noise).
        $dayColumns = array_values(array_filter(
            array_keys(ScheduleExportData::DAY_LABELS),
            static fn (int $d): bool => isset($daysUsed[$d]),
        ));
        // Catégorie puis nom, en ordre FRANÇAIS (`FrenchNameOrder`) — le même que l'écran :
        // un export ne doit jamais contredire la liste qu'on avait sous les yeux.
        uasort($teams, static fn (array $a, array $b): int => FrenchNameOrder::compare($a['category'], $b['category'])
            ?: FrenchNameOrder::compare($a['name'], $b['name']));

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Équipes × jours');

        // Header : two fixed columns (Équipe, Catégorie) then one per day. Kept as a
        // name-indexed list so the day header and the cells beneath it are projected from the
        // SAME `$dayColumns` order.
        $sheet->setCellValue([1, 1], 'Équipe');
        $sheet->setCellValue([2, 1], 'Catégorie');
        $firstDayCol = 3;
        foreach ($dayColumns as $offset => $day) {
            $sheet->setCellValue([$firstDayCol + $offset, 1], ScheduleExportData::DAY_LABELS[$day]);
        }
        $lastCol = $firstDayCol + \count($dayColumns) - 1;

        $row = 2;
        foreach ($teams as $teamId => $meta) {
            $sheet->setCellValue([1, $row], $meta['name']);
            $sheet->setCellValue([2, $row], $meta['category']);
            foreach ($dayColumns as $offset => $day) {
                // Projection PAR (équipe, jour) : la cellule sous « Mardi » lit toujours le mardi
                // de CETTE équipe — jamais l'écriture positionnelle qui produirait un décalage muet.
                $entries = $matrix[$teamId][$day] ?? [];
                usort($entries, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
                $text = implode("\n", array_map(static fn (array $e): string => $e['text'], $entries));
                $sheet->setCellValue([$firstDayCol + $offset, $row], $text);
                if (str_contains($text, "\n")) {
                    $sheet->getStyle([$firstDayCol + $offset, $row])->getAlignment()->setWrapText(true);
                }
            }
            ++$row;
        }

        $headerStyle = $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex($lastCol) . '1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach (range(1, $lastCol) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        // Freeze the header row and the two identity columns (Équipe, Catégorie) so a wide week
        // stays readable.
        $sheet->freezePane('C2');
    }

    private function toBinary(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);
        // Ceinture-bretelles de la même revue : jamais de pré-calcul de formule
        // côté serveur (une formule malgré tout présente ne peut ni boucler ni
        // faire lever l'export).
        $writer->setPreCalculateFormulas(false);
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new RuntimeException('Cannot open temp stream for XLSX.');
        }
        $writer->save($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return false === $content ? '' : $content;
    }
}
