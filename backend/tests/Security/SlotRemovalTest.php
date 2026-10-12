<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ClubGenerationLock;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — « RETIRER une séance placée » (axe *planning lifecycle* : retouche en place d'un plan sous
 * verdict de lecture seule/verrous). Les routes `POST /api/schedule-slots/{id}/remove` et
 * `POST /api/schedule-slots/remove-group` SUPPRIMENT la/les ligne(s) `ScheduleSlotTemplate` SANS
 * JAMAIS appeler le moteur (un retrait creuse un trou, il ne crée pas de conflit — décision
 * fondateur 2026-10-12), et le trou est SIGNALÉ (le retour porte `removed`, `lockLevel` et
 * `unmetPresence`).
 *
 * Ce que ce gate défend :
 *  1. retrait simple → la ligne disparaît, le planning est marqué « retouché » ;
 *  2. verrouillée → retirée quand même (le verrou part avec la ligne — responsabilité du gestionnaire) ;
 *  3. LOCK-SOURCE : après retrait, le builder de génération ne greffe PLUS l'épingle HARD de cette
 *     séance (falsifiable : un retrait qui n'écrirait pas laisserait l'épingle au payload) ;
 *  4. groupe tout-ou-rien → les N créneaux du bloc partent ensemble, le `SharedTrainingBlock` SURVIT ;
 *  5. `unmetPresence` : une SEASON_SLOTS active de l'équipe retirée est NOMMÉE en PÉRIODE, `[]` sur le socle ;
 *  6. `dryRun` (appel à blanc du ConfirmDialog) n'écrit RIEN ;
 *  7. version choisie (lecture seule) → 409 ;
 *  8. génération en cours → 409 `generation_in_progress` ;
 *  9. séance d'un AUTRE club → 404 (filtre tenant) ;
 * 10. SEC-07 : un non-gestionnaire est refusé 403 AVANT toute écriture (falsifiable en retirant
 *     `assertManager()` : le membre obtiendrait 200 et la séance partirait).
 */
