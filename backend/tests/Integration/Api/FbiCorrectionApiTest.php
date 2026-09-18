<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\FbiCorrection;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\FbiCorrectionCloseSource;
use App\Enum\FbiCorrectionField;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le registre « à corriger dans FBI » sur HTTP (commit 4) : lecture membre des
 * entrées ouvertes, fermeture manuelle (gestionnaire), réouverture d'un « corrigé »
 * récent. 404 byte-identique cross-club, 403 pour un simple membre sur la fermeture.
 */
#[Group('integration')]
final class FbiCorrectionApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /** Horloge figée : les fenêtres de réouverture (< 24 h) se mesurent contre elle. */
    private const string NOW = '2026-09-15 12:00:00';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testListReturnsOpenEntriesOfTheClubSeason(): void
    {
        [$club, $user, $season] = $this->createClubUser('fl');
        $this->seedOpen($club, $season, FbiCorrectionField::KICKOFF, '15:30', '17:00');

        $this->client->request('GET', '/api/fixtures/fbi-corrections', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $corrections = $this->responseData()['corrections'] ?? null;
        self::assertIsArray($corrections);
        self::assertCount(1, $corrections);
        self::assertSame('kickoff', $corrections[0]['field']);
        self::assertSame('15:30', $corrections[0]['appValue']);
        self::assertSame('17:00', $corrections[0]['fbiValue']);
        self::assertArrayHasKey('lastSeenInFbiAt', $corrections[0]);
    }

    public function testCloseMarksItManualAndItLeavesTheList(): void
    {
        [$club, $user, $season] = $this->createClubUser('fc');
        $entry = $this->seedOpen($club, $season, FbiCorrectionField::KICKOFF, '15:30', '17:00');

        $this->client->request('POST', '/api/fixtures/fbi-corrections/' . $entry->getId() . '/close', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/fixtures/fbi-corrections', [], [], $this->authHeaders($user));
        self::assertSame([], $this->responseData()['corrections'] ?? ['sentinel'], 'une entrée fermée quitte la liste');

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $reloaded = $this->em->getRepository(FbiCorrection::class)->find($entry->getId());
        self::assertInstanceOf(FbiCorrection::class, $reloaded);
        self::assertFalse($reloaded->isOpen());
        self::assertSame(FbiCorrectionCloseSource::MANUAL, $reloaded->getClosedBy());
    }

    public function testCloseOnAForeignEntryIs404AndLeavesItOpen(): void
    {
        [, $userA] = $this->createClubUser('fa');
        [$clubB, , $seasonB] = $this->createClubUser('fb');
        $foreign = $this->seedOpen($clubB, $seasonB, FbiCorrectionField::DATE, '2026-10-03', '2026-10-10');

        $this->client->request('POST', '/api/fixtures/fbi-corrections/' . $foreign->getId() . '/close', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(404);

        // Lecture BRUTE sous le GUC de B (les filtres Doctrine restent scopés à A après
        // la requête) : la ligne de B est toujours OUVERTE.
        $this->scopeGucToClub($clubB->getId());
        $closedAt = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT closed_at FROM fbi_correction WHERE id = ?', [$foreign->getId()]);
        self::assertNull($closedAt ?: null, 'l\'entrée de B n\'est jamais fermée par une tentative de A');
    }

    public function testAMemberCannotClose(): void
    {
        [$club, , $season] = $this->createClubUser('fm');
        $entry = $this->seedOpen($club, $season, FbiCorrectionField::KICKOFF, '15:30', '17:00');
        $member = $this->createMember($club, 'editor');

        $this->client->request('POST', '/api/fixtures/fbi-corrections/' . $entry->getId() . '/close', [], [], $this->authHeaders($member));
        self::assertResponseStatusCodeSame(403);
    }

    public function testReopenUndoesARecentManualClose(): void
    {
        [$club, $user, $season] = $this->createClubUser('fr');
        // Fermée MANUELLEMENT il y a une heure (< 24 h) → réouvrable.
        $id = $this->seedClosed($club, $season, FbiCorrectionCloseSource::MANUAL, new DateTimeImmutable(self::NOW)->modify('-1 hour'));

        $this->client->request('POST', '/api/fixtures/fbi-corrections/' . $id . '/reopen', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertTrue($this->em->getRepository(FbiCorrection::class)->find($id)?->isOpen());
    }

    public function testReopenRefusesADepositCloseAndAnOldManualClose(): void
    {
        [$club, $user, $season] = $this->createClubUser('fo');
        // Fermée par un DÉPÔT : jamais réouvrable (409).
        $byDeposit = $this->seedClosed($club, $season, FbiCorrectionCloseSource::DEPOSIT, new DateTimeImmutable(self::NOW));
        $this->client->request('POST', '/api/fixtures/fbi-corrections/' . $byDeposit . '/reopen', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(409);

        // Fermée MANUELLEMENT il y a plus de 24 h → 409.
        $old = $this->seedClosed($club, $season, FbiCorrectionCloseSource::MANUAL, new DateTimeImmutable(self::NOW)->modify('-25 hours'));
        $this->client->request('POST', '/api/fixtures/fbi-corrections/' . $old . '/reopen', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(409);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable(self::NOW));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    private function seedOpen(Club $club, Season $season, FbiCorrectionField $field, ?string $appValue, ?string $fbiValue): FbiCorrection
    {
        $this->scopeGucToClub($club->getId());
        $entry = (new FbiCorrection)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setFixtureId($this->uuid())
            ->setField($field)
            ->setAppValue($appValue)
            ->setFbiValue($fbiValue)
            ->setDecidedBy('44444444-4444-4444-8444-444444444444')
            ->setLastSeenInFbiAt(new DateTimeImmutable(self::NOW));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /** Seeds a CLOSED entry and returns its id. */
    private function seedClosed(Club $club, Season $season, FbiCorrectionCloseSource $by, DateTimeImmutable $closedAt): string
    {
        $entry = $this->seedOpen($club, $season, FbiCorrectionField::KICKOFF, '15:30', '17:00');
        $entry->setClosedAt($closedAt);
        $entry->setClosedBy($by);
        $this->em->flush();

        return $entry->getId();
    }

    private function createMember(Club $club, string $role): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User;
        $user->setEmail($role . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club fbi ' . $suffix);
        $club->setSlug('club-fbi-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('fbi' . $uid . '@test.com');
        $user->setFirstName('Fbi');
        $user->setLastName('User');
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

        $season = new Season;
        $season->setClubId($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->settleSeasonPlan($season);

        return [$club, $user, $season];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
