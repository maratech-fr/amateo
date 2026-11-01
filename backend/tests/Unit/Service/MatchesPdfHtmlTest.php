<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Export\MatchesExportData;
use App\Export\MatchesExportPlace;
use App\Export\MatchesExportRow;
use App\Export\MatchesExportTeamSection;
use App\Service\MatchesPdfGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Promesse : le document que les parents reçoivent se lit sans explication — date, heure, adversaire,
 * où, lieu et trajet, sans statut, en français, et aucun texte saisi par un club n'y est interprété.
 * `buildHtml` est pur : il ne lit que les données du contrat. Les cellules se lisent par position
 * (Date · Heure · Adversaire · Où · Lieu) : c'est l'ordre promis.
 */
final class MatchesPdfHtmlTest extends TestCase
{
    public function testTheTitleKeepsOneYearWhenBothBoundsShareIt(): void
    {
        $text = $this->textOf($this->build(['from' => '2026-11-01', 'to' => '2026-12-31']));

        self::assertStringContainsString('Matchs du 1er novembre au 31 décembre 2026', $text);
    }

    public function testTheTitlePrintsBothYearsWhenTheRangeCrossesNewYear(): void
    {
        $text = $this->textOf($this->build(['from' => '2026-12-15', 'to' => '2027-01-15']));

        self::assertStringContainsString('Matchs du 15 décembre 2026 au 15 janvier 2027', $text);
    }

    public function testASingleTeamExportNamesItInTheTitle(): void
    {
        $text = $this->textOf($this->build(['from' => '2026-11-09', 'to' => '2026-11-15', 'teamScopeName' => 'U13 M1']));

        self::assertStringContainsString('Matchs du 9 au 15 novembre 2026 — U13 M1', $text);
    }

    public function testTheHeaderCarriesEditionDateClubNameAndLogo(): void
    {
        $html = $this->build(['clubName' => 'Les Lions', 'logoDataUri' => 'data:image/png;base64,AAAA']);
        $text = $this->textOf($html);

        self::assertStringContainsString('Édité le 9 novembre 2026', $text);
        self::assertStringContainsString('Les Lions', $text);
        self::assertStringContainsString('src="data:image/png;base64,AAAA"', $html);
    }

    public function testColumnsAreTheFivePromisedOnes(): void
    {
        $html = $this->build(['sections' => [$this->section('U13 M1', [$this->row()])]]);

        $headers = new Crawler($html)->filterXPath('//th')->each(static fn (Crawler $th): string => trim($th->text()));
        self::assertSame(['Date', 'Heure', 'Adversaire', 'Où', 'Lieu'], $headers);
    }

    public function testARowReadsTheShortDateAndWhereInFrench(): void
    {
        $cells = $this->onlyRowCells($this->row(date: '2026-11-14', kickoff: '15:30', opponent: 'Les Lions', where: 'home'));

        self::assertSame('sam. 14 nov.', $cells[0]);
        self::assertSame('15:30', $cells[1]);
        self::assertSame('Les Lions', $cells[2]);
        self::assertSame('Domicile', $cells[3]);
    }

    public function testAwayAndNeutralAreNamed(): void
    {
        self::assertSame('Extérieur', $this->onlyRowCells($this->row(where: 'away'))[3]);
        self::assertSame('Terrain neutre', $this->onlyRowCells($this->row(where: 'neutral'))[3]);
    }

    public function testAFriendlyIsMarked(): void
    {
        self::assertSame('Les Lions (amical)', $this->onlyRowCells($this->row(opponent: 'Les Lions', friendly: true))[2]);
    }

    public function testToConfirmSitsNextToTheKickoff(): void
    {
        self::assertSame('15:30 · à confirmer', $this->onlyRowCells($this->row(kickoff: '15:30', toConfirm: true))[1]);
    }

    public function testToConfirmReplacesAMissingKickoff(): void
    {
        self::assertSame('à confirmer', $this->onlyRowCells($this->row(kickoff: null, toConfirm: true))[1]);
    }

    public function testNoPlacePrintsADash(): void
    {
        self::assertSame('—', $this->onlyRowCells($this->row(place: null))[4]);
    }

    public function testAPlaceWithoutAddressIsItsNameAlone(): void
    {
        self::assertSame('Gymnase du Parc', $this->onlyRowCells($this->row(place: new MatchesExportPlace(name: 'Gymnase du Parc', address: null, travelMinutes: null)))[4]);
    }

    public function testAPlaceWithAddressJoinsNameAndAddress(): void
    {
        $place = new MatchesExportPlace(name: 'Gymnase du Parc', address: '3 av. des Sports, 69100 Villeurbanne', travelMinutes: null);

        self::assertSame('Gymnase du Parc — 3 av. des Sports, 69100 Villeurbanne', $this->onlyRowCells($this->row(place: $place))[4]);
    }

