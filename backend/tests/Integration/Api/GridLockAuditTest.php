<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
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
 * P4-320 (axe planning lifecycle, ADDITIF) — les gestes de GRILLE et de VERROU laissent
 * une trace d'audit RGPD (accountability, art. 5.2) : qui a touché quoi, quand, SANS PII.
 *
 * Ce que chaque cas protège, en une phrase (promesse) :
 *  1. poser/retirer un verrou (POST manual-edit/lock → ManualEditService::applyLock) écrit UNE
 *     ligne `lock.changed` avec acteur, club, l'id du créneau et les niveaux avant/après ;
 *  2. créer/éditer un créneau de grille (VenueTrainingSlotStateProcessor) ou un réglage de
 *     période (VenuePeriodOverrideStateProcessor) écrit `grid.changed` avec ids + jour/heures
 *     (ou mode) avant/après ;
 *  3. « Charger » (POST load-locks → SchedulePlanProvisioner::pointLockSource) écrit UN SEUL
 *     événement agrégé portant un COMPTEUR — jamais N lignes (une par verrou chargé) ;
 *  4. supprimer un créneau de grille reste tracé par l'`entity.deleted` existant du
 *     choke-point AbstractStateProcessor::processDelete, SANS doublon `grid.changed` ;
 *  5. transversal : aucun nom ni e-mail ne voyage dans `details` (ids/niveaux/compteurs seuls).
 *
 * NIVEAU — PHPUnit intégration (API réelle) : chaque promesse TRAVERSE le serveur
 * (contrôleur/processeur → service → écriture AuditTrail), jsdom n'en voit rien, et ce n'est
 * pas un enchaînement métier de bout en bout (→ pas Behat, charte `tests-par-parcours.md` §2 :
 * « une seule règle serveur → PHPUnit »). Un test de service NU ne serait pas moins cher : le
 * foyer `applyLock` exige un acteur authentifié (Security), un créneau tenant-scopé persisté et
 * le GUC — le même coût que l'appel API, qui prouve EN PLUS que l'acteur remonte de bout en bout.
 *
 * CONTRAT imposé à l'implémentation (💊 NEO), valeurs d'enum FIGÉES :
 *   - AuditAction::LOCK_CHANGED = 'lock.changed', AuditAction::GRID_CHANGED = 'grid.changed' ;
 *   - lock.changed (foyer ManualEditService::applyLock) : entity_type 'ScheduleSlotTemplate',
 *     entity_id = id du créneau, acteur = utilisateur courant, club = clubId du créneau ;
 *     details = { "before": <LockLevel->value>, "after": <LockLevel->value> } ;
 *   - grid.changed (foyer VenueTrainingSlotStateProcessor POST/PUT) : entity_type
 *     'VenueTrainingSlot', entity_id = id du créneau ; details = { "venueId", "schedulePlanId"|null,
 *     "before": null|{"dayOfWeek":int,"startTime":"H:i"}, "after": {"dayOfWeek":int,"startTime":"H:i"} } ;
 *   - grid.changed (foyer VenuePeriodOverrideStateProcessor POST/PUT) : entity_type
 *     'VenuePeriodOverride', entity_id = id du réglage ; details = { "venueId", "schedulePlanId",
 *     "before": null|<mode>, "after": null|<mode> } ;
 *   - lock.changed AGRÉGÉ (chemin « Charger ») : EXACTEMENT une ligne, entity_id = id de la version
 *     chargée ; details = { "count": <int = nb de créneaux HARD de la version chargée>, … } ;
 *   - processDelete d'un VenueTrainingSlot : garder `entity.deleted` (choke-point existant), ne
 *     PAS émettre `grid.changed`.
 * Un écart MÉCANIQUE de nom de clé (ex. `after` → `to`) est adapté par 💊 NEO (option A), jamais
 * un ajout/retrait de cas.
 *
 * Piège Redis : aucun FLUSHALL ici (on ne touche pas Redis). APP_ENV=test explicite (CI/Makefile).
 */
