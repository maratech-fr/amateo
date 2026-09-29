<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\ClubUser;
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
 * P4-272 ④ : les INTERDICTIONS de gymnase par équipe (MatchConstraint scope TEAM,
 * toujours HARD) émises au solveur (`/place-matches`) sont EXACTEMENT les gymnases
 * stockés, portés par la BONNE équipe dans `teams[].forbiddenVenueIds` — ni plus, ni
 * moins, et JAMAIS ceux d'un autre club. C'est la promesse « une interdiction saisie
 * est honorée par le moteur » à la source : si le payload ne porte pas l'interdiction,
 * aucune sémantique aval ne peut retirer le gymnase du domaine de l'équipe.
 *
 * Falsifié dans les DEUX sens :
 *  - un gymnase INTERDIT stocké voyage sur l'équipe visée (un builder qui l'omettrait
 *    échouerait) ;
 *  - le payload N'INVENTE RIEN : une équipe SANS interdiction porte `[]`, et une règle
 *    de scope CLUB (③, sans gymnase) NE FUIT PAS dans `forbiddenVenueIds` ;
 *  - un AUTRE club ne fuit pas (RLS : le GUC scope le club courant).
 */
#[Group('phase1')]
#[Group('integration')]
final class ForbiddenVenuePayloadParityTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private MatchPlacementPayloadBuilder $builder;

    /**
     * Sens 1 + « n'invente rien » — le gymnase interdit stocké est EXACTEMENT ce que le
     * payload émet sur l'équipe visée, et une autre équipe du même club porte `[]`.
     */
    public function testStoredForbiddenVenueTravelsOnTheRightTeam(): void
    {
        [$club, $season] = $this->seed();
        $banned = $this->team($club, $season, 'SM1');
        $free = $this->team($club, $season, 'SM2');
        $forbidden = $this->venue($club, $season, 'Gymnase interdit');
        $this->ban($club, $season, $banned, $forbidden);
        $this->em->flush();

        $forbiddenByTeam = $this->forbiddenVenuesByTeam($club, $season->getId());

        self::assertSame([$forbidden->getId()], $forbiddenByTeam[$banned->getId()] ?? null, 'le gymnase interdit voyage sur l\'équipe visée');
        self::assertSame([], $forbiddenByTeam[$free->getId()] ?? null, 'une équipe sans interdiction porte []');
    }

    /**
     * Sens 2 — une règle de scope CLUB (③, sans gymnase) NE FUIT PAS dans les
     * `forbiddenVenueIds` : seule une interdiction TEAM les remplit.
     */
    public function testClubScopedRuleDoesNotLeakIntoForbiddenVenues(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'SF1');
        // Une règle de club « pas après 21h » le samedi : scope CLUB, aucun gymnase.
        $rule = new MatchConstraint;
        $rule->setClubId($club->getId());
        $rule->setSeasonId($season->getId());
        $rule->setScope(ConstraintScope::CLUB);
        $rule->setRuleType(ConstraintRuleType::HARD);
        $rule->setDaysOfWeek([6]);
        $rule->setKickoffMax(new DateTimeImmutable('21:00'));
        $this->em->persist($rule);
        $this->em->flush();

        $forbiddenByTeam = $this->forbiddenVenuesByTeam($club, $season->getId());

        self::assertSame([], $forbiddenByTeam[$team->getId()] ?? null, 'une règle CLUB ne remplit jamais forbiddenVenueIds');
    }

    /**
     * RLS — un autre club ne fuit pas : l'interdiction du club B est invisible dans le
     * payload du club A (GUC posé sur A).
     */
    public function testAnotherClubBanDoesNotLeak(): void
    {
        [$clubA, $seasonA] = $this->seed();
        $teamA = $this->team($clubA, $seasonA, 'SM1');
        [$clubB, $seasonB] = $this->seed();
        $teamB = $this->team($clubB, $seasonB, 'SM1');
        $venueB = $this->venue($clubB, $seasonB, 'Gymnase B');
        $this->ban($clubB, $seasonB, $teamB, $venueB);
        $this->em->flush();

        // GUC sur A : aucune équipe de A ne doit porter la moindre interdiction.
        $this->scopeGucToClub($clubA->getId());
        $forbiddenByTeam = $this->forbiddenVenuesByTeam($clubA, $seasonA->getId());

        self::assertSame([], $forbiddenByTeam[$teamA->getId()] ?? null, 'l\'interdiction du club B ne fuit pas dans le payload du club A');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
    }

    /**
     * teamId => forbiddenVenueIds, lu depuis le payload réellement émis au solveur.
     *
     * @return array<string, list<string>>
     */
    private function forbiddenVenuesByTeam(Club $club, string $seasonId): array
    {
        $result = $this->builder->build($club, $seasonId);
        /** @var list<array{id: string, forbiddenVenueIds: list<string>}> $teams */
        $teams = $result['payload']['teams'];
        $out = [];
        foreach ($teams as $team) {
            $out[$team['id']] = $team['forbiddenVenueIds'];
        }

        return $out;
    }

    private function ban(Club $club, Season $season, Team $team, Venue $venue): MatchConstraint
    {
        $ban = new MatchConstraint;
        $ban->setClubId($club->getId());
        $ban->setSeasonId($season->getId());
        $ban->setScope(ConstraintScope::TEAM);
        $ban->setScopeTargetId($team->getId());
        $ban->setVenueId($venue->getId());
        $ban->setRuleType(ConstraintRuleType::HARD);
        $ban->setDaysOfWeek([]);
        $this->em->persist($ban);

        return $ban;
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
        $club->setName('Forbidden Venue Parity Club');
        $club->setSlug('forbidden-venue-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('FVP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('forbidden-venue-parity-' . $uid . '@test.com');
        $user->setFirstName('F');
        $user->setLastName('V');
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