    public function testTravelFollowsTheAddress(): void
    {
        $place = new MatchesExportPlace(name: 'Salle du Parc', address: '12 rue X, 69500 Bron', travelMinutes: 35);

        self::assertSame('Salle du Parc — 12 rue X, 69500 Bron · 35 min en voiture', $this->onlyRowCells($this->row(where: 'away', place: $place))[4]);
    }

    public function testTheSingularMinuteKeepsTheSameWording(): void
    {
        $place = new MatchesExportPlace(name: 'Salle du Parc', address: null, travelMinutes: 1);

        self::assertStringContainsString('1 min en voiture', $this->onlyRowCells($this->row(where: 'away', place: $place))[4]);
    }

    public function testTheTravelLegendAppearsOnlyWhenATravelIsPrinted(): void
    {
        $legend = 'Temps de trajet estimé en voiture depuis le siège du club.';

        self::assertStringContainsString($legend, $this->textOf($this->build(['hasTravel' => true])));
        self::assertStringNotContainsString($legend, $this->textOf($this->build(['hasTravel' => false])));
    }

    public function testATeamWithoutMatchInTheRangeSaysSo(): void
    {
        $text = $this->textOf($this->build(['sections' => [$this->section('U9 F', [])]]));

        self::assertStringContainsString('U9 F', $text);
        self::assertStringContainsString('Aucun match sur cette période.', $text);
    }

    public function testAClubWithoutSectionStillSaysSo(): void
    {
        self::assertStringContainsString('Aucun match sur cette période.', $this->textOf($this->build(['sections' => []])));
    }

    /** Sans ces trois règles, un titre d'équipe reste seul en bas de page ou une ligne se coupe. */
    public function testPrintRulesKeepTitlesAndRowsTogether(): void
    {
        $html = $this->build();

        self::assertStringContainsString('break-after: avoid', $html);
        self::assertStringContainsString('break-inside: avoid', $html);
        self::assertStringContainsString('table-header-group', $html);
    }

    public function testClubTextsAreEscapedEverywhere(): void
    {
        $evil = '<script>alert(1)</script>';
        $place = new MatchesExportPlace(name: $evil, address: $evil, travelMinutes: null);

        $html = $this->build([
            'clubName' => $evil,
            'teamScopeName' => $evil,
            'sections' => [$this->section($evil, [$this->row(opponent: $evil, place: $place)])],
        ]);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @return list<string> les cinq cellules de l'unique ligne rendue */
    private function onlyRowCells(MatchesExportRow $row): array
    {
        $rows = new Crawler($this->build(['sections' => [$this->section('U13 M1', [$row])]]))->filterXPath('//tr[td]');
        self::assertCount(1, $rows);

        return $rows->filterXPath('.//td')->each(static fn (Crawler $td): string => trim((string) preg_replace('/\s+/u', ' ', $td->text())));
    }

    private function textOf(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', new Crawler($html)->filterXPath('//body')->text()));
    }

    /** @param array<string, mixed> $overrides */
    private function build(array $overrides = []): string
    {
        $data = new MatchesExportData(
            clubId: '11111111-1111-4111-8111-111111111111',
            clubName: $overrides['clubName'] ?? 'Les Lions',
            logoDataUri: $overrides['logoDataUri'] ?? null,
            from: new DateTimeImmutable($overrides['from'] ?? '2026-11-01'),
            to: new DateTimeImmutable($overrides['to'] ?? '2026-11-30'),
            editedOn: new DateTimeImmutable('2026-11-09'),
            teamScopeName: $overrides['teamScopeName'] ?? null,
            hasTravel: $overrides['hasTravel'] ?? false,
            sections: $overrides['sections'] ?? [$this->section('U13 M1', [$this->row()])],
        );

        return new ReflectionClass(MatchesPdfGenerator::class)->newInstanceWithoutConstructor()->buildHtml($data);
    }

    /** @param list<MatchesExportRow> $rows */
    private function section(string $teamName, array $rows): MatchesExportTeamSection
    {
        return new MatchesExportTeamSection(teamName: $teamName, rows: $rows);
    }

    private function row(
        string $date = '2026-11-14',
        ?string $kickoff = '15:30',
        bool $toConfirm = false,
        string $opponent = 'Les Lions',
        bool $friendly = false,
        string $where = 'home',
        ?MatchesExportPlace $place = null,
    ): MatchesExportRow {
        return new MatchesExportRow(
            date: new DateTimeImmutable($date),
            kickoff: $kickoff,
            toConfirm: $toConfirm,
            opponent: $opponent,
            friendly: $friendly,
            where: $where,
            place: $place,
        );
    }
}
