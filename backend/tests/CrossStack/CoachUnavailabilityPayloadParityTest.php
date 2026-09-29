<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\MatchConstraint;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Service\MatchPlacementPayloadBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes *constraint semantics* + *backend↔engine contract* (§7.1).
 *
 * P4-272 ⑤ : les INDISPONIBILITÉS d'entraîneur (MatchConstraint scope COACH, toujours
 * SOFT) émises au solveur (`/place-matches`) sont EXACTEMENT ce qui est stocké — le bon
 * coach, ses jours, sa fourchette de coup d'envoi — dans le bloc top-level
 * `coachUnavailabilities`, ni plus, ni moins, et JAMAIS celles d'un autre club. C'est la
 * promesse « une indisponibilité saisie pèse au moteur » à la source : si le payload ne
 * la porte pas, aucune sémantique aval ne peut pénaliser le créneau.
 *
 * Falsifié dans les DEUX sens :
 *  - une indisponibilité stockée voyage verbatim (un builder qui l'omettrait échouerait) ;
 *  - le payload N'INVENTE RIEN : une règle CLUB (③) et une interdiction TEAM (④) NE FUIENT
 *    PAS dans `coachUnavailabilities` ;
 *  - un AUTRE club ne fuit pas (RLS : le GUC scope le club courant).
 */
#[Group('phase1')]
#[Group('integration')]
final class CoachUnavailabilityPayloadParityTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private MatchPlacementPayloadBuilder $builder;

    /**
     * Sens 1 + « n'invente rien » — l'indisponibilité stockée est EXACTEMENT ce que le
     * payload émet (coach, jours, fourchette), et une règle CLUB voisine ne fuit pas.
     */
    public function testStoredCoachUnavailabilityTravelsVerbatim(): void
    {
        [$club, $season] = $this->seed();
        $coach = $this->coach($club, $season, 'Anna', 'Martin');
        $this->unavailability($club, $season, $coach, [6], '14:00', '16:00');
        // Une règle CLUB « pas après 21h » le samedi : elle ne doit PAS fuir ici.
        $clubRule = new MatchConstraint;
        $clubRule->setClubId($club->getId());
        $clubRule->setSeasonId($season->getId());
        $clubRule->setScope(ConstraintScope::CLUB);
        $clubRule->setRuleType(ConstraintRuleType::HARD);
        $clubRule->setDaysOfWeek([6]);
        $clubRule->setKickoffMax(new DateTimeImmutable('21:00'));
        $this->em->persist($clubRule);
        $this->em->flush();

        $rows = $this->coachUnavailabilities($club, $season->getId());

        self::assertSame(
            [['coachId' => $coach->getId(), 'daysOfWeek' => [6], 'kickoffMin' => '14:00', 'kickoffMax' => '16:00']],
            $rows,
            'l\'indisponibilité stockée est émise verbatim, et la règle CLUB ne fuit pas',
        );
    }

    /**
     * Une borne ouverte (« pas avant 14h », max nul) voyage telle quelle : `kickoffMax` null.
     */
    public function testAnOpenBoundTravelsAsNull(): void
    {
        [$club, $season] = $this->seed();
        $coach = $this->coach($club, $season, 'Bob', 'Durand');
        $this->unavailability($club, $season, $coach, [1, 3], '14:00', null);
        $this->em->flush();

        $rows = $this->coachUnavailabilities($club, $season->getId());

        self::assertSame(
            [['coachId' => $coach->getId(), 'daysOfWeek' => [1, 3], 'kickoffMin' => '14:00', 'kickoffMax' => null]],
            $rows,
        );
    }

    /**
     * Sens 2 — une interdiction de gymnase (scope TEAM, ④) NE FUIT PAS dans les
     * `coachUnavailabilities` : seule une indisponibilité COACH les remplit.
     */
    public function testTeamVenueBanDoesNotLeakIntoCoachUnavailabilities(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'SM1');
        $venue = $this->venue($club, $season, 'Gymnase interdit');
        $ban = new MatchConstraint;
        $ban->setClubId($club->getId());
        $ban->setSeasonId($season->getId());
        $ban->setScope(ConstraintScope::TEAM);
        $ban->setScopeTargetId($team->getId());
        $ban->setVenueId($venue->getId());
        $ban->setRuleType(ConstraintRuleType::HARD);
        $ban->setDaysOfWeek([]);
        $this->em->persist($ban);
        $this->em->flush();

        self::assertSame([], $this->coachUnavailabilities($club, $season->getId()), 'une interdiction TEAM ne remplit jamais coachUnavailabilities');
    }

    /**
     * RLS — un autre club ne fuit pas : l'indisponibilité du club B est invisible dans le
     * payload du club A (GUC posé sur A).
     */
    public function testAnotherClubUnavailabilityDoesNotLeak(): void
    {
        [$clubA, $seasonA] = $this->seed();
        [$clubB, $seasonB] = $this->seed();
        $coachB = $this->coach($clubB, $seasonB, 'Carl', 'Bloch');
        $this->unavailability($clubB, $seasonB, $coachB, [6], '10:00', '12:00');
        $this->em->flush();

        // GUC sur A : aucune indisponibilité de B ne doit apparaître.
        $this->scopeGucToClub($clubA->getId());

        self::assertSame([], $this->coachUnavailabilities($clubA, $seasonA->getId()), 'l\'indisponibilité du club B ne fuit pas dans le payload du club A');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
    }

    /**
     * Le bloc `coachUnavailabilities` réellement émis au solveur.
     *
     * @return list<array{coachId: string, daysOfWeek: list<int>, kickoffMin: ?string, kickoffMax: ?string}>
     */
    private function coachUnavailabilities(Club $club, string $seasonId): array
    {
        /** @var list<array{coachId: string, daysOfWeek: list<int>, kickoffMin: ?string, kickoffMax: ?string}> $rows */
        $rows = $this->builder->build($club, $seasonId)['payload']['coachUnavailabilities'];

        return $rows;
    }

    /**
     * @param list<int> $daysOfWeek
     */
    private function unavailability(Club $club, Season $season, Coach $coach, array $daysOfWeek, ?string $min, ?string $max): MatchConstraint
    {
        $unavailability = new MatchConstraint;
        $unavailability->setClubId($club->getId());
        $unavailability->setSeasonId($season->getId());
        $unavailability->setScope(ConstraintScope::COACH);
        $unavailability->setScopeTargetId($coach->getId());
        $unavailability->setRuleType(ConstraintRuleType::PREFERRED);
        $unavailability->setDaysOfWeek($daysOfWeek);
        $unavailability->setKickoffMin(null !== $min ? new DateTimeImmutable($min) : null);
        $unavailability->setKickoffMax(null !== $max ? new DateTimeImmutable($max) : null);
        $this->em->persist($unavailability);

        return $unavailability;
    }

    private function coach(Club $club, Season $season, string $firstName, string $lastName): Coach
    {
        $coach = (new Coach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setFirstName($firstName)->setLastName($lastName)->setIsActive(true);
        $this->em->persist($coach);

        return $coach;
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)
            ->setSportCategoryId('99999999-9999-4999-8999-999999999999')->setPriorityTierId(1)->setSessionsPerWeek(1);
        $this->em->persist($team);

        return $team;
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)->setSource('manual');
        $this->em->persist($venue);

        return $venue;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Coach Unavailability Parity Club');
        $club->setSlug('coach-unavailability-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('CUP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('coach-unavailability-parity-' . $uid . '@test.com');
        $user->setFirstName('C');
        $user->setLastName('U');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
