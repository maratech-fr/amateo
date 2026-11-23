<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\VenuePeriodMode;
use App\Export\ExportReservedWindow;
use App\Export\ScheduleExportData;
use App\Export\ScheduleExportDataProvider;
use App\Plans\SoclePlan;
use App\Service\BrandAssets;
use App\Service\PdfGenerator;
use App\Service\ProductIdentity;
use App\Service\SchedulePlanProvisioner;
use App\Service\SpreadsheetGenerator;
use App\Storage\LogoStorage;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\RouterInterface;

/**
 * L'export (PDF et Excel) montre les occupations du gymnase si, et seulement si, le gestionnaire
 * le demande ; décochée, l'option rend le fichier d'aujourd'hui.
 *
 * Logique à nombreux cas nommée par le plan (N4) : la promesse de bout en bout (« le fichier
 * nomme “Occupation — École” si je le demande, pas sinon ») est le scénario « J'exporte avec ou
 * sans les occupations » de `l-export-du-planning.feature` ; ce test en prouve les cas : l'option
 * lue en booléen strict, la couche de la version, la présence d'un gymnase à l'export d'ensemble
 * (une occupation ne le rend jamais « utilisé » ; désactivé ou entièrement fermé, ses occupations
 * suivent le sort de ses fenêtres vides — D22), le chevauchement partiel, la colonne et l'axe des
 * heures, la dormante, et les deux abus de texte brut (`<script>` dans le PDF, formule dans Excel).
 *
 * Décor : les occupations sont écrites en base (table `venue_occupancy`) — la garde de saisie est
 * prouvée par `Integration/Api/VenueOccupancyApiTest`, ici seul l'export est en jeu.
 *
 * ⚠ Seams PROPOSÉS (option A), adaptables mécaniquement par NEO : `ScheduleExportDataProvider::load(
 * Schedule, ?string, bool $showOccupancies)`, `ScheduleExportData::$occupancies`
 * (`list<ExportReservedWindow>`, le libellé de chaque fenêtre étant le MOTIF nu), `PdfGenerator::
 * buildHtml` lu par réflexion (même patron que `PdfGridCoachNameTest`), `PdfGenerator::renderPending(...,
 * $showCoaches, $showOccupancies)`, `SpreadsheetGenerator::generate(Schedule, ?string, bool)`.
 */
