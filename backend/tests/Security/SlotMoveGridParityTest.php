<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\VenueDayState;
use App\Exception\EvictTargetMismatchException;
use App\Exception\SlotUnavailableException;
use App\Service\ClubGenerationLock;
use App\Service\EngineClient;
use App\Service\MoveSlotService;
use App\Service\RequestIdContext;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * NR — résidu de P2-43, axes *planning lifecycle* ET *constraint semantics* : LA GARDE PRÉCOCE
 * DE `move()` sur la GRILLE de gymnase.
 *
 * LA RÈGLE (mots du fondateur) : « On donne des emplacements au solveur avec l'écran Gymnases,
 * le solveur y place des équipes (comme avec une réservation), c'est tout. Les tailles de
 * créneau ne sont pas changeables sauf si on le fait côté Gymnases. » Un emplacement est défini
 * à l'écran Gymnases, et lui seul dit sa taille — une affectation ne porte JAMAIS une durée qui
 * lui serait propre ; déplacer, c'est poser l'équipe dans UN emplacement, qui lui impose la
 * sienne. La fusion de deux créneaux en un bloc de 120 est un comportement d'ÉMISSION du solveur,
 * pas une propriété de l'équipe : ré-atterrir sur une fenêtre de 60 prend 60.
 *
 * L'invariant gardé ici (le moteur est MOQUÉ — un compteur de requêtes prouve qu'il n'est PAS
 * consulté sous un refus pré-moteur) : **ce que la grille OFFRE, le serveur l'ACCEPTE ; ce
 * qu'elle n'offre pas, il le REFUSE sans appeler le moteur ; la durée écrite est TOUJOURS celle
 * de l'emplacement.** Sept cas, falsifiés dans les deux sens, saison ET période :
 *  1. fenêtre 60 à la cible, source 90, moteur ACCEPT → durée persistée = 60 (la falsification
 *     centrale : rougit si la durée voyage avec l'équipe) ;
 *  2. triplet sans fenêtre → refus, 0 requête moteur, ligne intacte ;
 *  3. période, jour FERMÉ sur la cible → fenêtre absente du payload → refus sans moteur (grain
 *     JOUR) ;
 *  4. période, jour NON fermé → le moteur EST consulté (la garde ne sur-refuse pas) ;
 *  5. multi-blocs : source 120 → fenêtre 60 → durée écrite 60 ;
 *  6. éviction : occupant chevauchant sous la durée SOURCE mais pas sous la durée FENÊTRE →
 *     evict_target_mismatch pré-moteur ;
 *  7. dryRun vers une fenêtre inexistante → refus rapide pré-moteur, rien écrit.
 */
#[Group('phase1')]
#[Group('integration')]
final class SlotMoveGridParityTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string ACCEPT = '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    private EntityManagerInterface $em;

    // ── 1. La durée écrite est celle de la FENÊTRE, pas de la source ────────────────────────────

    /** Fenêtre 60 à la cible, source 90, moteur ACCEPT → la ligne persistée porte 60, jamais 90. */
    public function testAcceptedMovePersistsWindowDurationNotSourceDuration(): void
    {
        $ctx = $this->seedSeason(sourceDuration: 90, windowDuration: 60);
        $slot = $ctx['slot'];

        $requests = 0;
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$captured): MockResponse {
            ++$requests;
            $captured = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse(self::ACCEPT, ['http_code' => 200]);
        });

        $result = $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertTrue($result['valid']);
        self::assertSame(1, $requests, 'une fenêtre existe : le moteur EST consulté');
        // Le candidat émis au moteur porte déjà la durée de la fenêtre (60), pas celle de la source (90).
        self::assertIsArray($captured);
        self::assertSame(60, $captured['candidates'][0]['durationMinutes'], 'le candidat émis prend la durée de la FENÊTRE');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame($ctx['venue2'], $reloaded->getVenueId());
        self::assertSame(4, $reloaded->getDayOfWeek());
        self::assertSame('20:00', $reloaded->getStartTime()->format('H:i'));
        self::assertSame(60, $reloaded->getDurationMinutes(), 'la durée persistée est celle de l\'emplacement (60), jamais celle qui voyageait (90)');
    }

    // ── 2. Pas de fenêtre → refus SANS moteur, ligne intacte ────────────────────────────────────

    /** Un triplet sans fenêtre (jour sans emplacement) → SlotUnavailable, 0 requête, ligne inchangée. */
    public function testMoveToTripletWithoutWindowRefusesWithoutEngineAndLeavesRowIntact(): void
    {
        $ctx = $this->seedSeason(sourceDuration: 90, windowDuration: 60);
        $slot = $ctx['slot'];

        $requests = 0;
        $client = $this->countingClient($requests);

        // La fenêtre est seedée jeudi(4) 20:00 ; on vise vendredi(5) 20:00 : aucun emplacement là.
        try {
            $this->service($client)->move($slot, 5, new DateTimeImmutable('20:00'), $ctx['venue2']);
            self::fail('un déplacement vers un triplet sans fenêtre doit lever SlotUnavailableException');
        } catch (SlotUnavailableException) {
            // attendu
        }

        self::assertSame(0, $requests, 'aucune fenêtre : le moteur n\'est JAMAIS appelé');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        // La ligne source n'a pas bougé — ni jour, ni heure, ni gymnase, ni durée.
        self::assertSame($ctx['venue1'], $reloaded->getVenueId());
        self::assertSame(2, $reloaded->getDayOfWeek());
        self::assertSame('18:00', $reloaded->getStartTime()->format('H:i'));
        self::assertSame(90, $reloaded->getDurationMinutes());
    }

    // ── 3. Période, jour FERMÉ → fenêtre absente du payload → refus sans moteur ──────────────────

    /** Grain JOUR : la cible tombe sur un jour fermé (masque manuel) → fenêtre retirée → refus, 0 requête. */
    public function testPeriodClosedDayRefusesWithoutEngine(): void
    {
        $ctx = $this->seedPeriod();
        $slot = $ctx['slot'];

        $requests = 0;
        $client = $this->countingClient($requests);

        // Jeudi(4) est FERMÉ pour la période (masque manuel) : la fenêtre copiée du plan en sort.
        try {
            $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);
            self::fail('un jour fermé n\'offre aucune fenêtre : SlotUnavailableException attendue');
        } catch (SlotUnavailableException) {
            // attendu
        }

        self::assertSame(0, $requests, 'jour fermé : la fenêtre est absente du payload, le moteur n\'est pas appelé');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame(2, $reloaded->getDayOfWeek(), 'la source n\'a pas bougé');
    }

    // ── 4. Période, jour NON fermé → le moteur EST consulté (pas de sur-refus) ───────────────────

    /** Le jour vendredi(5) reste ouvert : sa fenêtre est dans le payload → le moteur EST consulté. */
    public function testPeriodOpenDayConsultsEngine(): void
    {
        $ctx = $this->seedPeriod();
        $slot = $ctx['slot'];

        $requests = 0;
        $client = $this->countingClient($requests, self::ACCEPT);

        $result = $this->service($client)->move($slot, 5, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertTrue($result['valid']);
        self::assertSame(1, $requests, 'jour ouvert : la garde ne sur-refuse pas, le moteur tranche');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame(5, $reloaded->getDayOfWeek());
        self::assertSame($ctx['venue2'], $reloaded->getVenueId());
        // La fenêtre de période fait 90 (copie de la grille de saison) : la ligne la prend.
        self::assertSame(90, $reloaded->getDurationMinutes());
    }

    // ── 5. Multi-blocs : source 120 (bloc fusionné) → fenêtre 60 → 60 ───────────────────────────

    /**
     * Une équipe à 120 min (deux créneaux fusionnés par le solveur en un bloc) déplacée vers une
     * fenêtre de 60 PREND 60 : la fusion est une émission du solveur, pas une propriété de l'équipe.
     */
    public function testMergedBlockTakesWindowDurationOnMove(): void
    {
        $ctx = $this->seedSeason(sourceDuration: 120, windowDuration: 60);
        $slot = $ctx['slot'];

        $requests = 0;
        $client = $this->countingClient($requests, self::ACCEPT);

        $result = $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertTrue($result['valid']);
        self::assertSame(1, $requests);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame(60, $reloaded->getDurationMinutes(), 'le bloc de 120 ré-atterrit à la taille de la fenêtre (60)');
    }

    // ── 6. Éviction jugée sur la durée FENÊTRE, pas SOURCE ──────────────────────────────────────

    /**
     * Source 120, fenêtre 60 à la cible. Un occupant siège à 21:10 pour 30 min : il chevauche
     * [20:00, 22:00[ (durée SOURCE) mais PAS [20:00, 21:00[ (durée FENÊTRE, celle qui compte).
     * L'éviction est donc jugée « ne siège pas à la cible » → evict_target_mismatch, AVANT tout
     * appel moteur. Falsification centrale de l'effet de bord : si le code jugeait encore sur la
     * durée source, l'occupant serait une cible valide et le moteur serait consulté.
     */
    public function testEvictionJudgedOnWindowDurationNotSourceDuration(): void
    {
        $ctx = $this->seedSeason(sourceDuration: 120, windowDuration: 60);
        $slot = $ctx['slot'];

        // Occupant hors de la fenêtre de 60 mais dans l'ombre de la source de 120.
        $occupant = $this->seedOccupant($ctx, 4, '21:10', $ctx['venue2'], 30);

        $requests = 0;
        $client = $this->countingClient($requests, self::ACCEPT);

        try {
            $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2'], $occupant->getId());
            self::fail('l\'occupant ne siège pas dans la fenêtre de 60 : EvictTargetMismatchException attendue');
        } catch (EvictTargetMismatchException) {
            // attendu
        }

        self::assertSame(0, $requests, 'l\'éviction est tranchée sur la durée FENÊTRE, avant tout appel moteur');

        // L'occupant est intact (rien supprimé), la source n'a pas bougé.
        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertInstanceOf(ScheduleSlotTemplate::class, $this->em->getRepository(ScheduleSlotTemplate::class)->find($occupant->getId()));
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame(2, $reloaded->getDayOfWeek());
    }

    // ── 7. dryRun vers une fenêtre inexistante → refus rapide, rien écrit ───────────────────────

    /** Un ESSAI vers un triplet sans fenêtre refuse vite (pré-moteur), et n'écrit rien. */
    public function testDryRunToNonexistentWindowRefusesFastAndWritesNothing(): void
    {
        $ctx = $this->seedSeason(sourceDuration: 90, windowDuration: 60);
        $slot = $ctx['slot'];

        $requests = 0;
        $client = $this->countingClient($requests, self::ACCEPT);

        try {
            $this->service($client)->move($slot, 5, new DateTimeImmutable('20:00'), $ctx['venue2'], null, true);
            self::fail('un essai vers un triplet sans fenêtre doit refuser vite (SlotUnavailableException)');
        } catch (SlotUnavailableException) {
            // attendu
        }

        self::assertSame(0, $requests, 'l\'essai refuse AVANT le moteur');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame($ctx['venue1'], $reloaded->getVenueId());
        self::assertSame(2, $reloaded->getDayOfWeek());
        self::assertSame(90, $reloaded->getDurationMinutes());

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertFalse($schedule->isManuallyEditedSinceGeneration(), 'un essai refusé n\'écrit rien');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Un `MockHttpClient` qui COMPTE ses requêtes (l'invariant « le moteur n'est pas appelé sous
     * un refus pré-moteur » s'atteste par un compteur, pas par une conjecture).
     */
    private function countingClient(int &$requests, string $body = self::ACCEPT): MockHttpClient
    {
        return new MockHttpClient(function () use (&$requests, $body): MockResponse {
            ++$requests;

            return new MockResponse($body, ['http_code' => 200]);
        });
    }

    private function service(MockHttpClient $client): MoveSlotService
    {
        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        return new MoveSlotService(
            $this->em,
            $container->get(ClubGenerationLock::class),
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(SchedulePlanProvisioner::class),
            new EngineClient($client, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new NullLogger,
        );
    }

    /**
     * Un créneau OCCUPANT sur le planning du contexte (la cible d'une éviction).
     *
     * @param array{clubId: string, seasonId: string, scheduleId: string, venue1: string, venue2: string, slot: ScheduleSlotTemplate} $ctx
     */
    private function seedOccupant(array $ctx, int $day, string $startHi, string $venueId, int $durationMinutes): ScheduleSlotTemplate
    {
        $occupant = (new ScheduleSlotTemplate)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setScheduleId($ctx['scheduleId'])
            ->setTeamId('77777777-7777-4777-8777-777777777777')
            ->setVenueId($venueId)
            ->setDayOfWeek($day)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $startHi))
            ->setDurationMinutes($durationMinutes)
            ->setLockLevel(LockLevel::NONE);
        $this->em->persist($occupant);
        $this->em->flush();

        return $occupant;
    }

    /**
     * Un club/saison SEASON, un planning terminé NON pointé (donc éditable), une source (U13,
     * mardi 18h, `sourceDuration`) sur venue1, et UNE fenêtre de gymnase à venue2 / jeudi(4) /
     * 20:00 de `windowDuration` (schedulePlanId null = grille de saison lue par buildForClubSeason).
     *
     * @return array{clubId: string, seasonId: string, scheduleId: string, venue1: string, venue2: string, slot: ScheduleSlotTemplate}
     */
    private function seedSeason(int $sourceDuration, int $windowDuration): array
    {
        $suffix = bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('smg-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venueIds = $this->seedVenues($clubId, $seasonId);

        $this->seedWindow($clubId, $seasonId, $venueIds[1], 4, '20:00', $windowDuration, null);

        $team = $this->seedTeam($clubId, $seasonId, $suffix);

        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Plan')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        $slot = (new ScheduleSlotTemplate)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setScheduleId($schedule->getId())
            ->setTeamId($team->getId())
            ->setVenueId($venueIds[0])
            ->setDayOfWeek(2)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '18:00'))
            ->setDurationMinutes($sourceDuration);
        $this->em->persist($slot);
        $this->em->flush();

        return [
            'clubId' => $clubId, 'seasonId' => $seasonId, 'scheduleId' => $schedule->getId(),
            'venue1' => $venueIds[0], 'venue2' => $venueIds[1], 'slot' => $slot,
        ];
    }

    /**
     * Un plan de PÉRIODE (adaptation) : la grille de saison porte deux fenêtres à venue2 (jeudi 4
     * ET vendredi 5, 20:00, 90 min). La naissance du plan les COPIE (ADR-0002). Un masque manuel
     * FERME le jeudi(4) de la période ; le vendredi(5) reste ouvert. Un planning de période
     * (calendarEntryId) terminé porte une source à venue1.
     *
     * @return array{clubId: string, seasonId: string, scheduleId: string, venue1: string, venue2: string, slot: ScheduleSlotTemplate}
     */
    private function seedPeriod(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('smgp-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venueIds = $this->seedVenues($clubId, $seasonId);

        // Grille de saison : deux fenêtres à venue2, jeudi(4) ET vendredi(5), 20:00, 90 min. La
        // naissance du plan de période les copie (schedulePlanId = planId).
        $this->seedWindow($clubId, $seasonId, $venueIds[1], 4, '20:00', 90, null);
        $this->seedWindow($clubId, $seasonId, $venueIds[1], 5, '20:00', 90, null);

        $team = $this->seedTeam($clubId, $seasonId, $suffix);

        $entry = (new CalendarEntry)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setKind(CalendarEntryKind::PERIOD)
            ->setTitle('Adaptation')
            ->setStartDate(new DateTimeImmutable('2026-10-19'))
            ->setEndDate(new DateTimeImmutable('2026-11-02'))
            ->setPeriodType(CalendarEntryPeriodType::HOLIDAY)
            ->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        // Pas de `calendarEntryId` sur le Schedule (C4 : le doublon a été supprimé) — l'overlay
        // se DÉRIVE du type de son plan, résolu par linkSeededSchedule ci-dessous.
        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Overlay')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        // Le plan de période naît du geste (linkSeededSchedule le provisionne et COPIE la grille).
        $this->linkSeededSchedule($schedule, $entry->getId());
        $this->em->flush();
        $planId = $schedule->getSchedulePlanId();

        // Masque manuel : jeudi(4) FERMÉ pour la période, ancré au plan (grain JOUR, la maison
        // unique `PlanVenueClosures` le compose). Le vendredi(5), absent du masque, reste ouvert.
        $override = (new VenuePeriodOverride)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setSchedulePlanId($planId)
            ->setVenueId($venueIds[1])
            ->setDayOverrides([4 => VenueDayState::CLOSED->value]);
        $this->em->persist($override);
        $this->em->flush();

        $slot = (new ScheduleSlotTemplate)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setScheduleId($schedule->getId())
            ->setTeamId($team->getId())
            ->setVenueId($venueIds[0])
            ->setDayOfWeek(2)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '18:00'))
            ->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();

        return [
            'clubId' => $clubId, 'seasonId' => $seasonId, 'scheduleId' => $schedule->getId(),
            'venue1' => $venueIds[0], 'venue2' => $venueIds[1], 'slot' => $slot,
        ];
    }

    /**
     * Deux gymnases (venue1 la source, venue2 la cible).
     *
     * @return array{0: string, 1: string}
     */
    private function seedVenues(string $clubId, string $seasonId): array
    {
        $venueIds = [];
        foreach (['Gymnase Un', 'Gymnase Deux'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $this->em->flush();
            $venueIds[] = $venue->getId();
        }

        return [$venueIds[0], $venueIds[1]];
    }

    private function seedWindow(string $clubId, string $seasonId, string $venueId, int $day, string $startHi, int $durationMinutes, ?string $schedulePlanId): void
    {
        $window = (new VenueTrainingSlot)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setVenueId($venueId)
            ->setDayOfWeek($day)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $startHi))
            ->setDurationMinutes($durationMinutes)
            ->setCapacity(1)
            ->setSchedulePlanId($schedulePlanId);
        $this->em->persist($window);
        $this->em->flush();
    }

    private function seedTeam(string $clubId, string $seasonId, string $suffix): Team
    {
        $sport = (new Sport)->setName('Basketball')->setSlug('bball-' . $suffix)->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        $category = (new SportCategory)->setClubId($clubId)->setSportId($sport->getId())->setName('U13')->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = (new PriorityTier)->setId(1)->setLabel('S')->setName('Senior')->setColor('#FF0000')->setOrToolsWeight(100)->setDefaultMinSessions(2);
            $this->em->persist($tier);
            $this->em->flush();
        }

        $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setSportCategoryId($category->getId())->setPriorityTierId($tier->getId())->setName('U13')->setSessionsPerWeek(2);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }
}
