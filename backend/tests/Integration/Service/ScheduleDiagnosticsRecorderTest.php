<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Season;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ScheduleDiagnosticsRecorder;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — axe §7.1 « generation pipeline (import) » : le pinpoint d'un `conflict` traverse l'import.
 *
 * L'engine (schéma 2.6) porte déjà `dayOfWeek`/`startTime` sur un diagnostic `conflict` — le
 * jour + l'heure de la séance fautive. La perte était 100 % côté import : le recorder ne mappait
 * que team/coach/venue. Ce test garde que ces deux champs entrent maintenant dans les colonnes,
 * DANS LES DEUX CASSES (l'engine émet camelCase, un payload nu peut être snake_case), tandis que
 * les 10 autres types de diagnostic les laissent NULL — et que les mappages existants
 * (team/coach/venue, sévérité) + la purge restent inchangés.
 *
 * P4-99 — étend la garde à `session_below_effective_min` : `causes` (liste
 * {kind, constraintId, label, count}) + `openCandidates` entrent en base, DANS LES DEUX CASSES,
 * et le `constraintId` de chaque cause est NORMALISÉ (le suffixe `:teamId` / `:forbidden:teamId`
 * que le builder ajoute est coupé au premier deux-points, l'UUID nu reste intact) pour que le
 * deep-link wizard `?edit=<id>` résolve. `openCandidates` distingue NULL (non mesuré) de 0.
 *
 * Additive display data → pas de step `blocking-tests` : tourne dans `unit-tests` (phpunit tests/).
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleDiagnosticsRecorderTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string VENUE_ID = '11111111-1111-4111-8111-111111111111';

    private const string TEAM_ID = '22222222-2222-4222-8222-222222222222';

    private const string COACH_ID = '33333333-3333-4333-8333-333333333333';

    private const string CONSTRAINT_ID = '44444444-4444-4444-8444-444444444444';

    private EntityManagerInterface $em;

    private ScheduleDiagnosticsRecorder $recorder;

    public function testAConflictWithCamelCaseDayAndTimeFillsTheColumns(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'conflict',
            'severity' => 'ERROR',
            'venueId' => self::VENUE_ID,
            'dayOfWeek' => 6,
            'startTime' => '10:00',
            'message' => 'Le gymnase accueille 2 équipes en même temps.',
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame('conflict', $diagnostic->getType());
        self::assertSame(ScheduleDiagnosticSeverity::ERROR, $diagnostic->getSeverity());
        self::assertSame(self::VENUE_ID, $diagnostic->getVenueId());
        self::assertSame(6, $diagnostic->getDayOfWeek());
        self::assertSame('10:00', $diagnostic->getStartTime());
    }

    public function testASnakeCaseDayAndTimeAreAlsoAccepted(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'conflict',
            'severity' => 'ERROR',
            'venue_id' => self::VENUE_ID,
            'day_of_week' => 3,
            'start_time' => '18:30',
            'message' => 'Conflit de capacité.',
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame(self::VENUE_ID, $diagnostic->getVenueId());
        self::assertSame(3, $diagnostic->getDayOfWeek());
        self::assertSame('18:30', $diagnostic->getStartTime());
    }

    public function testADiagnosticWithoutDayAndTimeLeavesColumnsNull(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'unplaced',
            'severity' => 'WARNING',
            'teamId' => self::TEAM_ID,
            'message' => 'Équipe non placée.',
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame(self::TEAM_ID, $diagnostic->getTeamId());
        self::assertNull($diagnostic->getDayOfWeek(), 'Un diagnostic sans jour/heure laisse les colonnes NULL.');
        self::assertNull($diagnostic->getStartTime());
    }

    public function testExistingTeamCoachVenueMappingsAreUnchanged(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'coach_overload',
            'severity' => 'WARNING',
            'team_id' => self::TEAM_ID,
            'coach_id' => self::COACH_ID,
            'venue_id' => self::VENUE_ID,
            'message' => 'Coach surchargé.',
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame(self::TEAM_ID, $diagnostic->getTeamId());
        self::assertSame(self::COACH_ID, $diagnostic->getCoachId());
        self::assertSame(self::VENUE_ID, $diagnostic->getVenueId());
        self::assertSame(ScheduleDiagnosticSeverity::WARNING, $diagnostic->getSeverity());
        self::assertNull($diagnostic->getDayOfWeek());
        self::assertNull($diagnostic->getStartTime());
    }

    public function testSessionCausesAndOpenCandidatesArePersisted(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'session_below_effective_min',
            'severity' => 'WARNING',
            'teamId' => self::TEAM_ID,
            'message' => 'Une séance manque à cette équipe.',
            'causes' => [
                ['kind' => 'venue_forbidden', 'constraintId' => self::CONSTRAINT_ID, 'label' => 'Gymnase interdit', 'count' => 3],
            ],
            'openCandidates' => 2,
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame('session_below_effective_min', $diagnostic->getType());
        self::assertSame(2, $diagnostic->getOpenCandidates());
        $causes = $diagnostic->getCauses();
        self::assertCount(1, $causes);
        self::assertSame('venue_forbidden', $causes[0]['kind']);
        self::assertSame(self::CONSTRAINT_ID, $causes[0]['constraintId']);
        self::assertSame(3, $causes[0]['count']);
    }

    public function testTeamSuffixedConstraintIdIsNormalisedToTheEntityUuid(): void
    {
        $schedule = $this->seedSchedule();

        // The builder suffixes a CLUB constraint expanded per team: `<uuid>:<teamId>`.
        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'session_below_effective_min',
            'severity' => 'WARNING',
            'teamId' => self::TEAM_ID,
            'message' => 'Séance manquante.',
            'causes' => [
                ['kind' => 'day_forbidden', 'constraintId' => self::CONSTRAINT_ID . ':' . self::TEAM_ID, 'label' => 'Jour interdit', 'count' => 1],
            ],
        ]]]);
        $this->em->flush();

        $causes = $this->onlyDiagnostic($schedule)->getCauses();
        self::assertSame(self::CONSTRAINT_ID, $causes[0]['constraintId'], 'Le suffixe :teamId est coupé au premier deux-points.');
    }

    public function testForbiddenSuffixedConstraintIdIsNormalisedToTheEntityUuid(): void
    {
        $schedule = $this->seedSchedule();

        // The other suffix form: `<uuid>:forbidden:<teamId>` (venue dedicated to a tag).
        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'session_below_effective_min',
            'severity' => 'WARNING',
            'teamId' => self::TEAM_ID,
            'message' => 'Séance manquante.',
            'causes' => [
                ['kind' => 'forced_venue_elsewhere', 'constraintId' => self::CONSTRAINT_ID . ':forbidden:' . self::TEAM_ID, 'label' => 'Gymnase dédié', 'count' => 4],
            ],
        ]]]);
        $this->em->flush();

        $causes = $this->onlyDiagnostic($schedule)->getCauses();
        self::assertSame(self::CONSTRAINT_ID, $causes[0]['constraintId'], 'Le suffixe :forbidden:teamId est coupé au premier deux-points, restituant l\'UUID nu.');
    }

    public function testBareConstraintIdIsLeftIntact(): void
    {
        $schedule = $this->seedSchedule();

        // A real TEAM/COACH constraint arrives un-suffixed: it must NOT be touched.
        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'session_below_effective_min',
            'severity' => 'WARNING',
            'teamId' => self::TEAM_ID,
            'message' => 'Séance manquante.',
            'causes' => [
                ['kind' => 'time_window', 'constraintId' => self::CONSTRAINT_ID, 'label' => 'Fenêtre horaire', 'count' => 2],
            ],
        ]]]);
        $this->em->flush();

        $causes = $this->onlyDiagnostic($schedule)->getCauses();
        self::assertSame(self::CONSTRAINT_ID, $causes[0]['constraintId'], 'Un id nu (sans suffixe) reste intact.');
    }

    public function testOpenCandidatesDistinguishesNullFromZero(): void
    {
        // Absent → NULL (not measured). Explicit 0 → 0 (nothing stayed open). The product
        // signal lives in this distinction — a smallint nullable carries it.
        $scheduleAbsent = $this->seedSchedule();
        $this->recorder->record($scheduleAbsent, ['diagnostics' => [[
            'type' => 'session_below_effective_min',
            'severity' => 'WARNING',
            'teamId' => self::TEAM_ID,
            'message' => 'Séance manquante, cause non mesurée.',
        ]]]);
        $this->em->flush();
        self::assertNull($this->onlyDiagnostic($scheduleAbsent)->getOpenCandidates(), 'Champ absent → NULL (non mesuré).');

        $scheduleZero = $this->seedSchedule();
        $this->recorder->record($scheduleZero, ['diagnostics' => [[
            'type' => 'session_below_effective_min',
            'severity' => 'WARNING',
            'teamId' => self::TEAM_ID,
            'message' => 'Séance manquante, aucun créneau resté ouvert.',
            'openCandidates' => 0,
        ]]]);
        $this->em->flush();
        self::assertSame(0, $this->onlyDiagnostic($scheduleZero)->getOpenCandidates(), '0 explicite → 0 (aucun resté ouvert).');
    }

    public function testSnakeCaseCauseAndOpenCandidatesAreAlsoAccepted(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'session_below_effective_min',
            'severity' => 'WARNING',
            'team_id' => self::TEAM_ID,
            'message' => 'Séance manquante.',
            'causes' => [
                ['kind' => 'hard_lock', 'constraint_id' => self::CONSTRAINT_ID . ':' . self::TEAM_ID, 'label' => 'Verrou', 'count' => 1],
            ],
            'open_candidates' => 5,
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame(5, $diagnostic->getOpenCandidates());
        $causes = $diagnostic->getCauses();
        self::assertSame(self::CONSTRAINT_ID, $causes[0]['constraintId'], 'constraintId snake_case est lu, normalisé et restitué en camelCase.');
        self::assertArrayNotHasKey('constraint_id', $causes[0], 'La variante snake_case ne subsiste pas dans la donnée stockée.');
    }

    public function testOtherDiagnosticTypesLeaveCausesEmptyAndOpenCandidatesNull(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'unplaced',
            'severity' => 'WARNING',
            'teamId' => self::TEAM_ID,
            'message' => 'Équipe non placée.',
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame([], $diagnostic->getCauses());
        self::assertNull($diagnostic->getOpenCandidates());
    }

    public function testPurgePreviousRemovesEarlierRunsForThisSchedule(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'conflict',
            'severity' => 'ERROR',
            'venueId' => self::VENUE_ID,
            'dayOfWeek' => 6,
            'startTime' => '10:00',
            'message' => 'Premier run.',
        ]]]);
        $this->em->flush();
        self::assertCount(1, $this->diagnostics($schedule));

        $this->recorder->purgePrevious($schedule);
        $this->em->flush();

        self::assertCount(0, $this->diagnostics($schedule), 'purgePrevious efface les diagnostics du run précédent.');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->recorder = self::getContainer()->get(ScheduleDiagnosticsRecorder::class);
    }

    private function seedSchedule(): Schedule
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('PSI' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('S')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    /** @return list<ScheduleDiagnostic> */
    private function diagnostics(Schedule $schedule): array
    {
        $this->em->clear();

        return array_values($this->em->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $schedule->getId()]));
    }

    private function onlyDiagnostic(Schedule $schedule): ScheduleDiagnostic
    {
        $found = $this->diagnostics($schedule);
        self::assertCount(1, $found);

        return $found[0];
    }
}
