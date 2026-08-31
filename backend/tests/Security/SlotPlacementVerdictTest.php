<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Exception\DurationMismatchException;
use App\Exception\ScheduleGenerationInProgressException;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR — P2-30 PR A, axes *planning lifecycle* ET *constraint semantics* : PLACER une séance À LA
 * DÉRIVE (une séance qui n'existait pas — surnuméraire ou rattrapage) passe SOUS LE VERDICT DU
 * MOTEUR, et la ligne n'est CRÉÉE QUE SI le moteur dit « oui ».
 *
 * C'est le pendant « création » de {@see SlotMoveVerdictTest} (déplacement). Sans ce garde, un
 * `POST /place-slot` pourrait écrire une séance illégale (capacité, fenêtre, repos coach…) sans
 * que rien ne l'arrête — exactement le danger que /move a fermé pour le déplacement.
 *
 * Ce qui est gardé ici (le moteur est MOQUÉ pour les cas de verdict : on teste que le BACKEND
 * respecte le verdict, pas que le moteur sait refuser — ça, c'est `ValidateAssignmentsContractSchemaTest`
 * + le smoke sémantique) :
 *  1. verdict « non » → AUCUNE ligne créée (état en base vérifié APRÈS) ;
 *  2. verdict « oui » → une ligne EST créée, déverrouillée (lockLevel NONE, lockOrigin null,
 *     coachId null), et le planning est marqué « retouché » (Mercure best-effort) ;
 *  3. la baseline envoyée au moteur est COMPLÈTE — les séances déjà en place sur CE planning y
 *     figurent (aucun retrait de source : il n'y a pas de source pour une création) ;
 *  4. une génération en cours → 409 (exception), le moteur n'est JAMAIS appelé ;
 *  5. la version CHOISIE du plan est en lecture seule → 409, avant tout appel moteur ;
 *  6. tenant : placer sur le planning d'un AUTRE club → 404 ; placer l'équipe d'un AUTRE club → 422 ;
 *  7. la DURÉE ne vient JAMAIS du client : elle est résolue de la fenêtre de gymnase visée (même
 *     source que le moteur) — un `durationMinutes` menteur (600 sur une fenêtre de 90) → 422
 *     `duration_mismatch`, RIEN écrit, moteur JAMAIS appelé ; la ligne persistée porte la durée
 *     de la fenêtre, pas celle du client ;
 *  8. aucune fenêtre à ce créneau → 422 `slot_unavailable`, avant tout appel moteur.
 */
#[Group('phase1')]
#[Group('integration')]
final class SlotPlacementVerdictTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string ACCEPT = '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    private const string REFUSE = '{"valid":false,"violations":[{"rule":"venue_capacity","message":"le gymnase est déjà plein à cette heure.","venueId":null}],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    /** Accepté AVEC un compromis nommé (P2-32) : la création gagne un jour préféré. */
    private const string ACCEPT_WITH_COMPROMISES = '{"valid":true,"violations":[],"compromises":[{"family":"day_preference","effect":"gained","message":"U13 s\'entraîne désormais un jour qui lui convient.","teamId":"team-u13","coachId":null,"venueId":null,"dayOfWeek":null,"startTime":null}],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    /** La fenêtre de gymnase seedée sur venue2 : jour 4, 20:00, 90 min. La durée fait foi de LÀ. */
    private const int WINDOW_DURATION = 90;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    /** Verdict « non » : aucune ligne n'est créée. */
    public function testRefusedPlacementCreatesNoRow(): void
    {
        $ctx = $this->seed('SPV-refuse');
        $before = $this->countSlots($ctx['scheduleId']);

        $service = $this->service(new MockHttpClient(new MockResponse(self::REFUSE, ['http_code' => 200])));
        $result = $service->place($ctx['schedule'], $ctx['teamId'], 4, new DateTimeImmutable('20:00'), $ctx['venue2'], 90);

        self::assertFalse($result['valid']);
        self::assertArrayNotHasKey('slotId', $result);

        self::assertSame($before, $this->countSlots($ctx['scheduleId']), 'un placement refusé ne crée aucune ligne');
    }

    /** Verdict « oui » : une ligne DÉVERROUILLÉE est créée et le planning est marqué retouché. */
    public function testAcceptedPlacementCreatesUnlockedRowAndMarksStale(): void
    {
        $ctx = $this->seed('SPV-accept');
        $before = $this->countSlots($ctx['scheduleId']);

        $service = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT, ['http_code' => 200])));
        // Aucune durée fournie : le serveur la résout de la fenêtre de gymnase.
        $result = $service->place($ctx['schedule'], $ctx['teamId'], 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertTrue($result['valid']);
        self::assertArrayHasKey('slotId', $result);
        self::assertNotSame('', $result['slotId']);

        self::assertSame($before + 1, $this->countSlots($ctx['scheduleId']), 'un placement accepté crée exactement une ligne');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $created = $this->em->getRepository(ScheduleSlotTemplate::class)->find($result['slotId']);
        self::assertInstanceOf(ScheduleSlotTemplate::class, $created);
        self::assertSame($ctx['teamId'], $created->getTeamId());
        self::assertSame($ctx['venue2'], $created->getVenueId());
        self::assertSame(4, $created->getDayOfWeek());
        self::assertSame('20:00', $created->getStartTime()->format('H:i'));
        // La durée PERSISTÉE est celle de la fenêtre (résolue serveur), pas une valeur client.
        self::assertSame(self::WINDOW_DURATION, $created->getDurationMinutes());
        self::assertSame($ctx['scheduleId'], $created->getScheduleId());
        // Une séance placée à la dérive naît DÉVERROUILLÉE : lockLevel NONE, lockOrigin null,
        // coachId null (le solveur/le gestionnaire l'affecteront ensuite, jamais l'API ici).
        self::assertSame(LockLevel::NONE, $created->getLockLevel());
        self::assertNull($created->getLockOrigin());
        self::assertNull($created->getCoachId());

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertTrue($schedule?->isManuallyEditedSinceGeneration(), 'un placement accepté rend le score affiché périmé');
    }

    /** P2-32 — dryRun : le verdict est rendu (compromis compris), AUCUNE ligne créée. */
    public function testDryRunPlacementCreatesNoRowButReturnsCompromises(): void
    {
        $ctx = $this->seed('SPV-dry');
        $before = $this->countSlots($ctx['scheduleId']);

        $service = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT_WITH_COMPROMISES, ['http_code' => 200])));
        $result = $service->place($ctx['schedule'], $ctx['teamId'], 4, new DateTimeImmutable('20:00'), $ctx['venue2'], null, true);

        self::assertTrue($result['valid']);
        self::assertTrue($result['dryRun']);
        self::assertArrayNotHasKey('slotId', $result, 'un essai ne crée aucune ligne, donc aucun slotId');
        self::assertNotEmpty($result['compromises'], 'un essai accepté rend les compromis nommés');
        self::assertSame('day_preference', $result['compromises'][0]['family']);
        self::assertSame('gained', $result['compromises'][0]['effect']);

        self::assertSame($before, $this->countSlots($ctx['scheduleId']), 'un essai ne crée AUCUNE ligne');

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertFalse($schedule?->isManuallyEditedSinceGeneration(), 'un essai ne marque pas le planning comme retouché');
    }

    /** La baseline envoyée au moteur est COMPLÈTE : les séances déjà en place de CE planning y figurent. */
    public function testPlacementBaselineIsComplete(): void
    {
        $ctx = $this->seed('SPV-baseline');
        // Une séance déjà en place sur le planning : elle DOIT rester dans la baseline (rien n'est retiré).
        $existing = $this->seedSlot($ctx, 2, '18:00', $ctx['venue1']);

        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse(self::ACCEPT, ['http_code' => 200]);
        });

        $this->service($client)->place($ctx['schedule'], $ctx['teamId'], 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertIsArray($captured);
        // Contrat 2.18 — `candidates` est une LISTE (une création = une liste à 1 élément).
        self::assertArrayHasKey('candidates', $captured);
        self::assertCount(1, $captured['candidates']);
        self::assertSame($ctx['teamId'], $captured['candidates'][0]['teamId']);
        // La durée du candidat envoyé au moteur vient de la fenêtre, pas du client.
        self::assertSame(self::WINDOW_DURATION, $captured['candidates'][0]['durationMinutes']);
        self::assertArrayHasKey('slotTemplates', $captured);
        $ids = array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $captured['slotTemplates']);
        self::assertContains($existing->getId(), $ids, 'les séances déjà en place restent dans la baseline (complète)');
    }

    /** Une génération en cours → 409 (exception), le moteur n'est jamais consulté. */
    public function testPlacementDuringGenerationIsRejectedWithoutCallingEngine(): void
    {
        $ctx = $this->seed('SPV-gen');
        $before = $this->countSlots($ctx['scheduleId']);

        $lock = self::getContainer()->get(ClubGenerationLock::class);
        self::assertNotNull($lock->acquire($ctx['clubId'], 60), 'seed: le verrou de génération doit être acquis');

        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('le moteur ne doit PAS être appelé pendant une génération');
        });

        try {
            $this->service($client)->place($ctx['schedule'], $ctx['teamId'], 4, new DateTimeImmutable('20:00'), $ctx['venue2'], 90);
            self::fail('un placement pendant une génération doit lever');
        } catch (ScheduleGenerationInProgressException) {
            // attendu
        }

        self::assertSame($before, $this->countSlots($ctx['scheduleId']), 'rien n\'est créé pendant une génération');
    }

    /** La version CHOISIE du plan est en lecture seule : 409, avant tout appel moteur. */
    public function testPlacementOnChosenVersionIsReadOnly(): void
    {
        $ctx = $this->seed('SPV-chosen');
        $this->choosePlanVersion($ctx['schedule']);

        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$ctx['scheduleId']}/place-slot", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'teamId' => $ctx['teamId'], 'dayOfWeek' => 4, 'startTime' => '20:00',
            'venueId' => $ctx['venue2'], 'durationMinutes' => 90,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409, 'la version choisie (validée) refuse une création de séance');
    }

    /** Placer sur le planning d'un AUTRE club → 404 (invisible sous le filtre tenant). */
    public function testPlacementOnAnotherClubsScheduleIs404(): void
    {
        $ctx = $this->seed('SPV-cross-sched');
        $other = $this->seed('SPV-cross-sched-B');

        // Requête froide : vider l'identity map, sinon `find()` renverrait le planning B depuis
        // la map SANS repasser par le filtre tenant (artefact d'EM partagé, absent d'une vraie requête).
        $this->em->clear();

        // Connecté sur le club A, on vise le planning du club B : il n'existe pour personne ici.
        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$other['scheduleId']}/place-slot", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'teamId' => $ctx['teamId'], 'dayOfWeek' => 4, 'startTime' => '20:00',
            'venueId' => $ctx['venue2'], 'durationMinutes' => 90,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404, 'le planning d\'un autre club est invisible (404)');
    }

    /** Placer l'équipe d'un AUTRE club → 422 (l'équipe n'appartient pas au club/saison du planning). */
    public function testPlacementOfAnotherClubsTeamIsRejected(): void
    {
        $ctx = $this->seed('SPV-cross-team');
        $other = $this->seed('SPV-cross-team-B');

        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$ctx['scheduleId']}/place-slot", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'teamId' => $other['teamId'], 'dayOfWeek' => 4, 'startTime' => '20:00',
            'venueId' => $ctx['venue2'], 'durationMinutes' => 90,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422, 'une équipe d\'un autre club ne peut être placée');
        self::assertSame(0, $this->countSlots($ctx['scheduleId']), 'un teamId invalide ne crée aucune ligne');
    }

    /**
     * Le trou fermé : un `durationMinutes` menteur (600 sur une fenêtre de 90) est REFUSÉ AVANT le
     * moteur — rien écrit, moteur jamais appelé. La durée ne peut pas venir du client.
     */
    public function testLyingDurationIsRejectedBeforeEngine(): void
    {
        $ctx = $this->seed('SPV-lie');
        $before = $this->countSlots($ctx['scheduleId']);

        // Un client qui ÉCHOUE le test s'il est appelé : le refus doit précéder tout appel moteur.
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('le moteur ne doit PAS être appelé quand la durée client ment sur la fenêtre');
        });

        try {
            $this->service($client)->place($ctx['schedule'], $ctx['teamId'], 4, new DateTimeImmutable('20:00'), $ctx['venue2'], 600);
            self::fail('une durée client contredisant la fenêtre doit lever');
        } catch (DurationMismatchException) {
            // attendu
        }

        self::assertSame($before, $this->countSlots($ctx['scheduleId']), 'une durée menteuse ne crée aucune ligne');
    }

    /** Le contrôleur mappe la durée menteuse en 422 `duration_mismatch`, sans rien écrire. */
    public function testLyingDurationReturns422DurationMismatch(): void
    {
        $ctx = $this->seed('SPV-lie-http');

        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$ctx['scheduleId']}/place-slot", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'teamId' => $ctx['teamId'], 'dayOfWeek' => 4, 'startTime' => '20:00',
            'venueId' => $ctx['venue2'], 'durationMinutes' => 600,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422, 'une durée qui ment sur la fenêtre est refusée');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('duration_mismatch', $body['code'] ?? null);
        self::assertSame(0, $this->countSlots($ctx['scheduleId']), 'une durée menteuse ne crée aucune ligne');
    }

    /** Aucune fenêtre à ce créneau (jour sans fenêtre) → refus avant le moteur, rien écrit. */
    public function testPlacementWithNoWindowIsRejectedBeforeEngine(): void
    {
        $ctx = $this->seed('SPV-nowindow');
        $before = $this->countSlots($ctx['scheduleId']);

        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('le moteur ne doit PAS être appelé quand aucune fenêtre n\'existe');
        });

        try {
            // Jour 3 : aucune fenêtre seedée (la seule est jour 4).
            $this->service($client)->place($ctx['schedule'], $ctx['teamId'], 3, new DateTimeImmutable('20:00'), $ctx['venue2']);
            self::fail('un créneau sans fenêtre doit lever');
        } catch (SlotUnavailableException) {
            // attendu
        }

        self::assertSame($before, $this->countSlots($ctx['scheduleId']), 'un créneau sans fenêtre ne crée aucune ligne');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
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

    private function countSlots(string $scheduleId): int
    {
        return \count($this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId]));
    }

    /**
     * Un club/saison/plan SEASON, un manager (rôle admin), un planning terminé NON choisi (donc
     * éditable), une équipe et deux gymnases (dont la cible du placement).
     *
     * @return array{user: User, clubId: string, seasonId: string, scheduleId: string, schedule: Schedule, teamId: string, venue1: string, venue2: string}
     */
    private function seed(string $tag): array
    {
        $suffix = $tag . '-' . bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('spv-' . strtolower($suffix))->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);

        $user = (new User)->setEmail('user-' . strtolower($suffix) . '@test.com')->setFirstName('M')->setLastName('GR');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

        $cu = (new ClubUser)->setClubId($clubId)->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($cu);

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venueIds = [];
        foreach (['Gymnase Un', 'Gymnase Deux'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $this->em->flush();
            $venueIds[] = $venue->getId();
        }

        // La fenêtre de gymnase cible : venue2, jour 4, 20:00, 90 min (schedulePlanId null = plan de
        // base, exactement ce que `buildForClubSeason` charge). C'est ELLE qui fixe la durée d'un
        // placement — le client ne peut pas l'imposer.
        $window = (new VenueTrainingSlot)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setVenueId($venueIds[1])
            ->setDayOfWeek(4)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '20:00'))
            ->setDurationMinutes(self::WINDOW_DURATION)
            ->setCapacity(1);
        $this->em->persist($window);
        $this->em->flush();

        $sport = (new Sport)->setName('Basketball')->setSlug('bball-' . strtolower($suffix))->setIsActive(true);
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

        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Plan')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return [
            'user' => $user, 'clubId' => $clubId, 'seasonId' => $seasonId,
            'scheduleId' => $schedule->getId(), 'schedule' => $schedule, 'teamId' => $team->getId(),
            'venue1' => $venueIds[0], 'venue2' => $venueIds[1],
        ];
    }

    /**
     * Une séance déjà en place sur le planning du contexte.
     *
     * @param array{user: User, clubId: string, seasonId: string, scheduleId: string, schedule: Schedule, teamId: string, venue1: string, venue2: string} $ctx
     */
    private function seedSlot(array $ctx, int $day, string $startHi, string $venueId): ScheduleSlotTemplate
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setScheduleId($ctx['scheduleId'])
            ->setTeamId($ctx['teamId'])
            ->setVenueId($venueId)
            ->setDayOfWeek($day)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $startHi))
            ->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }
}
