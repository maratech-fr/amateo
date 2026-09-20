<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\PurgeSeasonsCommand;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Retention purge NR (transition-de-saison §3): keep current + N-1 + futures,
 * delete N-2 and older (Season row included). Dry-run touches nothing;
 * one bad club never blocks the others.
 */
#[Group('phase1')]
#[Group('integration')]
final class PurgeSeasonsCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private string $oldWishId;

    private string $oldCampaignId;

    private string $oldTokenId;

    public function testDryRunDeletesNothing(): void
    {
        [$club, $seasons] = $this->createClubWithSeasons();
        [$old] = $seasons;

        $tester = $this->runPurge(['--dry-run' => true, '--club' => $club->getId(), '--date' => $this->purgeDate()]);
        $tester->assertCommandIsSuccessful();

        // The command cleared the GUC in its finally block — re-scope so the
        // RLS-protected season table is readable again for the assertions.
        $this->scopeGucToClub($club->getId());

        // The N-2 season and its team are still there.
        self::assertNotNull($this->em->getRepository(Season::class)->find($old->getId()));
        self::assertCount(1, $this->em->getRepository(Team::class)->findBy(['seasonId' => $old->getId()]));
        self::assertStringContainsString('would be purged', $tester->getDisplay());
    }

    public function testPurgesOnlySeasonsOlderThanPredecessor(): void
    {
        [$club, $seasons] = $this->createClubWithSeasons();
        [$old, $past, $current, $draft] = $seasons;

        $tester = $this->runPurge(['--club' => $club->getId(), '--date' => $this->purgeDate()]);
        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $this->scopeGucToClub($club->getId());

        // N-2 (old) purged, row and children gone (team + its tag assignment).
        self::assertNull($this->em->getRepository(Season::class)->find($old->getId()));
        self::assertCount(0, $this->em->getRepository(Team::class)->findBy(['seasonId' => $old->getId()]));
        self::assertCount(0, $this->em->getRepository(TeamTagAssignment::class)->findBy(['seasonId' => $old->getId()]));
        // #10 — la doléance coach de la saison purgée est partie.
        self::assertNull($this->em->getRepository(CoachWish::class)->find($this->oldWishId));
        self::assertNull($this->em->getRepository(CoachWishCampaign::class)->find($this->oldCampaignId), 'la campagne de collecte N-2 est purgée');
        self::assertNull($this->em->getRepository(CoachWishToken::class)->find($this->oldTokenId), 'son token part par la FK CASCADE');
        // planning-versions D2: the structure photos die with their season.
        self::assertCount(0, $this->em->getRepository(ScheduleStructureSnapshot::class)->findBy(['seasonId' => $old->getId()]));
        // Amendement 2026-09-20 : les APPARIEMENTS de gymnase sont club-scoped (sans saison) —
        // une purge de SAISON ne les touche jamais (ils survivent au changement de saison). Le
        // décrément P4-209(b) du compteur partagé a suivi ce grain et vit désormais à
        // l'effacement du club (couvert par AccountErasureTest), plus dans la purge de saison.
        // current, N-1 and the future draft survive.
        self::assertNotNull($this->em->getRepository(Season::class)->find($past->getId()));
        self::assertNotNull($this->em->getRepository(Season::class)->find($current->getId()));
        self::assertNotNull($this->em->getRepository(Season::class)->find($draft->getId()));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runPurge(array $options): CommandTester
    {
        $command = self::getContainer()->get(PurgeSeasonsCommand::class);
        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    /**
     * Seasons: N-2 (old, with a team), N-1, current, N+1 draft.
     *
     * @return array{0: Club, 1: array{0: Season, 1: Season, 2: Season, 3: Season}}
     */
    private function createClubWithSeasons(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club purge');
        $club->setSlug('club-purge-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('PUR' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $old = $this->season($club, $year - 2);
        $past = $this->season($club, $year - 1);
        $current = $this->season($club, $year);
        $draft = $this->season($club, $year + 1);
        $this->em->flush();

        // A team in the N-2 season, to prove children are purged too.
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($old->getId());
        $team->setSportCategoryId('00000000-0000-4000-8000-0000000000c1');
        $team->setPriorityTierId(1);
        $team->setName('Vieille équipe');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);

        // A tag assignment in the N-2 season — the outlier table (season_id,
        // no club_id) SeasonDataPurger must delete by season.
        $tag = new TeamTag;
        $tag->setClubId($club->getId());
        $tag->setName('CUSTOM-' . substr($uid, -4));
        $tag->setIsSystem(false);
        $this->em->persist($tag);
        $this->em->flush();

        $assignment = new TeamTagAssignment;
        // BCK-11 : tenant + RLS, une ligne sans club_id est refusée en base.
        $assignment->setClubId($team->getClubId());
        $assignment->setSeasonId($old->getId());
        $assignment->setTeamId($team->getId());
        $assignment->setTagId($tag->getId());
        $this->em->persist($assignment);
        $this->em->flush();

        // #10 — une doléance coach dans la saison N-2, ancrée à une entrée vacances : la
        // purge de rétention doit l'emporter (SeasonDataPurger, sous-requête calendarEntryId).
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($old->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Vacances N-2');
        $entry->setStartDate(new DateTimeImmutable(($year - 2) . '-02-16'));
        $entry->setEndDate(new DateTimeImmutable(($year - 2) . '-02-22'));
        $this->em->persist($entry);
        $this->em->flush();

        $wish = new CoachWish;
        $wish->setClubId($club->getId());
        $wish->setSeasonId($old->getId());
        $wish->setCalendarEntryId($entry->getId());
        $wish->setWeekStart(new DateTimeImmutable(($year - 2) . '-02-16'));
        $wish->setTeamId($team->getId());
        $this->em->persist($wish);
        $this->em->flush();
        $this->oldWishId = $wish->getId();

        // #10 C2 — une campagne de collecte (+ son token) ancrée à la même entrée N-2 : la
        // purge la supprime par sous-requête calendarEntryId (le token part par la FK CASCADE).
        $campaign = new CoachWishCampaign;
        $campaign->setClubId($club->getId());
        $campaign->setSeasonId($old->getId());
        $campaign->setCalendarEntryId($entry->getId());
        $campaign->setDeadline(new DateTimeImmutable(($year - 2) . '-02-10'));
        $campaign->setWeeks([($year - 2) . '-02-16']);
        $campaign->setTeamIds([$team->getId()]);
        $this->em->persist($campaign);
        $this->em->flush();
        $this->oldCampaignId = $campaign->getId();

        $coach = new Coach;
        $coach->setClubId($club->getId());
        $coach->setSeasonId($old->getId());
        $coach->setFirstName('Vieux');
        $coach->setLastName('Coach');
        $this->em->persist($coach);
        $this->em->flush();

        $token = (new CoachWishToken)
            ->setCampaignId($campaign->getId())
            ->setCoachId($coach->getId())
            ->setClubId($club->getId());
        $this->em->persist($token);
        $this->em->flush();
        $this->oldTokenId = $token->getId();

        return [$club, [$old, $past, $current, $draft]];
    }

    private function season(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }

    private function purgeDate(): string
    {
        $seasonYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));

        // The command deliberately grants 30 days after the season starts.
        // Pin the test after that grace window so it tests retention, not grace.
        return $seasonYear . '-09-01';
    }
}
