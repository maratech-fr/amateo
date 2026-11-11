<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Fbi;

use App\Clock\ClubClock;
use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Exception\ImportRejectedException;
use App\Service\ClubDay;
use App\Service\Fbi\FbiExportFreshnessGuard;
use App\Tests\WritesFbiExports;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;
use ZipArchive;

/**
 * Un export FBI n'est déposé que s'il est FRAIS : la garde lit `dcterms:created` de
 * `docProps/core.xml` (jamais la date des entrées zip, jamais PhpSpreadsheet), refuse un
 * fichier sans date lisible ou de plus de 24 heures sur l'horloge RÉELLE, et dit la date
 * du fichier à l'heure du club. Une marge de 15 minutes couvre l'écart analyse → import.
 */
#[Group('unit')]
final class FbiExportFreshnessGuardTest extends TestCase
{
    use WritesFbiExports;

    private const string REAL_EXPORT = __DIR__ . '/../../../Fixtures/fbi/rechercherRencontre.xlsx';

    private const string MISSING = 'Ce fichier ne porte pas la date d\'export de FBI. Déposez le fichier téléchargé depuis FBI, pas une copie refaite.';

    private const string TOO_OLD = 'Ce fichier a été exporté de FBI le 10/11/2026 à 10:38, il y a plus de 24 heures. Téléchargez un nouvel export depuis FBI et déposez-le.';

    /** L'instant d'export de l'exemple du plan : 10/11/2026 à 10:38 heure de Paris. */
    private const string EXPORTED = '2026-11-10T09:38:31Z';

    // ── Ce qu'on lit ─────────────────────────────────────────────────────

    public function testItReadsTheCreatedInstantOfAnExport(): void
    {
        $path = $this->fbiExport([], new DateTimeImmutable(self::EXPORTED));

        $at = $this->guard(self::EXPORTED)->exportedAt($path);

        self::assertInstanceOf(DateTimeImmutable::class, $at);
        self::assertSame(new DateTimeImmutable(self::EXPORTED)->getTimestamp(), $at->getTimestamp());
    }

    public function testItReadsTheCreatedInstantOfAnExportResavedInExcel(): void
    {
        // Le fichier réel figé a été ré-enregistré dans Excel (lastModifiedBy, modified ≠
        // created) : Excel conserve `created`, c'est lui qui est lu.
        $at = $this->guard(self::EXPORTED)->exportedAt(self::REAL_EXPORT);

        self::assertInstanceOf(DateTimeImmutable::class, $at);
        self::assertSame(new DateTimeImmutable('2026-07-30T08:00:27Z')->getTimestamp(), $at->getTimestamp());
    }

    public function testAWorkbookWithoutCorePartHasNoDateEvenWithFreshZipEntries(): void
    {
        // Les entrées zip de la copie sont datées d'à l'instant : la garde ne les lit jamais.
        $path = $this->withExportedAt($this->fbiExport([]), null);

        self::assertNull($this->guard(self::EXPORTED)->exportedAt($path));
    }

    public function testACorePartWithoutCreatedHasNoDate(): void
    {
        $path = $this->withCoreXml('<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>Apache POI</dc:creator></cp:coreProperties>');

        self::assertNull($this->guard(self::EXPORTED)->exportedAt($path));
    }

    public function testAnUnreadableCreatedHasNoDate(): void
    {
        $path = $this->withCoreXml('<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dcterms="http://purl.org/dc/terms/"><dcterms:created>pas une date</dcterms:created></cp:coreProperties>');

        self::assertNull($this->guard(self::EXPORTED)->exportedAt($path));
    }

    public function testACorePartOfExactlySixtyFourKilobytesIsStillRead(): void
    {
        $path = $this->withCorePartOfSize($this->fbiExport([]), FbiExportFreshnessGuard::CORE_PART_MAX_BYTES);

        self::assertInstanceOf(DateTimeImmutable::class, $this->guard(self::EXPORTED)->exportedAt($path));
    }

    public function testACorePartOverSixtyFourKilobytesHasNoDateAndIsRefusedAsUndated(): void
    {
        // Abus : une part `core.xml` gonflée n'est jamais chargée — sa taille décompressée
        // se lit sur l'index du zip avant toute lecture ; au-delà de la borne, pas de date.
        $path = $this->withCorePartOfSize($this->fbiExport([]), FbiExportFreshnessGuard::CORE_PART_MAX_BYTES + 1);
        $guard = $this->guard(self::EXPORTED);

        self::assertNull($guard->exportedAt($path));
        $this->assertRefusedWith(self::MISSING, fn () => $guard->assertFresh($path, $this->club()));
    }

    public function testATenMegabyteCorePartHasNoDate(): void
    {
        $path = $this->withCorePartOfSize($this->fbiExport([]), 10 * 1024 * 1024);

        self::assertNull($this->guard(self::EXPORTED)->exportedAt($path));
    }

    // ── Les bornes d'âge ─────────────────────────────────────────────────

    public function testAnUndatedFileIsRefusedNamingWhatToDeposit(): void
    {
        $path = $this->withExportedAt($this->fbiExport([]), null);

        $this->assertRefusedWith(self::MISSING, fn () => $this->guard(self::EXPORTED)->assertFresh($path, $this->club()));
    }

