<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axe backend↔engine contract (§7.1).
 *
 * P2-51 mutualisation par BLOC : ce que le club STOCKE (blocs {équipes, K}, ancrés au plan) doit
 * être EXACTEMENT le bloc `sharedBlocks` que le payload émet au solveur. La portée est dérivée du
 * plan (ADR-0002) : le socle (schedulePlanId NULL) émet ses blocs dans le build base ; une période
 * émet les SIENS (= planId) — et jamais les uns dans le build de l'autre. Un membre absent du
 * roster émis (équipe en pause) est filtré ; un bloc réduit à <2 membres actifs est abandonné.
 *
 * Falsifié dans les DEUX sens : un bloc stocké DOIT apparaître (un builder qui émettrait []
 * échoue) ET un bloc d'une AUTRE portée NE doit PAS fuir (un builder aveugle au plan échoue).
 */
#[Group('phase1')]
#[Group('integration')]
final class SharedBlockPayloadParityTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    /**
     * Chemin base : le socle émet ses blocs ; un bloc de PÉRIODE ne fuit pas dans la base.
     */
    public function testClubSeasonPayloadEmitsBaseBlocksOnly(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2);
        $t2 = $this->team($club, $season, 2);
        $t3 = $this->team($club, $season, 2);
        $this->em->flush();

        // Un bloc SOCLE (plan NULL) et un bloc de PÉRIODE (planId) — seul le socle doit sortir.
        $baseBlock = $this->block($club, $season, null, [$t1, $t2], 1);
        $entry = $this->holidayPeriod($club, $season);
        $planId = $this->planIdOf($entry);
        $this->block($club, $season, $planId, [$t1, $t3], 2);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        // Sens 1 — le bloc socle stocké est REFLÉTÉ exactement (un builder émettant [] échoue).
        self::assertSame(
            [[
                'id' => $baseBlock->getId(),
                'teamIds' => $this->sorted([$t1->getId(), $t2->getId()]),
                'commonSessions' => 1,
            ]],
            $payload['sharedBlocks'],
            'la base émet EXACTEMENT ses blocs socle (plan NULL)',
        );

        // Sens 2 — aucun teamId du bloc de période (t3) ne fuit dans la base.
        $emittedTeamIds = array_merge(...array_column($payload['sharedBlocks'], 'teamIds'));
        self::assertNotContains($t3->getId(), $emittedTeamIds, 'un bloc de période ne doit pas fuir dans la base');
    }

    /**
     * Chemin overlay de période : la période émet SES blocs (= planId) ; le socle ne fuit pas.
     */
    public function testPeriodOverlayPayloadEmitsPeriodBlocksOnly(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 3);
        $t2 = $this->team($club, $season, 3);
        $this->em->flush();

        $entry = $this->holidayPeriod($club, $season);
        $planId = $this->planIdOf($entry);

        // Un bloc socle (ne doit PAS sortir en période) et un bloc de période (= planId).
        $this->block($club, $season, null, [$t1, $t2], 1);
        $periodBlock = $this->block($club, $season, $planId, [$t1, $t2], 2);
        $this->em->flush();

        $payload = $this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        self::assertSame(
            [[
                'id' => $periodBlock->getId(),
                'teamIds' => $this->sorted([$t1->getId(), $t2->getId()]),
                'commonSessions' => 2,
            ]],
            $payload['sharedBlocks'],
            'la période émet EXACTEMENT ses propres blocs (= planId), jamais le socle',
        );
    }

    /**
     * Un membre ABSENT du roster émis (équipe en pause pour la période) est filtré ; un bloc réduit
     * à <2 membres actifs est ABANDONNÉ. Ici la « pause » est simulée par un membre dont le teamId
     * n'est pas dans le roster club+saison — c'est EXACTEMENT le prédicat que serializeSharedBlocks
     * teste (`isset($rosterIds[teamId])`), déclenché en production par une équipe désactivée pour
     * la période (PeriodConstraintSelection::deactivatedTeamIds retirant l'équipe du roster).
     */
    public function testMemberOutOfRosterIsFilteredAndSubTwoBlockIsDropped(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2);
        $t2 = $this->team($club, $season, 2);
        $t3 = $this->team($club, $season, 2);
        $this->em->flush();

        // Un teamId hors roster (aucune équipe émise ne le porte) → filtré à l'émission.
        $absentTeamId = $this->uuid();

        // Bloc à 3 membres dont un hors roster → 2 restent, il survit filtré.
        $survivor = $this->blockOfIds($club, $season, null, [$t1->getId(), $t2->getId(), $absentTeamId], 1);
        // Bloc à 2 membres dont un hors roster → 1 reste, il est abandonné (le moteur exige ≥ 2).
        $this->blockOfIds($club, $season, null, [$t3->getId(), $absentTeamId], 1);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame(
            [[
                'id' => $survivor->getId(),
                'teamIds' => $this->sorted([$t1->getId(), $t2->getId()]),
                'commonSessions' => 1,
            ]],
            $payload['sharedBlocks'],
            'membre hors roster filtré, bloc tombé sous 2 membres actifs abandonné',
        );
    }

    /**
     * Aucun bloc stocké ⇒ bloc VIDE : chemin byte-identique côté moteur (default_factory=list).
     */
    public function testNoBlocksEmitsEmptyBlock(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 2);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame([], $payload['sharedBlocks']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
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

    private function team(Club $club, Season $season, int $sessionsPerWeek): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->uuid());
        $team->setPriorityTierId(3);
        $team->setName('T' . substr($this->uuid(), 0, 6));
        $team->setSessionsPerWeek($sessionsPerWeek);
        $team->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    /**
     * @param list<Team> $teams
     */
    private function block(Club $club, Season $season, ?string $planId, array $teams, int $commonSessions): SharedTrainingBlock
    {
        return $this->blockOfIds(
            $club,
            $season,
            $planId,
            array_map(static fn (Team $team): string => $team->getId(), $teams),
            $commonSessions,
        );
    }

    /**
     * @param list<string> $teamIds
     */
    private function blockOfIds(Club $club, Season $season, ?string $planId, array $teamIds, int $commonSessions): SharedTrainingBlock
    {
        $block = new SharedTrainingBlock;
        $block->setClubId($club->getId());
        $block->setSeasonId($season->getId());
        $block->setSchedulePlanId($planId);
        $block->setCommonSessions($commonSessions);
        $this->em->persist($block);

        foreach ($teamIds as $teamId) {
            $member = new SharedTrainingBlockTeam;
            $member->setClubId($club->getId());
            $member->setSeasonId($season->getId());
            $member->setSchedulePlanId($planId);
            $member->setBlockId($block->getId());
            $member->setTeamId($teamId);
            $this->em->persist($member);
        }

        return $block;
    }

    private function holidayPeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Reprise');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Shared Block Parity Club');
        $club->setSlug('shared-block-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('SBP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('shared-block-parity-' . $uid . '@test.com');
        $user->setFirstName('S');
        $user->setLastName('B');
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
