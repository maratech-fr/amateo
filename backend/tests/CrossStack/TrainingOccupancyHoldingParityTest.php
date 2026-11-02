<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\ConstraintPeriodOverride;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\TeamCoachRole;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : `trainingOccupancies` dit qui une séance retient VRAIMENT ce jour-là, le tenant daté et
 * ses joueurs, plus le coach stocké et tous les principaux. C'est ce qui laisse « Placer » poser
 * U18 sur son idéal quand Emma tient U15, et garde le comportement d'avant quand Karim et Emma ont
 * chacun un match à placer. Le fil complet contre le vrai moteur : `MatchPlacementSemanticsGateTest`.
 */
#[Group('integration')]
final class TrainingOccupancyHoldingParityTest extends KernelTestCase
{
    use PlacementHoldingDecorTrait;

    private const string KARIM = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string EMMA = 'eeeeeeee-0000-4000-8000-000000000002';
    private const string DORIAN = 'dddddddd-0000-4000-8000-000000000003';
    private const string ALICE = 'aaaaaaaa-0000-4000-8000-000000000004';

    /** Le cas de référence : Karim a un match à placer, Emma non, Emma tient la séance. */
    public function testTheCoachWithoutAMatchToPlaceHoldsTheSession(): void
    {
        $this->referenceDecor(self::KARIM);

        self::assertSame([self::EMMA], $this->occupiedOn($this->payload()));
    }

    /** Une séance sans coach stocké (réservée, libre) reçoit le même verdict qu'une séance nommée. */
    public function testASessionWithoutStoredCoachGetsTheSameVerdict(): void
    {
        $this->referenceDecor(null);

        self::assertSame([self::EMMA], $this->occupiedOn($this->payload()));
    }

    /** Les joueurs de l'équipe restent retenus par la séance, quel que soit le coach qui la tient. */
    public function testThePlayersOfTheSessionStayEmitted(): void
    {
        $u15 = $this->referenceDecor(self::KARIM);
        $this->player(self::ALICE, $u15);

        self::assertSame([self::ALICE, self::EMMA], $this->occupiedOn($this->payload()));
    }

    /**
     * U18 (Karim) et U20 (Emma) à placer, U15 (Karim, Emma) : égalité, le coach nommé tient. U18 reste
     * repoussé et U20 se pose à 20:30, comme aujourd'hui.
     */
    public function testWhenBothCoachesHaveAMatchToPlaceTheNamedCoachHolds(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->link(self::EMMA, $u15);
        $this->session($u15, self::KARIM);
        $u18 = $this->team('U18');
        $this->link(self::KARIM, $u18);
        $this->homeToPlace($u18);
        $u20 = $this->team('U20');
        $this->link(self::EMMA, $u20);
        $this->homeToPlace($u20);

        self::assertSame([self::KARIM], $this->occupiedOn($this->payload()));
    }

    /** Un re-placement : deux matchs déjà posés par le solveur restent « à placer », pas figés. */
    public function testMatchesPlacedByTheSolverCountAsToPlaceNotAsFixed(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->link(self::EMMA, $u15);
        $this->session($u15, self::KARIM);
        $u18 = $this->team('U18');
        $this->link(self::KARIM, $u18);
        $this->homePlacedBySolver($u18, '20:00');
        $u20 = $this->team('U20');
        $this->link(self::EMMA, $u20);
        $this->homePlacedBySolver($u20, '20:00');

        self::assertSame([self::KARIM], $this->occupiedOn($this->payload()), 'figés, ils prendraient les deux coachs et la séance serait intenable');
    }

    /** Une indisponibilité d'entraînement d'Emma qui mord la séance : aucun repli, Karim tient. */
    public function testAWizardUnavailabilityOverlappingTheSessionRemovesTheCandidate(): void
    {
        $this->referenceDecor(self::KARIM);
        $this->wizardUnavailable(self::EMMA, '20:30', '22:00');

        self::assertSame([self::KARIM], $this->occupiedOn($this->payload()));
    }

    /** Une indisponibilité d'entraînement qui s'arrête au début de la séance ne retire personne. */
    public function testAWizardUnavailabilityEndingAtTheSessionStartKeepsTheCandidate(): void
    {
        $this->referenceDecor(self::KARIM);
        $this->wizardUnavailable(self::EMMA, '18:00', '20:00');

        self::assertSame([self::EMMA], $this->occupiedOn($this->payload()));
    }

