<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
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
 * La porte API du levier d'intensité de la règle « Trajet entre gymnases » (P2-53 RMM-8 PR-4).
 *
 * SINGLETON par club+saison, identifiant fixe `travelTime` : GET résout (défauts PREFERRED /
 * tolérance 20 / défaut 20, `isDefault=true`), PUT upserte (cran OFF|PREFERRED|MANDATORY + battement
 * toléré 0-60 + temps par défaut 1-120), une valeur inconnue ou hors bornes est refusée 422. Les
 * gardes management (403) et saison archivée (409) sont portées par les blocages `ManagementRoleTest`
 * / `SeasonReadonlyTest` (idiome partagé des processors).
 */
#[Group('integration')]
final class VenueTravelRuleSettingApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testGetResolvesTheDefaultPreferredWhenNothingStored(): void
    {
        [$user] = $this->seed();

        $this->client->request('GET', '/api/venue_travel_rule_settings/travelTime', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);

        $body = $this->body();
        self::assertSame('PREFERRED', $body['intensity'], 'sans réglage : le défaut PREFERRED');
        self::assertSame(20, $body['toleranceMinutes'], 'défaut battement toléré 20');
        self::assertSame(20, $body['defaultMinutes'], 'défaut temps par défaut 20');
        self::assertTrue($body['isDefault']);
    }

    public function testOffIsAcceptedAndReflected(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        $this->client->request('PUT', '/api/venue_travel_rule_settings/travelTime', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'OFF'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('OFF', $this->body()['intensity'], 'OFF « Inactive » est un cran valide (décision fondateur 2026-09-30)');
    }

    public function testUpsertToleranceAndDefaultMinutes(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        $this->client->request('PUT', '/api/venue_travel_rule_settings/travelTime', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED', 'toleranceMinutes' => 5, 'defaultMinutes' => 45], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = $this->body();
        self::assertSame(5, $body['toleranceMinutes']);
        self::assertSame(45, $body['defaultMinutes']);

        $this->client->request('GET', '/api/venue_travel_rule_settings/travelTime', [], [], $auth);
        $body = $this->body();
        self::assertSame(5, $body['toleranceMinutes'], 'le GET reflète le battement toléré stocké');
        self::assertSame(45, $body['defaultMinutes'], 'le GET reflète le temps par défaut stocké');
    }

    public function testOutOfBoundsToleranceOrDefaultIs422(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        // Battement toléré 0-60, temps par défaut 1-120 : hors bornes ⇒ 422.
        foreach ([['toleranceMinutes' => 61], ['toleranceMinutes' => -1], ['defaultMinutes' => 0], ['defaultMinutes' => 121]] as $bad) {
            $this->client->request('PUT', '/api/venue_travel_rule_settings/travelTime', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED'] + $bad, \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, \sprintf('hors bornes : %s', json_encode($bad)));
        }
    }

    public function testUpsertMandatoryAndGetReflectsIt(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        $this->client->request('PUT', '/api/venue_travel_rule_settings/travelTime', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'MANDATORY'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('MANDATORY', $this->body()['intensity']);

        $this->client->request('GET', '/api/venue_travel_rule_settings/travelTime', [], [], $auth);
        $body = $this->body();
        self::assertSame('MANDATORY', $body['intensity'], 'le GET reflète le levier stocké');
        self::assertFalse($body['isDefault']);
    }

    public function testUpsertPreferredAfterMandatoryReturnsToPreferred(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        $this->client->request('PUT', '/api/venue_travel_rule_settings/travelTime', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'MANDATORY'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // Repasser à Préféré : le même singleton est mis à jour (pas une seconde ligne).
        $this->client->request('PUT', '/api/venue_travel_rule_settings/travelTime', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/venue_travel_rule_settings/travelTime', [], [], $auth);
        self::assertSame('PREFERRED', $this->body()['intensity']);
    }

    public function testAWellbeingVocabularyIntensityIs422(): void
    {
        [$user] = $this->seed();

        // HARD n'est PAS un cran de trajet (OFF/PREFERRED/MANDATORY seulement) : Assert\Choice le
        // refuse en 422, comme toute chaîne inconnue.
        foreach (['HARD', 'nope', 'preferred'] as $bad) {
            $this->client->request('PUT', '/api/venue_travel_rule_settings/travelTime', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => $bad], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, \sprintf('« %s » n’est pas une intensité de trajet valide', $bad));
        }
    }

    public function testAnUnknownRuleKeyIs404NeverAnAlias(): void
    {
        // Revue sécurité 2026-08-26 (F-1) : toute clé ≠ travelTime fait 404 — en
        // lecture ET en écriture. Sans la garde, « nonsense » aliasait le réglage
        // réel (écriture comprise) et l'OpenAPI mentait sur l'identifiant.
        [$user] = $this->seed();

        $this->client->request('GET', '/api/venue_travel_rule_settings/nonsense', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(404, 'lecture sur clé inconnue → 404');

        $this->client->request('PUT', '/api/venue_travel_rule_settings/nonsense', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'MANDATORY'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404, 'écriture sur clé inconnue → 404, jamais un alias');

        // Et le réglage réel n'a PAS bougé : toujours le défaut.
        $this->client->request('GET', '/api/venue_travel_rule_settings/travelTime', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        self::assertSame('PREFERRED', $this->body()['intensity'], 'le PUT aliasé n\'a rien écrit');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Club, 2: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Travel Rule API Club');
        $club->setSlug('travel-rule-api-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('TRA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('travel-rule-api-' . $uid . '@test.com');
        $user->setFirstName('T');
        $user->setLastName('R');
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
        $season->setName($year . '-' . ($year + 1));
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
    }

    /** @return array{ruleKey: string, intensity: string, toleranceMinutes: int, defaultMinutes: int, isDefault: bool} */
    private function body(): array
    {
        /** @var array{ruleKey: string, intensity: string, toleranceMinutes: int, defaultMinutes: int, isDefault: bool} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
