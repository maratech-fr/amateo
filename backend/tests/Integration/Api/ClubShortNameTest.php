<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D1 — PATCH /api/club/short-name pose le nom court (libellé d'e-mail) du club de l'appelant,
 * valide le format, et /api/me l'expose. Vérifie aussi qu'un PUT club générique qui NE l'envoie
 * PAS ne l'écrase jamais (le nom court vit hors ClubInput, patron PATCH dédié).
 */
#[Group('integration')]
final class ClubShortNameTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    public function testShortNameIsWritableTrimmedAndExposed(): void
    {
        $this->patchShortName('  BC Vallée  ');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('BC Vallée', $data['shortName'], 'le nom court est trimé');

        self::assertSame('BC Vallée', $this->meClub()['shortName']);
    }

    public function testEmptyStringClearsTheShortName(): void
    {
        $this->patchShortName('BC Vallée');
        self::assertResponseIsSuccessful();
        $this->patchShortName('');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['shortName'], 'une chaîne vide efface le nom court');
    }

    public function testTooLongShortNameIsRejected(): void
    {
        $this->patchShortName(str_repeat('a', 21));
        self::assertResponseStatusCodeSame(422);
    }

    public function testIllegalCharactersAreRejected(): void
    {
        $this->patchShortName('BC <script>');
        self::assertResponseStatusCodeSame(422);
    }

    public function testAccentedLettersDigitsAndAllowedPunctuationAreAccepted(): void
    {
        $this->patchShortName('Été 92 & C.-D\'A');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Été 92 & C.-D\'A', $data['shortName']);
    }

    public function testGenericPutNeverOverwritesTheShortName(): void
    {
        // 1) pose le nom court via le PATCH dédié.
        $this->patchShortName('BC Vallée');
        self::assertResponseIsSuccessful();

        // 2) un PUT club générique (name/slug/timezone/locale) qui n'envoie PAS le nom court.
        $this->client->request('PUT', '/api/clubs/' . $this->club->getId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token(),
        ], json_encode([
            'name' => 'Nom modifié',
            'slug' => $this->club->getSlug(),
            'timezone' => 'Europe/Paris',
            'locale' => 'fr',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // 3) le nom court n'a PAS bougé.
        self::assertSame('BC Vallée', $this->meClub()['shortName']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Short Name Test Club');
        $this->club->setSlug('short-name-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('SNM' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('shortname' . $uid . '@test.com');
        $this->user->setFirstName('Short');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($this->club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $this->em->flush();
    }

    private function patchShortName(string $value): void
    {
        // Bearer à CHAQUE requête (le firewall /api est stateless JWT ; browser-kit rejouerait
        // sinon un cookie d'une requête à l'autre — gotcha backend).
        $this->client->request('PATCH', '/api/club/short-name', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token(),
        ], json_encode(['shortName' => $value], \JSON_THROW_ON_ERROR));
    }

    private function token(): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    /** @return array<string, mixed> */
    private function meClub(): array
    {
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token()]);
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $me['club'];
    }
}
