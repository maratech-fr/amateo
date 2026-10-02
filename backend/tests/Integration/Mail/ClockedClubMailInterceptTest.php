<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mail;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\User;
use App\Mail\ClubBusinessMail;
use App\Repository\ClubMailboxMessageRepository;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * P4-16 — NR de l'axe « un club à horloge simulée n'envoie JAMAIS de vrai e-mail MÉTIER », en
 * LISTE BLANCHE (durcissement revue sécu PR B) :
 *
 *  - un e-mail de COMPTE (reset de mot de passe…) n'est JAMAIS capté, même déclenché par un membre
 *    d'un club à horloge, même adressé à un utilisateur du MÊME club (il porterait un jeton →
 *    prise de compte) — cas (a) cross-club et (b) same-club via le vrai `/api/password/forgot` ;
 *  - un e-mail MÉTIER (rappel, campagne de vœux) marqué {@see ClubBusinessMail} ET adressé au club
 *    (membre ou coach) est capté — cas (c) membre, (d) coach ;
 *  - un e-mail métier avec un destinataire HORS club part réel — cas (e).
 *
 * Falsifiable : retirer la garde `isClubBusiness` → (b) capté (ROUGE) ; retirer la garde
 * destinataires → (a)/(e) captés (ROUGE).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClockedClubMailInterceptTest extends WebTestCase
{
    use TenantGucTrait;

    private const CLOCK = '2026-12-24';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private TenantConnectionContext $guc;

    private MailerInterface $mailer;

    private ClubMailboxMessageRepository $mailbox;

    // ---- e-mails MÉTIER (marqués) ----

    public function testBusinessMailToAClubMemberIsBoxed(): void
    {
        [$clubId, $memberEmail] = $this->seedClockedClub();

        $this->sendBusinessAs($clubId, $memberEmail, 'Rappel de période', 'Ta période commence bientôt.');

        self::assertSame([], $this->queuedEmails(), 'un e-mail métier d\'un club à horloge n\'est jamais envoyé réellement');
        self::assertSame(1, $this->mailbox->countForClub($clubId), 'il est rangé dans la boîte du club');
    }

    public function testBusinessMailToAClubCoachIsBoxed(): void
    {
        [$clubId, , $coachEmail] = $this->seedClockedClub();

        // Les campagnes de vœux visent des coachs NON-utilisateurs : l'appartenance passe par Coach.email.
        $this->sendBusinessAs($clubId, $coachEmail, 'Vos créneaux de match', 'Merci de répondre.');

        self::assertSame([], $this->queuedEmails());
        self::assertSame(1, $this->mailbox->countForClub($clubId), 'un e-mail métier vers un coach du club est capté');
    }

    public function testBusinessMailToAnOutOfClubRecipientIsSentReal(): void
    {
        [$clubId] = $this->seedClockedClub();

        $this->sendBusinessAs($clubId, 'etranger@ailleurs.fr', 'Rappel', 'Corps.');

        self::assertCount(1, $this->queuedEmails(), 'un destinataire hors club → l\'e-mail part réel (défense en profondeur)');
        self::assertSame(0, $this->mailbox->countForClub($clubId), 'rien en boîte quand un destinataire est hors club');
    }

    public function testAClubWithoutAClockSendsBusinessMailNormally(): void
    {
        [$clubId, $memberEmail] = $this->seedClockedClub(withClock: false);

        $this->sendBusinessAs($clubId, $memberEmail, 'Rappel', 'Corps.');

        self::assertCount(1, $this->queuedEmails(), 'un club sans horloge enfile son e-mail métier comme d\'habitude');
        self::assertSame(0, $this->mailbox->countForClub($clubId), 'rien en boîte pour un club sans horloge');
    }

    public function testAnotherClubNeverSeesTheBox(): void
    {
        [$clockedId, $memberEmail] = $this->seedClockedClub();
        [$otherId] = $this->seedClockedClub(withClock: false);

        $this->sendBusinessAs($clockedId, $memberEmail, 'Privé', 'Corps.');
        self::assertSame(1, $this->mailbox->countForClub($clockedId));

        $this->guc->setClubId($otherId);
        self::assertSame(0, $this->mailbox->countForClub($clockedId), 'la RLS cache la boîte d\'autrui même en la demandant explicitement');
    }

    // ---- e-mails de COMPTE (jamais captés) via le vrai /api/password/forgot ----

    public function testPasswordResetForAnotherClubUserIsNeverBoxed(): void
    {
        [$clockedId, , , $member] = $this->seedClockedClub();
        [, , , $victim] = $this->seedClockedClub(withClock: false); // victime d'un AUTRE club

        $this->forgotAs($member, $victim->getEmail());

        self::assertCount(1, $this->queuedEmails(), 'le reset part RÉELLEMENT (jamais capté, e-mail de compte)');
        self::assertSame(0, $this->mailbox->countForClub($clockedId), '0 ligne en boîte — pas de capture cross-club');
    }

    public function testPasswordResetForASameClubUserIsNeverBoxed(): void
    {
        // La victime est un MEMBRE du club à horloge : la garde « destinataires du club » passerait,
        // seule la garde « e-mail métier seulement » empêche la capture du jeton de reset.
        [$clockedId, , , $member] = $this->seedClockedClub();
        $victim = $this->addMember($clockedId, 'victime-' . bin2hex(random_bytes(3)) . '@club.fr');

        $this->forgotAs($member, $victim->getEmail());

        self::assertCount(1, $this->queuedEmails(), 'le reset d\'un membre du même club part RÉELLEMENT (e-mail de compte)');
        self::assertSame(0, $this->mailbox->countForClub($clockedId), '0 ligne — un e-mail de compte n\'est jamais capté');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->guc = $c->get(TenantConnectionContext::class);
        $this->mailer = $c->get(MailerInterface::class);
        $this->mailbox = $c->get(ClubMailboxMessageRepository::class);
    }

    protected function tearDown(): void
    {
        $this->guc->clear();
        parent::tearDown();
    }

    private function sendBusinessAs(string $clubId, string $to, string $subject, string $text): void
    {
        $this->guc->setClubId($clubId);
        $this->mailer->send(ClubBusinessMail::mark((new Email)->from('noreply@amateo.test')->to($to)->subject($subject)->text($text)));
    }

    private function forgotAs(User $member, string $victimEmail): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($member);
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $this->client->request('POST', '/api/password/forgot', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], json_encode(['email' => $victimEmail], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    /** @return list<Email> les e-mails enfilés sur le transport mémoire */
    private function queuedEmails(): array
    {
        $transport = self::getContainer()->get('messenger.transport.mailer_in_memory');
        \assert($transport instanceof InMemoryTransport);

        $emails = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (method_exists($message, 'getMessage') && $message->getMessage() instanceof Email) {
                $emails[] = $message->getMessage();
            }
        }

        return $emails;
    }

    /**
     * Un club (horloge au {@see self::CLOCK} par défaut) avec un membre actif et un coach, chacun
     * avec son e-mail.
     *
     * @return array{0: string, 1: string, 2: string, 3: User} [clubId, memberEmail, coachEmail, member]
     */
    private function seedClockedClub(bool $withClock = true): array
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club)->setName('Boîte ' . $suffix)->setSlug('boite-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setSimulatedToday($withClock ? new DateTimeImmutable(self::CLOCK) : null);
        $this->em->persist($club);
        $this->em->flush();

        $member = $this->addMember($club->getId(), 'membre-' . $suffix . '@club.fr');

        $this->scopeGucToClub($club->getId());
        $coachEmail = 'coach-' . $suffix . '@club.fr';
        $this->em->persist((new Coach)->setClubId($club->getId())->setSeasonId(Uuid::v4()->toRfc4122())->setFirstName('C')->setLastName('H')->setEmail($coachEmail));
        $this->em->flush();

        return [$club->getId(), $member->getEmail(), $coachEmail, $member];
    }

    private function addMember(string $clubId, string $email): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = (new User)->setEmail($email)->setFirstName('M')->setLastName('B');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($clubId);
        $this->em->persist((new ClubUser)->setClubId($clubId)->setUserId($user->getId())->setRole('viewer')->setIsActive(true));
        $this->em->flush();
        $this->guc->clear();

        return $user;
    }
}
