<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-60 — la LECTURE du budget solo par portée (`GET /api/team_solo_budgets?schedulePlanId=`).
 * Le front s'en sert pour n'offrir en réservation individuelle que ce qui reste réservable.
 */
#[Group('integration')]
final class TeamSoloBudgetApiTest extends WebTestCase
{
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private User $user;

    private Season $season;

    private string $token;

    public function testTheSocleScopeReportsResidualAndBlockMembership(): void
    {
        $t1 = $this->team(2); // R socle = 2 − 1 = 1
        $t2 = $this->team(1); // R socle = 1 − 1 = 0
        $this->block(null, [$t1, $t2], 1);

        $budgets = $this->getBudgets(null);

        $b1 = $this->budgetOf($budgets, $t1->getId());
        self::assertSame(2, $b1['effectiveSessions']);
        self::assertSame(1, $b1['blockSessions']);
        self::assertSame(1, $b1['residual']);
        self::assertTrue($b1['inBlock']);

        $b2 = $this->budgetOf($budgets, $t2->getId());
        self::assertSame(0, $b2['residual']);
        self::assertTrue($b2['inBlock']);
    }

    public function testThePeriodScopeIsIndependentOfTheSocleBlocks(): void
    {
        $t1 = $this->team(2);
        $t2 = $this->team(1);
        $this->block(null, [$t1, $t2], 1); // bloc SOCLE seulement

        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $budgets = $this->getBudgets($planId);

        $b1 = $this->budgetOf($budgets, $t1->getId());
        self::assertSame(0, $b1['blockSessions'], 'aucun bloc dans le plan de période');
        self::assertSame(2, $b1['residual']);
        self::assertFalse($b1['inBlock']);
    }

    public function testAnUnknownPlanIsRefusedWith422(): void
    {
        $this->client->request('GET', '/api/team_solo_budgets?schedulePlanId=99999999-9999-4999-8999-999999999999', [], [], $this->headers());
        self::assertResponseStatusCodeSame(422);
    }

    public function testAMalformedPlanParamIsRefusedWith400(): void
    {
        $this->client->request('GET', '/api/team_solo_budgets?schedulePlanId=not-a-uuid', [], [], $this->headers());
        self::assertResponseStatusCodeSame(400);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Budget Club ' . $uid)->setSlug('budget-club-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = (new User)->setEmail('budget' . $uid . '@test.com')->setFirstName('B')->setLastName('T');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = (new ClubUser)->setClubId($this->club->getId())->setUserId($this->user->getId())
            ->setRole('admin')->setIsActive(true);
        $this->em->persist($cu);

        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    private function team(int $sessionsPerWeek): Team
    {
        $team = (new Team)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId($this->uuid())->setPriorityTierId(3)
            ->setName('T' . substr($this->uuid(), 0, 6))
            ->setSessionsPerWeek($sessionsPerWeek)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /**
     * @param list<Team> $teams
     */
    private function block(?string $planId, array $teams, int $commonSessions): void
    {
        $block = (new SharedTrainingBlock)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($planId)->setCommonSessions($commonSessions);
        $this->em->persist($block);
        foreach ($teams as $team) {
            $member = (new SharedTrainingBlockTeam)
                ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
                ->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($team->getId());
            $this->em->persist($member);
        }
        $this->em->flush();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getBudgets(?string $schedulePlanId): array
    {
        $query = null !== $schedulePlanId ? '?schedulePlanId=' . $schedulePlanId : '';
        $this->client->request('GET', '/api/team_solo_budgets' . $query, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $body['member'] ?? $body['hydra:member'] ?? [];
    }

    /**
     * @param list<array<string, mixed>> $budgets
     *
     * @return array<string, mixed>
     */
    private function budgetOf(array $budgets, string $teamId): array
    {
        foreach ($budgets as $budget) {
            if (($budget['teamId'] ?? null) === $teamId) {
                return $budget;
            }
        }
        self::fail('aucun budget pour l\'équipe ' . $teamId);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