    /** Une séance que personne ne peut tenir retient tous ses candidats : le moteur reste prudent. */
    public function testASessionNobodyCanHoldOccupiesAllItsCandidates(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->link(self::EMMA, $u15);
        $this->session($u15, self::KARIM);
        $v1 = $this->team('V1');
        $this->link(self::KARIM, $v1);
        $this->homeFixed($v1, '20:00');
        $v2 = $this->team('V2');
        $this->link(self::EMMA, $v2);
        $this->homeFixed($v2, '20:00');
        $u9 = $this->team('U9');
        $this->homeToPlace($u9);

        self::assertSame([self::KARIM, self::EMMA], $this->occupiedOn($this->payload()));
    }

    /** Karim joue le match à placer et coache U15 : Emma, libre, tient U15. */
    public function testAPlayerOfAMatchToPlaceLeavesTheSessionToTheOtherCoach(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->link(self::EMMA, $u15);
        $this->session($u15, self::KARIM);
        $u18 = $this->team('U18');
        $this->player(self::KARIM, $u18);
        $this->homeToPlace($u18);

        self::assertSame([self::EMMA], $this->occupiedOn($this->payload()));
    }

    /** Le même Karim joueur, Emma prise par un match figé : Karim est retenu par U15. */
    public function testAPlayerIsHeldByTheSessionWhenTheOtherCoachIsTaken(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->link(self::EMMA, $u15);
        $this->session($u15, self::KARIM);
        $u18 = $this->team('U18');
        $this->player(self::KARIM, $u18);
        $this->homeToPlace($u18);
        $v = $this->team('V');
        $this->link(self::EMMA, $v);
        $this->homeFixed($v, '20:00');

        self::assertSame([self::KARIM], $this->occupiedOn($this->payload()));
    }

    /** Un coach nommé qui n'est qu'adjoint de l'équipe ne tient pas la séance : le principal la tient. */
    public function testANamedAssistantDoesNotHoldTheSession(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->link(self::DORIAN, $u15, TeamCoachRole::ASSISTANT);
        $this->session($u15, self::DORIAN);
        $this->homeToPlace($this->team('U9'));

        self::assertSame([self::KARIM], $this->occupiedOn($this->payload()));
    }

    /** Un coach nommé hors de l'équipe tient la séance. */
    public function testANamedCoachOutsideTheTeamHoldsTheSession(): void
    {
        $u15 = $this->team('U15');
        $this->session($u15, self::DORIAN);
        $this->homeToPlace($this->team('U9'));

        self::assertSame([self::DORIAN], $this->occupiedOn($this->payload()));
    }

    /** Un groupe déclaré sur la même case : UNE séance à tenir, Dorian (sans match à placer) la tient. */
    public function testADeclaredGroupOnTheSameCaseIsOneSessionToHold(): void
    {
        [$u11f, $u11m] = $this->twinSessions();
        $this->declareGroup([$u11f, $u11m]);

        self::assertSame([self::DORIAN], $this->occupiedOn($this->payload()));
    }

    /** Même case sans groupe déclaré : deux séances, chacune retient son coach. */
    public function testTheSameCaseWithoutGroupStaysTwoSessions(): void
    {
        $this->twinSessions();

        self::assertSame([self::KARIM, self::DORIAN], $this->occupiedOn($this->payload()));
    }

    /** Une séance qui chevauche un match figé de sa propre équipe n'a pas lieu : elle ne retient personne. */
    public function testASessionOverlappingAFixedMatchOfItsOwnTeamOccupiesNobody(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->player(self::ALICE, $u15);
        $this->session($u15, self::KARIM);
        $this->homeFixed($u15, '20:30');
        $this->homeToPlace($this->team('U9'));

        self::assertSame([], $this->occupiedOn($this->payload()));
    }

