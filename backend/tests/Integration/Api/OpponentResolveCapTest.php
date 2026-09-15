<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le cap dur du rattrapage d'annuaire (`POST /api/opponents/resolve`) : au-delà de MAX_DISTINCT
 * adversaires distincts, un 422 parlant AVANT tout appel réseau. Le club du fondateur a ~102
 * adversaires distincts : la borne est passée de 60 à 200 (PR 2a). Ce test franchit la borne
 * (201 adversaires) et assied le MAXIMUM annoncé — il tombe (« maximum 60 ») tant que la
 * constante n'a pas bougé.
 */
#[Group('integration')]
final class OpponentResolveCapTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testOverTheCapIsRefusedWithTheAnnouncedMaximum(): void
    {
        [$club, $user, $season] = $this->seedClub();

        // 201 adversaires AWAY distincts (sans code → dédupliqués par libellé normalisé), soit UN
        // de plus que la borne : le cap se déclenche avant tout réseau.
        for ($i = 1; $i <= 201; ++$i) {
            $fixture = new Fixture;
            $fixture->setClubId($club->getId());
            $fixture->setSeasonId($season->getId());
            $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
            $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
            $fixture->setHomeAway(FixtureHomeAway::AWAY);
            $fixture->setOpponentLabel(\sprintf('Adversaire distinct %03d', $i));
            $this->em->persist($fixture);
        }
        $this->em->flush();

        $this->client->request('POST', '/api/opponents/resolve', [], [], $this->authHeaders($user) + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $error = (string) ($data['error'] ?? '');
        self::assertStringContainsString('201', $error, 'le message annonce le nombre d\'adversaires');
        self::assertStringContainsString('maximum 200', $error, 'la borne annoncée est bien 200 (et plus 60)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function seedClub(): array
    {
        $uid = uniqid('cap', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club cap ' . $uid);
        $club->setSlug('club-cap-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail($uid . '@test.com');
        $user->setFirstName('C');
        $user->setLastName('Ap');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $user, $season];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
