<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ScheduleSlotTemplate;
use App\Export\ScheduleExportData;
use App\Service\SpreadsheetGenerator;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * D1 — la 2ᵉ feuille « Équipes × jours » (matrice lignes = équipes, colonnes = jours,
 * cellule = gymnase + heure) est un AJOUT au classeur : la feuille Planning ne bouge pas,
 * la matrice n'apparaît qu'en multi-gymnase, et elle est construite PAR (équipe, jour) puis
 * projetée — jamais en positionnel (même piège que la feuille 1, audit D-18).
 *
 * On exerce le seam réel `appendTeamDayMatrix(Spreadsheet, ScheduleExportData)` — le collaborateur
 * `ScheduleExportDataProvider` est `final` et ne se double pas ; on lui donne donc directement une
 * `ScheduleExportData` construite à la main, puis on sérialise en XLSX et on relit le fichier pour
 * asserter sur ce que le gestionnaire ouvrira vraiment.
 */
#[Group('phase1')]
final class SpreadsheetTeamDayMatrixTest extends TestCase
{
    private const MATRIX_SHEET = 'Équipes × jours';

    /** Le contenu de la feuille Planning simulée : en-têtes + 2 lignes, figé pour prouver qu'il ne bouge pas. */
    private const PLANNING_ROWS = 2;

    /**
     * Injection de formule (revue sécurité P2-17) : tout le classeur est de la
     * donnée club — un nom d'équipe ou un libellé de groupe « =HYPERLINK(...) »
     * doit rester une CHAÎNE à l'ouverture dans Excel, jamais une formule vivante.
     * Le StringValueBinder posé sur le classeur garde TOUTES les colonnes.
     */
    public function testFormulaLookingContentStaysAPlainString(): void
    {
        // Le nom d'équipe (colonne préexistante) ET le libellé de groupe (colonne
        // nouvelle) sont piégés : le binder doit couvrir les deux.
        $teamPayload = '=HYPERLINK("http://evil/?t","U13")';
        $groupPayload = '=HYPERLINK("http://evil/?g","CEC3")';
        $data = new ScheduleExportData(
            slots: [$this->slot('t-u13', 'v-a', 1, '18:30')],
            teamNames: ['t-u13' => $teamPayload],
            teamCategories: ['t-u13' => 'U13'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null]],
            coachNames: [],
            groupLabels: ['v-a|1|18:30' => $groupPayload],
        );

        // Le VRAI classeur livré : `render()` (la totalité de `generate()` après chargement),
        // sérialisé puis relu — pas la maquette de `renderAndReadBack`, qui n'exerce que la matrice.
        $generator = new ReflectionClass(SpreadsheetGenerator::class)->newInstanceWithoutConstructor();
        $path = tempnam(sys_get_temp_dir(), 'xlsx_') ?: throw new RuntimeException('no temp file');
        try {
            file_put_contents($path, $generator->render($data));
            $planning = new XlsxReader()->load($path)->getSheetByName('Planning');
        } finally {
            unlink($path);
        }
        self::assertNotNull($planning, 'la feuille Planning doit exister');

