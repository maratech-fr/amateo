<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * BCK-39 — `TeamCoachInput` valide `teamId`/`coachId` comme des UUID : POST /api/team_coaches
 * avec un identifiant mal formé est refusé 422 à la validation (précédent CalendarEntryInput /
 * CoachWishInput), jamais porté tel quel jusqu'à une requête Doctrine.
 */
#[Group('integration')]
final class TeamCoachValidationTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $manager;

    private Club $club;

    public function testAMalformedTeamIdIsRejected422(): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('POST', '/api/team_coaches', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => 'not-a-uuid',
            'coachId' => Uuid::v4()->toRfc4122(),
            'role' => 'MAIN',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('teamId', (string) $this->client->getResponse()->getContent());
    }

    public function testAMalformedCoachIdIsRejected422(): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('POST', '/api/team_coaches', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => Uuid::v4()->toRfc4122(),
            'coachId' => '1234',
            'role' => 'MAIN',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('coachId', (string) $this->client->getResponse()->getContent());
    }

    /**
     * « Coach obligatoire » n'existe plus à l'API : un `isRequired` envoyé par un ancien client est
     * ignoré (201, pas de 400), et le lien rendu ne porte plus ce champ.
     */
    public function testAnIsRequiredSentByAnOldClientIsIgnoredAndNeverReturned(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName('SM1');
        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($season->getId())->setFirstName('Karim')->setLastName('Benali');
        $this->em->persist($team);
        $this->em->persist($coach);
        $this->em->flush();

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->manager);
        $this->client->request('POST', '/api/team_coaches', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_X-Season-Id' => $season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode([
            'teamId' => $team->getId(),
            'coachId' => $coach->getId(),
            'role' => 'MAIN',
            'isRequired' => false,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('isRequired', json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $club = new Club;
        $this->club = $club;
        $club->setName('TeamCoach Validation Club');
        $club->setSlug('tc-valid-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('TCV' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $this->manager = new User;
        $this->manager->setEmail('tcvalid' . $uid . '@test.com');
        $this->manager->setFirstName('Tc');
        $this->manager->setLastName('Valid');
        $this->manager->setPasswordHash($hasher->hashPassword($this->manager, 'pass'));
        $this->em->persist($this->manager);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($this->manager->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);
        $this->em->flush();
    }
}
