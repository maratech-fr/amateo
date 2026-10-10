<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Export\ScheduleExportDataProvider;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les « créneaux vides » d'un export (PDF/XLSX) viennent de la COUCHE de la version
 * exportée, et d'elle seule.
 *
 * #8 — depuis qu'une période possède sa grille (copie du modèle de saison à la naissance
 * du plan), le même créneau existe autant de fois en base qu'il y a de plans. L'export
 * lisait tous les créneaux du club/saison sans distinction : un club à trois périodes
 * voyait chaque créneau vide répété quatre fois sur son planning de saison.
 */
#[Group('integration')]
final class ScheduleExportEmptySlotsTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleExportDataProvider $provider;

    public function testEachLayerExportsItsOwnEmptySlotsOnly(): void
    {
        [$club, $season] = $this->seed();
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Barros');
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);

        // Un créneau de saison, vide (aucune séance placée dessus).
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venue->getId());
        $slot->setDayOfWeek(3);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $this->em->persist($slot);
        $this->em->flush();

        $seasonVersion = new Schedule;
        $seasonVersion->setClubId($club->getId());
        $seasonVersion->setSeasonId($season->getId());
        $seasonVersion->setName('Socle');
        $seasonVersion->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($seasonVersion);
        $this->em->flush();

        // Le geste « Adapter » : la période naît avec SA copie du même créneau.
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Toussaint');
        $entry->setStartDate(new DateTimeImmutable('+1 month'));
        $entry->setEndDate(new DateTimeImmutable('+1 month +7 days'));
        $this->em->persist($entry);
        $this->em->flush();
        $periodVersion = new Schedule;
        $periodVersion->setClubId($club->getId());
        $periodVersion->setSeasonId($season->getId());
        $periodVersion->setName('Toussaint V1');
        $periodVersion->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($periodVersion, $entry->getId());
        $this->em->flush();
        self::assertCount(
            2,
            $this->em->getRepository(VenueTrainingSlot::class)->findBy(['venueId' => $venue->getId()]),
            'le décor du bug : le même créneau existe deux fois en base, un par couche',
        );

        // D7 (lot 2) — le gymnase doit être UTILISÉ (≥ 1 séance placée) pour que sa fenêtre vide
        // subsiste à l'export d'ensemble ; sinon il disparaît. On place une séance (jeu. 20:00,
        // hors de la fenêtre vide du mer. 18:00) sur CHAQUE version, pour que la garde anti-doublon
        // #8 reste observable sous le filtre D7.
        foreach ([$seasonVersion, $periodVersion] as $version) {
            $placed = new ScheduleSlotTemplate;
            $placed->setClubId($club->getId());
            $placed->setSeasonId($season->getId());
            $placed->setScheduleId($version->getId());
            $placed->setTeamId($this->uuid());
            $placed->setVenueId($venue->getId());
            $placed->setDayOfWeek(4);
            $placed->setStartTime(new DateTimeImmutable('20:00'));
            $placed->setDurationMinutes(90);
            $placed->setLockLevel(LockLevel::NONE);
            $this->em->persist($placed);
        }
        $this->em->flush();

        // Chaque planning n'affiche QUE le sien — une fois, pas deux.
        self::assertCount(1, $this->provider->load($seasonVersion)->emptySlots, 'le planning de saison montre son créneau vide une seule fois');
        self::assertCount(1, $this->provider->load($periodVersion)->emptySlots, 'la période montre le sien, pas celui du socle en plus');
    }

    /**
     * D7 (lot 2) — à l'export d'ensemble, un gymnase SANS aucune séance placée DISPARAÎT (ses
     * fenêtres « vide » n'encombrent plus la grille) ; un gymnase utilisé garde, lui, ses cellules
     * « vide ». L'export d'UN seul gymnase (`?venueId=`) n'applique pas ce filtre.
     */
    public function testUnusedVenueVanishesFromExportButItsOwnExportKeepsIt(): void
    {
        [$club, $season] = $this->seed();

        // Gymnase A — DEUX fenêtres (socle), dont une recevra une séance → gymnase UTILISÉ.
        $venueA = $this->makeVenue($club, $season, 'Alpha');
        $this->makeWindow($club, $season, $venueA, 3, '18:00'); // sera remplie
        $this->makeWindow($club, $season, $venueA, 4, '18:00'); // restera vide
        // Gymnase B — UNE fenêtre, jamais remplie → gymnase ENTIÈREMENT vide.
        $venueB = $this->makeVenue($club, $season, 'Beta');
        $this->makeWindow($club, $season, $venueB, 3, '18:00');
        $this->em->flush();

        $seasonVersion = new Schedule;
        $seasonVersion->setClubId($club->getId());
        $seasonVersion->setSeasonId($season->getId());
        $seasonVersion->setName('Socle');
        $seasonVersion->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($seasonVersion);
        $this->em->flush();

        // Une séance placée sur le gymnase A (mer. 18:00) → A est « utilisé », sa fenêtre du jeudi
        // reste « vide ».
        $placed = new ScheduleSlotTemplate;
        $placed->setClubId($club->getId());
        $placed->setSeasonId($season->getId());
        $placed->setScheduleId($seasonVersion->getId());
        $placed->setTeamId($this->uuid());
        $placed->setVenueId($venueA->getId());
        $placed->setDayOfWeek(3);
        $placed->setStartTime(new DateTimeImmutable('18:00'));
        $placed->setDurationMinutes(90);
        $placed->setLockLevel(LockLevel::NONE);
        $this->em->persist($placed);
        $this->em->flush();

        // Export d'ensemble : SEUL le gymnase A (utilisé) garde sa fenêtre vide (jeu. 18:00) ;
        // le gymnase B, entièrement vide, disparaît.
        $empty = $this->provider->load($seasonVersion)->emptySlots;
        self::assertCount(1, $empty, 'seule la fenêtre vide du gymnase UTILISÉ subsiste');
        self::assertSame($venueA->getId(), $empty[0]->venueId, 'et c\'est bien celle du gymnase A');

        // Export du SEUL gymnase B : on garde toute sa grille, vides compris (pas de filtre).
        $soloB = $this->provider->load($seasonVersion, $venueB->getId())->emptySlots;
        self::assertCount(1, $soloB, 'l\'export mono-gymnase d\'un gymnase vide garde sa grille');
        self::assertSame($venueB->getId(), $soloB[0]->venueId);
    }

    /**
     * Lot 9 (B2) — le NOM d'un bloc de mutualisation PRIME sur le libellé de la fenêtre : une case
     * portant des séances de groupe LIÉES à un bloc nommé affiche le nom du bloc à l'export.
     */
    public function testBlockLabelPrimesOverWindowGroupLabel(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->makeVenue($club, $season, 'Barros');

        // La fenêtre à la case porte DÉJÀ un libellé de créneau.
        $window = new VenueTrainingSlot;
        $window->setClubId($club->getId());
        $window->setSeasonId($season->getId());
        $window->setVenueId($venue->getId());
        $window->setDayOfWeek(3);
        $window->setStartTime(new DateTimeImmutable('18:00'));
        $window->setDurationMinutes(90);
        $window->setCapacity(2);
        $window->setGroupLabel('CEC3');
        $this->em->persist($window);
        $this->em->flush();

        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setName('Socle');
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version);
        $this->em->flush();

        // Un bloc NOMMÉ + deux séances de groupe LIÉES, co-localisées sur la case.
        $t1 = $this->uuid();
        $t2 = $this->uuid();
        $block = new SharedTrainingBlock;
        $block->setClubId($club->getId());
        $block->setSeasonId($season->getId());
        $block->setSchedulePlanId(null);
        $block->setCommonSessions(1);
        $block->setLabel('Baby U7-U9');
        $this->em->persist($block);
        foreach ([$t1, $t2] as $teamId) {
            $member = new SharedTrainingBlockTeam;
            $member->setClubId($club->getId());
            $member->setSeasonId($season->getId());
            $member->setSchedulePlanId(null);
            $member->setBlockId($block->getId());
            $member->setTeamId($teamId);
            $this->em->persist($member);
        }
        $this->em->flush();

        foreach ([$t1, $t2] as $teamId) {
            $slot = new ScheduleSlotTemplate;
            $slot->setClubId($club->getId());
            $slot->setSeasonId($season->getId());
            $slot->setScheduleId($version->getId());
            $slot->setTeamId($teamId);
            $slot->setVenueId($venue->getId());
            $slot->setDayOfWeek(3);
            $slot->setStartTime(new DateTimeImmutable('18:00'));
            $slot->setDurationMinutes(90);
            $slot->setLockLevel(LockLevel::HARD);
            $slot->setSharedTrainingBlockId($block->getId());
            $this->em->persist($slot);
        }
        $this->em->flush();

        $groupLabels = $this->provider->load($version)->groupLabels;
        self::assertSame('Baby U7-U9', $groupLabels[$venue->getId() . '|3|18:00'] ?? null, 'le nom du bloc prime sur le libellé de la fenêtre');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->provider = self::getContainer()->get(ScheduleExportDataProvider::class);
    }

    private function makeVenue(Club $club, Season $season, string $name): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);

        return $venue;
    }

    private function makeWindow(Club $club, Season $season, Venue $venue, int $day, string $start): void
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venue->getId());
        $slot->setDayOfWeek($day);
        $slot->setStartTime(new DateTimeImmutable($start));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $this->em->persist($slot);
    }

    private function uuid(): string
    {
        return \sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0x8000, 0xBFFF), random_int(0, 0xFFFFFFFFFFFF));
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('EXP Club');
        $club->setSlug('exp-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('EXP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('exp-' . $uid . '@test.com');
        $user->setFirstName('E');
        $user->setLastName('X');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