        $found = [];
        foreach ($planning->getRowIterator(2) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if (\in_array($cell->getValue(), [$teamPayload, $groupPayload], true)) {
                    $found[] = $cell->getValue();
                    self::assertNotSame('f', $cell->getDataType(), 'le contenu utilisateur en forme de formule doit rester une chaîne, pas une formule');
                }
            }
        }
        self::assertSame([$groupPayload, $teamPayload], $found, 'les deux contenus piégés doivent être présents tels quels (chaînes intactes), équipe et groupe');
    }

    public function testSecondSheetIsAddedAndPlanningStaysUntouched(): void
    {
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-a', 1, '18:30'),
                $this->slot('t-u15', 'v-b', 3, '20:00'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
        );

        $book = $this->renderAndReadBack($data);

        // Deux feuilles, Planning en premier, la matrice ajoutée après.
        self::assertSame(2, $book->getSheetCount());
        self::assertSame(['Planning', self::MATRIX_SHEET], $book->getSheetNames());

        // Planning inchangée : mêmes en-têtes, même nombre de lignes qu'avant l'ajout de la matrice.
        $planning = $book->getSheet(0);
        self::assertSame($this->headers(), \array_slice($planning->toArray()[0], 0, \count($this->headers())));
        self::assertSame(1 + self::PLANNING_ROWS, $planning->getHighestDataRow(), 'la matrice n’ajoute ni ne retire aucune ligne à la feuille Planning');
    }

    public function testMatrixCellCarriesVenueAndTimeButNeverTheCoach(): void
    {
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-a', 1, '18:30', 'c-1'),
                $this->slot('t-u15', 'v-b', 3, '20:00', 'c-1'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: ['c-1' => 'Jean Dupont'],
        );

        $cell = $this->matrixCell($this->renderAndReadBack($data), 'U13 F', 'Lundi');

        self::assertStringContainsString('Gymnase Municipal', $cell);
        self::assertStringContainsString('18:30', $cell);
        // Assertion négative explicite : le coach vit dans la feuille Planning, jamais ici.
        self::assertStringNotContainsString('Jean Dupont', $cell);
    }

    public function testTheMatrixIsAddedEvenOnASingleVenueClub(): void
    {
        // ⚑ Décision fondateur 2026-08-21 — **les DEUX vues, toujours.** La 2ᵉ feuille était
        // omise quand tous les créneaux tenaient dans UN gymnase, au motif qu'elle « lève
        // l'ambiguïté sur le gymnase » : justification d'un DÉCLENCHEUR prise pour la raison
        // d'être de la vue. La feuille Planning dit « qui occupe quel gymnase ce jour-là » ; la
        // matrice dit « quand s'entraîne CETTE équipe », une ligne à lire — un club
        // mono-gymnase a le même besoin de donner à chaque équipe sa ligne.
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-a', 1, '18:30'),
                $this->slot('t-u15', 'v-a', 3, '20:00'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
        );

        $book = $this->renderAndReadBack($data);

        self::assertSame(2, $book->getSheetCount(), 'un club mono-gymnase reçoit AUSSI sa matrice');
        self::assertStringContainsString('Gymnase Municipal', $this->matrixCell($book, 'U13 F', 'Lundi'));
    }

    public function testTwoSlotsSameDayAreBothKeptInTheCell(): void
    {
        // Une équipe qui s'entraîne 2× le mardi, deux gymnases/heures : aucun créneau ne
        // disparaît en silence, les deux vivent dans la cellule, triés par heure.
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-b', 2, '20:00'),
                $this->slot('t-u13', 'v-a', 2, '18:30'),
                $this->slot('t-u15', 'v-b', 4, '19:00'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
        );

        $cell = $this->matrixCell($this->renderAndReadBack($data), 'U13 F', 'Mardi');

        self::assertStringContainsString('Gymnase Municipal', $cell);
        self::assertStringContainsString('18:30', $cell);
        self::assertStringContainsString('Salle B', $cell);
        self::assertStringContainsString('20:00', $cell);
        // Triés par heure : 18:30 avant 20:00 dans la cellule.
        self::assertLessThan(mb_strpos($cell, '20:00'), mb_strpos($cell, '18:30'));
    }

    /**
     * Équivalent feuille-2 du garde D-18 : la cellule sous « Mercredi » lit toujours le
     * mercredi de CETTE équipe, quel que soit le nombre de colonnes de jour. Ajouter un jour
     * (ici un samedi pour une autre équipe) décale les positions de colonnes mais PAS le
     * contenu, parce que la lecture se fait par NOM de jour, jamais par position.
     *
     * ⚑ Falsification : inverser deux colonnes de jours (en-tête vs cellule) ou écrire une
     * cellule en positionnel fait rougir ce test en nommant l'équipe et le jour concernés.
     */
    public function testDayColumnsAreProjectedByNameNotPosition(): void
    {
        $base = [
            $this->slot('t-u13', 'v-a', 1, '18:30'), // Lundi → Gymnase Municipal
            $this->slot('t-u13', 'v-b', 3, '20:00'), // Mercredi → Salle B
            $this->slot('t-u15', 'v-b', 5, '19:00'), // Vendredi (autre équipe)
        ];
        $withExtraDay = [...$base, $this->slot('t-u15', 'v-a', 6, '10:00')]; // + Samedi : décale les colonnes

        $venues = ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]];
        $names = ['t-u13' => 'U13 F', 't-u15' => 'U15 M'];
        $cats = ['t-u13' => 'U13', 't-u15' => 'U15'];

        $bookA = $this->renderAndReadBack(new ScheduleExportData($base, $names, $cats, $venues, []));
        $bookB = $this->renderAndReadBack(new ScheduleExportData($withExtraDay, $names, $cats, $venues, []));

        foreach ([$bookA, $bookB] as $book) {
            self::assertStringContainsString('Gymnase Municipal', $this->matrixCell($book, 'U13 F', 'Lundi'));
            self::assertStringContainsString('Salle B', $this->matrixCell($book, 'U13 F', 'Mercredi'));
            self::assertSame('', $this->matrixCell($book, 'U13 F', 'Vendredi'), 'U13 ne s’entraîne pas le vendredi : cellule vide, jamais le contenu d’une colonne voisine');
        }
    }

    public function testTeamWithoutAnySlotStillGetsAnEmptyRow(): void
    {
        // Décision figée : une équipe sans séance est un TROU dans le planning, le premier
        // défaut que le gestionnaire doit voir — sa ligne reste, cellules vides, jamais masquée.
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-a', 1, '18:30'),
                $this->slot('t-u15', 'v-b', 3, '20:00'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M', 't-u17' => 'U17 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15', 't-u17' => 'U17'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
        );

        $book = $this->renderAndReadBack($data);
        $sheet = $book->getSheetByName(self::MATRIX_SHEET);
        self::assertNotNull($sheet);

        // U17 n'a aucun créneau : sa ligne existe quand même...
        $teamColumn = array_column(\array_slice($sheet->toArray(), 1), 0);
        self::assertContains('U17 M', $teamColumn, 'une équipe sans séance garde sa ligne dans la matrice');
        // Lot B — ...et sa PREMIÈRE cellule de jour porte « aucune séance » (parité ClubViewTable.tsx :
        // le trou se lit d'un coup d'œil) ; les autres cellules de jour restent vides.
        self::assertSame('aucune séance', $this->matrixCell($book, 'U17 M', 'Lundi'), 'U17 n’a aucune séance : première cellule de jour « aucune séance »');
        self::assertSame('', $this->matrixCell($book, 'U17 M', 'Mercredi'), 'les autres cellules de jour de U17 restent vides');
    }

    /**
     * Lot B — « aucune séance » se pose dans la PREMIÈRE cellule de JOUR. S'il n'existe AUCUNE
     * colonne de jour (aucun créneau placé dans tout le planning), il n'y a pas de cellule où
     * l'écrire : on n'ajoute pas de colonne fantôme, et la mention n'apparaît nulle part.
     */
    public function testNoSessionCaptionNeedsAtLeastOneDayColumn(): void
    {
        $data = new ScheduleExportData(
            slots: [],
            teamNames: ['t-u13' => 'U13 F'],
            teamCategories: ['t-u13' => 'U13'],
            venues: [],
            coachNames: [],
        );

        $book = $this->renderAndReadBack($data);
        $sheet = $book->getSheetByName(self::MATRIX_SHEET);
        self::assertNotNull($sheet);

        // L'équipe garde sa ligne (nom en colonne 1)...
        self::assertContains('U13 F', array_column(\array_slice($sheet->toArray(), 1), 0), 'l’équipe garde sa ligne même sans aucun créneau');
        // ...mais « aucune séance » n'apparaît dans AUCUNE cellule (pas de colonne de jour où l'écrire).
        foreach ($sheet->toArray() as $line) {
            foreach ($line as $cell) {
                self::assertNotSame('aucune séance', (string) $cell, 'sans colonne de jour, pas de mention « aucune séance »');
            }
        }
    }

    public function testHeaderCarriesNoRankColumnAndDaysStartAtColumnThree(): void
    {
        // P4-106 (décision fondateur 2026-08-18) : le rang est une information de GESTION. Un
        // export part au gymnase et aux familles — la priorisation interne des équipes n'y a
        // pas sa place. Ce test épingle l'ABSENCE : réintroduire une colonne « Rang » (ou tout
        // en-tête portant ce mot) fait rougir ici, et décaler les jours ailleurs qu'en 3 aussi.
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-a', 1, '18:30'),
                $this->slot('t-u15', 'v-b', 3, '20:00'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
            teamRanks: ['t-u13' => $this->rank('S', 'Elite', 1), 't-u15' => $this->rank('D', 'Loisir', 5)],
        );

        $header = $this->matrixHeader($this->renderAndReadBack($data));

        self::assertSame('Équipe', $header[0] ?? null);
        self::assertSame('Catégorie', $header[1] ?? null);
        self::assertSame('Lundi', $header[2] ?? null, 'les jours commencent en colonne 3 : plus de colonne « Rang » avant eux');
        self::assertNotContains('Rang', $header, 'aucune colonne de rang dans un document destiné au public');
    }

    public function testNoRankLabelLeaksAnywhereInTheWorkbook(): void
    {
        // Le test qui compte : on fournit des rangs BIEN nommés, et on vérifie qu'aucune de
        // leurs étiquettes ne ressort dans UNE SEULE cellule du classeur — en-têtes compris.
        // Une réintroduction discrète (une colonne renommée, un suffixe collé au nom d'équipe)
        // échoue ici même si l'en-tête « Rang » n'est jamais réécrit tel quel.
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-sen', 'v-a', 1, '18:30'),
                $this->slot('t-u15', 'v-b', 3, '20:00'),
            ],
            teamNames: ['t-sen' => 'Séniors M1', 't-u15' => 'U15 F1'],
            teamCategories: ['t-sen' => 'Séniors', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
            teamRanks: ['t-sen' => $this->rank('S', 'ChampionnatElite', 1), 't-u15' => $this->rank('A', 'RegionalPlus', 2)],
        );

        $book = $this->renderAndReadBack($data);

        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $line) {
                foreach ($line as $cell) {
                    $text = (string) $cell;
                    self::assertStringNotContainsString('ChampionnatElite', $text, 'le nom du rang ne doit apparaître nulle part');
                    self::assertStringNotContainsString('RegionalPlus', $text, 'le nom du rang ne doit apparaître nulle part');
                }
            }
        }
    }

    public function testDefaultRowOrderStaysCategoryThenNameNotRank(): void
    {
        // Ordre par défaut inchangé : catégorie puis nom — surtout PAS le rang. On choisit des
        // données où l'ordre par rang (S avant D) contredit l'ordre par catégorie (U11 avant U13).
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-hi', 'v-a', 1, '18:30'),
                $this->slot('t-lo', 'v-b', 3, '20:00'),
            ],
            teamNames: ['t-hi' => 'Zoulou', 't-lo' => 'Alpha'],
            teamCategories: ['t-hi' => 'U13', 't-lo' => 'U11'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
            // Zoulou est rang S (1), Alpha rang D (5) : par rang Zoulou serait premier — l'inverse
            // de l'ordre par catégorie, qui doit rester en vigueur par défaut.
            teamRanks: ['t-hi' => $this->rank('S', 'Elite', 1), 't-lo' => $this->rank('D', 'Loisir', 5)],
        );

        $teamColumn = array_column($this->rankRows($this->renderAndReadBack($data)), 0);

        // U11/Alpha avant U13/Zoulou : l'ordre des lignes reste catégorie puis nom.
        self::assertSame(['Alpha', 'Zoulou'], $teamColumn);
    }

    private function slot(string $teamId, string $venueId, int $day, string $time, ?string $coachId = null): ScheduleSlotTemplate
    {
        return new ScheduleSlotTemplate()
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($time))
            ->setDurationMinutes(90)
            ->setCoachId($coachId);
    }

    /** @return list<string> */
    private function headers(): array
    {
        /** @var list<string> $headers */
        $headers = new ReflectionClass(SpreadsheetGenerator::class)->getConstant('HEADERS');

        return $headers;
    }

    /**
     * Simule la feuille Planning déjà construite (en-têtes + quelques lignes), applique le seam
     * réel `appendTeamDayMatrix`, sérialise en XLSX et relit — exactement le fichier livré.
     */
    private function renderAndReadBack(ScheduleExportData $data): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $planning = $spreadsheet->getActiveSheet();
        $planning->setTitle('Planning');
        foreach ($this->headers() as $i => $label) {
            $planning->setCellValue([$i + 1, 1], $label);
        }
        for ($row = 2; $row <= 1 + self::PLANNING_ROWS; ++$row) {
            $planning->setCellValue([1, $row], 'ligne existante');
        }

        // Le seam n'utilise pas la dépendance `final` du générateur ; on instancie donc ce
        // dernier sans son constructeur plutôt que de doubler un `final` (interdit par le projet).
        $generator = new ReflectionClass(SpreadsheetGenerator::class)->newInstanceWithoutConstructor();
        new ReflectionMethod(SpreadsheetGenerator::class, 'appendTeamDayMatrix')->invoke($generator, $spreadsheet, $data);

        $path = tempnam(sys_get_temp_dir(), 'xlsx_') ?: throw new RuntimeException('no temp file');
        try {
            new XlsxWriter($spreadsheet)->save($path);

            return new XlsxReader()->load($path);
        } finally {
            unlink($path);
        }
    }

    /** Cellule (équipe, jour) de la matrice, localisée PAR NOM — ligne par nom d'équipe, colonne par libellé de jour. */
    private function matrixCell(Spreadsheet $book, string $teamName, string $dayLabel): string
    {
        $sheet = $book->getSheetByName(self::MATRIX_SHEET);
        self::assertNotNull($sheet, 'la feuille matrice doit exister');
        $grid = $sheet->toArray();

        $colIndex = array_search($dayLabel, $grid[0], true);
        self::assertIsInt($colIndex, \sprintf('colonne de jour « %s » absente de l’en-tête', $dayLabel));

        foreach ($grid as $line) {
            if (($line[0] ?? null) === $teamName) {
                return (string) ($line[$colIndex] ?? '');
            }
        }
        self::fail(\sprintf('ligne de l’équipe « %s » introuvable', $teamName));
    }

    /** @return array{label: string, name: string, tierRank: int, tierOrder: int} */
    private function rank(string $label, string $name, int $tierRank, int $tierOrder = 0): array
    {
        return ['label' => $label, 'name' => $name, 'tierRank' => $tierRank, 'tierOrder' => $tierOrder];
    }

    /** @return list<string> l'en-tête de la feuille matrice, tel qu'ouvert. */
    private function matrixHeader(Spreadsheet $book): array
    {
        $sheet = $book->getSheetByName(self::MATRIX_SHEET);
        self::assertNotNull($sheet, 'la feuille matrice doit exister');

        return array_map(static fn (mixed $v): string => (string) $v, $sheet->toArray()[0]);
    }

    /** Valeur de la colonne « Rang » pour une équipe, localisée PAR NOM. */
    private function rankRows(Spreadsheet $book): array
    {
        $sheet = $book->getSheetByName(self::MATRIX_SHEET);
        self::assertNotNull($sheet, 'la feuille matrice doit exister');
        $grid = $sheet->toArray();

        // Plus de colonne « Rang » (P4-106) : on ne lit que la colonne des équipes, qui suffit
        // à épingler l'ORDRE des lignes — le seul reliquat visible du rang, et il est voulu.
        $rows = [];
        foreach (\array_slice($grid, 1) as $line) {
            $rows[] = [(string) ($line[0] ?? '')];
        }

        return $rows;
    }
}