    public function testAnExportOfTwentyThreeHoursFiftyNineIsAccepted(): void
    {
        $path = $this->fbiExport([], new DateTimeImmutable(self::EXPORTED));

        $at = $this->guard('2026-11-11T09:37:31Z')->assertFresh($path, $this->club());

        self::assertSame(new DateTimeImmutable(self::EXPORTED)->getTimestamp(), $at->getTimestamp(), 'la date lue est rendue à l\'appelant');
    }

    public function testAnExportOfTwentyFourHoursOneIsRefusedWithItsDateAtTheClubHour(): void
    {
        // 09:38:31Z = 10:38 à Paris (UTC+1 en novembre) : le message parle l'heure du club.
        $path = $this->fbiExport([], new DateTimeImmutable(self::EXPORTED));

        $this->assertRefusedWith(self::TOO_OLD, fn () => $this->guard('2026-11-11T09:39:31Z')->assertFresh($path, $this->club()));
    }

    public function testTheImportGraceAcceptsTwentyFourHoursTenButNotTwentyFourHoursSixteen(): void
    {
        // Analyse à 24 h moins quelques minutes, import 10 min plus tard : la marge de 15 min
        // ne perd pas un appariement fait entre les deux gestes.
        $path = $this->fbiExport([], new DateTimeImmutable(self::EXPORTED));

        $at = $this->guard('2026-11-11T09:48:31Z')->assertFresh($path, $this->club(), FbiExportFreshnessGuard::IMPORT_GRACE_SECONDS);
        self::assertSame(new DateTimeImmutable(self::EXPORTED)->getTimestamp(), $at->getTimestamp());

        $this->assertRefusedWith(self::TOO_OLD, fn () => $this->guard('2026-11-11T09:54:31Z')->assertFresh($path, $this->club(), FbiExportFreshnessGuard::IMPORT_GRACE_SECONDS));
    }

    public function testAnExportDatedInTheFutureIsAccepted(): void
    {
        // Garde-fou contre l'erreur, pas contre la fraude : un `created` forgé dans le futur passe.
        $path = $this->fbiExport([], new DateTimeImmutable('2027-01-01T00:00:00Z'));

        $at = $this->guard(self::EXPORTED)->assertFresh($path, $this->club());

        self::assertSame(new DateTimeImmutable('2027-01-01T00:00:00Z')->getTimestamp(), $at->getTimestamp());
    }

    public function testTheAgeIsMeasuredOnTheRealClockEvenForAClubWithASimulatedToday(): void
    {
        // Le club de démo vit le 09/11 (horloge simulée), son ClubDay aussi : l'âge d'un
        // export, lui, se mesure sur l'horloge RÉELLE (11/11 à 10:39 UTC) → refusé.
        $path = $this->fbiExport([], new DateTimeImmutable(self::EXPORTED));
        $club = $this->club();
        $club->setIsDemo(true);
        $club->setSimulatedToday(new DateTimeImmutable('2026-11-09'));
        $guard = new FbiExportFreshnessGuard(
            new MockClock(new DateTimeImmutable('2026-11-11T09:39:31Z')),
            $this->clubDay(new MockClock(new DateTimeImmutable('2026-11-09T08:00:00Z'))),
        );

        $this->assertRefusedWith(self::TOO_OLD, fn () => $guard->assertFresh($path, $club));
    }

    public function testTheConstantsAreTheAgreedBounds(): void
    {
        self::assertSame(86400, FbiExportFreshnessGuard::MAX_AGE_SECONDS);
        self::assertSame(900, FbiExportFreshnessGuard::IMPORT_GRACE_SECONDS);
        self::assertSame(65536, FbiExportFreshnessGuard::CORE_PART_MAX_BYTES);
    }

    protected function tearDown(): void
    {
        $this->removeFbiExports();
        parent::tearDown();
    }

    // ── Outils ───────────────────────────────────────────────────────────

    /** La garde, l'horloge réelle posée à `$realNowUtc` ; le ClubDay suit la même horloge. */
    private function guard(string $realNowUtc): FbiExportFreshnessGuard
    {
        $real = new MockClock(new DateTimeImmutable($realNowUtc));

        return new FbiExportFreshnessGuard($real, $this->clubDay($real));
    }

    private function clubDay(MockClock $clock): ClubDay
    {
        // Montage inerte de ClubDayTest : sans drapeau ni pin, ClubClock ne lit que l'entité.
        $clubClock = new ClubClock(
            $clock,
            new RequestStack,
            $this->createMock(EntityManagerInterface::class),
            new DevClockStore(new ArrayAdapter),
            false,
            'test',
        );

        return new ClubDay($clock, $clubClock);
    }

    private function club(): Club
    {
        return new Club()->setTimezone('Europe/Paris');
    }

    /** Une copie d'export dont `core.xml` est remplacé tel quel. */
    private function withCoreXml(string $xml): string
    {
        $path = $this->withExportedAt($this->fbiExport([]), new DateTimeImmutable(self::EXPORTED));
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path));
        $zip->addFromString('docProps/core.xml', $xml);
        $zip->close();

        return $path;
    }

    /** @param callable(): mixed $gesture */
    private function assertRefusedWith(string $message, callable $gesture): void
    {
        try {
            $gesture();
            self::fail('le fichier devait être refusé');
        } catch (ImportRejectedException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame($message, $e->getMessage());
        }
    }
}
