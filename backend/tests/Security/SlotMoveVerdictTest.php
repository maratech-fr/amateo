<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Exception\EngineTimeoutException;
use App\Exception\EvictTargetLockedException;
use App\Exception\EvictTargetMismatchException;
use App\Exception\ScheduleGenerationInProgressException;
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
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * NR — P2-2 F2b, axes *planning lifecycle* ET *constraint semantics* : LE DÉPLACEMENT D'UN
 * CRÉNEAU PASSE SOUS LE VERDICT DU MOTEUR, et le planning n'est ÉCRIT QUE SI le moteur dit
 * « oui ».
 *
 * Le geste passait par `manual-edit/one-time`, qui n'inspecte que les chevauchements bruts :
 * un créneau illégal (capacité, fenêtre, repos coach…) s'écrivait sans obstacle. F2b répare
 * ce danger livré — le backend HONORE le verdict.
 *
 * Ce qui est gardé ici (le moteur est MOQUÉ : on teste que le BACKEND respecte le verdict, pas
 * que le moteur sait refuser — ça, c'est le contrat cross-stack `ValidateAssignmentsContractSchemaTest`
 * + le smoke sémantique) :
 *  1. verdict « non » → le déplacement N'EST PAS écrit (état en base VÉRIFIÉ APRÈS, pas juste
 *     le retour) — falsification n°1 : faire écrire sans consulter le moteur rougit ici ;
 *  2. verdict « oui » → le créneau bouge ET le marqueur « retouché à la main » est posé ;
 *  3. la baseline envoyée au moteur NE CONTIENT PAS la source — sinon l'équipe se heurte à
 *     elle-même et un déplacement légal serait refusé (falsification n°2) ;
 *  4. une génération en cours → 409 (exception), le moteur n'est JAMAIS appelé (falsification n°3).
 *
 * P2-30 PR A — ÉVICTION (`evictSlotId`) : déplacer un créneau vers une cible occupée peut
 * demander de retirer l'occupant, MAIS toujours sous le verdict et sous D3 :
 *  5. éviction acceptée → la source bouge, la ligne de l'occupant est SUPPRIMÉE, la baseline
 *     figée ne contient NI la source NI l'occupant, le bloc `evicted` restitue l'état d'AVANT
 *     suppression, et le marqueur « retouché » est posé ;
 *  6. occupant verrouillé (lockLevel ≠ NONE, D3) → refus AVANT tout appel moteur, RIEN écrit ni
 *     supprimé ;
 *  7. verdict « non » sur un move avec éviction → RIEN supprimé ni déplacé ;
 *  8. `evictSlotId` ne siégeant pas à la cible → refus AVANT tout appel moteur, RIEN écrit.
 */
