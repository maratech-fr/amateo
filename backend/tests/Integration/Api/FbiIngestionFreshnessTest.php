<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\FbiIngestion;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Enum\FbiIngestionSource;
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
 * Promesse : le titre « Matchs - (Dernier import FBI jj/mm/aaaa) » date le dernier DÉPÔT de fichier
 * FBI au jour civil du CLUB, servi par le serveur (`latest.depositedDate`, `Y-m-d`) — le front ne
 * connaît pas le fuseau du club et trancherait l'horodatage du serveur : un dépôt à 00:30 heure de
 * Paris serait daté de la veille. Sans dépôt de fichier (aucun, ou la seule synchro API FFBB),
 * `latest` reste `null` et le titre dit « Matchs ». Lecture ouverte au Membre.
 */
#[Group('integration')]
final class FbiIngestionFreshnessTest extends WebTestCase
{
    use TenantGucTrait;

    private const string URI = '/api/fbi-ingestions/latest';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** 00:30 le 11/11 à Paris, 23:30 le 10/11 au méridien de Greenwich. */
    public function testTheDepositDateIsTheClubsCivilDay(): void
    {
        $ctx = $this->seedClub('Europe/Paris');
        $this->ingestion($ctx, FbiIngestionSource::FBI_XLSX, '2026-11-10T23:30:00+00:00');

        $latest = $this->latest($ctx['user']);

        self::assertIsArray($latest);
        self::assertSame('2026-11-11', $latest['depositedDate'] ?? null);
        self::assertArrayHasKey('depositedAt', $latest, 'l\'horodatage reste servi : la fraîcheur « il y a N jours » le lit');
    }

    /** Le fuseau est celui du club, pas un Paris codé en dur : 02:30 UTC le 11/11 est encore le 10/11 en Guadeloupe. */
    public function testTheDepositDateFollowsTheClubTimezone(): void
    {
        $ctx = $this->seedClub('America/Guadeloupe');
        $this->ingestion($ctx, FbiIngestionSource::FBI_XLSX, '2026-11-11T02:30:00+00:00');

        self::assertSame('2026-11-10', $this->latest($ctx['user'])['depositedDate'] ?? null);
    }

    public function testWithoutDepositThereIsNoLatest(): void
    {
        $ctx = $this->seedClub('Europe/Paris');

        self::assertNull($this->latest($ctx['user']));
    }

    /** « Vérifier via l'API FFBB » n'est pas un import de fichier : le titre ne bouge pas. */
    public function testAnApiSyncAloneIsNoDeposit(): void
    {
        $ctx = $this->seedClub('Europe/Paris');
        $this->ingestion($ctx, FbiIngestionSource::FFBB_API, '2026-11-10T18:40:00+01:00');

        self::assertNull($this->latest($ctx['user']));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array<string, mixed>|null le champ `latest` de la réponse */
    private function latest(User $user): ?array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->em->clear();
        $this->client->request('GET', self::URI, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('latest', $body);

        return $body['latest'];
    }

    /** @param array{club: Club, season: Season} $ctx */
    private function ingestion(array $ctx, FbiIngestionSource $source, string $depositedAt): void
    {
        $this->scopeGucToClub($ctx['club']->getId());
        $this->em->persist(new FbiIngestion($ctx['club']->getId(), $ctx['season']->getId(), $source, new DateTimeImmutable($depositedAt), 1, 0, 0, 0));
        $this->em->flush();
    }

    /** @return array{user: User, club: Club, season: Season} un Membre (la lecture lui est ouverte) d'un club à saison courante */
    private function seedClub(string $timezone): array
    {
        $uid = uniqid('', true);
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));

        $club = new Club;
        $club->setName('Les Lions ' . $uid);
        $club->setSlug('lions-' . $uid);
        $club->setTimezone($timezone);
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('lions-' . $uid . '@test.com');
        $user->setFirstName('Lea');
        $user->setLastName('Martin');
        $user->setPasswordHash(self::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole(ClubRole::MEMBER->value);
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return ['user' => $user, 'club' => $club, 'season' => $season];
    }
}
