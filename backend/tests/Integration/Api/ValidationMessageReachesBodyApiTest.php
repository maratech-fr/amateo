<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P4-126 — les 422 des state processors sont PARLANTS jusque dans le corps HTTP. Un
 * `new ValidationException('chaîne')` nu rendait `violations: []` et `detail: ""` : le motif
 * n'atteignait pas l'écran. Le helper `AbstractStateProcessor::refuse()` construit une vraie
 * `ConstraintViolationList` ; on prouve ici, de bout en bout, que son message ressort DANS le
 * corps que le front lit (`detail` puis `violations[].message`, cf. `errorMessage.ts`).
 *
 * Preuve e2e complète sur la mutualisation (`violations[0].message` ET `detail`), puis un cas
 * par entité restée sans couturé : TeamMatchHabit, VenueMatchWindow, VenueUnavailability. Ces
 * trois portaient des messages ANGLAIS traduits dans la même passe — on garde la traduction ET
 * le fait qu'elle voyage. ⚠ On n'assortit PAS ces messages à `MatchTenantIsolationTest` (step
 * bloquant) : une garde de COPIE ne se couple pas à une garde de sécurité.
 *
 * Non-bloquant (groupe `integration`, tourne dans `unit-tests`) : garde une discipline de sortie
 * 422, aucun étage aval ne la consomme.
 */
#[Group('integration')]
final class ValidationMessageReachesBodyApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $passwordHasher;

    private Club $club;

    private Season $season;

    private string $token;

    public function testSharedTrainingGroupDuplicateTeamCarriesFrenchViolation(): void
    {
        $t1 = $this->team();
        $t2 = $this->team();

        // Premier groupe socle {t1, t2} : accepté.
        $this->post('/api/shared_training_groups', ['schedulePlanId' => null, 'teamIds' => [$t1, $t2], 'commonSessions' => 1]);
        self::assertResponseStatusCodeSame(201);

        // Re-déclarer {t1, t2} : t1 est déjà mutualisée → refus PARLANT.
        $this->post('/api/shared_training_groups', ['schedulePlanId' => null, 'teamIds' => [$t1, $t2], 'commonSessions' => 1]);
        self::assertResponseStatusCodeSame(422);

        $message = 'Une équipe fait déjà partie d\'un autre groupe mutualisé pour cette portée.';
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        // Le front lit `detail` PUIS `violations[].message` : les deux doivent porter le motif,
        // c'est exactement ce que le constructeur-chaîne d'API Platform laissait vides.
        self::assertIsArray($body['violations'] ?? null, 'un 422 muet rendrait violations: []');
        self::assertSame($message, $body['violations'][0]['message'] ?? null);
        self::assertIsString($body['detail'] ?? null);
        self::assertStringContainsString($message, $body['detail']);
    }

    public function testTeamMatchHabitUnknownTeamCarriesFrenchViolation(): void
    {
        $this->post('/api/team_match_habits', ['teamId' => $this->uuid(), 'dayOfWeek' => 3, 'kickoffTime' => '10:00']);
        $this->assertBodyCarries(422, 'Équipe inconnue pour ce club.');
    }

    public function testTeamMatchHabitUnknownVenueCarriesFrenchViolation(): void
    {
        $team = $this->team();
        $this->post('/api/team_match_habits', ['teamId' => $team, 'dayOfWeek' => 3, 'kickoffTime' => '10:00', 'venueId' => $this->uuid()]);
        $this->assertBodyCarries(422, 'Gymnase inconnu pour ce club.');
    }

    public function testTeamMatchHabitDuplicateCarriesFrenchViolation(): void
    {
        $team = $this->team();
        $this->post('/api/team_match_habits', ['teamId' => $team, 'dayOfWeek' => 3, 'kickoffTime' => '10:00']);
        self::assertResponseStatusCodeSame(201);

        $this->post('/api/team_match_habits', ['teamId' => $team, 'dayOfWeek' => 3, 'kickoffTime' => '11:00']);
        $this->assertBodyCarries(422, 'Cette équipe a déjà une habitude de match ce jour-là — modifiez-la.');
    }

    public function testVenueMatchWindowEndBeforeStartCarriesFrenchViolation(): void
    {
        $venue = $this->venue();
        $this->post('/api/venue_match_windows', ['venueId' => $venue, 'dayOfWeek' => 6, 'startTime' => '14:00', 'endTime' => '12:00']);
        $this->assertBodyCarries(422, 'Une fenêtre d\'accès aux matchs doit se terminer après son début, le même jour.');
    }

    public function testVenueMatchWindowUnknownVenueCarriesFrenchViolation(): void
    {
        $this->post('/api/venue_match_windows', ['venueId' => $this->uuid(), 'dayOfWeek' => 6, 'startTime' => '10:00', 'endTime' => '12:00']);
        $this->assertBodyCarries(422, 'Gymnase inconnu pour ce club.');
    }

    public function testVenueUnavailabilityEndBeforeStartCarriesFrenchViolation(): void
    {
        $venue = $this->venue();
        $this->post('/api/venue_unavailabilities', ['venueId' => $venue, 'startDate' => '2025-12-20', 'endDate' => '2025-12-10']);
        $this->assertBodyCarries(422, 'L\'indisponibilité doit se terminer à sa date de début ou après.');
    }

    public function testVenueUnavailabilityUnknownVenueCarriesFrenchViolation(): void
    {
        $this->post('/api/venue_unavailabilities', ['venueId' => $this->uuid(), 'startDate' => '2025-12-10', 'endDate' => '2025-12-20']);
        $this->assertBodyCarries(422, 'Gymnase inconnu pour ce club.');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);
        $this->club = (new Club)->setName('VMB Club')->setSlug('vmb-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $user = (new User)->setEmail('vmb-' . $uid . '@test.com')->setFirstName('V')->setLastName('B');
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();

        // Firewall stateless : chaque requête porte son Bearer (plusieurs POST par test).
        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $uri, array $payload): void
    {
        $this->client->request('POST', $uri, [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function assertBodyCarries(int $status, string $message): void
    {
        self::assertResponseStatusCodeSame($status);
        // On DÉCODE le corps (l'apostrophe voyage `'` dans le JSON brut : un match de chaîne
        // brute la manquerait). Le front lit `detail` PUIS `violations[].message` : un 422 muet ne
        // porterait ni l'un ni l'autre — on exige les deux, exactement comme le consommateur.
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['violations'] ?? null, 'un 422 muet rendrait violations: []');
        self::assertSame($message, $body['violations'][0]['message'] ?? null);
        self::assertIsString($body['detail'] ?? null);
        self::assertStringContainsString($message, $body['detail']);
    }

    private function team(): string
    {
        $team = (new Team)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setSportCategoryId($this->uuid())
            ->setPriorityTierId(3)
            ->setName('T' . substr($this->uuid(), 0, 6))
            ->setSessionsPerWeek(2)
            ->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return (string) $team->getId();
    }

    private function venue(): string
    {
        $venue = (new Venue)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setName('V' . substr($this->uuid(), 0, 6))
            ->setSource('manual')
            ->setIsActive(true)
            ->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        return (string) $venue->getId();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
