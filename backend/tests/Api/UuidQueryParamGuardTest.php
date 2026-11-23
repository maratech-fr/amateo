<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les filtres de collection portant un identifiant partent dans une comparaison
 * DQL contre une colonne PostgreSQL `uuid`/`guid` : une valeur mal formée y
 * remontait en **500** (`invalid input syntax for type uuid`) — et, sous le
 * harnais de test, avortait la transaction DAMA englobante, empoisonnant tout
 * ce qui suivait. Une entrée client invalide doit rendre **400**.
 *
 * Ce test garde le contrat de `ReadsUuidQueryParamTrait` sur les endpoints
 * réellement exposés, y compris la distinction ABSENT (défaut du provider) vs
 * PRÉSENT-MAIS-VIDE (`?x=` : erreur d'appel, pas « absent » — les confondre
 * ferait répondre au filtre d'une période par les données du socle).
 */
#[Group('phase1')]
#[Group('integration')]
final class UuidQueryParamGuardTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @return iterable<string, array{string, string}> */
    public static function malformedFilterProvider(): iterable
    {
        yield 'reservations / schedulePlanId' => ['/api/reservations', 'schedulePlanId'];
        yield 'venue_training_slots / schedulePlanId' => ['/api/venue_training_slots', 'schedulePlanId'];
        yield 'venue_training_slots / venueId' => ['/api/venue_training_slots', 'venueId'];
        yield 'team_period_overrides / schedulePlanId' => ['/api/team_period_overrides', 'schedulePlanId'];
        yield 'team_period_overrides / teamId' => ['/api/team_period_overrides', 'teamId'];
        yield 'venue_period_overrides / venueId' => ['/api/venue_period_overrides', 'venueId'];
        yield 'constraint_period_overrides / constraintId' => ['/api/constraint_period_overrides', 'constraintId'];
        yield 'schedule_plans / calendarEntryId' => ['/api/schedule_plans', 'calendarEntryId'];
        yield 'constraints / calendarEntryId' => ['/api/constraints', 'calendarEntryId'];
        yield 'coach_wishes / calendarEntryId' => ['/api/coach_wishes', 'calendarEntryId'];
        yield 'teams / seasonId' => ['/api/teams', 'seasonId'];
    }

    #[DataProvider('malformedFilterProvider')]
    public function testAMalformedUuidFilterIsRejectedWithFourHundred(string $path, string $param): void
    {
        $token = $this->authenticatedManager();

        $this->request($path . '?' . $param . '=not-a-uuid', $token);

        self::assertSame(
            400,
            $this->client->getResponse()->getStatusCode(),
            \sprintf('%s?%s=<invalide> doit rendre 400, jamais une erreur serveur', $path, $param),
        );
    }

    #[DataProvider('malformedFilterProvider')]
    public function testAPresentButEmptyUuidFilterIsRejectedWithFourHundred(string $path, string $param): void
    {
        $token = $this->authenticatedManager();

        $this->request($path . '?' . $param . '=', $token);

        self::assertSame(
            400,
            $this->client->getResponse()->getStatusCode(),
            \sprintf('%s?%s= (vide) est une erreur d’appel, pas « absent »', $path, $param),
        );
    }

    public function testAValidUuidFilterAndAnAbsentFilterBothStaySuccessful(): void
    {
        $token = $this->authenticatedManager();

        $this->request('/api/reservations', $token);
        self::assertTrue($this->client->getResponse()->isSuccessful(), 'filtre absent : le provider applique son défaut');

        $this->request('/api/reservations?schedulePlanId=11111111-1111-4111-8111-111111111111', $token);
        self::assertTrue($this->client->getResponse()->isSuccessful(), 'un UUID bien formé mais inconnu rend une liste vide, pas une erreur');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function request(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
    }

    /** Un gestionnaire actif d'un club neuf, avec sa saison courante. */
    private function authenticatedManager(): string
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club UUID Guard');
        $club->setSlug('club-uuid-guard-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('UUG' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('uuidguard' . $uid . '@test.com');
        $user->setFirstName('Uuid');
        $user->setLastName('Guard');
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
        SeasonPlanOf::id(self::getContainer(), $season->getId());

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
