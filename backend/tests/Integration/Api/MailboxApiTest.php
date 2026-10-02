<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubMailboxMessage;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Repository\ClubMailboxMessageRepository;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'endpoint de LECTURE de la boîte aux lettres (P4-16) : tout membre connecté du club lit SA
 * boîte (liste + détail), jamais celle d'un autre club — le club vient du JWT, la RLS fait le
 * reste. Compagnon de l'axe gardé par ClockedClubMailInterceptTest (côté enfilage).
 */
#[Group('phase1')]
final class MailboxApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private ClubMailboxMessageRepository $mailbox;

    public function testAMemberListsAndReadsItsClubBox(): void
    {
        [$clubId, $user] = $this->seedClubWithMember();
        $id = $this->seedMessage($clubId, 'coach@club.fr', 'Relance des vœux', 'Pensez à répondre.');

        $this->client->request('GET', '/api/mailbox', [], [], $this->auth($user));
        self::assertResponseIsSuccessful();
        $list = $this->data();
        self::assertSame(1, $list['count']);
        self::assertSame('Relance des vœux', $list['messages'][0]['subject']);
        self::assertSame($id, $list['messages'][0]['id']);
        self::assertArrayNotHasKey('bodyText', $list['messages'][0], 'la liste ne porte pas le corps');

        $this->client->request('GET', '/api/mailbox/' . $id, [], [], $this->auth($user));
        self::assertResponseIsSuccessful();
        $detail = $this->data();
        self::assertSame('Pensez à répondre.', $detail['bodyText']);
        self::assertArrayHasKey('bodyHtml', $detail);
    }

    public function testAMemberNeverSeesAnotherClubBox(): void
    {
        [$clubA, , $idA] = $this->seedClubWithMailbox();
        [, $userB] = $this->seedClubWithMember();

        // Le membre de B liste : sa boîte est vide, celle de A est invisible.
        $this->client->request('GET', '/api/mailbox', [], [], $this->auth($userB));
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->data()['count'], 'le membre de B ne voit aucune ligne du club A');

        // Et le détail d'un message de A, demandé par B, est un 404 (RLS → introuvable).
        $this->client->request('GET', '/api/mailbox/' . $idA, [], [], $this->auth($userB));
        self::assertResponseStatusCodeSame(404);

        unset($clubA);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->mailbox = $c->get(ClubMailboxMessageRepository::class);
    }

    /** @return array{0: string, 1: User} [clubId, member] */
    private function seedClubWithMember(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club())->setName('MB ' . $suffix)->setSlug('mb-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $club->setSimulatedToday(new DateTimeImmutable('2026-12-24'));
        $this->em->persist($club);

        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = (new User())->setEmail('m' . $suffix . '@test.fr')->setFirstName('M')->setLastName('B');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        // Simple MEMBRE (viewer), pas gestionnaire : la boîte est lisible par tout membre.
        $this->em->persist((new ClubUser())->setClubId($club->getId())->setUserId($user->getId())->setRole('viewer')->setIsActive(true));
        $this->em->flush();

        return [$club->getId(), $user];
    }

    /** @return array{0: string, 1: User, 2: string} [clubId, member, messageId] */
    private function seedClubWithMailbox(): array
    {
        [$clubId, $user] = $this->seedClubWithMember();
        $id = $this->seedMessage($clubId, 'coach@club.fr', 'Privé A', 'Corps A.');

        return [$clubId, $user, $id];
    }

    private function seedMessage(string $clubId, string $to, string $subject, string $text): string
    {
        $this->scopeGucToClub($clubId);
        $message = (new ClubMailboxMessage())
            ->setClubId($clubId)
            ->setSimulatedDate(new DateTimeImmutable('2026-12-24'))
            ->setFromAddress('noreply@amateo.test')
            ->setToAddress($to)
            ->setSubject($subject)
            ->setBodyText($text);
        $this->mailbox->record($message);
        $this->clearGuc(); // relâche le GUC avant la requête HTTP authentifiée (reposé depuis le JWT)

        return $message->getId();
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function auth(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($user)];
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
