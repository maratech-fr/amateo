<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\CoachWishPerimeter;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : une équipe a UN seul destinataire principal — celui que le gestionnaire a marqué, sinon
 * le premier principal lié — et le périmètre d'un coach se lit sur son JETON, plus sur ses liens.
 */
#[Group('phase1')]
#[Group('integration')]
final class CoachWishPerimeterTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private CoachWishPerimeter $perimeter;

    private Club $club;

    private Season $season;

    private CalendarEntry $entry;

    private Team $team;

    public function testTheFirstLinkedMainIsTheDefaultRecipient(): void
    {
        $maxime = $this->coach('Maxime');
        $julie = $this->coach('Julie');
        $this->link($julie, TeamCoachRole::MAIN, '2026-01-02');
        $this->link($maxime, TeamCoachRole::MAIN, '2026-01-01');

        $entry = $this->perimeter->recipientsByTeam($this->campaign())[$this->team->getId()];

        self::assertSame($maxime->getId(), $entry['recipientCoachId']);
        self::assertSame([$maxime->getId(), $julie->getId()], $entry['candidateCoachIds'], 'candidats triés par date de lien');
    }

    public function testTheMarkedMainWinsOverTheFirstLinked(): void
    {
        $maxime = $this->coach('Maxime');
        $julie = $this->coach('Julie');
        $this->link($maxime, TeamCoachRole::MAIN, '2026-01-01');
        $this->link($julie, TeamCoachRole::MAIN, '2026-01-02', marked: true);

        self::assertSame($julie->getId(), $this->perimeter->recipientsByTeam($this->campaign())[$this->team->getId()]['recipientCoachId']);
    }

    public function testAMarkOnAnAssistantIsIgnoredAndHeIsNeverACandidate(): void
    {
        $maxime = $this->coach('Maxime');
        $benoit = $this->coach('Benoît');
        $this->link($maxime, TeamCoachRole::MAIN, '2026-01-02');
        $this->link($benoit, TeamCoachRole::ASSISTANT, '2026-01-01', marked: true);

        $entry = $this->perimeter->recipientsByTeam($this->campaign())[$this->team->getId()];

        self::assertSame($maxime->getId(), $entry['recipientCoachId']);
        self::assertSame([$maxime->getId()], $entry['candidateCoachIds']);
    }

    public function testTwoMarksResolveToTheFirstOfTheSort(): void
    {
        $maxime = $this->coach('Maxime');
        $julie = $this->coach('Julie');
        $this->link($julie, TeamCoachRole::MAIN, '2026-01-02', marked: true);
        $this->link($maxime, TeamCoachRole::MAIN, '2026-01-01', marked: true);

        self::assertSame($maxime->getId(), $this->perimeter->recipientsByTeam($this->campaign())[$this->team->getId()]['recipientCoachId']);
    }

    public function testATeamWithoutMainHasNoRecipient(): void
    {
        $this->link($this->coach('Benoît'), TeamCoachRole::ASSISTANT, '2026-01-01');

        self::assertSame(['recipientCoachId' => null, 'candidateCoachIds' => []], $this->perimeter->recipientsByTeam($this->campaign())[$this->team->getId()]);
    }

    public function testStaffListsMainsThenAssistantsWithExactlyFourFields(): void
    {
        $benoit = $this->coach('Benoît');
        $julie = $this->coach('Julie');
        $maxime = $this->coach('Maxime');
        $this->link($benoit, TeamCoachRole::ASSISTANT, '2026-01-01');
        $this->link($julie, TeamCoachRole::MAIN, '2026-01-03');
        $this->link($maxime, TeamCoachRole::MAIN, '2026-01-02');

        $staff = $this->perimeter->staffOfTeams([$this->team->getId()])[$this->team->getId()];

        self::assertSame([$maxime->getId(), $julie->getId(), $benoit->getId()], array_column($staff, 'coachId'));
        self::assertSame(['coachId', 'firstName', 'lastName', 'role'], array_keys($staff[0]));
        self::assertSame(['MAIN', 'MAIN', 'ASSISTANT'], array_column($staff, 'role'));
    }

    public function testThePerimeterIsReadOnTheTokenNeverOnTheLinks(): void
    {
        // Karim est lié à l'équipe, mais son jeton ne porte rien : il ne répond pour rien.
        $karim = $this->coach('Karim');
        $this->link($karim, TeamCoachRole::MAIN, '2026-01-01');
        $other = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName('U15');
        $this->em->persist($other);
        $this->em->flush();
        $nora = $this->coach('Nora');

        $campaign = $this->campaign([$this->team->getId(), $other->getId()]);
        $this->em->persist((new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($karim->getId())->setClubId($this->club->getId())->setTeamIds([]));
        // Nora : jeton sur les deux équipes (dans le désordre) + une équipe hors campagne, écartée.
        $this->em->persist((new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($nora->getId())->setClubId($this->club->getId())
            ->setTeamIds([$other->getId(), '77777777-7777-4777-8777-777777777777', $this->team->getId()]));
        $this->em->flush();

        self::assertSame([], $this->perimeter->teamIdsForCoach($campaign, $karim->getId()));
        self::assertSame([$this->team->getId(), $other->getId()], $this->perimeter->teamIdsForCoach($campaign, $nora->getId()), 'ordre de la campagne');
        self::assertSame([$nora->getId() => true], $this->perimeter->coachIdSet($campaign), 'un jeton sans équipe sort du périmètre');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->perimeter = self::getContainer()->get(CoachWishPerimeter::class);
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Périmètre')->setSlug('perim-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Pâques')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($this->entry);
        $this->team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName('U11M1');
        $this->em->persist($this->team);
        $this->em->flush();
    }

    /** @param list<string>|null $teamIds */
    private function campaign(?array $teamIds = null): CoachWishCampaign
    {
        $campaign = (new CoachWishCampaign)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->entry->getId())->setDeadline(new DateTimeImmutable('2027-06-30'))
            ->setWeeks(['2026-02-16'])->setTeamIds($teamIds ?? [$this->team->getId()]);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function coach(string $firstName): Coach
    {
        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setFirstName($firstName)->setLastName('Test');
        $this->em->persist($coach);
        $this->em->flush();

        return $coach;
    }

    private function link(Coach $coach, TeamCoachRole $role, string $createdAt, bool $marked = false): void
    {
        $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($this->team->getId())->setCoachId($coach->getId())->setRole($role)
            ->setCreatedAt(new DateTimeImmutable($createdAt))->setWishRecipient($marked));
        $this->em->flush();
    }
}