#[Group('phase1')]
#[Group('integration')]
final class SlotMoveVerdictTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string ACCEPT = '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    private const string CONFLICTING_TEAM_ID = '99999999-9999-4999-8999-999999999999';

    private const string COACH_ID = '88888888-8888-4888-8888-888888888888';

    private const string REFUSE = '{"valid":false,"violations":[{"rule":"coach_double_booking","message":"le coach Dupont a déjà les U15 à 20h dans un autre gymnase.","coachId":"88888888-8888-4888-8888-888888888888","dayOfWeek":4,"startTime":"20:00","conflictingTeamId":"99999999-9999-4999-8999-999999999999"}],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    /** Accepté AVEC un compromis nommé (P2-32) : le déplacement casse une préférence de gymnase. */
    private const string ACCEPT_WITH_COMPROMISES = '{"valid":true,"violations":[],"compromises":[{"family":"venue_preference","effect":"broken","message":"U13 ne s\'entraîne plus dans son gymnase préféré (Gymnase Un).","teamId":"team-u13","coachId":null,"venueId":"venue-un","dayOfWeek":null,"startTime":null}],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    private EntityManagerInterface $em;

    /** Verdict « non » : le créneau ne bouge PAS, et le marqueur reste à false. */
    public function testRefusedMoveIsNotWritten(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $originalDay = $slot->getDayOfWeek();
        $originalVenue = $slot->getVenueId();
        $originalStart = $slot->getStartTime()->format('H:i');

        $service = $this->service(new MockHttpClient(new MockResponse(self::REFUSE, ['http_code' => 200])));
        $result = $service->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertFalse($result['valid']);
        self::assertNotEmpty($result['violations']);
        self::assertSame('coach_double_booking', $result['violations'][0]['rule']);
        // Les ids du verdict transitent vers l'UI (surlignage du conflit) — tels que le moteur
        // les émet. conflictingTeamId nomme l'équipe DÉJÀ en place qui bloque le candidat.
        self::assertSame(self::CONFLICTING_TEAM_ID, $result['violations'][0]['conflictingTeamId']);
        self::assertSame(self::COACH_ID, $result['violations'][0]['coachId']);
        self::assertSame(4, $result['violations'][0]['dayOfWeek']);
        self::assertSame('20:00', $result['violations'][0]['startTime']);
        // Absents du verdict → null-safe, jamais une clé manquante.
        self::assertNull($result['violations'][0]['teamId']);
        self::assertNull($result['violations'][0]['venueId']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame($originalDay, $reloaded->getDayOfWeek(), 'un déplacement refusé ne doit PAS déplacer le créneau');
        self::assertSame($originalVenue, $reloaded->getVenueId());
        self::assertSame($originalStart, $reloaded->getStartTime()->format('H:i'));

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertFalse($schedule->isManuallyEditedSinceGeneration(), 'un refus ne marque pas le planning comme retouché');
    }

    /** Verdict « oui » : le créneau bouge, et le score devient périmé (marqueur posé). */
    public function testAcceptedMoveIsWrittenAndMarksScoreStale(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];

        $service = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT, ['http_code' => 200])));
        $result = $service->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertTrue($result['valid']);
        self::assertSame([], $result['violations']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame(4, $reloaded->getDayOfWeek());
        self::assertSame('20:00', $reloaded->getStartTime()->format('H:i'));
        self::assertSame($ctx['venue2'], $reloaded->getVenueId());

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertTrue($schedule->isManuallyEditedSinceGeneration(), 'un déplacement accepté rend le score affiché périmé');
    }

    /** La baseline envoyée au moteur NE contient PAS la source (sinon l'équipe se heurte à elle-même). */
    public function testSourceIsRemovedFromTheBaseline(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];

        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse(self::ACCEPT, ['http_code' => 200]);
        });

        $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertIsArray($captured);
        // Contrat 2.18 — `candidates` est une LISTE (un déplacement simple = une liste à 1 élément).
        self::assertArrayHasKey('candidates', $captured);
        self::assertCount(1, $captured['candidates']);
        self::assertSame($slot->getTeamId(), $captured['candidates'][0]['teamId']);
        self::assertArrayHasKey('slotTemplates', $captured);
        $ids = array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $captured['slotTemplates']);
        self::assertNotContains($slot->getId(), $ids, 'la SOURCE doit être retirée de la baseline avant le verdict');

        // P2-32 / 2.18 — le payload porte `references` (liste appariée) = le placement d'ORIGINE de
        // la source (état « avant » du DELTA de compromis), pas la cible du déplacement.
        self::assertArrayHasKey('references', $captured);
        self::assertCount(1, $captured['references']);
        self::assertSame($slot->getTeamId(), $captured['references'][0]['teamId']);
        self::assertSame($ctx['venue1'], $captured['references'][0]['venueId'], 'reference = le gymnase D\'ORIGINE');
        self::assertSame(2, $captured['references'][0]['dayOfWeek'], 'reference = le jour D\'ORIGINE (mardi)');
        self::assertSame('18:00', $captured['references'][0]['startTime']);
    }

    /**
     * P2-32 — dryRun accepté : le verdict est rendu AVEC ses compromis, mais RIEN n'est écrit —
     * le créneau ne bouge pas, le marqueur reste à false. État SONDÉ en base (pas juste le retour).
     */
    public function testDryRunAcceptedWritesNothingButReturnsCompromises(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $originalDay = $slot->getDayOfWeek();
        $originalVenue = $slot->getVenueId();

        $service = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT_WITH_COMPROMISES, ['http_code' => 200])));
        $result = $service->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2'], null, true);

        self::assertTrue($result['valid']);
        self::assertTrue($result['dryRun']);
        self::assertNotEmpty($result['compromises'], 'un essai accepté doit rendre les compromis nommés');
        self::assertSame('venue_preference', $result['compromises'][0]['family']);
        self::assertSame('broken', $result['compromises'][0]['effect']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame($originalDay, $reloaded->getDayOfWeek(), 'un essai ne déplace PAS le créneau');
        self::assertSame($originalVenue, $reloaded->getVenueId());

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertFalse($schedule->isManuallyEditedSinceGeneration(), 'un essai ne marque PAS le planning comme retouché');
    }

    /** P2-32 — dryRun refusé : rien écrit non plus, et le retour porte le verdict « non ». */
    public function testDryRunRefusedWritesNothing(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $originalDay = $slot->getDayOfWeek();

        $service = $this->service(new MockHttpClient(new MockResponse(self::REFUSE, ['http_code' => 200])));
        $result = $service->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2'], null, true);

        self::assertFalse($result['valid']);
        self::assertTrue($result['dryRun']);
        self::assertNotEmpty($result['violations']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertSame($originalDay, $reloaded?->getDayOfWeek(), 'un essai refusé ne déplace pas le créneau');
        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertFalse($schedule?->isManuallyEditedSinceGeneration());
    }

    /**
     * P2-32 — dryRun avec éviction : le verrou reste souverain (D3) et l'occupant N'EST PAS
     * supprimé ; le bloc `evicted` décrit ce qui SERAIT libéré.
     */
    public function testDryRunWithEvictionDeletesNothing(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $occupant = $this->seedOccupant($ctx, 4, '20:00', $ctx['venue2']);
        $occupantId = $occupant->getId();

        $service = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT_WITH_COMPROMISES, ['http_code' => 200])));
        $result = $service->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2'], $occupantId, true);

        self::assertTrue($result['valid']);
        self::assertTrue($result['dryRun']);
        self::assertIsArray($result['evicted']);
        self::assertSame($occupantId, $result['evicted']['slotId']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertNotNull(
            $this->em->getRepository(ScheduleSlotTemplate::class)->find($occupantId),
            'un essai ne supprime PAS l\'occupant qui SERAIT évincé',
        );
    }

    /** Une génération en cours → 409 (exception), et le moteur n'est jamais consulté. */
    public function testMoveDuringGenerationIsRejectedWithoutCallingTheEngine(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];

        $lock = self::getContainer()->get(ClubGenerationLock::class);
        self::assertNotNull($lock->acquire($ctx['clubId'], 60), 'seed: le verrou de génération doit être acquis');

        // Un client qui ÉCHOUE le test s'il est appelé : la sonde isGenerating() doit court-circuiter avant.
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('le moteur ne doit PAS être appelé pendant une génération');
        });

        try {
            $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);
            self::fail('un déplacement pendant une génération doit lever');
        } catch (ScheduleGenerationInProgressException) {
            // attendu
        }

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertSame($slot->getDayOfWeek(), $reloaded?->getDayOfWeek(), 'rien ne bouge pendant une génération');
    }

    /** Éviction acceptée : la source bouge, l'occupant est SUPPRIMÉ, le bloc `evicted` restitue l'avant. */
    public function testEvictionAcceptedMovesSourceAndDeletesOccupant(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $occupant = $this->seedOccupant($ctx, 4, '20:00', $ctx['venue2']);
        $occupantId = $occupant->getId();
        $occupantTeamId = $occupant->getTeamId();

        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse(self::ACCEPT, ['http_code' => 200]);
        });

        $result = $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2'], $occupantId);

        self::assertTrue($result['valid']);
        // Le bloc `evicted` = l'état d'AVANT suppression, pour que le front propose de replacer.
        self::assertIsArray($result['evicted']);
        self::assertSame($occupantId, $result['evicted']['slotId']);
        self::assertSame($occupantTeamId, $result['evicted']['teamId']);
        self::assertSame(4, $result['evicted']['dayOfWeek']);
        self::assertSame('20:00', $result['evicted']['startTime']);
        self::assertSame($ctx['venue2'], $result['evicted']['venueId']);
        self::assertSame(90, $result['evicted']['durationMinutes']);

        // La baseline figée ne contient NI la source NI l'occupant évincé.
        self::assertIsArray($captured);
        $ids = array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $captured['slotTemplates']);
        self::assertNotContains($slot->getId(), $ids, 'la SOURCE doit être retirée de la baseline');
        self::assertNotContains($occupantId, $ids, 'l\'OCCUPANT évincé doit être retiré de la baseline');

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame(4, $reloaded->getDayOfWeek());
        self::assertSame('20:00', $reloaded->getStartTime()->format('H:i'));
        self::assertSame($ctx['venue2'], $reloaded->getVenueId());

        self::assertNull(
            $this->em->getRepository(ScheduleSlotTemplate::class)->find($occupantId),
            'l\'occupant évincé doit être supprimé de la base',
        );

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertTrue($schedule->isManuallyEditedSinceGeneration());
    }

    /** D3 : un occupant VERROUILLÉ ne peut être évincé — refus avant tout appel moteur, rien touché. */
    public function testEvictionOfLockedOccupantIsRefusedWithoutCallingEngine(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $occupant = $this->seedOccupant($ctx, 4, '20:00', $ctx['venue2'], 90, LockLevel::HARD);
        $occupantId = $occupant->getId();

        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('le moteur ne doit PAS être appelé quand la cible est verrouillée (D3)');
        });

        try {
            $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2'], $occupantId);
            self::fail('évincer un créneau verrouillé doit lever');
        } catch (EvictTargetLockedException) {
            // attendu
        }

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertSame($slot->getDayOfWeek(), $reloaded?->getDayOfWeek(), 'la source ne bouge pas');
        self::assertNotNull(
            $this->em->getRepository(ScheduleSlotTemplate::class)->find($occupantId),
            'un occupant verrouillé ne doit pas être supprimé',
        );

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertFalse($schedule?->isManuallyEditedSinceGeneration());
    }

    /** Verdict « non » sur un move avec éviction : RIEN supprimé ni déplacé. */
    public function testRefusedMoveWithEvictionDeletesNothing(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $occupant = $this->seedOccupant($ctx, 4, '20:00', $ctx['venue2']);
        $occupantId = $occupant->getId();

        $service = $this->service(new MockHttpClient(new MockResponse(self::REFUSE, ['http_code' => 200])));
        $result = $service->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2'], $occupantId);

        self::assertFalse($result['valid']);
        self::assertArrayNotHasKey('evicted', $result);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertSame($slot->getDayOfWeek(), $reloaded?->getDayOfWeek(), 'un refus ne déplace pas la source');
        self::assertNotNull(
            $this->em->getRepository(ScheduleSlotTemplate::class)->find($occupantId),
            'un refus ne supprime pas l\'occupant',
        );
    }

    /** L'occupant désigné ne siège PAS à la cible (autre jour) → refus avant le moteur, rien touché. */
    public function testEvictSlotNotSittingAtTargetIsRejected(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        // Occupant au MÊME gymnase cible mais un autre jour : il ne siège pas là où le candidat atterrit.
        $occupant = $this->seedOccupant($ctx, 3, '20:00', $ctx['venue2']);
        $occupantId = $occupant->getId();

        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('le moteur ne doit PAS être appelé quand la cible d\'éviction ne correspond pas');
        });

        try {
            $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2'], $occupantId);
            self::fail('un evictSlotId ne siégeant pas à la cible doit lever');
        } catch (EvictTargetMismatchException) {
            // attendu
        }

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertSame($slot->getDayOfWeek(), $reloaded?->getDayOfWeek());
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($occupantId));
    }

    /**
     * Le moteur est TROP LENT (délai transport dépassé) : le service NE DOIT NI écrire, NI
     * inventer un verdict — il traduit le timeout en {@see EngineTimeoutException} PORTANT son
     * code machine (`engine_timeout`), que le contrôleur mappe en 504. Falsification : avaler le
     * timeout en « oui » (écrire) ou en « non » nu (verdict inventé) rougit ici. Régression
     * fondateur : sur un club dense, le déplacement était LÉGAL mais le backend abandonnait ~0,7 s
     * avant la réponse du moteur et rendait un 502 muet.
     */
    public function testEngineTimeoutIsNotWrittenAndCarriesItsCode(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $originalDay = $slot->getDayOfWeek();

        // Le client HTTP dépasse le délai : un TimeoutException (TimeoutExceptionInterface) — le
        // MÊME type que « Idle timeout reached » observé en production locale.
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TimeoutException('Idle timeout reached for "http://engine:8000/validate-assignments".');
        });

        try {
            $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);
            self::fail('un timeout moteur doit lever, jamais rendre un verdict');
        } catch (EngineTimeoutException $e) {
            // Le service NOMME la cause pour le contrôleur (→ 504 + code), il ne devine pas.
            self::assertSame('engine_timeout', $e->errorCode());
        }

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame($originalDay, $reloaded->getDayOfWeek(), 'un timeout ne doit PAS déplacer le créneau');

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertFalse($schedule->isManuallyEditedSinceGeneration(), 'un timeout ne marque pas le planning comme retouché');
    }

    /**
     * P4-119 (c) — INVARIANT : le backend n'applique JAMAIS un verdict que le moteur n'a pas rendu
     * À TEMPS, éviction comprise. Le cas nu (sans éviction) est déjà épinglé par
     * {@see testEngineTimeoutIsNotWrittenAndCarriesItsCode} ; ici la VARIANTE éviction, la plus
     * dangereuse — l'écriture supprime l'occupant. Un timeout transport survient : le service lève
     * {@see EngineTimeoutException} (→ 504) et NE DOIT rien avoir écrit — le créneau ne bouge pas
     * (jour/heure/gymnase intacts), l'occupant à évincer n'est PAS supprimé, le marqueur reste à
     * false. Falsification : déplacer l'écriture (setDayOfWeek/remove(evicted)/flush) AVANT
     * `validateUnderTimeout` supprimerait l'occupant et déplacerait la source malgré le timeout →
     * rouge ici. Le cas nginx-499 (client parti / serveur qui termine quand même) n'est PAS simulé
     * : ce test couvre le vrai risque « le moteur n'a pas répondu à temps », testable proprement.
     */
    public function testEngineTimeoutWithEvictionDeletesNothingAndCarriesItsCode(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $originalDay = $slot->getDayOfWeek();
        $originalVenue = $slot->getVenueId();
        $originalStart = $slot->getStartTime()->format('H:i');
        $occupant = $this->seedOccupant($ctx, 4, '20:00', $ctx['venue2']);
        $occupantId = $occupant->getId();

        // Le moteur dépasse le délai transport (MÊME type que « Idle timeout reached » en prod) —
        // le verdict n'arrive jamais, donc l'éviction ne doit surtout pas s'appliquer.
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TimeoutException('Idle timeout reached for "http://engine:8000/validate-assignments".');
        });

        try {
            $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2'], $occupantId);
            self::fail('un timeout moteur doit lever, jamais appliquer l\'éviction');
        } catch (EngineTimeoutException $e) {
            self::assertSame('engine_timeout', $e->errorCode());
        }

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame($originalDay, $reloaded->getDayOfWeek(), 'un timeout ne déplace PAS la source');
        self::assertSame($originalVenue, $reloaded->getVenueId());
        self::assertSame($originalStart, $reloaded->getStartTime()->format('H:i'));

        self::assertNotNull(
            $this->em->getRepository(ScheduleSlotTemplate::class)->find($occupantId),
            'un verdict jamais rendu ne doit PAS supprimer l\'occupant à évincer',
        );

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertFalse($schedule->isManuallyEditedSinceGeneration(), 'un timeout ne marque pas le planning comme retouché');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Un créneau OCCUPANT sur le planning du contexte, au jour/heure/gymnase donnés — la cible
     * d'une éviction. Un lockLevel ≠ NONE le rend souverain (D3).
     *
     * @param array{clubId: string, seasonId: string, scheduleId: string, venue1: string, venue2: string, slot: ScheduleSlotTemplate} $ctx
     */
    private function seedOccupant(array $ctx, int $day, string $startHi, string $venueId, int $durationMinutes = 90, LockLevel $lock = LockLevel::NONE): ScheduleSlotTemplate
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
            ->setLockLevel($lock);
        if (LockLevel::NONE !== $lock) {
            $occupant->setLockOrigin(LockOrigin::MANUAL);
        }
        $this->em->persist($occupant);
        $this->em->flush();

        return $occupant;
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
     * Un club/saison/plan SEASON, un planning terminé NON choisi (donc éditable), et un
     * créneau source (U13, mardi 18h). Un second gymnase sert de cible au déplacement.
     *
     * @return array{clubId: string, seasonId: string, scheduleId: string, venue1: string, venue2: string, slot: ScheduleSlotTemplate}
     */
    private function seed(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('smv-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

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

        // La fenêtre de gymnase de DESTINATION de tous les déplacements du test : venue2, jeudi (4),
        // 20:00 (schedulePlanId null = grille de saison, ce que charge buildForClubSeason). Depuis
        // la garde précoce de move(), un déplacement vers un triplet SANS fenêtre lève
        // SlotUnavailableException avant le moteur — sans cette fenêtre, tout le test rougirait.
        $window = (new VenueTrainingSlot)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setVenueId($venueIds[1])
            ->setDayOfWeek(4)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '20:00'))
            ->setDurationMinutes(90)
            ->setCapacity(1);
        $this->em->persist($window);
        $this->em->flush();

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

        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Plan')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        // lot D : la version ne peut être flushée sans plan — linkSeededSchedule résout le plan SEASON.
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
            ->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();

        return [
            'clubId' => $clubId, 'seasonId' => $seasonId, 'scheduleId' => $schedule->getId(),
            'venue1' => $venueIds[0], 'venue2' => $venueIds[1], 'slot' => $slot,
        ];
    }
}