    /**
     * La limite écrite au plan : face à un match À PLACER de sa propre équipe, la séance retient encore
     * son coach, l'occupation ne portant pas d'équipe au contrat 1.9. Témoin moteur :
     * `engine/tests/test_match_placement.py::test_main_coach_training_pushes_the_match_away`.
     */
    public function testASessionFacingAMatchToPlaceOfItsOwnTeamStillOccupiesItsCoach(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->session($u15, self::KARIM);
        $this->homeToPlace($u15);

        self::assertSame([self::KARIM], $this->occupiedOn($this->payload()));
    }

    /** Une date qui ne porte aucun match à placer ne projette rien : un match figé seul n'y suffit pas. */
    public function testADateWithoutAMatchToPlaceProjectsNothing(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->player(self::ALICE, $u15);
        $this->session($u15, self::KARIM);
        $v = $this->team('V');
        $this->homeFixed($v, '14:00');
        $nextFriday = $this->fridayFromNow(2);
        $this->homeToPlace($v, $nextFriday);

        $payload = $this->payload();

        self::assertSame([], $this->occupiedOn($payload));
        self::assertSame($this->sorted([self::KARIM, self::ALICE]), $this->occupiedOn($payload, $nextFriday));
    }

    /** « Placer ce week-end » : une date hors de la fenêtre du run ne projette rien non plus. */
    public function testADateOutsideTheRunWindowProjectsNothing(): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->session($u15, self::KARIM);
        $v = $this->team('V');
        $this->homeToPlace($v);
        $nextFriday = $this->fridayFromNow(2);
        $this->homeToPlace($v, $nextFriday);

        $day = $nextFriday->format('Y-m-d');
        $payload = $this->payload(['from' => $day, 'to' => $day]);

        self::assertSame([], $this->occupiedOn($payload));
        self::assertSame([self::KARIM], $this->occupiedOn($payload, $nextFriday));
    }

    /**
     * Le plan effectif de la date décide : l'indisponibilité d'Emma, coupée dans le plan de période,
     * la laisse tenir aux dates de la période ; aux autres dates, Karim reste le seul candidat.
     */
    public function testThePeriodPlanDecidesTheWizardUnavailabilityOfItsOwnDates(): void
    {
        $inPeriod = $this->fridayFromNow(3);
        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Fermeture');
        $entry->setStartDate($inPeriod->modify('-4 days'));
        $entry->setEndDate($inPeriod->modify('+2 days'));
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $planId = $this->planIdOf($entry);
        $periodSchedule = $this->pointedVersion($planId);

        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->link(self::EMMA, $u15);
        $this->session($u15, self::KARIM);
        $this->session($u15, self::KARIM, '20:00', $periodSchedule);
        $u18 = $this->team('U18');
        $this->link(self::KARIM, $u18);
        $this->homeToPlace($u18);
        $this->homeToPlace($u18, $inPeriod);

        $override = new ConstraintPeriodOverride;
        $override->setClubId($this->club->getId());
        $override->setSeasonId($this->season->getId());
        $override->setSchedulePlanId($planId);
        $override->setConstraintId($this->wizardUnavailable(self::EMMA));
        $override->setIsActive(false);
        $this->em->persist($override);
        $this->em->flush();

        $payload = $this->payload();

        self::assertSame([self::EMMA], $this->occupiedOn($payload, $inPeriod));
        self::assertSame([self::KARIM], $this->occupiedOn($payload));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->seedDecor();
    }

    /** U15 (Karim, Emma) s'entraîne vendredi 20:00 ; U18 (Karim seul) a un match à placer ce jour-là. */
    private function referenceDecor(?string $storedCoach): string
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->link(self::EMMA, $u15);
        $this->session($u15, $storedCoach);
        $u18 = $this->team('U18');
        $this->link(self::KARIM, $u18);
        $this->homeToPlace($u18);

        return $u15;
    }

    /**
     * U11F (Karim) et U11M (Dorian) sur la même case, Karim ayant un match à placer.
     *
     * @return array{0: string, 1: string}
     */
    private function twinSessions(): array
    {
        $u11f = $this->team('U11F');
        $this->link(self::KARIM, $u11f);
        $this->session($u11f, self::KARIM);
        $u11m = $this->team('U11M');
        $this->link(self::DORIAN, $u11m);
        $this->session($u11m, self::DORIAN);
        $u18 = $this->team('U18');
        $this->link(self::KARIM, $u18);
        $this->homeToPlace($u18);

        return [$u11f, $u11m];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
