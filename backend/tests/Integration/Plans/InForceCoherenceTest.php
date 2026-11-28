<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plans;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Person;
use App\Entity\PlanTeam;
use App\Entity\Reservation;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\TeamCoachRole;
use App\Plans\InForceCoherence;
use App\Plans\PendingChanges;
use App\Plans\PlanComposition;
use App\Service\EngineClient;
use App\Service\RequestIdContext;
use App\Tests\EngineRequestDecorTrait;
use App\Tests\Integration\EngineRequestReferenceTest;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Promesse (lot conception 5, PR 6, ligne 47 du plan) : avant « Valider ces modifications », le contrôle d'incohérences
 * soumet au verdict du moteur les seules séances touchées par les modifications en attente, à leur place actuelle, le
 * reste du planning figé ; il découpe par demandes de 50 séances au plus, six au plus ; au-delà de 300 séances, ou sur
 * un refus, une erreur ou un délai du moteur, il répond « non concluant » sans lever ; une règle cassée est nommée avec
 * sa séance.
 *
 * Angle : les nombreux cas du contrôle (table « quelles séances sont touchées » du §3.6, découpage, issues du moteur).
 * Le parcours de bout en bout, sur la stack, est la feature `valider-des-modifications-sans-regenerer.feature` (trois
 * exemples d'annonce, moteur réel) ; la demande d'un contrôle est figée à l'octet par la référence 22 de
 * {@see EngineRequestReferenceTest}.
 *
 * Décor : `EngineRequestDecorTrait` (U13 F et U15 M tenues par l'entraîneur A, SM1 par B, A joue en SM1 ; passerelle
 * U13-U15 ; gymnases Nord sécable et Sud) ; une version en vigueur, pointée, chargée, avec sa copie ; puis une
 * modification de l'état de travail, sans valider. Le moteur est simulé : chaque demande reçue est relevée.
 */
#[Group('phase1')]
#[Group('integration')]
final class InForceCoherenceTest extends KernelTestCase
{
    use EngineRequestDecorTrait;

    /** Les cinq séances du décor des séances touchées : [n, équipe, gymnase, jour, heure]. */
    private const array FIVE_SESSIONS = [
        1 => ['U13', 'NORD', 1, '18:00'],
        2 => ['U15', 'NORD', 3, '18:00'],
        3 => ['SM1', 'NORD', 4, '18:00'],
        4 => ['SM1', 'SUD', 2, '18:00'],
        5 => ['U15', 'SUD', 4, '19:30'],
    ];

    private const string ER_COACH_C = 'c0ac0000-0000-4000-8000-000000000003';

    /** @var list<array<string, mixed>> les demandes que le moteur simulé a reçues, dans l'ordre */
    private array $engineRequests = [];

    /** @var Closure(array<string, mixed>): MockResponse la réponse du moteur simulé à une demande */
    private Closure $engineAnswer;

    /** @return iterable<string, array{string, list<int>}> une ligne de la table du §3.6 → les séances (n) soumises */
    public static function touchedByEachRowOfTheTable(): iterable
    {
        yield 'coach principal changé (SM1 : B remplacé par C, sans équipe)' => ['coach principal', [3, 4]];
        yield 'coach-joueur ajouté (B joue en U15)' => ['coach-joueur', [2, 3, 4, 5]];
        yield 'règle d\'une équipe (U15)' => ['règle d\'équipe', [2, 5]];
        yield 'règle d\'un coach (B, qui tient SM1)' => ['règle de coach', [3, 4]];
        yield 'règle d\'un gymnase (Sud)' => ['règle de gymnase', [4, 5]];
        yield 'règle de club' => ['règle de club', [1, 2, 3, 4, 5]];
        yield 'créneau de grille retiré (Nord, jeudi 18:00)' => ['créneau retiré', [3]];
        yield 'réservation retirée (SM1, Sud, mardi 18:00)' => ['réservation retirée', [3, 4]];
        yield 'nombre de séances d\'une équipe (U13)' => ['nombre de séances', [1]];
        yield 'passerelle retirée (U13-U15)' => ['passerelle retirée', [1, 2, 5]];
        yield 'rang d\'une équipe (U13)' => ['rang', [1]];
        yield '« divisible » d\'un gymnase (Nord)' => ['divisible', [1, 2, 3]];
    }

    /** @return iterable<string, array{string, int}> */
    public static function engineFailures(): iterable
    {
        yield 'refus 422 du moteur' => ['{"detail":[{"type":"too_long","loc":["body","candidates"]}]}', 422];
        yield 'délai atteint (indéterminé)' => ['{"valid":false,"indeterminate":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":2000}}', 200];
        yield 'moteur en erreur' => ['{"detail":"internal"}', 500];
    }

    /** Chaque ligne de la table « quelles séances sont touchées » soumet exactement ses séances, aucune autre. */
    #[DataProvider('touchedByEachRowOfTheTable')]
    public function testEachRowOfTheTableTouchesItsOwnSessionsOnly(string $modification, array $expected): void
    {
        $this->seedFiveSessionsInForce();
        $this->modify($modification);

        $changes = self::getContainer()->get(PendingChanges::class)->changesOf($this->erSeasonPlanId(), self::ER_SOCLE_V1);
        self::assertFalse($changes->isEmpty(), 'témoin : la modification attend la validation');
        $touched = self::getContainer()->get(InForceCoherence::class)->touchedSlotIds($this->erSeasonPlanId(), $changes);

        $expectedIds = array_map(static fn (int $n): string => self::erId('5107', $n), $expected);
        sort($expectedIds);
        sort($touched);
        self::assertSame($expectedIds, $touched, \sprintf('%s : les séances soumises au contrôle', $modification));
    }

    /** Le contrôle soumet les séances touchées à leur place actuelle, en une demande ; le moteur les accepte : rien à dire. */
    public function testOnlyTheTouchedSessionsAreSubmittedAtTheirCurrentPlace(): void
    {
        $this->seedFiveSessionsInForce();
        $this->modify('règle d\'équipe');

        $result = $this->check();

        self::assertCount(1, $this->engineRequests, 'deux séances touchées : une seule demande');
        $candidates = $this->candidatesOf($this->engineRequests[0]);
        sort($candidates);
        self::assertSame([
            self::ER_TEAM_U15 . '|' . self::ER_VENUE_NORD . '|3|18:00',
            self::ER_TEAM_U15 . '|' . self::ER_VENUE_SUD . '|4|19:30',
        ], $candidates, 'les séances des U15, à leur place actuelle');
        self::assertSame(['incoherences' => [], 'missingSessions' => [], 'inconclusive' => false], $result);
    }

    /** Une règle de club touche 120 séances : trois demandes de 50 au plus, chacune soumet ses propres séances. */
    public function testAClubRuleOn120SessionsIsCheckedInThreeRequestsOfAtMost50(): void
    {
        $this->seedManySessionsInForce(120);
        $this->modify('règle de club');

        $result = $this->check();

        self::assertCount(3, $this->engineRequests, '120 séances touchées : trois demandes');
        $sizes = array_map(fn (array $request): int => \count($this->candidatesOf($request)), $this->engineRequests);
        foreach ($sizes as $size) {
            self::assertLessThanOrEqual(50, $size, 'jamais plus de 50 séances par demande (borne du moteur)');
        }
        self::assertSame(120, array_sum($sizes), 'chaque séance touchée est soumise une fois');
        self::assertFalse($result['inconclusive']);
    }

    /** 301 séances touchées dépassent six demandes de 50 : non concluant, sans aucun appel au moteur. */
    public function testMoreThan300TouchedSessionsAreInconclusiveWithoutCallingTheEngine(): void
    {
        $this->seedManySessionsInForce(301);
        $this->modify('règle de club');

        $result = $this->check();

        self::assertSame([], $this->engineRequests, 'aucun appel au moteur');
        self::assertTrue($result['inconclusive'], 'au-delà de 300 séances : non concluant');
        self::assertSame([], $result['incoherences']);
    }

    /** Le moteur refuse la demande (422), ou ne conclut pas dans son délai : le contrôle est non concluant et ne lève pas. */
    #[DataProvider('engineFailures')]
    public function testAnEngineRefusalOrTimeoutIsInconclusive(string $body, int $status): void
    {
        $this->seedFiveSessionsInForce();
        $this->modify('règle d\'équipe');
        $this->engineAnswer = static fn (): MockResponse => new MockResponse($body, ['http_code' => $status]);

        $result = $this->check();

        self::assertCount(1, $this->engineRequests, 'témoin : le moteur a été appelé');
        self::assertTrue($result['inconclusive'], 'refus ou délai du moteur : non concluant');
        self::assertSame([], $result['incoherences'], 'un refus n\'est pas une incohérence');
    }

    /** Une règle cassée est nommée avec sa séance ; le contrôle conclut, il ne bloque pas. */
    public function testABrokenRuleIsNamedWithItsSession(): void
    {
        $this->seedFiveSessionsInForce();
        $this->modify('règle d\'équipe');
        $this->engineAnswer = static fn (): MockResponse => new MockResponse(json_encode([
            'valid' => false,
            'violations' => [[
                'rule' => 'coach_double_booking',
                'message' => 'L\'entraîneur est déjà en séance à ce moment.',
                'teamId' => self::ER_TEAM_U15, 'coachId' => self::ER_COACH_A, 'venueId' => self::ER_VENUE_SUD,
                'dayOfWeek' => 4, 'startTime' => '19:30', 'conflictingTeamId' => self::ER_TEAM_SM1,
            ]],
            'metrics' => ['solver_version' => 'cp-sat', 'nb_variables' => 0, 'nb_constraints' => 0, 'wall_time_ms' => 0],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);

        $result = $this->check();

        self::assertFalse($result['inconclusive'], 'un verdict rendu conclut');
        self::assertCount(1, $result['incoherences'], 'une incohérence');
        $incoherence = $result['incoherences'][0];
        self::assertSame(self::erId('5107', 5), $incoherence['slotId'] ?? null, 'la séance nommée : U15, Sud, jeudi 19:30');
        self::assertSame(self::ER_TEAM_U15, $incoherence['teamId'] ?? null);
        self::assertSame('U15 M', $incoherence['teamName'] ?? null);
        self::assertSame(4, $incoherence['dayOfWeek'] ?? null);
        self::assertSame('19:30', $incoherence['startTime'] ?? null);
        self::assertSame('coach_double_booking', $incoherence['rule'] ?? null, 'la règle cassée, nommée');
        self::assertIsString($incoherence['label'] ?? null);
        self::assertNotSame('', $incoherence['label']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->engineAnswer = static fn (): MockResponse => new MockResponse('{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}', ['http_code' => 200]);
        // Avant tout service qui en dépend : un service déjà instancié ne se remplace plus.
        self::getContainer()->set(EngineClient::class, new EngineClient(new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringEndsWith('/validate-assignments', $url, 'le contrôle passe par le verdict existant');
            $body = json_decode((string) ($options['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            $this->engineRequests[] = $body;

            return ($this->engineAnswer)($body);
        }), new RequestIdContext));
        $this->pinEngineRequestClock();
        $this->seedEngineRequestBase();
    }

    protected function tearDown(): void
    {
        $this->purgeEngineRequestCache();
        $this->unpinEngineRequestClock();
        parent::tearDown();
    }

    /** @return array{incoherences: list<array<string, mixed>>, missingSessions: list<array<string, mixed>>, inconclusive: bool} */
    private function check(): array
    {
        $this->erEm()->flush();
        $this->erEm()->clear();
        $this->purgeEngineRequestCache();
        $this->scopeGucToClub(self::ER_CLUB);

        return self::getContainer()->get(InForceCoherence::class)->check($this->erSeasonPlanId());
    }

    /** La version 1 en vigueur, pointée, chargée, avec sa copie, portant les cinq séances du décor. */
    private function seedFiveSessionsInForce(): void
    {
        $teams = ['U13' => self::ER_TEAM_U13, 'U15' => self::ER_TEAM_U15, 'SM1' => self::ER_TEAM_SM1];
        $venues = ['NORD' => self::ER_VENUE_NORD, 'SUD' => self::ER_VENUE_SUD];
        $slots = [];
        foreach (self::FIVE_SESSIONS as $n => [$team, $venue, $day, $time]) {
            $slots[] = [$n, $teams[$team], $venues[$venue], $day, $time, LockLevel::NONE];
        }
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, $slots);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);
    }

    /** Une version en vigueur portant `$count` séances, réparties sur les trois équipes, les deux gymnases, du lundi au vendredi. */
    private function seedManySessionsInForce(int $count): void
    {
        $teams = [self::ER_TEAM_U13, self::ER_TEAM_U15, self::ER_TEAM_SM1];
        $venues = [self::ER_VENUE_NORD, self::ER_VENUE_SUD];
        $slots = [];
        for ($n = 1; $n <= $count; ++$n) {
            $slots[] = [$n, $teams[$n % 3], $venues[$n % 2], 1 + ($n % 5), \sprintf('%02d:%02d', 8 + intdiv($n, 30) % 14, ($n % 2) * 30), LockLevel::NONE];
        }
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, $slots);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);
    }

    /** Une modification de l'état de travail, sans valider : le geste du Wizard, écrit par les entités. */
    private function modify(string $modification): void
    {
        $em = $this->erEm();
        $this->scopeGucToClub(self::ER_CLUB);
        switch ($modification) {
            case 'coach principal':
                $person = (new Person)->setClubId(self::ER_CLUB)->setFirstName('Cyan')->setLastName('Entraîneur');
                $em->persist($person);
                $em->persist(new Coach($person)->setId(self::ER_COACH_C)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON));
                $em->remove($this->found(TeamCoach::class, self::erId('7c0a', 3)));
                $em->flush();
                $em->persist((new TeamCoach)->setId(self::erId('7c0a', 9))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                    ->setTeamId(self::ER_TEAM_SM1)->setCoachId(self::ER_COACH_C)->setRole(TeamCoachRole::MAIN));
                break;
            case 'coach-joueur':
                $em->persist((new CoachPlayerMembership)->setId(self::erId('3e3b', 2))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                    ->setCoachId(self::ER_COACH_B)->setTeamId(self::ER_TEAM_U15)->setIsActive(true));
                break;
            case 'règle d\'équipe':
                $this->erConstraint(11, ConstraintScope::TEAM, self::ER_TEAM_U15, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['minStartTime' => '19:00']);
                break;
            case 'règle de coach':
                $this->erConstraint(12, ConstraintScope::COACH, self::ER_COACH_B, ConstraintFamily::COACH_AVAILABILITY, ConstraintRuleType::HARD, ['unavailableDays' => [6]]);
                break;
            case 'règle de gymnase':
                $this->erConstraint(13, ConstraintScope::FACILITY, self::ER_VENUE_SUD, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['minStartTime' => '19:00']);
                break;
            case 'règle de club':
                $this->erConstraint(14, ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['minStartTime' => '17:00']);
                break;
            case 'créneau retiré':
                $em->remove($this->found(VenueTrainingSlot::class, self::erId('a7a1', 4)));
                break;
            case 'réservation retirée':
                $em->remove($this->found(Reservation::class, self::erId('e5e0', 1)));
                break;
            case 'nombre de séances':
                $line = $em->getRepository(PlanTeam::class)->findOneBy(['schedulePlanId' => $this->erSeasonPlanId(), 'teamId' => self::ER_TEAM_U13]);
                self::assertInstanceOf(PlanTeam::class, $line, 'témoin : les U13 ont leur ligne dans le plan de saison');
                $line->setSessionsPerWeek(3);
                break;
            case 'passerelle retirée':
                $em->remove($this->found(TeamLink::class, self::erId('11c0', 1)));
                break;
            case 'rang':
                $this->found(Team::class, self::ER_TEAM_U13)->setPriorityTierId(2);
                break;
            case 'divisible':
                $this->found(Venue::class, self::ER_VENUE_NORD)->setCanSplit(false);
                break;
            default:
                self::fail('modification inconnue : ' . $modification);
        }
        $em->flush();
        // Comme une saisie : une règle ajoutée reçoit sa ligne dans le plan de saison.
        self::getContainer()->get(PlanComposition::class)->ensureLines(self::ER_SEASON);
        $em->flush();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function found(string $class, string $id): object
    {
        $row = $this->erEm()->find($class, $id);
        self::assertInstanceOf($class, $row, \sprintf('témoin : %s %s existe dans le décor', $class, $id));

        return $row;
    }

    /**
     * Les candidates d'une demande de verdict : `équipe|gymnase|jour|heure`.
     *
     * @param array<string, mixed> $request
     *
     * @return list<string>
     */
    private function candidatesOf(array $request): array
    {
        $candidates = \is_array($request['candidates'] ?? null) ? $request['candidates'] : [];

        return array_values(array_map(static function (mixed $candidate): string {
            self::assertIsArray($candidate);
            $start = \is_string($candidate['startTime'] ?? null) ? substr($candidate['startTime'], 0, 5) : '';

            return \sprintf('%s|%s|%s|%s', (string) ($candidate['teamId'] ?? ''), (string) ($candidate['venueId'] ?? ''), (string) ($candidate['dayOfWeek'] ?? ''), $start);
        }, $candidates));
    }
}
