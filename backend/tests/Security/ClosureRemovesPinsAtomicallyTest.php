<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Enum\AuditAction;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Service\ClubGenerationLock;
use App\Tests\ClosurePinDecorTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR GATE — axe planning lifecycle (ADR-0002) : « Enregistrer une fermeture, après lecture de
 * l'aperçu, vaut geste » (décision fondateur 2026-11-02). Déclarer, re-dater ou élargir une
 * fermeture de gymnase RETIRE, dans la MÊME transaction, les verrous HARD (toute origine, version
 * CHARGÉE de chaque plan de période qui gouverne les jours fermés) et les réservations de ces plans
 * sur ce gymnase à ces jours — et RIEN d'autre : ni les autres jours, ni les autres gymnases, ni le
 * socle. Une génération en vol (le verrou de génération du club est PRIS par le retrait) → 409
 * `generation_in_progress` et rien d'écrit ; un échec en cours de retrait → rien d'écrit non plus
 * (pas de fermeture sans son retrait, pas de retrait sans sa fermeture).
 *
 * Les trois portes d'écriture sont prouvées par l'API réelle : POST de la contrainte `venue_closed`
 * (le geste qui complète la fermeture), PUT de re-datage d'une racine à plan (aperçu préalable
 * obligatoire dès qu'un retrait s'annonce, correction E2a), POST `/window` (élargissement). Plus la
 * porte DÉROBÉE fermée par E2b : une contrainte `venue_closed` rattachée ne change ni de gymnase ni
 * de dates par `PUT /api/constraints` (sinon on fermerait sans rien retirer).
 *
 * Jumeaux : `Integration/Service/ClosurePinRemoverTest` (la règle au niveau du service : jour
 * décoché, gymnase désactivé, version chargée, case bloc, club voisin, `preview == apply`) ;
 * `Integration/HardLockSurvivesPayloadTest` (un verrou d'un autre jour reste HARD dans le payload) ;
 * `Integration/Service/OrphanPinGuardTest` (le filet, si une épingle subsiste malgré tout).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClosureRemovesPinsAtomicallyTest extends WebTestCase
{
    use ClosurePinDecorTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * Le cœur de la promesse : Mistral fermé le mardi des vacances de mars → le verrou HARD de U13 le
     * mardi à Mistral redevient un placement (NONE, origine effacée) et la réservation de SM1 le mardi
     * à Mistral disparaît ; le jeudi à Mistral, le mardi à Brel et le verrou du SOCLE restent HARD ; la
     * réponse dit `removed` ; l'audit trace chaque retrait avec sa cause ; le verrou de génération est
     * relâché ; le plan devient périmé (empreinte) sans passer pour une retouche manuelle.
     */
    public function testDeclaringAClosureRemovesExactlyThePinsItPutsOutOfScope(): void
    {
        $d = $this->seedClosurePinDecor('CRA');
        $hashBefore = $this->servedStructureHash($d['user'], $d['holidayPlanId']);

        $closure = $this->declareClosure($this->client, $d['user'], $d['mistral'], $d['closedTuesday'], $d['closedTuesday']);

        self::assertSame(201, $closure['status'], json_encode($closure['body'], \JSON_THROW_ON_ERROR));
        self::assertSame(['locks' => 1, 'reservations' => 1], $closure['body']['removed'] ?? null, 'la réponse dit ce qui a été retiré');

        $clubId = $d['club']->getId();
        self::assertSame(LockLevel::NONE->value, $this->decorLockLevel($clubId, $d['lockTueMistral']), 'le verrou du mardi à Mistral est retiré');
        self::assertNull($this->decorLockOrigin($clubId, $d['lockTueMistral']), 'son origine est effacée, comme un déverrouillage');
        self::assertFalse($this->decorReservationExists($clubId, $d['reservationTueMistral']), 'la réservation du mardi à Mistral est retirée');

        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['lockThuMistral']), 'un autre JOUR du même gymnase reste verrouillé');
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['lockTueBrel']), 'un autre GYMNASE le même jour reste verrouillé');
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['socleLockTueMistral']), 'le SOCLE n’est jamais touché');

        $constraintId = $this->constraintIdOf($clubId, $closure['entryId']);
        $lockAudit = $this->decorAuditRows(AuditAction::LOCK_CHANGED->value, $d['lockTueMistral']);
        self::assertCount(1, $lockAudit, 'une ligne LOCK_CHANGED par verrou retiré');
        self::assertSame('venue_closed', $lockAudit[0]['cause'] ?? null, 'la trace dit la cause');
        self::assertSame($constraintId, $lockAudit[0]['constraintId'] ?? null, 'la trace nomme la fermeture');
        self::assertCount(1, $this->decorAuditRows(AuditAction::ENTITY_DELETED->value, $d['reservationTueMistral']), 'la réservation retirée laisse sa trace RGPD');

        self::assertFalse(self::getContainer()->get(ClubGenerationLock::class)->isGenerating($clubId), 'le verrou de génération pris par le retrait est relâché');
        self::assertNotSame($hashBefore, $this->servedStructureHash($d['user'], $d['holidayPlanId']), 'le plan devient périmé : son empreinte change');
        $this->scopeGucToClub($clubId);
        self::assertFalse(
            (bool) $this->em->getConnection()->fetchOne('SELECT manually_edited_since_generation FROM schedule WHERE id = :id', ['id' => $d['holidayVersionId']]),
            'un retrait système ne passe pas pour une retouche manuelle',
        );
    }

    /**
     * E1 — le retrait PREND le verrou de génération du club : une génération en vol le tient déjà →
     * 409 `generation_in_progress`, et RIEN n'est écrit (ni contrainte, ni retrait) — l'import de la
     * génération rematérialiserait sinon les verrous retirés.
     */
    public function testAClosureDeclaredDuringAGenerationIsRefusedAndWritesNothing(): void
    {
        $d = $this->seedClosurePinDecor('CRB');
        $clubId = $d['club']->getId();
        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($clubId, 120);
        self::assertIsString($token, 'décor : la « génération en vol » tient le verrou du club');

        try {
            $closure = $this->declareClosure($this->client, $d['user'], $d['mistral'], $d['closedTuesday'], $d['closedTuesday']);
        } finally {
            $lock->release($clubId, $token);
        }

        self::assertSame(409, $closure['status']);
        self::assertSame('generation_in_progress', $closure['body']['code'] ?? null);
        self::assertSame(0, $this->decorConstraintCount($clubId, $closure['entryId']), 'aucune fermeture enregistrée');
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['lockTueMistral']), 'aucun verrou retiré');
        self::assertTrue($this->decorReservationExists($clubId, $d['reservationTueMistral']), 'aucune réservation retirée');
    }

    /**
     * Atomicité — un échec PENDANT le retrait (injecté : la suppression de la réservation lève) annule
     * TOUT : la contrainte n'existe pas, le verrou est resté HARD, la réservation est là, aucune trace
     * LOCK_CHANGED orpheline, et le verrou de génération est relâché quand même.
     *
     * Hypothèse déclarée : le retrait supprime la réservation par l'ORM (`EntityManager::remove`, comme
     * `ReservationStateProcessor::processDelete`) — c'est ce qui rend l'échec injectable ici.
     */
    public function testAFailureWhileRemovingRollsBackTheWholeClosure(): void
    {
        $d = $this->seedClosurePinDecor('CRC');
        $clubId = $d['club']->getId();
        $this->client->disableReboot();
        $target = $d['reservationTueMistral'];
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class($target) {
            public function __construct(private readonly string $reservationId) {}

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
                    if ($entity instanceof Reservation && $entity->getId() === $this->reservationId) {
                        throw new RuntimeException('échec injecté pendant le retrait');
                    }
                }
            }
        });

        $closure = $this->declareClosure($this->client, $d['user'], $d['mistral'], $d['closedTuesday'], $d['closedTuesday']);

        self::assertGreaterThanOrEqual(500, $closure['status'], 'l’échec injecté doit avoir été atteint (sinon le retrait ne passe pas par l’ORM)');
        self::assertSame(0, $this->decorConstraintCount($clubId, $closure['entryId']), 'pas de fermeture sans son retrait');
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['lockTueMistral']), 'pas de retrait sans sa fermeture');
        self::assertTrue($this->decorReservationExists($clubId, $target));
        self::assertSame([], $this->decorAuditRows(AuditAction::LOCK_CHANGED->value, $d['lockTueMistral']), 'aucune trace d’un retrait annulé');
        self::assertFalse(self::getContainer()->get(ClubGenerationLock::class)->isGenerating($clubId), 'le verrou de génération est relâché même en échec');
    }

    /**
     * E2a — re-dater une racine de fermeture à plan d'un bloc qui RETIRERAIT quelque chose exige
     * l'aperçu : le PUT sans confirmation répond 409 avec les nombres et ne change rien ; confirmé,
     * il retire pour les SEULS jours nouveaux (le mardi de la semaine ajoutée), Brel intact.
     *
     * Contrat choisi par TANK (le plan ne le nomme pas) : 409 `{code: 'closure_pins_preview_required',
     * toRemove: {locks, reservations}}`, confirmation par `confirmPinRemoval: true` dans le corps du PUT.
     */
    public function testRedatingAClosureThatWouldRemovePinsNeedsTheAnnouncementFirst(): void
    {
        $d = $this->seedClosurePinDecor('CRD');
        [$entryId, $lockTueMistral, $lockTueBrel] = $this->seedClosureWithItsOwnPlan($d);
        $clubId = $d['club']->getId();

        $this->putEntryDates($d, $entryId, $this->decorMonday(12), $this->decorDay(13, 7));
        self::assertResponseStatusCodeSame(409);
        $refusal = $this->json();
        self::assertSame('closure_pins_preview_required', $refusal['code'] ?? null);
        self::assertSame(['locks' => 1, 'reservations' => 0], $refusal['toRemove'] ?? null, 'le refus annonce les nombres');
        self::assertSame([$this->decorMonday(12), $this->decorDay(12, 7)], $this->entryWindow($clubId, $entryId), 'rien n’est re-daté sans l’annonce');
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $lockTueMistral));

        $this->putEntryDates($d, $entryId, $this->decorMonday(12), $this->decorDay(13, 7), ['confirmPinRemoval' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(['locks' => 1, 'reservations' => 0], $this->json()['removed'] ?? null);
        self::assertSame(LockLevel::NONE->value, $this->decorLockLevel($clubId, $lockTueMistral), 'le mardi des jours nouveaux est retiré');
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $lockTueBrel), 'un autre gymnase reste verrouillé');
    }

    /** Témoin de E2a : un re-datage qui ne retire RIEN passe sans annonce, comme aujourd'hui. */
    public function testRedatingAClosureThatRemovesNothingNeedsNoAnnouncement(): void
    {
        $d = $this->seedClosurePinDecor('CRE');
        [$entryId, $lockTueMistral] = $this->seedClosureWithItsOwnPlan($d);
        $clubId = $d['club']->getId();
        // Plus rien à retirer : le verrou du mardi à Mistral est déjà un simple placement.
        $this->scopeGucToClub($clubId);
        $this->em->getConnection()->executeStatement('UPDATE schedule_slot_template SET lock_level = :n, lock_origin = NULL WHERE id = :id', ['n' => LockLevel::NONE->value, 'id' => $lockTueMistral]);

        $this->putEntryDates($d, $entryId, $this->decorMonday(12), $this->decorDay(13, 7));

        self::assertResponseIsSuccessful();
        self::assertSame([$this->decorMonday(12), $this->decorDay(13, 7)], $this->entryWindow($clubId, $entryId));
    }

    /** E1 sur la porte du re-datage : génération en vol → 409, la fermeture garde ses dates. */
    public function testRedatingDuringAGenerationIsRefusedAndWritesNothing(): void
    {
        $d = $this->seedClosurePinDecor('CRF');
        [$entryId, $lockTueMistral] = $this->seedClosureWithItsOwnPlan($d);
        $clubId = $d['club']->getId();
        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($clubId, 120);
        self::assertIsString($token);

        try {
            $this->putEntryDates($d, $entryId, $this->decorMonday(12), $this->decorDay(13, 7), ['confirmPinRemoval' => true]);
        } finally {
            $lock->release($clubId, $token);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame('generation_in_progress', $this->json()['code'] ?? null);
        self::assertSame([$this->decorMonday(12), $this->decorDay(12, 7)], $this->entryWindow($clubId, $entryId));
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $lockTueMistral));
    }

    /**
     * Troisième porte — élargir la fermeture par le rail `/window` (aperçu + jeton) retire pour les
     * SEULS jours nouveaux, et la réponse dit `removed`.
     */
    public function testExtendingAClosureByItsWindowRemovesThePinsOfTheNewDaysOnly(): void
    {
        $d = $this->seedClosurePinDecor('CRG');
        [$entryId, $lockTueMistral, $lockTueBrel, $planId] = $this->seedClosureWithItsOwnPlan($d);
        $clubId = $d['club']->getId();

        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window-preview', [], [], $this->decorHeaders($d['user']), json_encode(['startDate' => $this->decorMonday(12), 'endDate' => $this->decorDay(13, 7)], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $token = (string) ($this->json()['token'] ?? '');

        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window', [], [], $this->decorHeaders($d['user']), json_encode(['startDate' => $this->decorMonday(12), 'endDate' => $this->decorDay(13, 7), 'previewToken' => $token], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertSame(['locks' => 1, 'reservations' => 0], $this->json()['removed'] ?? null);
        self::assertSame(LockLevel::NONE->value, $this->decorLockLevel($clubId, $lockTueMistral));
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $lockTueBrel));
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['socleLockTueMistral']), 'le socle n’est jamais touché');
        self::assertNotSame('', $entryId);
    }

    /**
     * E2b — la porte DÉROBÉE : une contrainte `venue_closed` RATTACHÉE à une entrée ne change ni de
     * gymnase ni de dates par `PUT /api/constraints` (on fermerait sans retirer) → 422 « Modifiez la
     * fermeture depuis le calendrier. » ; renommer reste permis (témoin).
     */
    public function testAnAttachedClosureConstraintCannotMoveOutsideTheCalendar(): void
    {
        $d = $this->seedClosurePinDecor('CRH');
        $closure = $this->declareClosure($this->client, $d['user'], $d['mistral'], $this->decorDay(10, 2), $this->decorDay(10, 2));
        $clubId = $d['club']->getId();
        $constraintId = $this->constraintIdOf($clubId, $closure['entryId']);
        $base = [
            'name' => 'Mistral indisponible', 'scope' => 'FACILITY', 'scopeTargetId' => $d['mistral'], 'family' => 'FACILITY', 'ruleType' => 'HARD',
            'config' => ['type' => 'venue_closed', 'startDate' => $this->decorDay(10, 2), 'endDate' => $this->decorDay(10, 2)],
            'calendarEntryId' => $closure['entryId'],
        ];

        $this->client->request('PUT', '/api/constraints/' . $constraintId, [], [], $this->decorHeaders($d['user']), json_encode(['scopeTargetId' => $d['brel']] + $base, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Modifiez la fermeture depuis le calendrier.', (string) $this->client->getResponse()->getContent());

        $moved = $base;
        $moved['config']['startDate'] = $this->decorDay(8, 2);
        $moved['config']['endDate'] = $this->decorDay(8, 2);
        $this->client->request('PUT', '/api/constraints/' . $constraintId, [], [], $this->decorHeaders($d['user']), json_encode($moved, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['lockTueMistral']), 'aucun déplacement dérobé sur les vacances de mars');

        $this->client->request('PUT', '/api/constraints/' . $constraintId, [], [], $this->decorHeaders($d['user']), json_encode(['name' => 'Mistral fermé (mairie)'] + $base, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->pinDecorClock();
    }

    protected function tearDown(): void
    {
        $this->unpinDecorClock();
        parent::tearDown();
    }

    /**
     * Une racine de fermeture « d'un bloc » À PLAN (re-datable) : « Mistral en travaux », semaine +12,
     * sa contrainte `venue_closed` appariée, sa V1 VALIDÉE portant un verrou HARD de U13 le mardi à
     * Mistral (posé quand le fermé-total était inerte) et un à Brel.
     *
     * @param array<string, mixed> $d
     *
     * @return array{0: string, 1: string, 2: string, 3: string} [entryId, lockTueMistral, lockTueBrel, planId]
     */
    private function seedClosureWithItsOwnPlan(array $d): array
    {
        $club = $d['club'];
        $season = $d['season'];
        self::assertInstanceOf(\App\Entity\Club::class, $club);
        self::assertInstanceOf(\App\Entity\Season::class, $season);
        $this->scopeGucToClub($club->getId());
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Mistral en travaux')
            ->setStartDate(new DateTimeImmutable($this->decorMonday(12)))->setEndDate(new DateTimeImmutable($this->decorDay(12, 7)))
            ->setIsDisruptive(true)->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $constraint = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Mistral en travaux')
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId((string) $d['mistral'])
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($entry->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $this->decorMonday(12), 'endDate' => $this->decorDay(12, 7)]);
        $this->em->persist($constraint);
        $planId = $this->planIdOf($entry);

        $version = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Travaux V1')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version, $entry->getId());
        $this->em->flush();
        $lockTueMistral = $this->decorTemplate($club, $season, $version->getId(), (string) $d['u13'], (string) $d['mistral'], 2, '18:00', LockLevel::HARD);
        $lockTueBrel = $this->decorTemplate($club, $season, $version->getId(), (string) $d['sm1'], (string) $d['brel'], 2, '18:00', LockLevel::HARD);
        $this->em->flush();
        $this->choosePlanVersion($version);

        return [$entry->getId(), $lockTueMistral, $lockTueBrel, $planId];
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $extra
     */
    private function putEntryDates(array $d, string $entryId, string $start, string $end, array $extra = []): void
    {
        $user = $d['user'];
        self::assertInstanceOf(\App\Entity\User::class, $user);
        $this->client->request('PUT', '/api/calendar_entries/' . $entryId, [], [], $this->decorHeaders($user), json_encode([
            'kind' => 'period', 'periodType' => 'closure', 'title' => 'Mistral en travaux', 'startDate' => $start, 'endDate' => $end,
        ] + $extra, \JSON_THROW_ON_ERROR));
    }

    /** @return array{0: string, 1: string} */
    private function entryWindow(string $clubId, string $entryId): array
    {
        $this->scopeGucToClub($clubId);
        $row = $this->em->getConnection()->fetchAssociative('SELECT start_date, end_date FROM calendar_entry WHERE id = :id', ['id' => $entryId]);
        self::assertIsArray($row);

        return [mb_substr((string) $row['start_date'], 0, 10), mb_substr((string) $row['end_date'], 0, 10)];
    }

    private function constraintIdOf(string $clubId, string $entryId): string
    {
        $this->scopeGucToClub($clubId);
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM "constraint" WHERE calendar_entry_id = :eid', ['eid' => $entryId]);
        self::assertIsString($id, 'la fermeture porte sa contrainte');

        return $id;
    }

    private function servedStructureHash(\App\Entity\User $user, string $planId): ?string
    {
        $this->client->request('GET', '/api/schedule_plans/' . $planId . '/structure-hash', [], [], $this->decorHeaders($user));
        self::assertResponseIsSuccessful();
        $hash = $this->json()['currentStructureHash'] ?? null;

        return null === $hash ? null : (string) $hash;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($body) ? $body : [];
    }
}
