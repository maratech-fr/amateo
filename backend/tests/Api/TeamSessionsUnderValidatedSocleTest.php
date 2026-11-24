<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ComposesPlanTeamsTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse (lot 4, PR 3a, ligne 18 du plan) : sous un planning de saison en vigueur, le serveur garde ce qu'il gardait
 * quand les séances vivaient sur l'équipe. Le plafond de séances par jour d'une équipe est refusé, même message ; le
 * nombre de séances ne l'est pas (comportement actuel, seul l'écran le grise). Les deux se lisent et s'écrivent sur la
 * ligne du socle, et `/api/teams` les rend inchangés.
 *
 * Jumeau, cité : le geste de bout en bout sous socle validé, `backend/features/le-socle-commande-les-plans.feature`.
 */
#[Group('integration')]
final class TeamSessionsUnderValidatedSocleTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ComposesPlanTeamsTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $user;

    private Season $season;

    private string $teamId;

    public function testTheDailyCapIsRefusedUnderAValidatedSocleWithTheSameMessage(): void
    {
        $this->put(['maxSessionsPerDay' => 2]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Rouvrez le planning de saison pour modifier le nombre de séances.', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->lineRow(SeasonPlanOf::id(self::getContainer(), $this->season->getId()), $this->teamId)['maxSessionsPerDay'] ?? null, 'la ligne du socle garde son plafond');
    }

    public function testTheSessionCountIsNotRefusedByTheServerAndLandsOnTheSocleLine(): void
    {
        $this->put(['sessionsPerWeek' => 3]);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(3, $this->lineRow(SeasonPlanOf::id(self::getContainer(), $this->season->getId()), $this->teamId)['sessionsPerWeek'] ?? null, 'la ligne du socle porte les séances écrites');

        $this->client->request('GET', '/api/teams/' . $this->teamId, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $team = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([3, 1], [$team['sessionsPerWeek'] ?? null, $team['maxSessionsPerDay'] ?? null], '/api/teams rend les valeurs de la ligne du socle, mêmes champs');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $club = (new Club)->setName('Socle validé ' . $uid)->setSlug('socle-valide-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->user = (new User)->setEmail('socle-valide' . $uid . '@test.com')->setFirstName('S')->setLastName('Ocle');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $this->season->getId());
        $team = (new Team)->setClubId($club->getId())->setSeasonId($this->season->getId())->setSportCategoryId('33333333-3333-3333-3333-333333333333')
            ->setPriorityTierId(1)->setName('SM1')->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        $this->teamId = $team->getId();
        $this->setSocleTeam($this->season->getId(), $this->teamId, 2, 1);
        $this->settleSeasonPlan($this->season);
        $this->em->flush();
        self::assertNotNull($this->chosenPlanVersion($this->season), 'témoin : le planning de saison est en vigueur');
    }

    /** @param array<string, mixed> $changes */
    private function put(array $changes): void
    {
        $body = ['name' => 'SM1', 'sportCategoryId' => '33333333-3333-3333-3333-333333333333', 'priorityTierId' => 1] + $changes;
        $this->client->request('PUT', '/api/teams/' . $this->teamId, [], [], $this->headers(), json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }
}
