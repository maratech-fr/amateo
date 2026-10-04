<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Service\ClubMailboxPurgerInterface;
use App\Tests\Double\RecordingClubMailboxPurger;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR (axes *auth & memberships* + *tenant isolation*) — `POST /api/club/clock` : le widget
 * d'horloge du compte DÉMO.
 *
 * Décision fondateur 2026-10-02 : l'horloge simulée ne vit QUE pour un compte démo. On garde :
 *  - un GESTIONNAIRE d'un club DÉMO pose/relâche l'horloge (200) ;
 *  - un membre NON gestionnaire d'un club démo est refusé (403, SEC-07) ;
 *  - un GESTIONNAIRE d'un VRAI club est refusé (403) et son `simulated_today` reste intact ;
 *  - une date malformée est refusée (422) ;
 *  - `clear` relâche l'horloge (NULL) et invoque la maison unique de vidage de boîte ;
 *  - poser l'horloge d'un club ne touche JAMAIS un autre club.
 *
 * Le vidage de boîte est VÉRIFIÉ par interaction (double {@see RecordingClubMailboxPurger}) :
 * le vrai purger agit sur la connexion ADMIN, invisible à la transaction DAMA d'une requête
 * tenant — son effet DB réel est couvert par ClubClockCommandTest.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubClockEndpointTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private RecordingClubMailboxPurger $purger;

    public function testDemoManagerSetsThenClearsTheSimulatedClock(): void
    {
        [$token, , $clubId] = $this->register('CLKA');
        $this->makeDemo($clubId);

        $this->post($token, ['date' => '2027-05-15']);
        self::assertResponseIsSuccessful();
        self::assertSame('2027-05-15', $this->responseBody()['simulatedToday']);
        self::assertSame('2027-05-15', $this->simulatedToday($clubId));
        self::assertSame([], $this->purger->purged, 'poser une date ne vide pas la boîte');

        $this->post($token, ['clear' => true]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->responseBody()['simulatedToday']);
        self::assertNull($this->simulatedToday($clubId), 'le clear relâche l\'horloge (NULL)');
        self::assertSame([$clubId], $this->purger->purged, 'le clear invoque la maison unique de vidage de boîte');
    }

    public function testDemoNonManagerIsForbidden(): void
    {
        [, , $clubId] = $this->register('CLKB');
        $this->makeDemo($clubId);
        $memberToken = $this->addActiveMember($clubId, 'member');

        $this->post($memberToken, ['date' => '2027-05-15']);
        self::assertResponseStatusCodeSame(403, 'un membre non gestionnaire ne pose pas l\'horloge');
        self::assertNull($this->simulatedToday($clubId), 'rien écrit');
        self::assertSame([], $this->purger->purged);
    }

    public function testRealClubManagerIsForbiddenAndClockUntouched(): void
    {
        // Un club RÉEL (register ne crée jamais un club démo) : même un gestionnaire est refusé.
        [$token, , $clubId] = $this->register('CLKC');

        $this->post($token, ['date' => '2027-05-15']);
        self::assertResponseStatusCodeSame(403, 'l\'horloge est réservée aux comptes de démonstration');
        self::assertNull($this->simulatedToday($clubId), 'le vrai club reste à l\'heure réelle');
    }

    public function testADateBeforeTheCurrentSeasonIsRejected(): void
    {
        // BCK-34 — un club issu du register porte une seule saison (15/07 de l'année en
        // cours → 14/07 suivante). Une date AVANT ce début est hors bornes.
        [$token, , $clubId] = $this->register('CLKF');
        $this->makeDemo($clubId);

        $this->post($token, ['date' => '2026-01-01']);
        self::assertResponseStatusCodeSame(422, 'avant le début de la saison en cours → hors bornes');
        self::assertNull($this->simulatedToday($clubId), 'rien écrit hors bornes');
    }

    public function testADateAfterTheNextSeasonIsRejected(): void
    {
        // La borne haute = fin de la saison SUIVANTE (ici projetée, le club n'a qu'une
        // saison) ≈ +2 ans. Une date bien au-delà est hors bornes.
        [$token, , $clubId] = $this->register('CLKG');
        $this->makeDemo($clubId);

        $this->post($token, ['date' => '2029-06-01']);
        self::assertResponseStatusCodeSame(422, 'après la fin de la saison suivante → hors bornes');
        self::assertNull($this->simulatedToday($clubId));
    }

    public function testADateInsideTheSeasonWindowIsAccepted(): void
    {
        [$token, , $clubId] = $this->register('CLKH');
        $this->makeDemo($clubId);

        $this->post($token, ['date' => '2027-05-15']);
        self::assertResponseIsSuccessful('une date dans la fenêtre des saisons est acceptée');
        self::assertSame('2027-05-15', $this->simulatedToday($clubId));
    }

    public function testTheCheckConstraintRefusesASimulatedDateOnARealClub(): void
    {
        // SEC-30 — défense BASE : même en contournant les contrôleurs, la contrainte
        // CHECK (simulated_today IS NULL OR is_demo) refuse l'écriture sur un vrai club.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $club = new Club;
        $club->setName('Réel ' . $suffix)->setSlug('reel-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setIsDemo(false);
        $club->setSimulatedToday(new DateTimeImmutable('2027-05-15'));
        $em->persist($club);

        $this->expectException(DbalException::class);
        $em->flush();
    }

    public function testMalformedDateIsRejected(): void
    {
        [$token, , $clubId] = $this->register('CLKD');
        $this->makeDemo($clubId);

        // 2026-02-31 « parse » en 3 mars → refusée (422), rien écrit.
        $this->post($token, ['date' => '2026-02-31']);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->simulatedToday($clubId));

        // date ET clear ensemble → ambigu, 422.
        $this->post($token, ['date' => '2027-05-15', 'clear' => true]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSettingTheClockNeverTouchesAnotherClub(): void
    {
        [$tokenA, , $clubA] = $this->register('CLKE');
        $this->makeDemo($clubA);
        // Un AUTRE club démo, horloge déjà posée, créé directement (pas de session) : poser
        // l'horloge de clubA ne doit jamais toucher clubB.
        $clubB = $this->seedOtherDemoClub('2026-09-09');

        $this->post($tokenA, ['date' => '2027-01-01']);
        self::assertResponseIsSuccessful();
        self::assertSame('2027-01-01', $this->simulatedToday($clubA));
        self::assertSame('2026-09-09', $this->simulatedToday($clubB), 'l\'autre club n\'est jamais touché');
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/club/clock', [], [], ['CONTENT_TYPE' => 'application/json'], '{"date":"2027-05-15"}');
        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Double scoped à CE test : le vrai purger agit sur la connexion ADMIN (hors DAMA tenant).
        // disableReboot pour que le double survive aux requêtes successives.
        $this->client->disableReboot();
        $this->purger = new RecordingClubMailboxPurger;
        self::getContainer()->set(ClubMailboxPurgerInterface::class, $this->purger);
    }

    private function makeDemo(string $clubId): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        $club->setIsDemo(true);
        $em->flush();
        $em->clear();
    }

    private function seedOtherDemoClub(string $simulatedDate): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $club = new Club;
        $club->setName('Autre Démo ' . $suffix)->setSlug('autre-demo-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setIsDemo(true);
        $club->setSimulatedToday(new DateTimeImmutable($simulatedDate));
        $em->persist($club);
        $em->flush();
        $em->clear();

        return $club->getId();
    }

    private function simulatedToday(string $clubId): ?string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);

        return $club->getSimulatedToday()?->format('Y-m-d');
    }

    /** @param array<string, mixed> $body */
    private function post(string $token, array $body): void
    {
        // Le cookie JWT se rejoue d'une requête à l'autre (extracteur cookie prioritaire) :
        // repartir d'une session neuve avant d'endosser un Bearer donné.
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/club/clock', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function register(string $ara): array
    {
        $this->startFreshBrowserSession($this->client);
        // IP à haute entropie : le limiteur d'inscription vit dans Redis, non rollback entre runs.
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'I', 'lastName' => 'Clock', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($me);

        return [$token, (string) $me['id'], (string) $me['club']['id']];
    }
}
