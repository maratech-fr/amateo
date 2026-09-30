<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P4-271 — le réglage d'affichage `club.weekendAlternates` (« modèle de week-end sur
 * deux semaines »). Écriture réservée au gestionnaire (comme tout champ club : le PUT
 * est gaté sur le rôle de gestion), lecture exposée par /api/me. Faux par défaut.
 */
#[Group('integration')]
final class ClubWeekendAlternatesTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $admin;

    private User $editor;

    public function testDefaultsToFalseAndIsExposedBySession(): void
    {
        $me = $this->get('/api/me', $this->tokenFor($this->admin));
        self::assertArrayHasKey('weekendAlternates', $me['club'], 'la session expose le réglage');
        self::assertFalse($me['club']['weekendAlternates'], 'faux par défaut');
    }

    public function testManagerCanTurnItOnAndSessionReflectsIt(): void
    {
        $this->put($this->tokenFor($this->admin), ['weekendAlternates' => true]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['weekendAlternates'], 'le PUT gestionnaire pose le réglage');

        $me = $this->get('/api/me', $this->tokenFor($this->admin));
        self::assertTrue($me['club']['weekendAlternates'], 'la session reflète le réglage');
    }

    public function testNonManagerCannotWriteIt(): void
    {
        $this->put($this->tokenFor($this->editor), ['weekendAlternates' => true]);
        self::assertResponseStatusCodeSame(403, 'un membre non gestionnaire ne modifie pas le club');

        // Le réglage n'a pas bougé.
        $me = $this->get('/api/me', $this->tokenFor($this->admin));
        self::assertFalse($me['club']['weekendAlternates']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Weekend Alt Club');
        $this->club->setSlug('weekend-alt-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->admin = $this->makeUser($hasher, 'admin' . $uid);
        $this->editor = $this->makeUser($hasher, 'editor' . $uid);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->makeMembership($this->admin, 'admin');
        $this->makeMembership($this->editor, 'editor');
        $this->em->flush();
    }

    private function makeUser(UserPasswordHasherInterface $hasher, string $tag): User
    {
        $user = new User;
        $user->setEmail($tag . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Tester');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);

        return $user;
    }

    private function makeMembership(User $user, string $role): void
    {
        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole($role);
        $cu->setIsActive(true);
        $this->em->persist($cu);
    }

    private function tokenFor(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function put(string $token, array $extra): void
    {
        // Le PUT club exige les champs NotBlank (idiome full-replace) : on renvoie
        // l'existant et on ajoute le réglage à écrire.
        $body = array_merge([
            'name' => $this->club->getName(),
            'slug' => $this->club->getSlug(),
            'timezone' => 'Europe/Paris',
            'locale' => 'fr',
        ], $extra);

        $this->client->request('PUT', '/api/clubs/' . $this->club->getId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri, string $token): array
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
