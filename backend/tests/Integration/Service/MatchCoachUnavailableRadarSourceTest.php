<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\MatchConstraint;
use App\Entity\Season;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\ConflictRadarLoader;
use App\Service\MatchConflictDetector;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Correctif fondateur chantier H (embarqué dans la PR import IA) — l'alerte radar de match
 * `MATCH_COACH_UNAVAILABLE` ne doit lire QUE les contraintes coach du MODULE MATCHS
 * (`MatchConstraint` scope COACH, onglet « Contraintes » des matchs). Les indisponibilités coach
 * saisies au WIZARD (`Constraint` famille COACH_AVAILABILITY, étape Contraintes du wizard) ne
 * concernent QUE les ENTRAÎNEMENTS et ne doivent JAMAIS lever cette alerte de match.
 *
 * Aujourd'hui {@see ConflictRadarLoader} UNIT les deux sources dans `$coachUnavailabilities`
 * (COACH_AVAILABILITY via `CoachDoubleBookingDetector::blockedIntervalsByCoach` + MatchConstraint
 * COACH) — c'est précisément ce que le fondateur a décidé de réduire à la SEULE source matchs.
 *
 * TANK écrit ROUGE (le wizard qui ne doit plus rien lever) et VERT-TÉMOIN (la vraie source matchs,
 * qui doit CONTINUER à lever rouge 3 / orange 4) :
 *  - une COACH_AVAILABILITY du wizard couvrant le coup d'envoi d'un match à domicile → AUCUN
 *    `MATCH_COACH_UNAVAILABLE` (ROUGE aujourd'hui : la ligne est encore unie par le loader) ;
 *  - une `MatchConstraint` scope COACH couvrant le coup d'envoi → `MATCH_COACH_UNAVAILABLE`
 *    sévérité 3 (ROUGE), déjà vrai (témoin que le correctif ne casse pas la vraie source) ;
 *  - la même, mais l'équipe a un ADJOINT disponible → sévérité 4 (ORANGE), déjà vrai (témoin).
 *
 * Severity : 1 = pire (convention {@see MatchConflictDetector}). 3 = rouge « coach
 * principal indisponible », 4 = orange « un adjoint/autre principal peut couvrir ».
 */
#[Group('integration')]
final class MatchCoachUnavailableRadarSourceTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ConflictRadarLoader $radar;

    public function testWizardCoachAvailabilityNeverRaisesTheMatchCoachUnavailableAlert(): void
    {
        [$club, $season, $coachId, , $weekday] = $this->seedHomeMatchWithMainCoach('wiz');

        // Indispo DÉCLARÉE au WIZARD (COACH_AVAILABILITY) couvrant 16:00 : elle ne vaut QUE pour les
        // entraînements. Le radar de MATCH ne doit rien lever.
        $this->seedWizardCoachAvailability($club, $season, $coachId, $weekday, '15:00', '17:00');

        $this->scopeGucToClub($club->getId());
        $conflicts = $this->radar->conflicts($club->getId(), $season->getId())['conflicts'];

        self::assertSame(
            [],
            $this->coachUnavailable($conflicts),
            'une indisponibilité COACH_AVAILABILITY du wizard ne touche que les entraînements — jamais l\'alerte de match',
        );
    }

    public function testAMatchCoachConstraintRaisesItRed(): void
    {
        // TÉMOIN (doit rester vert) : la vraie source — une MatchConstraint scope COACH du module
        // Matchs couvrant le coup d'envoi — lève bien l'alerte, en ROUGE (3), sans adjoint.
        [$club, $season, $coachId, , $weekday] = $this->seedHomeMatchWithMainCoach('red');
        $this->seedMatchCoachConstraint($club, $season, $coachId, $weekday, '15:00', '17:00');

        $this->scopeGucToClub($club->getId());
        $rows = $this->coachUnavailable($this->radar->conflicts($club->getId(), $season->getId())['conflicts']);

        self::assertCount(1, $rows, 'la contrainte coach du module Matchs lève l\'alerte');
        self::assertSame(3, $rows[0]['severity'], 'coup d\'envoi dans l\'indispo du principal, aucun adjoint → rouge (3)');
    }

    public function testAnAvailableAssistantSoftensItToOrange(): void
    {
        // TÉMOIN (doit rester vert) : la même contrainte matchs, mais l'équipe a un ADJOINT DISPONIBLE
        // au coup d'envoi (aucune contrainte matchs sur lui) → l'alerte s'adoucit en ORANGE (4).
        [$club, $season, $coachId, $teamId, $weekday] = $this->seedHomeMatchWithMainCoach('org');
        $this->seedMatchCoachConstraint($club, $season, $coachId, $weekday, '15:00', '17:00');
        $this->linkCoach($club, $season, $teamId, $this->makeCoach($club, $season, 'Adjoint')->getId(), TeamCoachRole::ASSISTANT);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $rows = $this->coachUnavailable($this->radar->conflicts($club->getId(), $season->getId())['conflicts']);

        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]['severity'], 'un adjoint disponible adoucit le conflit en orange (4)');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->radar = self::getContainer()->get(ConflictRadarLoader::class);
    }

    /**
     * Un club avec UN match à DOMICILE (coup d'envoi 16:00) un jour futur, coaché par un PRINCIPAL.
     * Date future (today + 60 j) pour échapper au filtre « matchs passés » sans dépendre d'une date
     * en dur ; le jour ISO de cette date pilote les contraintes.
     *
     * @return array{0: Club, 1: Season, 2: string, 3: string, 4: int} club, saison, coachId, teamId, jour ISO
     */
    private function seedHomeMatchWithMainCoach(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = (new Club)
            ->setName('Radar coach ' . $suffix)->setSlug('radar-coach-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = (new User)
            ->setEmail('radar' . $uid . '@test.com')->setFirstName('R')->setLastName('C')
            ->setPasswordHash($hasher->hashPassword(new User, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = (new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($cu);

        $season = (new Season)
            ->setClubId($club->getId())->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-08-01'))->setEndDate(new DateTimeImmutable('2027-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);

        $coach = $this->makeCoach($club, $season, 'Principal');
        $this->em->flush();

        $teamId = $this->uuid();
        $this->linkCoach($club, $season, $teamId, $coach->getId(), TeamCoachRole::MAIN);

        $matchDate = new DateTimeImmutable('today +60 days');
        $weekday = (int) $matchDate->format('N');
        $fixture = (new Fixture)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($teamId)
            ->setMatchDate($matchDate)->setHomeAway(FixtureHomeAway::HOME)->setOpponentLabel('Adv');
        $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', '16:00') ?: null);
        $this->em->persist($fixture);
        $this->em->flush();

        return [$club, $season, $coach->getId(), $teamId, $weekday];
    }

    private function makeCoach(Club $club, Season $season, string $lastName): Coach
    {
        $this->scopeGucToClub($club->getId());
        $coach = (new Coach)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setFirstName('Coach')->setLastName($lastName . ' ' . substr(uniqid('', true), -6));
        $this->em->persist($coach);

        return $coach;
    }

    private function linkCoach(Club $club, Season $season, string $teamId, string $coachId, TeamCoachRole $role): void
    {
        $link = (new TeamCoach)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($teamId)->setCoachId($coachId)->setRole($role);
        $this->em->persist($link);
    }

    private function seedWizardCoachAvailability(Club $club, Season $season, string $coachId, int $weekday, string $from, string $until): void
    {
        $this->scopeGucToClub($club->getId());
        $constraint = (new Constraint)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('indispo wizard')->setScope(ConstraintScope::COACH)->setScopeTargetId($coachId)
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['coachId' => $coachId, 'unavailableDays' => [$weekday], 'fromTime' => $from, 'untilTime' => $until]);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    private function seedMatchCoachConstraint(Club $club, Season $season, string $coachId, int $weekday, string $kickoffMin, string $kickoffMax): void
    {
        $this->scopeGucToClub($club->getId());
        $constraint = (new MatchConstraint)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScope(ConstraintScope::COACH)->setScopeTargetId($coachId)
            ->setRuleType(ConstraintRuleType::PREFERRED)->setDaysOfWeek([$weekday])
            ->setKickoffMin(DateTimeImmutable::createFromFormat('!H:i', $kickoffMin) ?: null)
            ->setKickoffMax(DateTimeImmutable::createFromFormat('!H:i', $kickoffMax) ?: null);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /**
     * @param list<array<string, mixed>> $conflicts
     *
     * @return list<array<string, mixed>>
     */
    private function coachUnavailable(array $conflicts): array
    {
        return array_values(array_filter($conflicts, static fn (array $c): bool => 'MATCH_COACH_UNAVAILABLE' === ($c['type'] ?? null)));
    }

    private function uuid(): string
    {
        return \sprintf(
            '%04x%04x-%04x-4%03x-8%03x-%04x%04x%04x',
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFF),
            random_int(0, 0xFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
        );
    }
}
