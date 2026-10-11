<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
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
use App\Export\ExportEmptyWindow;
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

    /**
     * Lot 3a — bug prod BCCL : la grille d'une période est RETAILLÉE après la génération, donc une
     * séance VERROUILLÉE garde un début qui ne coïncide plus avec celui de la fenêtre. Un créneau
     * compte comme occupé dès qu'une séance du même gymnase+jour le CHEVAUCHE (règle partagée avec
     * la grille à l'écran — App\Service\VenueWindowOccupancy) : l'export ne doit plus poser une case
     * « vide » à côté de la séance décalée. Une fenêtre NON chevauchée du même gymnase reste vide
     * (pas de sur-remplissage).
     */
    public function testRetailoredWindowIsNotEmptyWhenAnOverlappingLockedSessionCoversIt(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->makeVenue($club, $season, 'Armand');

        // La fenêtre RETAILLÉE : mer. 17:30-18:45 (75 min) — son début ne coïncide plus avec la séance.
        $retailored = new VenueTrainingSlot;
        $retailored->setClubId($club->getId());
        $retailored->setSeasonId($season->getId());
        $retailored->setVenueId($venue->getId());
        $retailored->setDayOfWeek(3);
        $retailored->setStartTime(new DateTimeImmutable('17:30'));
        $retailored->setDurationMinutes(75);
        $retailored->setCapacity(1);
        $this->em->persist($retailored);

        // Une AUTRE fenêtre du même gymnase, jamais chevauchée (jeu. 20:00) → doit RESTER vide.
        $this->makeWindow($club, $season, $venue, 4, '20:00');
        $this->em->flush();

        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setName('Socle');
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version);
        $this->em->flush();

        // La séance VERROUILLÉE 17:15-18:45 (90 min) chevauche la fenêtre 17:30-18:45 : « coupé en deux » du bug.
        $placed = new ScheduleSlotTemplate;
        $placed->setClubId($club->getId());
        $placed->setSeasonId($season->getId());
        $placed->setScheduleId($version->getId());
        $placed->setTeamId($this->uuid());
        $placed->setVenueId($venue->getId());
        $placed->setDayOfWeek(3);
        $placed->setStartTime(new DateTimeImmutable('17:15'));
        $placed->setDurationMinutes(90);
        $placed->setLockLevel(LockLevel::HARD);
        $this->em->persist($placed);
        $this->em->flush();

        $empty = $this->provider->load($version)->emptySlots;
        // La fenêtre 17:30 est occupée par chevauchement → PLUS de case « vide » à côté de la séance 17:15.
        self::assertCount(1, $empty, 'seule la fenêtre non chevauchée (jeu. 20:00) reste vide ; la fenêtre 17:30 chevauchée disparaît');
        self::assertSame(4, $empty[0]->dayOfWeek, 'la case vide restante est bien la fenêtre du jeudi, pas la fenêtre retaillée du mercredi');
    }

    /**
     * Lot B — (a)+(b) : à l'export d'ENSEMBLE, un gymnase DÉSACTIVÉ pour la période perd ses
     * fenêtres vides (comme un gymnase jamais utilisé), MAIS ses séances déjà placées restent
     * (l'export ne nie pas ce qui existe).
     */
    public function testDisabledVenueDropsItsEmptyWindowsButKeepsItsPlacedSessions(): void
    {
        [$club, $season] = $this->seed();
        // Le plan de période est créé AVANT les gymnases : sa copie des créneaux du socle (à la
        // naissance du plan) ne ramasse rien, on maîtrise exactement les fenêtres de la couche.
        [$version, $planId] = $this->seedPeriodVersion($club, $season, '2026-05-04', '2026-05-10');
        $disabled = $this->makeVenue($club, $season, 'Désactivé');
        $control = $this->makeVenue($club, $season, 'Ouvert');
        // Une fenêtre vide (jeu. 18:00) sur la couche de la période, pour les DEUX gymnases.
        $this->layerWindow($club, $season, $disabled, 4, '18:00', $planId);
        $this->layerWindow($club, $season, $control, 4, '18:00', $planId);
        $this->em->flush();
        // Une séance placée sur CHAQUE gymnase, hors de la fenêtre vide (mer. 20:00) → gymnases UTILISÉS
        // (sinon le filtre #1142 les escamoterait déjà, et (a) passerait pour la mauvaise raison).
        $this->placeSession($club, $season, $version, $disabled, 3, '20:00');
        $this->placeSession($club, $season, $version, $control, 3, '20:00');
        $this->disableVenue($club, $season, $planId, $disabled);
        $this->em->flush();

        $data = $this->provider->load($version);

        // (a) le gymnase désactivé ne porte plus AUCUNE fenêtre vide ; le gymnase ouvert garde la sienne.
        self::assertSame(
            [$control->getId()],
            array_map(static fn (ExportEmptyWindow $w): string => $w->venueId, $data->emptySlots),
            'un gymnase désactivé perd ses fenêtres vides à l’ensemble ; le gymnase ouvert garde la sienne',
        );
        // (b) ses séances placées, elles, restent — l'export rend ce qui existe.
        self::assertContains(
            $disabled->getId(),
            array_map(static fn (ScheduleSlotTemplate $s): string => $s->getVenueId(), $data->slots),
            'la séance placée dans le gymnase désactivé reste à l’export',
        );
    }

    /**
     * Lot B — (c) : un gymnase EFFECTIVEMENT fermé-total (fermeture `venue_closed` couvrant toute la
     * fenêtre du plan) perd lui aussi ses fenêtres vides à l'ensemble.
     */
    public function testEffectivelyFullyClosedVenueDropsItsEmptyWindows(): void
    {
        [$club, $season] = $this->seed();
        // Fenêtre d'UN seul jour (mer. 2026-05-06 = jour 3) : la couvrir d'un coup ferme TOUT le plan.
        [$version, $planId, $entry] = $this->seedPeriodVersion($club, $season, '2026-05-06', '2026-05-06');
        $closed = $this->makeVenue($club, $season, 'Fermé');
        $control = $this->makeVenue($club, $season, 'Ouvert');
        $this->layerWindow($club, $season, $closed, 3, '18:00', $planId);
        $this->layerWindow($club, $season, $control, 3, '18:00', $planId);
        $this->em->flush();
        $this->placeSession($club, $season, $version, $closed, 3, '20:00');
        $this->placeSession($club, $season, $version, $control, 3, '20:00');
        $this->closeVenue($club, $season, $entry, $closed, '2026-05-06', '2026-05-06');
        $this->em->flush();

        self::assertSame(
            [$control->getId()],
            array_map(static fn (ExportEmptyWindow $w): string => $w->venueId, $this->provider->load($version)->emptySlots),
            'un gymnase effectivement fermé-total perd ses fenêtres vides ; le gymnase ouvert garde la sienne',
        );
    }

    /**
     * Lot B — (d) : l'exclusion ne vaut QU'À l'ensemble. L'export MONO-gymnase d'un gymnase désactivé
     * garde toute sa grille, vides compris (on consulte alors sa grille à lui).
     */
    public function testSingleVenueExportOfADisabledVenueKeepsItsFullGrid(): void
    {
        [$club, $season] = $this->seed();
        [$version, $planId] = $this->seedPeriodVersion($club, $season, '2026-05-04', '2026-05-10');
        $disabled = $this->makeVenue($club, $season, 'Désactivé');
        $this->layerWindow($club, $season, $disabled, 4, '18:00', $planId);
        $this->em->flush();
        $this->disableVenue($club, $season, $planId, $disabled);
        $this->em->flush();

        // Ensemble : le gymnase désactivé n'offre aucune fenêtre vide.
        self::assertCount(0, $this->provider->load($version)->emptySlots, 'à l’ensemble, le gymnase désactivé n’offre aucune fenêtre vide');
        // Mono-gymnase du désactivé : toute sa grille, vides compris (pas de filtre d'ensemble).
        $solo = $this->provider->load($version, $disabled->getId())->emptySlots;
        self::assertCount(1, $solo, 'l’export mono-gymnase d’un gymnase désactivé garde sa grille');
        self::assertSame($disabled->getId(), $solo[0]->venueId);
    }

    /**
     * Lot B — (e) : le filtre #1142 (un gymnase JAMAIS utilisé disparaît) reste en vigueur sur la
     * couche de la période, à côté de l'exclusion des gymnases fermés — aucune régression.
     */
    public function testUnusedVenueFilterStillAppliesOnThePeriodLayer(): void
    {
        [$club, $season] = $this->seed();
        [$version, $planId] = $this->seedPeriodVersion($club, $season, '2026-05-04', '2026-05-10');
        $unused = $this->makeVenue($club, $season, 'Jamais utilisé'); // ni séance ni désactivation
        $used = $this->makeVenue($club, $season, 'Utilisé');
        $this->layerWindow($club, $season, $unused, 4, '18:00', $planId);
        $this->layerWindow($club, $season, $used, 4, '18:00', $planId);
        $this->em->flush();
        $this->placeSession($club, $season, $version, $used, 3, '20:00'); // seul $used est utilisé
        $this->em->flush();

        self::assertSame(
            [$used->getId()],
            array_map(static fn (ExportEmptyWindow $w): string => $w->venueId, $this->provider->load($version)->emptySlots),
            'le filtre #1142 (gymnase jamais utilisé) reste en vigueur à côté de l’exclusion des fermés',
        );
    }

    /**
     * Lot B — (f) : un jour simplement ROUVERT à la main (masque OPEN) sur un gymnase fermé par un
     * incident ne le rend ni désactivé ni fermé-total : ses fenêtres vides SUBSISTENT (on n'exclut
     * que le désactivé et le fermé-total effectif, pas le partiellement fermé).
     */
    public function testVenueWithAManuallyReopenedDayKeepsItsEmptyWindows(): void
    {
        [$club, $season] = $this->seed();
        [$version, $planId, $entry] = $this->seedPeriodVersion($club, $season, '2026-05-04', '2026-05-10');
        $venue = $this->makeVenue($club, $season, 'Partiellement fermé');
        $this->layerWindow($club, $season, $venue, 3, '18:00', $planId); // fenêtre vide le mercredi
        $this->em->flush();
        $this->placeSession($club, $season, $version, $venue, 3, '20:00'); // utilisé
        // Un incident ferme le mercredi, MAIS le gestionnaire le ROUVRE à la main (masque OPEN).
        $this->closeVenue($club, $season, $entry, $venue, '2026-05-06', '2026-05-06');
        $this->reopenDay($club, $season, $planId, $venue, 3);
        $this->em->flush();

        self::assertSame(
            [$venue->getId()],
            array_map(static fn (ExportEmptyWindow $w): string => $w->venueId, $this->provider->load($version)->emptySlots),
            'un jour rouvert à la main garde les fenêtres vides (ni désactivé ni fermé-total)',
        );
    }

    /**
     * Lot B (NR — la couche vient de la VERSION, jamais du client). `load(Schedule, ?venueId)` n'a
     * aucun paramètre de couche : elle se dérive du schedule. Même gymnase, même fenêtre vide clonée
     * dans les deux couches — le socle la garde (sa couche n'a pas de fermeture), la période la perd
     * (sa couche désactive le gymnase).
     */
    public function testExportLayerComesFromTheScheduleNeverFromTheClient(): void
    {
        [$club, $season] = $this->seed();
        [$periodVersion, $planId] = $this->seedPeriodVersion($club, $season, '2026-05-04', '2026-05-10');
        $venue = $this->makeVenue($club, $season, 'Armand');
        // Même fenêtre vide (jeu. 18:00) dans CHAQUE couche : socle (schedulePlanId NULL) et période.
        $this->layerWindow($club, $season, $venue, 4, '18:00', null);
        $this->layerWindow($club, $season, $venue, 4, '18:00', $planId);
        $this->em->flush();

        $seasonVersion = new Schedule;
        $seasonVersion->setClubId($club->getId());
        $seasonVersion->setSeasonId($season->getId());
        $seasonVersion->setName('Socle');
        $seasonVersion->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($seasonVersion);
        $this->em->flush();

        // Gymnase UTILISÉ dans les deux couches (jeu. 20:00, hors de la fenêtre vide).
        $this->placeSession($club, $season, $seasonVersion, $venue, 3, '20:00');
        $this->placeSession($club, $season, $periodVersion, $venue, 3, '20:00');
        $this->disableVenue($club, $season, $planId, $venue); // désactivé POUR LA PÉRIODE seulement
        $this->em->flush();

        self::assertCount(1, $this->provider->load($seasonVersion)->emptySlots, 'le socle lit SA couche : aucune fermeture, la fenêtre vide reste');
        self::assertCount(0, $this->provider->load($periodVersion)->emptySlots, 'la période lit SA couche : gymnase désactivé, la fenêtre vide disparaît');
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

    /**
     * Une version de PÉRIODE liée à son plan (comme la prod au POST) : on la crée AVANT tout gymnase,
     * pour que la copie des créneaux du socle à la naissance du plan ne ramasse rien.
     *
     * @return array{0: Schedule, 1: string, 2: CalendarEntry}
     */
    private function seedPeriodVersion(Club $club, Season $season, string $start, string $end): array
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Période');
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setName('Période V1');
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version, $entry->getId());
        $this->em->flush();

        return [$version, $version->getSchedulePlanId(), $entry];
    }

    /** Une fenêtre (vide) sur une COUCHE donnée (schedulePlanId null = socle, l'id = période). */
    private function layerWindow(Club $club, Season $season, Venue $venue, int $day, string $start, ?string $planId): void
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setSchedulePlanId($planId);
        $slot->setVenueId($venue->getId());
        $slot->setDayOfWeek($day);
        $slot->setStartTime(new DateTimeImmutable($start));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $this->em->persist($slot);
    }

    /** Une séance placée (sur la version) — rend le gymnase « utilisé ». */
    private function placeSession(Club $club, Season $season, Schedule $version, Venue $venue, int $day, string $start): void
    {
        $placed = new ScheduleSlotTemplate;
        $placed->setClubId($club->getId());
        $placed->setSeasonId($season->getId());
        $placed->setScheduleId($version->getId());
        $placed->setTeamId($this->uuid());
        $placed->setVenueId($venue->getId());
        $placed->setDayOfWeek($day);
        $placed->setStartTime(new DateTimeImmutable($start));
        $placed->setDurationMinutes(90);
        $placed->setLockLevel(LockLevel::NONE);
        $this->em->persist($placed);
    }

    /** Désactive un gymnase pour le plan (mode DISABLED). */
    private function disableVenue(Club $club, Season $season, string $planId, Venue $venue): void
    {
        $this->em->persist(
            (new VenuePeriodOverride)
                ->setClubId($club->getId())->setSeasonId($season->getId())
                ->setSchedulePlanId($planId)->setVenueId($venue->getId())
                ->setMode(VenuePeriodMode::DISABLED)->setDayOverrides(null),
        );
    }

    /** Rouvre un jour à la main (masque OPEN), sans mode — l'incident redevient informatif ce jour-là. */
    private function reopenDay(Club $club, Season $season, string $planId, Venue $venue, int $day): void
    {
        $this->em->persist(
            (new VenuePeriodOverride)
                ->setClubId($club->getId())->setSeasonId($season->getId())
                ->setSchedulePlanId($planId)->setVenueId($venue->getId())
                ->setMode(null)->setDayOverrides([$day => 'OPEN']),
        );
    }

    /** Une fermeture datée `venue_closed` portée par l'entrée, pour un gymnase. */
    private function closeVenue(Club $club, Season $season, CalendarEntry $carrier, Venue $venue, string $start, string $end): void
    {
        $constraint = (new Constraint)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Gym en travaux')
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId($venue->getId())
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($carrier->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end]);
        $this->em->persist($constraint);
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
