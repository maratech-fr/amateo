<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Export\ExportEmptyWindow;
use App\Export\ExportReservedWindow;
use App\Export\ScheduleExportData;
use App\Export\ScheduleExportDataProvider;
use App\Storage\LogoStorage;
use App\Support\FrenchNameOrder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use finfo;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Renders a weekly training schedule as a landscape grid (days × venues, time
 * down the rows) and hands the HTML to the Puppeteer worker.
 *
 * **Deux sections, toujours** (décision fondateur 2026-08-21) — elles répondent à deux
 * questions distinctes, et c'est pour ça qu'aucune ne se déduit de l'autre :
 *   1. la **grille** du gestionnaire (jours × gymnases) : qui occupe quel gymnase ce jour-là,
 *      ajustée par le worker à une seule page A4 paysage (la police rétrécit au besoin) ;
 *   2. la **matrice équipes × jours** (une ligne par équipe, groupée par rang) : quand
 *      s'entraîne CETTE équipe — la page qu'on donne à chaque équipe.
 *
 * ⚠ La matrice était auparavant conditionnée à « ≥ 2 gymnases utilisés ». Ce seuil venait de
 * la justification du DÉCLENCHEUR (lever l'ambiguïté sur le gymnase), prise à tort pour la
 * raison d'être de la vue : un club mono-gymnase a le même besoin de donner à chaque équipe sa
 * ligne. Même geste côté Excel (`SpreadsheetGenerator::appendTeamDayMatrix`).
 */
class PdfGenerator
{
    private const PDF_WORKER_URL = 'http://pdf-worker:3000/generate';
    private const OUTPUT_DIR = '/app/backend/public/exports';
    private const PUBLIC_PATH = '/exports';
    // Match the on-screen planning grid's granularity (grid.ts stepMin = 15) so
    // slots starting at :15/:45 land on the right row instead of being snapped or
    // dropped into a coarser bucket.
    private const STEP_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly ScheduleExportDataProvider $exportData,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly LogoStorage $logoStorage,
        private readonly BrandAssets $brandAssets,
        private readonly ProductIdentity $productIdentity,
    ) {}

    /**
     * P4-52 — où les rendus atterrissent, et sous quelle URL ils sont servis.
     *
     * Exposés en accesseurs plutôt que recopiés dans `PurgeExportsCommand` : la purge doit
     * balayer EXACTEMENT le répertoire que ce générateur écrit. Deux constantes qui
     * dériveraient donneraient une purge qui ne trouve rien — et qui le dit en vert.
     */
    public static function outputDir(): string
    {
        return self::OUTPUT_DIR;
    }

    public static function publicPath(): string
    {
        return self::PUBLIC_PATH;
    }

    /**
     * @return array{pdf: string}
     */
    public function generate(Schedule $schedule, ?string $venueId = null): array
    {
        $data = $this->exportData->load($schedule, $venueId);
        // P3-20 — la vue « club » (matrice équipes × jours) est ce que le gestionnaire veut
        // EN IMAGE. On ne fabrique pas une 5ᵉ mise en forme pour ça : c'est la section 2 déjà
        // rendue ici qui est photographiée, le worker choisissant simplement quelle section
        // il garde à l'écran avant le screenshot.
        // ⚑ **Les DEUX vues, toujours** (décision fondateur 2026-08-21). La matrice ne dépend
        // plus du nombre de gymnases : elle répond à une AUTRE question que la grille — « quand
        // s'entraîne cette équipe », une ligne à lire, là où la grille dit « qui occupe quel
        // gymnase ce jour-là ». Le seuil « ≥ 2 gymnases » venait d'une justification de
        // DÉCLENCHEUR (lever l'ambiguïté sur le gymnase) prise à tort pour la raison d'être de
        // la vue : un club mono-gymnase a le même besoin de donner à chaque équipe SA ligne.
        $html = $this->buildHtml($schedule, $data, $venueId, true);
        // Scope suffix keeps the all-venues and per-venue exports as distinct files. Le jeton
        // de VUE compte autant : la page qui attend l'export compare ce suffixe pour ne pas
        // saisir le fichier d'une autre demande — sans lui, une image « par club » servirait
        // la grille rendue une seconde plus tôt. La vue « grid » ne porte AUCUN jeton : son
        // nom de fichier reste byte-identique à l'historique.
        $scope = null === $venueId ? 'all' : substr($venueId, 0, 8);
        $pdfFilename = \sprintf('schedule-%s-%s.pdf', $schedule->getId(), $scope);

        // The Puppeteer worker (a separate container) both creates this dir and
        // writes the files, as its own uid — PHP only reads back the result, so we
        // do NOT pre-create it here (that left it owned by www-data, unwritable by
        // the worker, and forced a world-writable chmod).

        try {
            // P5-24 — le pied de marque « Généré avec … » posé sur CHAQUE page par
            // Puppeteer (`footerTemplate`). Construit ici, côté backend : le worker
            // reste bête, il ne fait que le câbler. Le nom vient de `ProductIdentity`
            // (jamais un littéral de marque), l'icône est inlinée en data URI.
            $this->callWorker($html, $pdfFilename, $this->buildFooterTemplate());
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('PDF worker unreachable: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return ['pdf' => self::PUBLIC_PATH . '/' . $pdfFilename];
    }

    private function callWorker(string $html, string $filename, string $footerTemplate): void
    {
        // L'export porte TOUJOURS ses deux sections depuis le retrait du PNG : le drapeau
        // reste explicite dans le payload plutôt qu'implicite côté worker.
        // `footerTemplate` = le pied de marque déjà rendu (HTML inline, icône en data URI) :
        // le worker le pose tel quel via `displayHeaderFooter`, sans rien composer lui-même.
        $json = [
            'html' => $html,
            'filename' => $filename,
            'landscape' => true,
            'multiSection' => true,
            'footerTemplate' => $footerTemplate,
        ];

        $response = $this->httpClient->request('POST', self::PDF_WORKER_URL, [
            'json' => $json,
            'timeout' => 30,
        ]);

        $result = $response->toArray(false);

        if (!($result['success'] ?? false)) {
            throw new RuntimeException($result['error'] ?? 'Worker generation failed.');
        }
    }

    /**
     * P5-24 — le pied de marque « Généré avec [icône] amateo », posé par Puppeteer sur
     * CHAQUE page (`footerTemplate`), petit et aligné à droite (maquette option 2).
     *
     * Contraintes du pied Puppeteer respectées ICI, à la source : styles INLINE seulement
     * (aucune feuille externe n'est chargée dans ce contexte) et `font-size` EXPLICITE (le
     * défaut du pied est nul). L'icône est inlinée en data URI par `BrandAssets` — le worker
     * ne joint aucune URL. Le nom vient de `ProductIdentity` (variable, jamais un littéral) et
     * s'écrit en minuscules comme le logotype à l'écran (`BrandMark`).
     */
    private function buildFooterTemplate(): string
    {
        $logo = $this->brandAssets->pdfLogoDataUri();
        $name = $this->productIdentity->name();
        $word = htmlspecialchars(mb_strtolower($name));
        $alt = htmlspecialchars($name);

        return \sprintf(
            '<div style="width:100%%;padding:0 24px;font-family:Arial,Helvetica,sans-serif;font-size:8px;color:#9ca3af;text-align:right;">'
            . '<span style="display:inline-flex;align-items:center;gap:4px;vertical-align:middle;">'
            . 'Généré avec'
            . '<img src="%s" alt="%s" style="height:11px;width:11px;display:inline-block;">'
            . '<span style="font-weight:600;color:#6b7280;">%s</span>'
            . '</span></div>',
            $logo,
            $alt,
            $word,
        );
    }

    private function buildHtml(Schedule $schedule, ScheduleExportData $data, ?string $venueId, bool $multiSection = false): string
    {
        $slots = $data->slots;
        $teamNames = $data->teamNames;
        $venues = $data->venues;
        $club = $this->entityManager->getRepository(Club::class)->find($schedule->getClubId());

        // Columns = ordered (day, venue) pairs that actually carry a slot, so the
        // grid never shows an empty venue column. Header groups them by day.
        $present = [];
        foreach ($slots as $slot) {
            $present[$slot->getDayOfWeek()][$slot->getVenueId()] = true;
        }
        // Empty windows create their (day, venue) column too, so a venue used ONLY
        // for unfilled windows still gets a column of `vide` cells.
        foreach ($data->emptySlots as $window) {
            $present[$window->dayOfWeek][$window->venueId] = true;
        }
        // Lot 4bis — un créneau LIBRE réservé crée aussi sa colonne (un gymnase utilisé UNIQUEMENT
        // par des créneaux libres mérite sa colonne de cases nommées).
        foreach ($data->freeSlots as $window) {
            $present[$window->dayOfWeek][$window->venueId] = true;
        }
        ksort($present);
        /** @var list<array{day:int,venueId:string}> $columns */
        $columns = [];
        foreach ($present as $day => $venueIds) {
            $ids = array_keys($venueIds);
            usort($ids, static fn (string $a, string $b): int => FrenchNameOrder::compare($venues[$a]['name'] ?? '', $venues[$b]['name'] ?? ''));
            foreach ($ids as $vId) {
                $columns[] = ['day' => $day, 'venueId' => $vId];
            }
        }
        // Column indices that OPEN a new day, so a marked vertical band (black, distinct
        // from the light grid line) draws the mon/tue/… frontier in header AND body — same
        // "isolate a day at a glance" intent as the wizard grid's thick day border.
        $dayStartCols = [];
        $prevDay = null;
        foreach ($columns as $colIndex => $col) {
            if (0 !== $colIndex && $col['day'] !== $prevDay) {
                $dayStartCols[$colIndex] = true;
            }
            $prevDay = $col['day'];
        }

        [$startMin, $endMin] = $this->timeBounds($slots, [...$data->emptySlots, ...$data->freeSlots]);

        // Index slots by column + start step. A step can hold MORE than one slot
        // (a split court runs two teams at the same time in the same gym), so we
        // keep a list per bucket instead of overwriting.
        $byColStep = [];
        foreach ($slots as $slot) {
            $key = $slot->getDayOfWeek() . '|' . $slot->getVenueId();
            $startStep = intdiv($this->minutesOf($slot->getStartTime()) - $startMin, self::STEP_MINUTES);
            $byColStep[$key][$startStep][] = $slot;
        }
        // Empty windows indexed by col+start step. Only one per (col, step) — two
        // unfilled windows starting in the same 15-min step (e.g. a split court)
        // collapse to a single `vide` cell (MVP, mirrors the capacity note above).
        $emptyByColStep = [];
        foreach ($data->emptySlots as $window) {
            $key = $window->dayOfWeek . '|' . $window->venueId;
            $startStep = intdiv($this->minutesOf($window->startTime) - $startMin, self::STEP_MINUTES);
            $emptyByColStep[$key][$startStep] = $window;
        }
        // Lot 4bis — créneaux LIBRES indexés col+step, comme les vides. Rendus AVANT la case
        // « vide » (la case est occupée, pas un trou).
        $freeByColStep = [];
        foreach ($data->freeSlots as $window) {
            $key = $window->dayOfWeek . '|' . $window->venueId;
            $startStep = intdiv($this->minutesOf($window->startTime) - $startMin, self::STEP_MINUTES);
            $freeByColStep[$key][$startStep] = $window;
        }

        $steps = max(1, intdiv($endMin - $startMin, self::STEP_MINUTES));
        $rows = $this->buildRows($columns, $byColStep, $emptyByColStep, $freeByColStep, $startMin, $steps, $teamNames, $venues, $data->groupLabels, $dayStartCols);
        $header = $this->buildHeader($columns, $venues, $dayStartCols);

        $scopeLabel = null === $venueId ? 'Tous les gymnases' : ($venues[$venueId]['name'] ?? 'Gymnase');
        // P4-52 — le logo du club en tête de PDF, INLINÉ en data URI : le worker
        // Puppeteer ne doit dépendre d'aucun fetch réseau (une URL /api/... peut
        // être injoignable depuis son contexte, et le document doit rester
        // auto-portant). Best-effort : pas de logo = un en-tête comme avant.
        $logoBytes = $club instanceof Club ? $this->logoStorage->read($club->getId()) : null;
        $logoImg = '';
        if (null !== $logoBytes && '' !== $logoBytes) {
            $mime = new finfo(\FILEINFO_MIME_TYPE)->buffer($logoBytes) ?: 'image/png';
            $logoImg = \sprintf('<img class="logo" src="data:%s;base64,%s" alt="">', $mime, base64_encode($logoBytes));
        }
        // ADR-0002 inv. 12 : le nom VIVANT du plan, jamais la photo `Schedule.name` prise à
        // la création de la version — sinon le document remis aux coachs contredit le nom que
        // l'écran affiche et celui du fichier qui le porte (revue #339 round 2).
        $planningName = $this->schedulePlanProvisioner->displayNameOf($schedule);
        $title = htmlspecialchars($club?->getName() ?? $planningName);

        $grid = [] === $columns
            ? '<p class="empty">Aucun créneau planifié.</p>'
            : \sprintf('<table><thead>%s</thead><tbody>%s</tbody></table>', $header, $rows);
        // Section 1 = the manager grid (page 1). The worker scales THIS section to one page.
        $body = \sprintf('<section class="page-grid">%s</section>', $grid);
        // Section 2 = the "team × day" matrix, on its own page(s). Only present on a
        // multi-venue export — the same condition that set $multiSection.
        if ($multiSection) {
            $body .= $this->buildMatrixSection($data, $venueId);
        }

        return $this->wrapDocument($title, htmlspecialchars($scopeLabel), htmlspecialchars($planningName), $body, $logoImg);
    }

    /**
     * @param list<array{day:int,venueId:string}>                   $columns
     * @param array<string, array<int, list<ScheduleSlotTemplate>>> $byColStep
     * @param array<string, array<int, ExportEmptyWindow>>          $emptyByColStep
     * @param array<string, array<int, ExportReservedWindow>>       $freeByColStep  lot 4bis free slots
     * @param array<string, string>                                 $teamNames
     * @param array<string, array{name:string,color:?string}>       $venues
     * @param array<string, string>                                 $groupLabels    "venue|day|H:i" → label
     * @param array<int, true>                                      $dayStartCols   column indices that open a new day
     */
    private function buildRows(array $columns, array $byColStep, array $emptyByColStep, array $freeByColStep, int $startMin, int $steps, array $teamNames, array $venues, array $groupLabels, array $dayStartCols): string
    {
        // covered[colIndex][step] = true when a rowspan from above occupies the cell.
        $covered = [];
        $rows = '';
        for ($step = 0; $step < $steps; ++$step) {
            $absMin = $startMin + $step * self::STEP_MINUTES;
            $timeLabel = $this->formatMinutes($absMin);
            // Midday break [12:00, 14:00) shaded on the empty cells + time gutter (a real
            // placement keeps its venue colour and shows through, as on the wizard grid);
            // the 12:00 and 14:00 edges get a marked top rule so the band reads as a zone.
            $inNoon = $absMin >= 12 * 60 && $absMin < 14 * 60;
            $noonEdge = 12 * 60 === $absMin || 14 * 60 === $absMin;
            $trClass = $noonEdge ? ' class="noon-edge"' : '';
            $timeClass = $inNoon ? 'time noon' : 'time';
            $cells = '';
            foreach ($columns as $colIndex => $col) {
                if (isset($covered[$colIndex][$step])) {
                    continue;
                }
                $dayStart = isset($dayStartCols[$colIndex]);
                $colKey = $col['day'] . '|' . $col['venueId'];
                $bucket = $byColStep[$colKey][$step] ?? [];
                if ([] === $bucket) {
                    // Lot 4bis — un créneau LIBRE ici → une case OCCUPÉE nommée (pas un trou, pas
                    // « vide »), couvrant sa durée sans jamais recouvrir une case placée/déjà prise.
                    $free = $freeByColStep[$colKey][$step] ?? null;
                    if (null !== $free) {
                        $maxSpan = max(1, (int) ceil($free->durationMinutes / self::STEP_MINUTES));
                        $span = 1;
                        while ($span < $maxSpan && !isset($covered[$colIndex][$step + $span]) && [] === ($byColStep[$colKey][$step + $span] ?? [])) {
                            ++$span;
                        }
                        for ($k = 1; $k < $span; ++$k) {
                            $covered[$colIndex][$step + $k] = true;
                        }
                        $cells .= $this->freeSlotCell($free, $span, $dayStart);

                        continue;
                    }
                    // A defined-but-unfilled window here → a `vide` cell spanning its
                    // duration; otherwise a blank gap.
                    $window = $emptyByColStep[$colKey][$step] ?? null;
                    if (null !== $window) {
                        // Grow the `vide` cell over its duration but NEVER across an
                        // already-covered step or a real placement below it — a rowspan
                        // that covered a placement's step would skip (drop) that cell.
                        $maxSpan = max(1, (int) ceil($window->durationMinutes / self::STEP_MINUTES));
                        $span = 1;
                        while ($span < $maxSpan && !isset($covered[$colIndex][$step + $span]) && [] === ($byColStep[$colKey][$step + $span] ?? [])) {
                            ++$span;
                        }
                        for ($k = 1; $k < $span; ++$k) {
                            $covered[$colIndex][$step + $k] = true;
                        }
                        $cells .= \sprintf('<td class="%s" rowspan="%d">vide</td>', $this->cellClasses('cell empty', $dayStart, $inNoon), $span);

                        continue;
                    }
                    $cells .= \sprintf('<td class="%s"></td>', $this->cellClasses('cell', $dayStart, $inNoon));

                    continue;
                }
                // Cell spans the longest concurrent session in this bucket.
                $span = 1;
                foreach ($bucket as $slot) {
                    $span = max($span, (int) ceil($slot->getDurationMinutes() / self::STEP_MINUTES));
                }
                for ($k = 1; $k < $span; ++$k) {
                    $covered[$colIndex][$step + $k] = true;
                }
                $cells .= $this->slotCell($bucket, $span, $teamNames, $venues, $groupLabels, $dayStart);
            }
            $rows .= \sprintf('<tr%s><th class="%s">%s</th>%s</tr>', $trClass, $timeClass, $timeLabel, $cells);
        }

        return $rows;
    }

    /** Base cell classes plus the day-boundary band and midday-break shade when applicable. */
    private function cellClasses(string $base, bool $dayStart, bool $inNoon): string
    {
        if ($dayStart) {
            $base .= ' day-start';
        }
        if ($inNoon) {
            $base .= ' noon';
        }

        return $base;
    }

    /**
     * @param list<ScheduleSlotTemplate>                      $bucket      concurrent slots (≥1) in one cell
     * @param array<string, string>                           $teamNames
     * @param array<string, array{name:string,color:?string}> $venues
     * @param array<string, string>                           $groupLabels "venue|day|H:i" → label
     */
    private function slotCell(array $bucket, int $span, array $teamNames, array $venues, array $groupLabels, bool $dayStart): string
    {
        // Colour the cell by the (shared) venue of the bucket; stack each team.
        $venue = $venues[$bucket[0]->getVenueId()] ?? ['name' => 'Salle', 'color' => null];
        $color = $venue['color'] ?? '#666666';
        $fg = $this->readableForeground($color);

        // Cell composition (founder): start time on TOP, team name(s) centred below. The
        // gym is NOT repeated here — it already IS the column header (adaptation of the
        // "gym at the bottom" wish), and the coach is dropped from the grid entirely (it
        // only cluttered the cell). A shared window stacks each team on its own line, one
        // per row, WITHOUT splitting the cell into sub-cells.
        $slot0 = $bucket[0];
        $time = \sprintf('<div class="cell-time">%s</div>', htmlspecialchars($slot0->getStartTime()->format('H:i')));

        // Group label ("CEC3") of this cell's window, if any — titled above the stacked teams.
        // Keyed by the window identity (venue|day|start) all slots of the bucket share. It is user
        // content: escaped like everything else in the template.
        $groupKey = $slot0->getVenueId() . '|' . $slot0->getDayOfWeek() . '|' . $slot0->getStartTime()->format('H:i');
        $groupLabel = $groupLabels[$groupKey] ?? null;
        $title = null === $groupLabel ? '' : \sprintf('<div class="group-title">%s</div>', htmlspecialchars($groupLabel));

        $entries = '';
        foreach ($bucket as $slot) {
            $teamName = $teamNames[$slot->getTeamId()] ?? 'Équipe inconnue';
            $entries .= \sprintf('<div class="entry"><span class="team">%s</span></div>', htmlspecialchars($teamName));
        }

        return \sprintf(
            '<td class="%s" rowspan="%d" style="background:%s;color:%s">%s%s%s</td>',
            $dayStart ? 'cell filled day-start' : 'cell filled',
            $span,
            htmlspecialchars($color),
            htmlspecialchars($fg),
            $time,
            $title,
            $entries,
        );
    }

    /**
     * Lot 4bis — la case d'un CRÉNEAU LIBRE réservé : l'heure en tête, le libellé centré dessous,
     * sur un fond neutre tramé (distinct d'une séance d'équipe, qui porte la couleur du gymnase).
     * Le libellé est du contenu club, échappé comme tout le reste du gabarit.
     */
    private function freeSlotCell(ExportReservedWindow $free, int $span, bool $dayStart): string
    {
        return \sprintf(
            '<td class="%s" rowspan="%d"><div class="cell-time">%s</div><div class="entry"><span class="team">%s</span></div></td>',
            $dayStart ? 'cell filled free day-start' : 'cell filled free',
            $span,
            htmlspecialchars($free->startTime->format('H:i')),
            htmlspecialchars($free->label),
        );
    }

    /**
     * @param list<array{day:int,venueId:string}>             $columns
     * @param array<string, array{name:string,color:?string}> $venues
     * @param array<int, true>                                $dayStartCols column indices that open a new day
     */
    private function buildHeader(array $columns, array $venues, array $dayStartCols): string
    {
        // Row 1: day labels spanning their venue columns. Row 2: venue names. The marked
        // day-boundary band (see $dayStartCols) runs down both rows so the mon/tue frontier
        // reads at a glance.
        $dayGroups = [];
        foreach ($columns as $col) {
            $dayGroups[$col['day']] = ($dayGroups[$col['day']] ?? 0) + 1;
        }
        $dayRow = '<th class="corner"></th>';
        $firstDay = true;
        foreach ($dayGroups as $day => $count) {
            $dayRow .= \sprintf('<th class="%s" colspan="%d">%s</th>', $firstDay ? 'day' : 'day day-start', $count, htmlspecialchars(ScheduleExportData::DAY_LABELS[$day] ?? ''));
            $firstDay = false;
        }
        $venueRow = '<th class="corner"></th>';
        foreach ($columns as $colIndex => $col) {
            // Colour each venue header with its own colour (same mapping as the
            // cells) instead of the flat grey; readable text on top.
            $venue = $venues[$col['venueId']] ?? ['name' => '', 'color' => null];
            $color = $venue['color'] ?? null;
            $style = null === $color ? '' : \sprintf(' style="background:%s;color:%s"', htmlspecialchars($color), htmlspecialchars($this->readableForeground($color)));
            $venueRow .= \sprintf('<th class="%s"%s>%s</th>', isset($dayStartCols[$colIndex]) ? 'venue day-start' : 'venue', $style, htmlspecialchars($venue['name']));
        }

        return \sprintf('<tr>%s</tr><tr>%s</tr>', $dayRow, $venueRow);
    }

    /**
     * Section 2 — a one-line-per-team matrix (rows = teams, columns = the days actually used,
     * cell = gym + time, NO coach) so a manager can hand each team the page that concerns it.
     *
     * Mirrors the XLSX second sheet's data rules (empty windows belong to no team and never
     * enter it; a team with no session keeps an empty row — the hole a manager must see; a
     * team training twice a day keeps both entries). It diverges from the XLSX on ROW ORDER on
     * purpose (founder-frozen for the PDF): rows are grouped by priority tier S→A→B→C→D, each
     * group kept atomic in landscape pagination (tbody break-inside: avoid), whereas the XLSX
     * sheet sorts by category then name. ⚠ Le rang ORDONNE mais ne s'AFFICHE PLUS (P4-106) :
     * l'export part au gymnase et aux familles, la priorisation interne reste au gestionnaire.
     *
     * ⚠ **Les LIGNES dépendent de la PORTÉE** (P3-20). Sur un export « tous les gymnases », ce
     * sont TOUTES les équipes de la saison : une équipe sans séance est un trou du planning, la
     * première chose que le gestionnaire doit voir, jamais une ligne masquée. Sur un export
     * limité à UN gymnase, seules les équipes qui y ont une séance : `ScheduleExportData` porte
     * toujours les équipes du club+saison quelle que soit la portée, donc les lister toutes
     * afficherait des lignes vides MENSONGÈRES — une équipe qui s'entraîne dans un autre
     * gymnase passerait pour une équipe sans entraînement, sur un document remis aux familles.
     * Le cas n'existait pas avant P3-20 (la matrice ne s'ouvrait qu'en multi-gymnases, donc
     * jamais sur un export scopé) ; il existe depuis qu'on peut la DEMANDER par la vue « club ».
     */
    // ⚠ Le paramètre s'appelle `$scopeVenueId`, PAS `$venueId` : le corps de cette méthode
    // réutilise déjà `$venueId` comme variable de boucle sur les placements — un paramètre
    // homonyme y était ÉCRASÉ par le dernier gymnase parcouru, et la portée lue plus bas
    // n'était plus celle de l'appelant (attrapé par les tests, jamais visible à l'œil).
    private function buildMatrixSection(ScheduleExportData $data, ?string $scopeVenueId = null): string
    {
        $venueName = static fn (string $id): string => $data->venues[$id]['name'] ?? '';
        $venueColor = static fn (string $id): string => $data->venues[$id]['color'] ?? '#666666';

        // Model indexed by (team, day) from the placements; each entry carries its own venue
        // colour so a cell stacking two sessions colours each like the grid does.
        /** @var array<string, array<int, list<array{start:string, text:string, color:string}>>> $matrix */
        $matrix = [];
        $daysUsed = [];
        foreach ($data->slots as $slot) {
            $day = $slot->getDayOfWeek();
            $start = $slot->getStartTime()->format('H:i');
            $venueId = $slot->getVenueId();
            // No coach on purpose (founder decision): it already lives in the grid and would
            // only clutter the scan.
            $matrix[$slot->getTeamId()][$day][] = [
                'start' => $start,
                'text' => trim($venueName($venueId) . ' · ' . $start),
                'color' => $venueColor($venueId),
            ];
            $daysUsed[$day] = true;
        }

        // Rows = EVERY season team (a team with no session is a hole to surface, never hidden),
        // plus any placement whose team is missing from teamNames (anomaly) so no slot vanishes.
        /** @var array<string, array{name:string, tierRank:int, tierOrder:int}> $teams */
        $teams = [];
        // Portée réduite → seules les équipes RÉELLEMENT placées ici (cf. docblock) ; portée
        // complète → toutes celles de la saison, plus toute équipe placée mais absente de la
        // liste (anomalie), pour qu'aucun placement ne s'évapore.
        $teamIds = null === $scopeVenueId ? array_keys($data->teamNames + $matrix) : array_keys($matrix);
        foreach ($teamIds as $teamId) {
            $rank = $data->teamRanks[$teamId] ?? ['label' => '', 'name' => '', 'tierRank' => \PHP_INT_MAX, 'tierOrder' => \PHP_INT_MAX];
            // Le rang ne sert plus qu'à ORDONNER (P4-106) : ni label ni sous-titre ne sont
            // conservés, pour qu'aucun rendu ne puisse les réafficher par inadvertance.
            $teams[$teamId] = [
                'name' => $data->teamNames[$teamId] ?? '',
                'tierRank' => $rank['tierRank'],
                'tierOrder' => $rank['tierOrder'],
            ];
        }

        // Columns = only the days actually used, kept in DAY_LABELS order (Sam/Dim appear only
        // when occupied). Group teams by tier (S→A→B→C→D = ascending tierRank), then by their
        // in-tier order, then name — the global rank the manager sees on screen.
        $dayColumns = array_values(array_filter(
            array_keys(ScheduleExportData::DAY_LABELS),
            static fn (int $d): bool => isset($daysUsed[$d]),
        ));
        // Rang, puis position dans le rang, puis le NOM — ce dernier en ordre français
        // (`FrenchNameOrder`) : une équipe accentuée ne doit pas finir en bas de son groupe.
        uasort($teams, static fn (array $a, array $b): int => [$a['tierRank'], $a['tierOrder']] <=> [$b['tierRank'], $b['tierOrder']]
            ?: FrenchNameOrder::compare($a['name'], $b['name']));

        $colspan = 1 + \count($dayColumns);
        $head = '<tr><th class="team-col">Équipe</th>';
        foreach ($dayColumns as $day) {
            $head .= \sprintf('<th>%s</th>', htmlspecialchars(ScheduleExportData::DAY_LABELS[$day]));
        }
        $head .= '</tr>';

        // One <tbody class="rank-group"> per tier: break-inside:avoid keeps a whole group on a
        // single page, so a rank is never split by landscape pagination.
        $groups = '';
        $currentRank = null;
        $groupRows = '';
        foreach ($teams as $teamId => $meta) {
            if ($meta['tierRank'] !== $currentRank) {
                $groups .= $this->renderRankGroup($colspan, $groupRows);
                $currentRank = $meta['tierRank'];
                $groupRows = '';
            }
            $groupRows .= $this->matrixRow($teamId, $meta['name'], $matrix, $dayColumns);
        }
        $groups .= $this->renderRankGroup($colspan, $groupRows);

        return \sprintf(
            '<section class="page-matrix"><h2 class="matrix-title">Par équipe — quand chaque équipe s’entraîne</h2>'
            . '<table class="matrix"><thead>%s</thead>%s</table></section>',
            $head,
            $groups,
        );
    }

    /** One rank group as a break-inside-safe tbody (its team rows only); '' when empty. */
    /**
     * Un groupe de rang = un `<tbody>` (pour que la pagination ne coupe pas un rang en deux),
     * SANS titre affiché. P4-106 (décision fondateur 2026-08-18) : le rang est une information
     * de GESTION — un export est affiché au gymnase et envoyé aux familles, la priorisation
     * interne des équipes n'a rien à y faire. L'ORDRE, lui, reste piloté par le rang : il se
     * voit sans se lire, et c'est ce qui rend le document utile au gestionnaire.
     */
    private function renderRankGroup(int $colspan, string $rows): string
    {
        if ('' === $rows) {
            return '';
        }

        return \sprintf('<tbody class="rank-group">%s</tbody>', $rows);
    }

    /**
     * One matrix row for a team, projected by (teamId, day) — the cell under "Mardi" always
     * reads THIS team's Tuesday, never a positional neighbour (same D-18 discipline as the XLSX).
     *
     * @param array<string, array<int, list<array{start:string, text:string, color:string}>>> $matrix
     * @param list<int>                                                                       $dayColumns
     */
    private function matrixRow(string $teamId, string $teamName, array $matrix, array $dayColumns): string
    {
        $cells = \sprintf('<th class="team-col">%s</th>', htmlspecialchars($teamName));
        foreach ($dayColumns as $day) {
            $entries = $matrix[$teamId][$day] ?? [];
            usort($entries, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            // No pill/badge (founder): the venue colour FILLS the whole cell, text centred
            // with a readable foreground. A day holding two sessions stacks two full-width
            // colour blocks that together fill the cell (each keeps its own gym colour).
            $fills = '';
            foreach ($entries as $entry) {
                $color = $entry['color'];
                $fills .= \sprintf(
                    '<div class="fill" style="background:%s;color:%s">%s</div>',
                    htmlspecialchars($color),
                    htmlspecialchars($this->readableForeground($color)),
                    htmlspecialchars($entry['text']),
                );
            }
            $cells .= \sprintf('<td>%s</td>', $fills);
        }

        return \sprintf('<tr>%s</tr>', $cells);
    }

    /**
     * @param array<ScheduleSlotTemplate>                  $slots
     * @param list<ExportEmptyWindow|ExportReservedWindow> $windows empty windows + lot 4bis free slots
     *
     * @return array{0:int,1:int} floor(min start)/ceil(max end) to the hour
     */
    private function timeBounds(array $slots, array $windows = []): array
    {
        if ([] === $slots && [] === $windows) {
            return [17 * 60, 21 * 60];
        }
        $min = \PHP_INT_MAX;
        $max = 0;
        foreach ($slots as $slot) {
            $start = $this->minutesOf($slot->getStartTime());
            $min = min($min, $start);
            $max = max($max, $start + $slot->getDurationMinutes());
        }
        foreach ($windows as $window) {
            $start = $this->minutesOf($window->startTime);
            $min = min($min, $start);
            $max = max($max, $start + $window->durationMinutes);
        }

        return [intdiv($min, 60) * 60, (int) (ceil($max / 60) * 60)];
    }

    private function minutesOf(DateTimeImmutable $time): int
    {
        return 60 * (int) $time->format('G') + (int) $time->format('i');
    }

    private function formatMinutes(int $total): string
    {
        return \sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /** Near-black or white text for legible contrast on a coloured cell (WCAG-ish). */
    private function readableForeground(string $hex): string
    {
        if (1 !== preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $m)) {
            return '#ffffff';
        }
        $n = (int) hexdec($m[1]);
        $lin = static function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $lum = 0.2126 * $lin(($n >> 16) & 0xFF) + 0.7152 * $lin(($n >> 8) & 0xFF) + 0.0722 * $lin($n & 0xFF);

        return $lum > 0.42 ? '#0b0b0c' : '#ffffff';
    }

    private function wrapDocument(string $title, string $scopeLabel, string $scheduleName, string $body, string $logoImg = ''): string
    {
        return \sprintf(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
                * { box-sizing: border-box; }
                body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 10px; color: #111; }
                header { display: flex; align-items: baseline; gap: 12px; margin-bottom: 8px; border-bottom: 2px solid #111; padding-bottom: 6px; }
                header h1 { font-size: 16px; margin: 0; }
                header .logo { height: 26px; width: 26px; object-fit: contain; align-self: center; }
                header .scope { font-size: 12px; color: #333; font-weight: bold; }
                header .sched { font-size: 11px; color: #777; margin-left: auto; }
                table { width: 100%%; border-collapse: collapse; table-layout: fixed; }
                th, td { border: 1px solid #bbb; padding: 2px 3px; font-size: 9px; vertical-align: top; }
                thead th { background: #f0f0f0; text-align: center; }
                th.day { font-size: 10px; }
                th.corner { width: 34px; background: #fff; border: none; }
                th.time { background: #fafafa; text-align: right; white-space: nowrap; font-weight: normal; color: #555; width: 34px; }
                td.cell { height: 14px; }
                /* Occupied cells: a stronger (near-black) border than the light #bbb grid line. */
                /* Heure épinglée en HAUT (absolue), bloc équipes centré dans la hauteur
                   REELLE de la cellule fusionnée (vertical-align sur le td) — sinon tout
                   s empile au sommet et une séance longue semble vide en bas. */
                td.filled { border: 2px solid #111; text-align: center; position: relative; vertical-align: middle; }
                td.filled .cell-time { position: absolute; top: 2px; left: 0; right: 0; font-size: 8px; font-weight: 600; opacity: 0.9; line-height: 1.1; }
                td.filled .group-title { display: block; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; opacity: 0.95; margin-bottom: 2px; padding-bottom: 1px; border-bottom: 1px solid rgba(255,255,255,0.55); }
                td.filled .entry + .entry { margin-top: 2px; padding-top: 2px; border-top: 1px dashed rgba(255,255,255,0.45); }
                td.filled .team { display: block; font-weight: bold; line-height: 1.1; }
                .empty { color: #999; font-style: italic; }
                td.cell.empty { text-align: center; vertical-align: middle; color: #999; font-style: italic; font-size: 8px; border: 1px dashed #ccc; }
                /* Lot 4bis — case de creneau LIBRE reserve : fond neutre trame, libelle en gris
                   fonce, distinct dune seance dequipe (qui porte la couleur du gymnase). */
                td.filled.free { background: repeating-linear-gradient(45deg, #f0f0f0 0 5px, #e6e6e6 5px 10px); color: #444; }
                td.filled.free .team { font-weight: 600; font-style: italic; }
                /* Midday break [12:00, 14:00): a rosé band on empty cells + gutter, with a
                   marked rule at its 12:00/14:00 edges — a real placement shows through in
                   its venue colour, same intent as the wizard slot grid. */
                td.cell.noon { background: #fdf2f4; }
                th.time.noon { background: #fbe9ec; }
                tr.noon-edge td, tr.noon-edge th { border-top: 2px solid #e3a7b1; }
                /* Marked vertical band (black) at each mon/tue/… day frontier. */
                .day-start { border-left: 2px solid #111; }
                /* Section 2 — the team × day matrix, on its own page(s). */
                .page-matrix { break-before: page; }
                .matrix-title { font-size: 13px; margin: 0 0 8px; }
                table.matrix td { text-align: center; height: 16px; padding: 0; }
                table.matrix .team-col { text-align: left; font-weight: bold; width: 92px; background: #fafafa; }
                /* A whole rank group stays on one page — never split by landscape pagination. */
                table.matrix tbody.rank-group { break-inside: avoid; }
                /* No badge: the venue colour FILLS the cell, text centred. Two sessions in a
                   day stack two full-width blocks that together fill the cell. */
                table.matrix td .fill { display: block; padding: 2px 4px; font-size: 8px; line-height: 1.3; }
                table.matrix td .fill + .fill { border-top: 1px solid rgba(255,255,255,0.5); }
            </style></head><body>
                <header>%s<h1>%s</h1><span class="scope">%s</span><span class="sched">%s</span></header>
                %s
            </body></html>',
            $logoImg,
            $title,
            $scopeLabel,
            $scheduleName,
            $body,
        );
    }
}