#[Group('integration')]
final class ScheduleExportOccupanciesTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private ScheduleExportDataProvider $provider;

    private Club $club;

    private Season $season;

    private User $user;

    /** Sans l'option (absente ou fausse), aucune occupation n'est chargée pour l'export. */
    public function testWithoutTheOptionNoOccupancyIsLoaded(): void
    {
        $version = $this->seasonVersion();
        $venue = $this->makeVenue('Alpha');
        $this->placeSession($version, $venue, 4, '18:00');
        $this->occupancy($venue, 2, '14:00', 135, 'École');

        self::assertSame([], $this->provider->load($version)->occupancies, 'option absente → aucune occupation');
        self::assertSame([], $this->provider->load($version, null, false)->occupancies, 'option fausse → aucune occupation');
        self::assertSame(['École'], $this->labels($this->provider->load($version, null, true)), 'option vraie → l\'occupation est chargée');
    }

    /** La route d'export Excel ne lit l'option que sur le booléen strict `true` : `"true"`, `1` ou l'absence ne montrent rien. */
    public function testTheExcelRouteReadsTheOptionAsAStrictBoolean(): void
    {
        $version = $this->seasonVersion();
        $venue = $this->makeVenue('Alpha');
        $this->placeSession($version, $venue, 4, '18:00');
        $this->occupancy($venue, 2, '14:00', 135, 'École');
        $versionId = $version->getId();
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);
        $this->em->clear();

        foreach (['{}', '{"showOccupancies": "true"}', '{"showOccupancies": 1}', '{"showOccupancies": false}'] as $body) {
            self::assertNotContains('Occupation — École', $this->exportXlsx($token, $versionId, $body), $body . ' → le fichier ne nomme pas l\'occupation');
        }
        self::assertContains('Occupation — École', $this->exportXlsx($token, $versionId, '{"showOccupancies": true}'), 'true → le fichier nomme « Occupation — École »');
    }

    /** L'export ne lit que les occupations de la couche de la version exportée : la saison pour le socle, la période pour un plan de période. */
    public function testOnlyTheExportedVersionsLayerIsRendered(): void
    {
        $seasonVersion = $this->seasonVersion();
        [$periodVersion, $planId] = $this->periodVersion('2026-05-04', '2026-05-10');
        $venue = $this->makeVenue('Alpha');
        $this->placeSession($seasonVersion, $venue, 4, '18:00');
        $this->placeSession($periodVersion, $venue, 4, '18:00');
        $this->occupancy($venue, 2, '14:00', 135, 'Saison');
        $this->occupancy($venue, 2, '14:00', 135, 'Période', $planId);

        self::assertSame(['Saison'], $this->labels($this->provider->load($seasonVersion, null, true)), 'le socle montre l\'occupation de saison seule');
        self::assertSame(['Période'], $this->labels($this->provider->load($periodVersion, null, true)), 'la période montre la sienne seule');
    }

    /** À l'export d'ensemble, une occupation ne rend jamais un gymnase « utilisé » : un gymnase qui ne porte qu'une occupation reste absent. */
    public function testAVenueCarryingOnlyAnOccupancyStaysOutOfTheWholeExport(): void
    {
        $version = $this->seasonVersion();
        $used = $this->makeVenue('Utilisé');
        $onlyOccupied = $this->makeVenue('Occupé seulement');
        $this->placeSession($version, $used, 4, '18:00');
        $this->occupancy($used, 2, '14:00', 135, 'École');
        $this->occupancy($onlyOccupied, 2, '14:00', 135, 'Mairie');

        $data = $this->provider->load($version, null, true);

        self::assertSame([$used->getId()], $this->venueIds($data), 'seul le gymnase utilisé porte ses occupations à l\'export d\'ensemble');
        self::assertStringNotContainsString('Occupé seulement', $this->pdfHtml($version, $data), 'le gymnase qui ne porte qu\'une occupation n\'a aucune colonne');
    }

    /** Un gymnase « utilisé » par un seul créneau libre réservé est présent, avec ses occupations. */
    public function testAVenueUsedOnlyByAFreeReservedSlotIsPresentWithItsOccupancy(): void
    {
        $version = $this->seasonVersion();
        $venue = $this->makeVenue('Réservé');
        $this->window($venue, 3, '18:00', null);
        $this->em->persist(
            (new Reservation)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setSchedulePlanId(self::getContainer()->get(SoclePlan::class)->idOf($this->season->getId()))
                ->setTeamId(null)->setLabel('Loto du club')->setVenueId($venue->getId())
                ->setDayOfWeek(3)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90),
        );
        $this->em->flush();
        $this->occupancy($venue, 2, '14:00', 135, 'École');

        self::assertSame(['École'], $this->labels($this->provider->load($version, null, true)), 'le créneau libre réservé rend le gymnase présent, son occupation suit');
    }

    /** D22 — à l'export d'ensemble d'une période, un gymnase désactivé garde sa séance placée mais pas son occupation. */
    public function testADisabledVenueKeepsItsPlacedSessionButNotItsOccupancy(): void
    {
        [$version, $planId] = $this->periodVersion('2026-05-04', '2026-05-10');
        $disabled = $this->makeVenue('Désactivé');
        $open = $this->makeVenue('Ouvert');
        $this->placeSession($version, $disabled, 3, '20:00');
        $this->placeSession($version, $open, 3, '20:00');
        $this->occupancy($disabled, 2, '14:00', 135, 'École', $planId);
        $this->occupancy($open, 2, '14:00', 135, 'Mairie', $planId);
        $this->em->persist(
            (new VenuePeriodOverride)
                ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
                ->setSchedulePlanId($planId)->setVenueId($disabled->getId())
                ->setMode(VenuePeriodMode::DISABLED)->setDayOverrides(null),
        );
        $this->em->flush();

        $data = $this->provider->load($version, null, true);

        self::assertContains($disabled->getId(), array_map(static fn (ScheduleSlotTemplate $s): string => $s->getVenueId(), $data->slots), 'la séance placée du gymnase désactivé reste');
        self::assertSame(['Mairie'], $this->labels($data), 'l\'occupation du gymnase désactivé n\'est pas rendue ; celle du gymnase ouvert l\'est');
    }

    /** D22 — même règle pour un gymnase entièrement fermé pour la période. */
    public function testAFullyClosedVenueKeepsItsPlacedSessionButNotItsOccupancy(): void
    {
        [$version, $planId, $entry] = $this->periodVersion('2026-05-06', '2026-05-06');
        $closed = $this->makeVenue('Fermé');
        $open = $this->makeVenue('Ouvert');
        $this->placeSession($version, $closed, 3, '20:00');
        $this->placeSession($version, $open, 3, '20:00');
        $this->occupancy($closed, 3, '14:00', 135, 'École', $planId);
        $this->occupancy($open, 3, '14:00', 135, 'Mairie', $planId);
        $constraint = (new Constraint)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Gym en travaux')
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId($closed->getId())
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($entry->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => '2026-05-06', 'endDate' => '2026-05-06']);
        $this->em->persist($constraint);
        $this->em->flush();

        $data = $this->provider->load($version, null, true);

        self::assertContains($closed->getId(), array_map(static fn (ScheduleSlotTemplate $s): string => $s->getVenueId(), $data->slots), 'la séance placée du gymnase fermé reste');
        self::assertSame(['Mairie'], $this->labels($data), 'l\'occupation du gymnase entièrement fermé n\'est pas rendue');
    }

    /** D22 — l'export d'un seul gymnase montre toutes ses occupations, même désactivé pour la période. */
    public function testASingleVenueExportOfADisabledVenueRendersItsOccupancy(): void
    {
        [$version, $planId] = $this->periodVersion('2026-05-04', '2026-05-10');
        $disabled = $this->makeVenue('Désactivé');
        $this->occupancy($disabled, 2, '14:00', 135, 'École', $planId);
        $this->em->persist(
            (new VenuePeriodOverride)
                ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
                ->setSchedulePlanId($planId)->setVenueId($disabled->getId())
                ->setMode(VenuePeriodMode::DISABLED)->setDayOverrides(null),
        );
        $this->em->flush();

        self::assertSame(['École'], $this->labels($this->provider->load($version, $disabled->getId(), true)));
    }

    /** Une occupation qui partage du temps, même en partie, avec une séance placée ou un créneau de la couche n'est pas rendue (D12). */
    public function testAnOccupancyOverlappingASessionOrASlotIsNotRendered(): void
    {
        $version = $this->seasonVersion();
        $venue = $this->makeVenue('Alpha');
        $this->placeSession($version, $venue, 2, '18:30'); // mardi 18:30–20:00
        $this->occupancy($venue, 2, '18:00', 60, 'Sous la séance'); // 18:00–19:00 : partage 18:30–19:00
        $this->window($venue, 3, '18:00', null); // mercredi 18:00–19:30, fenêtre de la couche
        $this->occupancy($venue, 3, '19:00', 60, 'Sous le créneau'); // 19:00–20:00 : partage 19:00–19:30
        $this->occupancy($venue, 4, '14:00', 135, 'Libre'); // témoin positif

        $data = $this->provider->load($version, null, true);
        self::assertSame(['Libre'], $this->labels($data), 'seule l\'occupation qui ne partage aucun temps est rendue');

        $html = $this->pdfHtml($version, $data);
        self::assertStringNotContainsString('Sous la séance', $html, 'la case montre la séance, pas l\'occupation');
        self::assertStringNotContainsString('Sous le créneau', $html);
        self::assertContains('Occupation — Libre', $this->xlsxCells($this->spreadsheet()->generate($version, null, true)), 'Excel : « Occupation — <motif> » dans la colonne Équipe');
        self::assertNotContains('Occupation — Sous la séance', $this->xlsxCells($this->spreadsheet()->generate($version, null, true)));
    }

    /** Une occupation seule un jour crée la colonne de ce jour pour son gymnase et étire l'axe des heures jusqu'à elle. */
    public function testAnOccupancyAloneOnADayOpensItsColumnAndStretchesTheTimeAxis(): void
    {
        $version = $this->seasonVersion();
        $venue = $this->makeVenue('Alpha');
        $this->placeSession($version, $venue, 4, '18:00'); // jeudi seulement
        $this->occupancy($venue, 1, '07:00', 60, 'Ménage'); // lundi 07:00–08:00

        $without = $this->pdfHtml($version, $this->provider->load($version));
        self::assertStringNotContainsString('>Lundi<', $without, 'décor : sans l\'option, aucune colonne du lundi');
        self::assertStringNotContainsString('<th class="time">07:00</th>', $without, 'décor : sans l\'option, l\'axe commence à la séance');

        $with = $this->pdfHtml($version, $this->provider->load($version, null, true));
        self::assertStringContainsString('>Lundi<', $with, 'l\'occupation crée la colonne de son jour');
        self::assertStringContainsString('<th class="time">07:00</th>', $with, 'l\'axe des heures englobe l\'occupation');
        self::assertStringContainsString('Ménage', $with, 'la case porte le motif');
    }

    /** Une occupation dont le gymnase n'existe plus (dormante, version chargée sans lui) est absente de l'export. */
    public function testADormantOccupancyIsAbsent(): void
    {
        $version = $this->seasonVersion();
        $venue = $this->makeVenue('Alpha');
        $this->placeSession($version, $venue, 4, '18:00');
        $this->occupancy($venue, 2, '14:00', 135, 'École');
        $this->occupancyOnVenueId('00000000-0000-4000-8000-0000000d0e11', 2, '14:00', 135, 'Dormante');

        self::assertSame(['École'], $this->labels($this->provider->load($version, null, true)));
    }

    /** Abus : un motif en balise sort échappé dans le PDF, jamais comme balise vivante. */
    public function testAScriptLabelIsEscapedInThePdf(): void
    {
        $version = $this->seasonVersion();
        $venue = $this->makeVenue('Alpha');
        $this->placeSession($version, $venue, 4, '18:00');
        $this->occupancy($venue, 2, '14:00', 135, '<script>pwn()</script>');

        $html = $this->pdfHtml($version, $this->provider->load($version, null, true));

        self::assertStringContainsString('&lt;script&gt;pwn()&lt;/script&gt;', $html, 'le motif paraît, échappé');
        self::assertStringNotContainsString('<script>pwn', $html, 'jamais de balise vivante');
    }

    /** Abus : un motif en formule reste du texte dans Excel. */
    public function testAFormulaLabelStaysTextInExcel(): void
    {
        $version = $this->seasonVersion();
        $venue = $this->makeVenue('Alpha');
        $this->placeSession($version, $venue, 4, '18:00');
        $formula = '=HYPERLINK("http://example.test","clic")';
        $this->occupancy($venue, 2, '14:00', 135, $formula);

        $path = $this->writeTemp($this->spreadsheet()->generate($version, null, true));
        $found = false;
        foreach (IOFactory::load($path)->getAllSheets() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    if (str_contains((string) $cell->getValue(), 'HYPERLINK')) {
                        $found = true;
                        self::assertSame(DataType::TYPE_STRING, $cell->getDataType(), 'la cellule est du texte, jamais une formule');
                    }
                }
            }
        }
        @unlink($path);
        self::assertTrue($found, 'témoin : le motif est bien dans le classeur');
    }

    /** Le nom du PDF porte `-occupations` quand l'option est cochée (jeton que le polling compare), et la route de téléchargement l'accepte. */
    public function testThePdfFilenameCarriesTheOccupationsSuffixAndTheDownloadRouteAcceptsIt(): void
    {
        $version = $this->seasonVersion();
        $generator = $this->pdfGenerator();

        $both = $generator->renderPending($version, null, true, true)['url'];
        $only = $generator->renderPending($version, null, false, true)['url'];
        $none = $generator->renderPending($version, null, false, false)['url'];

        self::assertStringEndsWith('-all-coaches-occupations.pdf', $both, '-occupations vient après -coaches');
        self::assertStringEndsWith('-all-occupations.pdf', $only);
        self::assertStringEndsWith('-all.pdf', $none, 'décochée : le nom d\'aujourd\'hui');

        $requirement = self::getContainer()->get(RouterInterface::class)->getRouteCollection()->get('api_schedule_export_pdf_download')?->getRequirement('filename');
        self::assertIsString($requirement);
        foreach ([$both, $only] as $url) {
            self::assertMatchesRegularExpression('#^' . $requirement . '$#', basename($url), 'la route de téléchargement accepte ' . basename($url));
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->provider = $container->get(ScheduleExportDataProvider::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('EXP Occupations');
        $this->club->setSlug('exp-occ-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('OCC' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('exp-occ-' . $uid . '@test.com');
        $this->user->setFirstName('E');
        $this->user->setLastName('X');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $membership = new ClubUser;
        $membership->setClubId($this->club->getId());
        $membership->setUserId($this->user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->provisionSeasonPlan($this->season);
    }

    private function seasonVersion(): Schedule
    {
        $version = new Schedule;
        $version->setClubId($this->club->getId());
        $version->setSeasonId($this->season->getId());
        $version->setName('Socle');
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version);
        $this->em->flush();

        return $version;
    }

    /**
     * Une version de PÉRIODE liée à son plan, créée AVANT tout gymnase (sa copie de la grille de
     * saison ne ramasse rien : on maîtrise la couche).
     *
     * @return array{0: Schedule, 1: string, 2: CalendarEntry}
     */
    private function periodVersion(string $start, string $end): array
    {
        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Période');
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        $version = new Schedule;
        $version->setClubId($this->club->getId());
        $version->setSeasonId($this->season->getId());
        $version->setName('Période V1');
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version, $entry->getId());
        $this->em->flush();

        return [$version, (string) $version->getSchedulePlanId(), $entry];
    }

    private function makeVenue(string $name): Venue
    {
        $venue = $this->newVenue($this->club->getId(), $this->season->getId(), $name)->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function window(Venue $venue, int $day, string $start, ?string $planId): void
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($this->club->getId());
        $slot->setSeasonId($this->season->getId());
        $slot->setSchedulePlanId($planId ?? self::getContainer()->get(SoclePlan::class)->idOf($this->season->getId()));
        $slot->setVenueId($venue->getId());
        $slot->setDayOfWeek($day);
        $slot->setStartTime(new DateTimeImmutable($start));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $this->em->persist($slot);
        $this->em->flush();
    }

    private function placeSession(Schedule $version, Venue $venue, int $day, string $start): void
    {
        $placed = new ScheduleSlotTemplate;
        $placed->setClubId($this->club->getId());
        $placed->setSeasonId($this->season->getId());
        $placed->setScheduleId($version->getId());
        $placed->setTeamId($this->uuid());
        $placed->setVenueId($venue->getId());
        $placed->setDayOfWeek($day);
        $placed->setStartTime(new DateTimeImmutable($start));
        $placed->setDurationMinutes(90);
        $placed->setLockLevel(LockLevel::NONE);
        $this->em->persist($placed);
        $this->em->flush();
    }

    private function occupancy(Venue $venue, int $day, string $start, int $duration, string $label, ?string $planId = null): void
    {
        $this->occupancyOnVenueId($venue->getId(), $day, $start, $duration, $label, $planId);
    }

    /** Une occupation écrite en base (colonnes du plan §3) ; un `venueId` inconnu fait une dormante. */
    private function occupancyOnVenueId(string $venueId, int $day, string $start, int $duration, string $label, ?string $planId = null): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->getConnection()->executeStatement(
            'INSERT INTO venue_occupancy (id, version, created_at, updated_at, club_id, season_id, venue_id, day_of_week, start_time, duration_minutes, label, schedule_plan_id)'
            . ' VALUES (:id, 1, now(), now(), :club, :season, :venue, :day, :start, :duration, :label, :plan)',
            ['id' => $this->uuid(), 'club' => $this->club->getId(), 'season' => $this->season->getId(), 'venue' => $venueId, 'day' => $day, 'start' => $start . ':00', 'duration' => $duration, 'label' => $label, 'plan' => $planId ?? self::getContainer()->get(SoclePlan::class)->idOf($this->season->getId())],
        );
    }

    /** @return list<string> les motifs des occupations chargées pour l'export */
    private function labels(ScheduleExportData $data): array
    {
        $labels = array_map(static fn (ExportReservedWindow $w): string => $w->label, $data->occupancies);
        sort($labels);

        return $labels;
    }

    /** @return list<string> les gymnases des occupations chargées */
    private function venueIds(ScheduleExportData $data): array
    {
        return array_values(array_unique(array_map(static fn (ExportReservedWindow $w): string => $w->venueId, $data->occupancies)));
    }

    /** Le HTML que le worker photographie, construit par le vrai générateur (seam privé lu par réflexion). */
    private function pdfHtml(Schedule $version, ScheduleExportData $data): string
    {
        return (string) new ReflectionMethod(PdfGenerator::class, 'buildHtml')->invoke($this->pdfGenerator(), $version, $data, null, false, false);
    }

    private function pdfGenerator(): PdfGenerator
    {
        $container = self::getContainer();

        return new PdfGenerator(
            $this->em,
            new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode(['success' => true]))),
            $this->provider,
            $container->get(SchedulePlanProvisioner::class),
            $container->get(LogoStorage::class),
            $container->get(BrandAssets::class),
            $container->get(ProductIdentity::class),
        );
    }

    private function spreadsheet(): SpreadsheetGenerator
    {
        return self::getContainer()->get(SpreadsheetGenerator::class);
    }

    /** @return list<string> toutes les cellules non vides du classeur (toutes feuilles) */
    private function xlsxCells(string $binary): array
    {
        $path = $this->writeTemp($binary);
        $cells = [];
        foreach (IOFactory::load($path)->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $row) {
                foreach ($row as $value) {
                    if (null !== $value && '' !== $value) {
                        $cells[] = (string) $value;
                    }
                }
            }
        }
        @unlink($path);

        return $cells;
    }

    /** @return list<string> les cellules du classeur rendu par la route d'export Excel */
    private function exportXlsx(string $token, string $versionId, string $body): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $this->client->request('POST', '/api/schedules/' . $versionId . '/export-xlsx', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], $body);
        self::assertResponseStatusCodeSame(200, 'l\'export Excel aboutit');

        return $this->xlsxCells((string) $this->client->getResponse()->getContent());
    }

    private function writeTemp(string $binary): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'occ') . '.xlsx';
        file_put_contents($path, $binary);

        return $path;
    }

    private function uuid(): string
    {
        return \sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0x8000, 0xBFFF), random_int(0, 0xFFFFFFFFFFFF));
    }
}