#[Group('phase1')]
#[Group('integration')]
final class SlotRemovalTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** Retrait simple : la ligne disparaît, le planning est « retouché », le retour porte `removed` + `lockLevel: NONE`. */
    public function testRemovesPlacedSlot(): void
    {
        $ctx = $this->seedPeriod('a');
        $slot = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::NONE);

        $this->client->request('POST', '/api/schedule-slots/' . $slot->getId() . '/remove', [], [], $this->managerHeaders($ctx), '{}');
        self::assertResponseStatusCodeSame(200);

        $body = $this->responseData();
        self::assertSame('NONE', $body['lockLevel']);
        self::assertSame($slot->getId(), $body['removed']['slotId']);
        self::assertSame([], $body['unmetPresence']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId()), 'la séance retirée disparaît');
        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertTrue($schedule?->isManuallyEditedSinceGeneration(), 'le planning est marqué retouché');
    }

    /** Verrouillée ou non → retirée : le verrou part avec la ligne, le retour NOMME le verrou perdu. */
    public function testLockedSlotIsRemovedAnyway(): void
    {
        $ctx = $this->seedPeriod('b');
        $slot = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::HARD);

        $this->client->request('POST', '/api/schedule-slots/' . $slot->getId() . '/remove', [], [], $this->managerHeaders($ctx), '{}');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('HARD', $this->responseData()['lockLevel'], 'le verrou perdu est nommé pour la confirmation');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId()), 'une séance verrouillée est retirée, verrou compris');
    }

    /**
     * LOCK-SOURCE (falsification) — une séance HARD d'une version de SOCLE est greffée comme épingle
     * dans le payload de génération (`buildForClubSeason`). Après retrait, elle N'Y EST PLUS : la
     * prochaine génération ne la figera plus. Un retrait qui n'écrirait pas (no-op) laisserait
     * l'épingle au payload — cette assertion le ferait rougir.
     */
    public function testLockSourcePinNoLongerGraftedAfterRemoval(): void
    {
        $ctx = $this->seedSeason('c');
        $slot = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::HARD);

        $before = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason($ctx['clubId'], $ctx['seasonId']);
        self::assertTrue($this->payloadHasHardPin($before, $slot->getId()), 'le payload greffe bien l\'épingle HARD avant le retrait');

        $this->client->request('POST', '/api/schedule-slots/' . $slot->getId() . '/remove', [], [], $this->managerHeaders($ctx), '{}');
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $after = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason($ctx['clubId'], $ctx['seasonId']);
        self::assertFalse($this->payloadHasHardPin($after, $slot->getId()), 'après retrait, l\'épingle HARD a disparu du payload');
    }

    /** Groupe tout-ou-rien : les deux créneaux du bloc partent ensemble, le bloc et ses adhésions SURVIVENT. */
    public function testGroupRemovalIsAllOrNothingAndBlockSurvives(): void
    {
        $ctx = $this->seedPeriod('d');
        $other = $this->createTeam($ctx, 'Autre', 2);
        $block = $this->declareBlock($ctx, [$ctx['teamId'], $other]);
        $s1 = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::NONE);
        $s2 = $this->placeSlot($ctx, $other, LockLevel::NONE);

        $this->client->request('POST', '/api/schedule-slots/remove-group', [], [], $this->managerHeaders($ctx), json_encode([
            'scheduleId' => $ctx['scheduleId'],
            'blockId' => $block->getId(),
            'source' => ['venueId' => $ctx['venueId'], 'dayOfWeek' => 2, 'startTime' => '18:00'],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, $this->responseData()['removedSlotIds']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($s1->getId()), 'le premier membre part');
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($s2->getId()), 'le second membre part aussi (tout-ou-rien)');
        self::assertNotNull($this->em->getRepository(SharedTrainingBlock::class)->find($block->getId()), 'le bloc de mutualisation SURVIT au retrait');
        self::assertCount(2, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $block->getId()]), 'les adhésions du bloc survivent');
    }

    /** Aucun membre du bloc à la case source → 422 `slot_unavailable`, rien n'est retiré. */
    public function testGroupRemovalWithNoSessionAtCaseRefused(): void
    {
        $ctx = $this->seedPeriod('e');
        $other = $this->createTeam($ctx, 'Autre', 2);
        $block = $this->declareBlock($ctx, [$ctx['teamId'], $other]);

        $this->client->request('POST', '/api/schedule-slots/remove-group', [], [], $this->managerHeaders($ctx), json_encode([
            'scheduleId' => $ctx['scheduleId'],
            'blockId' => $block->getId(),
            'source' => ['venueId' => $ctx['venueId'], 'dayOfWeek' => 5, 'startTime' => '20:00'],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('slot_unavailable', $this->responseData()['code'] ?? null);
    }

    /** `unmetPresence` : une SEASON_SLOTS ACTIVE de l'équipe retirée est NOMMÉE (famille, équipe, message humain) en PÉRIODE. */
    public function testUnmetPresenceNamedInPeriod(): void
    {
        $ctx = $this->seedPeriod('f', withEntry: true);
        $this->seasonSlotsConstraint($ctx, $ctx['teamId'], $ctx['entryId']);
        $slot = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::NONE);

        $this->client->request('POST', '/api/schedule-slots/' . $slot->getId() . '/remove', [], [], $this->managerHeaders($ctx), '{}');
        self::assertResponseStatusCodeSame(200);

        $unmet = $this->responseData()['unmetPresence'];
        self::assertCount(1, $unmet, 'la présence SEASON_SLOTS de l\'équipe retirée est signalée');
        self::assertSame('SEASON_SLOTS', $unmet[0]['family']);
        self::assertSame($ctx['teamId'], $unmet[0]['teamId']);
        self::assertStringContainsString('Source', $unmet[0]['message'], 'le message NOMME l\'équipe');
        self::assertStringContainsString('garder les créneaux de saison', $unmet[0]['message']);
    }

    /** Sur le SOCLE de saison, la famille SEASON_SLOTS est inerte : même avec la contrainte, `unmetPresence` == []. */
    public function testUnmetPresenceEmptyOnSeasonBasePlan(): void
    {
        $ctx = $this->seedSeason('g');
        $this->seasonSlotsConstraint($ctx, $ctx['teamId'], null);
        $slot = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::NONE);

        $this->client->request('POST', '/api/schedule-slots/' . $slot->getId() . '/remove', [], [], $this->managerHeaders($ctx), '{}');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseData()['unmetPresence'], 'le socle ne signale aucune présence SEASON_SLOTS');
    }

    /** `dryRun` : l'appel à blanc rend les conséquences (removed, lockLevel) mais N'ÉCRIT RIEN. */
    public function testDryRunWritesNothing(): void
    {
        $ctx = $this->seedPeriod('h');
        $slot = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::HARD);

        $this->client->request('POST', '/api/schedule-slots/' . $slot->getId() . '/remove', [], [], $this->managerHeaders($ctx), json_encode(['dryRun' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $body = $this->responseData();
        self::assertTrue($body['dryRun']);
        self::assertSame('HARD', $body['lockLevel']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId()), 'un essai ne retire rien');
        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertFalse($schedule?->isManuallyEditedSinceGeneration(), 'un essai ne marque pas le planning');
    }

    /** Version choisie (lecture seule) → 409, rien n'est retiré. */
    public function testValidatedPlanRefused(): void
    {
        $ctx = $this->seedPeriod('i');
        $slot = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::NONE);
        $this->scopeGucToClub($ctx['clubId']);
        self::getContainer()->get(SchedulePlanProvisioner::class)->choose($ctx['schedule']);
        $this->em->flush();

        $this->client->request('POST', '/api/schedule-slots/' . $slot->getId() . '/remove', [], [], $this->managerHeaders($ctx), '{}');
        self::assertResponseStatusCodeSame(409);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId()), 'une version choisie est en lecture seule');
    }

    /** Génération en cours → 409 `generation_in_progress`. */
    public function testGenerationInProgressRefused(): void
    {
        $ctx = $this->seedPeriod('j');
        $slot = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::NONE);
        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($ctx['clubId'], 60);
        self::assertIsString($token);

        try {
            $this->client->request('POST', '/api/schedule-slots/' . $slot->getId() . '/remove', [], [], $this->managerHeaders($ctx), '{}');
            self::assertResponseStatusCodeSame(409);
            self::assertSame('generation_in_progress', $this->responseData()['code'] ?? null);
        } finally {
            $lock->release($ctx['clubId'], $token);
        }

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId()), 'rien n\'est retiré pendant une génération');
    }

    /** Séance d'un AUTRE club : le filtre tenant la rend invisible → 404. */
    public function testForeignSlotNotFound(): void
    {
        $ctx = $this->seedPeriod('k');
        $foreign = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::NONE);
        $other = $this->seedPeriod('l');

        // Une requête RÉELLE part d'un cache d'identité VIDE : sans ce clear, find() servirait la
        // séance de l'autre club depuis la map (court-circuitant le filtre tenant) et on retirerait.
        $this->em->clear();

        $this->client->request('POST', '/api/schedule-slots/' . $foreign->getId() . '/remove', [], [], $this->managerHeaders($other), '{}');
        // Le filtre tenant rend la séance de l'autre club INVISIBLE : `findSlot` rend null → 404
        // AVANT tout appel au service, donc rien ne peut être retiré (patron MutualizeSlotApiTest).
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * SEC-07 (falsification) — un non-gestionnaire est refusé 403 AVANT toute écriture. Retirer
     * `assertManager()` du contrôleur rendrait le membre capable de vider un créneau : il obtiendrait
     * 200 et la séance partirait — cette assertion sur le 403 ET la survie de la séance le ferait rougir.
     */
    public function testNonManagerRefused(): void
    {
        $ctx = $this->seedPeriod('m');
        $slot = $this->placeSlot($ctx, $ctx['teamId'], LockLevel::NONE);
        $member = $this->createMember($ctx['clubId'], 'editor');

        $this->client->request('POST', '/api/schedule-slots/' . $slot->getId() . '/remove', [], [], $this->authHeaders($member) + ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId()), 'un non-gestionnaire ne retire rien');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadHasHardPin(array $payload, string $slotId): bool
    {
        $templates = \is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [];
        foreach ($templates as $template) {
            if (\is_array($template) && ($template['id'] ?? null) === $slotId && 'HARD' === ($template['lockLevel'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un club/manager/saison + un gymnase et sa fenêtre à la case (mardi 18h, couche PÉRIODE), une
     * équipe SOURCE et un planning de PÉRIODE éditable. `withEntry` accroche le plan à une vraie
     * entrée de calendrier (requise pour que la sélection de contraintes de période s'applique).
     *
     * @return array{clubId: string, seasonId: string, planId: string, scheduleId: string, schedule: Schedule, venueId: string, categoryId: string, tierId: int, user: User, teamId: string, entryId: ?string}
     */
    private function seedPeriod(string $suffix, bool $withEntry = false): array
    {
        $ctx = $this->seedBase($suffix);
        $entryId = null;
        if ($withEntry) {
            $entryId = $this->createHolidayEntry($ctx['clubId'], $ctx['seasonId']);
        }
        $planId = $this->createPeriodPlan($ctx['clubId'], $ctx['seasonId'], $entryId);
        $ctx['planId'] = $planId;
        $ctx['entryId'] = $entryId;
        $ctx['schedule'] = $this->linkPeriodSchedule($ctx['clubId'], $ctx['seasonId'], $planId);
        $ctx['scheduleId'] = $ctx['schedule']->getId();
        $this->createWindow($ctx['clubId'], $ctx['seasonId'], $planId, $ctx['venueId']);

        return $ctx;
    }

    /**
     * La variante SOCLE : le planning est une version du plan SEASON (isSeasonSchedule = true),
     * NON choisie (donc retirable). `entryId` reste null.
     *
     * @return array{clubId: string, seasonId: string, planId: ?string, scheduleId: string, schedule: Schedule, venueId: string, categoryId: string, tierId: int, user: User, teamId: string, entryId: ?string}
     */
    private function seedSeason(string $suffix): array
    {
        $ctx = $this->seedBase($suffix);
        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Socle')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $this->linkSeededSchedule($schedule); // plan SEASON
        $this->em->flush();
        $ctx['planId'] = null;
        $ctx['entryId'] = null;
        $ctx['schedule'] = $schedule;
        $ctx['scheduleId'] = $schedule->getId();
        $this->createWindow($ctx['clubId'], $ctx['seasonId'], null, $ctx['venueId']);

        return $ctx;
    }

    /**
     * @return array{clubId: string, seasonId: string, venueId: string, categoryId: string, tierId: int, user: User, teamId: string}
     */
    private function seedBase(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = (new Club)->setName('Club rm ' . $suffix)->setSlug('club-rm-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = (new User)->setEmail('rm' . $uid . '@test.com')->setFirstName('Gest')->setLastName('User')->setPasswordHash($hasher->hashPassword(new User, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = (new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($membership);

        $season = (new Season)->setClubId($club->getId())->setName('2026')->setStartDate(new DateTimeImmutable('2026-08-01'))->setEndDate(new DateTimeImmutable('2027-07-15'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->provisionSeasonPlan($season);

        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase ' . $suffix)->setSource('manual');
        $this->em->persist($venue);

        $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
        $this->em->persist($sport);
        $category = (new SportCategory)->setClubId($club->getId())->setSportId($sport->getId())->setName('U9-' . $uid)->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        $ctx = [
            'clubId' => $club->getId(), 'seasonId' => $season->getId(), 'venueId' => $venue->getId(),
            'categoryId' => $category->getId(), 'tierId' => 3, 'user' => $user,
        ];
        $ctx['teamId'] = $this->createTeam($ctx, 'Source', 2);

        return $ctx;
    }

    private function createHolidayEntry(string $clubId, string $seasonId): string
    {
        $this->scopeGucToClub($clubId);
        $entry = new CalendarEntry;
        $entry->setClubId($clubId);
        $entry->setSeasonId($seasonId);
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Vacances');
        $entry->setStartDate(new DateTimeImmutable('2026-10-19'));
        $entry->setEndDate(new DateTimeImmutable('2026-11-02'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry->getId();
    }

    /**
     * @param array{clubId: string, seasonId: string, categoryId: string, tierId: int} $ctx
     */
    private function createTeam(array $ctx, string $name, int $sessionsPerWeek): string
    {
        $this->scopeGucToClub($ctx['clubId']);
        $team = (new Team)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setSportCategoryId($ctx['categoryId'])
            ->setPriorityTierId($ctx['tierId'])
            ->setName($name)
            ->setSessionsPerWeek($sessionsPerWeek)
            ->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    /**
     * Une séance de l'équipe à la case mardi 18h, au niveau de verrou donné.
     *
     * @param array{clubId: string, seasonId: string, scheduleId: string, venueId: string} $ctx
     */
    private function placeSlot(array $ctx, string $teamId, LockLevel $lockLevel): ScheduleSlotTemplate
    {
        $this->scopeGucToClub($ctx['clubId']);
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setScheduleId($ctx['scheduleId'])
            ->setTeamId($teamId)
            ->setVenueId($ctx['venueId'])
            ->setDayOfWeek(2)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '18:00'))
            ->setDurationMinutes(90)
            ->setLockLevel($lockLevel);
        if (LockLevel::NONE !== $lockLevel) {
            $slot->setLockOrigin(LockOrigin::MANUAL);
        }
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    private function createWindow(string $clubId, string $seasonId, ?string $planId, string $venueId): void
    {
        $this->scopeGucToClub($clubId);
        $window = (new VenueTrainingSlot)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setVenueId($venueId)
            ->setDayOfWeek(2)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '18:00'))
            ->setDurationMinutes(90)
            ->setCapacity(2);
        $window->setSchedulePlanId($planId);
        $this->em->persist($window);
        $this->em->flush();
    }

    /**
     * La contrainte « garder ses créneaux de saison » (SEASON_SLOTS, TEAM, PRÉFÉRÉE, active) visant
     * l'équipe — DATÉE sur l'entrée (patron CoachWishTransferService) quand une période la porte.
     *
     * @param array{clubId: string, seasonId: string} $ctx
     */
    private function seasonSlotsConstraint(array $ctx, string $teamId, ?string $entryId): void
    {
        $this->scopeGucToClub($ctx['clubId']);
        $constraint = (new Constraint)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setCalendarEntryId($entryId)
            ->setName('Garder les créneaux de saison · Source')
            ->setScope(ConstraintScope::TEAM)
            ->setScopeTargetId($teamId)
            ->setFamily(ConstraintFamily::SEASON_SLOTS)
            ->setRuleType(ConstraintRuleType::PREFERRED)
            ->setConfig([])
            ->setIsActive(true)
            ->setSortOrder(0);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    private function declareBlock(array $ctx, array $teamIds): SharedTrainingBlock
    {
        $this->scopeGucToClub($ctx['clubId']);
        $planId = $ctx['planId'] ?? null;
        $block = (new SharedTrainingBlock)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($planId)->setCommonSessions(1);
        $this->em->persist($block);
        foreach ($teamIds as $teamId) {
            $member = (new SharedTrainingBlockTeam)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($teamId);
            $this->em->persist($member);
        }
        $this->em->flush();

        return $block;
    }

    private function linkPeriodSchedule(string $clubId, string $seasonId, string $planId): Schedule
    {
        $this->scopeGucToClub($clubId);
        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Période')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $schedule->setSchedulePlanId($planId);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function createMember(string $clubId, string $role): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = (new User)->setEmail($role . $uid . '@test.com')->setFirstName('N')->setLastName('Member')->setPasswordHash($hasher->hashPassword(new User, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = (new ClubUser)->setClubId($clubId)->setUserId($user->getId())->setRole($role)->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array{user: User} $ctx
     *
     * @return array{HTTP_AUTHORIZATION: string, CONTENT_TYPE: string}
     */
    private function managerHeaders(array $ctx): array
    {
        return $this->authHeaders($ctx['user']) + ['CONTENT_TYPE' => 'application/json'];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