#[Group('phase1')]
#[Group('integration')]
final class GridLockAuditTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private User $manager;

    private Season $season;

    private string $token;

    /**
     * Promesse 1 — poser un verrou HARD écrit `lock.changed` (acteur, club, créneau, NONE→HARD).
     */
    public function testApplyingAHardLockRecordsLockChangedWithLevelsAndActor(): void
    {
        $schedule = $this->createSchedule(ScheduleStatus::COMPLETED);
        $slot = $this->createSlot($schedule, dayOfWeek: 2, startHm: '18:00');

        $this->postLock($slot->getId(), 'HARD');
        self::assertResponseIsSuccessful();

        $rows = $this->auditRows('lock.changed');
        self::assertCount(1, $rows, 'poser un verrou trace UNE ligne lock.changed');
        self::assertSame($slot->getId(), $rows[0]['entity_id'], 'la ligne désigne le créneau touché');
        self::assertSame('ScheduleSlotTemplate', $rows[0]['entity_type']);
        self::assertSame($this->manager->getId(), $rows[0]['actor_user_id'], 'acteur tracé');
        self::assertSame($this->club->getId(), $rows[0]['club_id'], 'club tracé');

        $details = $this->details($rows[0]);
        self::assertSame('NONE', $details['before'] ?? null, 'niveau AVANT');
        self::assertSame('HARD', $details['after'] ?? null, 'niveau APRÈS');
    }

    /**
     * Promesse 1 — retirer un verrou (HARD→NONE) écrit `lock.changed` avec l'avant ET l'après.
     */
    public function testRemovingALockRecordsLockChangedWithHardThenNone(): void
    {
        $schedule = $this->createSchedule(ScheduleStatus::COMPLETED);
        $slot = $this->createSlot($schedule, dayOfWeek: 2, startHm: '18:00', lockLevel: LockLevel::HARD);

        $this->postLock($slot->getId(), 'NONE');
        self::assertResponseIsSuccessful();

        $rows = $this->auditRows('lock.changed');
        self::assertCount(1, $rows, 'retirer un verrou trace UNE ligne lock.changed');
        $details = $this->details($rows[0]);
        self::assertSame('HARD', $details['before'] ?? null, 'le niveau AVANT retrait est capturé');
        self::assertSame('NONE', $details['after'] ?? null, 'le niveau APRÈS retrait est capturé');
    }

    /**
     * Promesse 2 — créer un créneau de grille écrit `grid.changed` (ids + jour/heure, avant null).
     */
    public function testCreatingAGridSlotRecordsGridChangedWithTheNewDayAndTime(): void
    {
        $venue = $this->createVenue();
        $this->postSlot($venue->getId(), 1, '18:00', 90);
        self::assertResponseStatusCodeSame(201);
        $slotId = $this->decode()['id'];

        $rows = $this->auditRows('grid.changed');
        self::assertCount(1, $rows, 'créer un créneau trace UNE ligne grid.changed');
        self::assertSame($slotId, $rows[0]['entity_id']);
        self::assertSame('VenueTrainingSlot', $rows[0]['entity_type']);

        $details = $this->details($rows[0]);
        self::assertSame($venue->getId(), $details['venueId'] ?? null, 'id du gymnase tracé');
        self::assertArrayHasKey('before', $details);
        self::assertNull($details['before'], 'à la création, pas d\'état antérieur');
        self::assertSame(1, $details['after']['dayOfWeek'] ?? null, 'jour posé tracé');
        self::assertSame('18:00', $details['after']['startTime'] ?? null, 'heure posée tracée');
    }

    /**
     * Promesse 2 — éditer un créneau (jour/heure) écrit `grid.changed` avec AVANT et APRÈS.
     */
    public function testEditingAGridSlotRecordsGridChangedWithDayAndTimeBeforeAndAfter(): void
    {
        $venue = $this->createVenue();
        $this->postSlot($venue->getId(), 1, '18:00', 90);
        self::assertResponseStatusCodeSame(201);
        $slotId = $this->decode()['id'];

        $this->client->request('PUT', '/api/venue_training_slots/' . $slotId, [], [], $this->headers(), json_encode([
            'venueId' => $venue->getId(),
            'dayOfWeek' => 2,
            'startTime' => '20:00',
            'durationMinutes' => 90,
            'capacity' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $rows = $this->auditRows('grid.changed');
        self::assertCount(2, $rows, 'création puis édition = deux traces grid.changed');

        // La ligne d'ÉDITION est celle dont l'état antérieur n'est pas nul — repérée par ce
        // critère (et non par l'ordre : occurred_at est à la seconde, POST et PUT la partagent).
        $editRow = null;
        foreach ($rows as $row) {
            $details = $this->details($row);
            if (null !== ($details['before'] ?? null)) {
                $editRow = $details;
            }
        }
        self::assertNotNull($editRow, 'une des deux traces porte l\'état avant édition');
        self::assertSame(1, $editRow['before']['dayOfWeek'] ?? null, 'jour AVANT');
        self::assertSame('18:00', $editRow['before']['startTime'] ?? null, 'heure AVANT');
        self::assertSame(2, $editRow['after']['dayOfWeek'] ?? null, 'jour APRÈS');
        self::assertSame('20:00', $editRow['after']['startTime'] ?? null, 'heure APRÈS');
    }

    /**
     * Promesse 2 — régler puis changer le mode d'un gymnase de période écrit `grid.changed`.
     */
    public function testSettingAndChangingAVenuePeriodModeRecordsGridChanged(): void
    {
        $venue = $this->createVenue();
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());

        $this->client->request('POST', '/api/venue_period_overrides', [], [], $this->headers(), json_encode([
            'schedulePlanId' => $planId,
            'venueId' => $venue->getId(),
            'mode' => 'BLANK',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $overrideId = $this->decode()['id'];

        $this->client->request('PUT', '/api/venue_period_overrides/' . $overrideId, [], [], $this->headers(), json_encode([
            'schedulePlanId' => $planId,
            'venueId' => $venue->getId(),
            'mode' => 'DISABLED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $rows = $this->auditRows('grid.changed');
        self::assertCount(2, $rows, 'poser puis changer le mode = deux traces grid.changed');
        foreach ($rows as $row) {
            self::assertSame('VenuePeriodOverride', $row['entity_type']);
            self::assertSame($overrideId, $row['entity_id']);
            self::assertSame($venue->getId(), $this->details($row)['venueId'] ?? null, 'id du gymnase tracé');
        }

        // La trace de CHANGEMENT porte le mode antérieur (BLANK) et le nouveau (DISABLED).
        $changeRow = null;
        foreach ($rows as $row) {
            $details = $this->details($row);
            if (null !== ($details['before'] ?? null)) {
                $changeRow = $details;
            }
        }
        self::assertNotNull($changeRow, 'une des deux traces porte le mode avant changement');
        self::assertSame('BLANK', $changeRow['before'] ?? null, 'mode AVANT');
        self::assertSame('DISABLED', $changeRow['after'] ?? null, 'mode APRÈS');
    }

    /**
     * Promesse 3 — « Charger » une version écrit UN SEUL événement agrégé portant un compteur,
     * jamais une ligne par verrou chargé.
     */
    public function testLoadingLocksRecordsASingleAggregatedEventWithACounter(): void
    {
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        // v1 porte DEUX verrous HARD ; v2 (dernière COMPLETED) est la source par défaut.
        $v1 = $this->periodVersion($planId, ScheduleStatus::COMPLETED);
        $this->createSlot($v1, 1, '18:00', lockLevel: LockLevel::HARD);
        $this->createSlot($v1, 2, '20:00', lockLevel: LockLevel::HARD);
        $v2 = $this->periodVersion($planId, ScheduleStatus::COMPLETED);
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/load-locks", [], [], $this->headers());
        self::assertResponseStatusCodeSame(200, 'v1 n\'est pas la source (v2 l\'est) → « Charger » agit');

        $rows = $this->auditRows('lock.changed');
        self::assertCount(1, $rows, '« Charger » n\'écrit JAMAIS une ligne par verrou — un seul événement agrégé');
        self::assertSame($v1->getId(), $rows[0]['entity_id'], 'l\'événement désigne la version chargée');

        $details = $this->details($rows[0]);
        self::assertSame(2, $details['count'] ?? null, 'le compteur = nombre de verrous HARD de la version chargée');

        // Garde-fou de non-régression du sujet : la maison unique renvoie bien v1 comme source.
        $this->scopeGucToClub($this->club->getId());
        self::assertSame($v1->getId(), $this->provisioner()->lockSourceScheduleIdOfPlan($planId));
        $this->clearGuc();
    }

    /**
     * Promesse 4 — supprimer un créneau reste tracé par `entity.deleted`, SANS doublon `grid.changed`.
     */
    public function testDeletingAGridSlotStaysTrackedByEntityDeletedWithoutDuplicateGridChanged(): void
    {
        $venue = $this->createVenue();
        $this->postSlot($venue->getId(), 1, '18:00', 90);
        self::assertResponseStatusCodeSame(201);
        $slotId = $this->decode()['id'];

        $this->client->request('DELETE', '/api/venue_training_slots/' . $slotId, [], [], $this->headers());
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 204]);

        $deleted = $this->auditRowsFor('entity.deleted', $slotId);
        self::assertCount(1, $deleted, 'la suppression reste tracée par le choke-point entity.deleted');
        self::assertSame('VenueTrainingSlot', $deleted[0]['entity_type']);

        $gridForSlot = $this->auditRowsFor('grid.changed', $slotId);
        self::assertCount(1, $gridForSlot, 'une seule trace grid.changed — celle de la création (le DELETE n\'en ajoute pas)');
    }

    /**
     * Promesse 5 (transversal) — aucun nom ni e-mail ne voyage dans `details` : ids/niveaux seuls.
     */
    public function testAuditDetailsNeverCarryNamesOrEmails(): void
    {
        // Un geste de verrou ET un geste de grille, pour balayer les deux foyers d'un coup.
        $schedule = $this->createSchedule(ScheduleStatus::COMPLETED);
        $slot = $this->createSlot($schedule, dayOfWeek: 2, startHm: '18:00');
        $this->postLock($slot->getId(), 'HARD');
        self::assertResponseIsSuccessful();

        $venue = $this->createVenue();
        $this->postSlot($venue->getId(), 3, '19:00', 90);
        self::assertResponseStatusCodeSame(201);

        $this->scopeGucToClub($this->club->getId());
        $allDetails = $this->em->getConnection()->fetchFirstColumn(
            'SELECT details FROM audit_log WHERE club_id = :cid',
            ['cid' => $this->club->getId()],
        );
        $this->clearGuc();
        self::assertNotEmpty($allDetails, 'des traces ont bien été écrites (sinon le no-PII serait creux)');

        $emailLocalPart = explode('@', $this->manager->getEmail())[0];
        foreach ($allDetails as $details) {
            $json = (string) $details;
            self::assertStringNotContainsString($this->manager->getFirstName(), $json, 'pas de prénom dans details');
            self::assertStringNotContainsString($this->manager->getLastName(), $json, 'pas de nom dans details');
            self::assertStringNotContainsString($emailLocalPart, $json, 'pas d\'e-mail dans details');
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('GLA ' . $uid)->setSlug('gla-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        // Prénom/nom/e-mail DISTINCTS et non triviaux : le test no-PII les cherche dans `details`.
        $this->manager = (new User)->setEmail('gla-mgr-' . $uid . '@test.com')->setFirstName('Gerald')->setLastName('Lantier');
        $this->manager->setPasswordHash($hasher->hashPassword($this->manager, 'Password123!'));
        $this->em->persist($this->manager);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($this->manager->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->manager);
    }

    private function provisioner(): SchedulePlanProvisioner
    {
        return self::getContainer()->get(SchedulePlanProvisioner::class);
    }

    private function createSchedule(ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Plan')->setStatus($status);
        // lot D : schedule_plan_id NOT NULL — linkSeededSchedule résout le plan SEASON, le pose,
        // persiste et numérote (plan AVANT persist).
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function periodVersion(string $planId, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('V')->setStatus($status)->setSchedulePlanId($planId);
        $this->em->persist($schedule);
        $this->provisioner()->linkSchedule($schedule);

        return $schedule;
    }

    private function createSlot(
        Schedule $schedule,
        int $dayOfWeek,
        string $startHm,
        string $venueId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        LockLevel $lockLevel = LockLevel::NONE,
    ): ScheduleSlotTemplate {
        $this->scopeGucToClub($this->club->getId());
        $startTime = DateTimeImmutable::createFromFormat('!H:i', $startHm);
        self::assertInstanceOf(DateTimeImmutable::class, $startTime);
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId('44444444-4444-4444-8444-444444444444')
            ->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime($startTime)
            ->setDurationMinutes(90)
            ->setLockLevel($lockLevel);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    private function createVenue(): Venue
    {
        $this->scopeGucToClub($this->club->getId());
        $venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Venue ' . uniqid())->setSource('manual')->setIsActive(true)->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function postLock(string $slotId, string $lockLevel): void
    {
        $this->client->request('POST', "/api/schedule-slots/{$slotId}/manual-edit/lock", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['lockLevel' => $lockLevel], \JSON_THROW_ON_ERROR));
    }

    private function postSlot(string $venueId, int $day, string $start, int $duration): void
    {
        $this->client->request('POST', '/api/venue_training_slots', [], [], $this->headers(), json_encode([
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'startTime' => $start,
            'durationMinutes' => $duration,
            'capacity' => 1,
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * Les lignes d'audit du club pour une action donnée, lues sous le GUC tenant (dama : l'écriture
     * de la connexion default reste dans la transaction de test, visible sous le bon GUC).
     *
     * @return list<array<string, mixed>>
     */
    private function auditRows(string $action): array
    {
        $this->scopeGucToClub($this->club->getId());
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM audit_log WHERE club_id = :cid AND action = :action',
            ['cid' => $this->club->getId(), 'action' => $action],
        );
        $this->clearGuc();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRowsFor(string $action, string $entityId): array
    {
        $this->scopeGucToClub($this->club->getId());
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM audit_log WHERE club_id = :cid AND action = :action AND entity_id = :eid',
            ['cid' => $this->club->getId(), 'action' => $action, 'eid' => $entityId],
        );
        $this->clearGuc();

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function details(array $row): array
    {
        $decoded = json_decode((string) $row['details'], true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
