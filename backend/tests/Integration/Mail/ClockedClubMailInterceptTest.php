<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mail;

use App\Entity\Club;
use App\Repository\ClubMailboxMessageRepository;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * P4-16 — NR de l'axe « un club à horloge simulée n'envoie JAMAIS de vrai e-mail ».
 *
 * Preuve à l'ENFILAGE (les e-mails de l'app passent par le bus, routés en test sur le transport
 * mémoire `mailer_in_memory`) :
 *  1. club à horloge active → AUCUN SendEmailMessage enfilé, UNE ligne dans SA boîte ;
 *  2. club SANS horloge → e-mail enfilé normalement, RIEN en boîte ;
 *  3. un autre club ne voit jamais la boîte d'autrui (RLS : sous son GUC, 0 ligne).
 *
 * Falsifiable : désactiver le listener {@see \App\EventListener\ClockedClubMailInterceptor}
 * (retirer `reject()`/le subscriber) fait enfiler l'e-mail du club à horloge → rouge sur (1).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClockedClubMailInterceptTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private TenantConnectionContext $guc;

    private MailerInterface $mailer;

    private ClubMailboxMessageRepository $mailbox;

    public function testAClockedClubEmailIsBoxedAndNeverQueued(): void
    {
        $clubId = $this->seedClub(simulatedToday: new DateTimeImmutable('2026-12-24'));
        $this->guc->setClubId($clubId);

        $this->mailer->send($this->email('coach@club.fr', 'Relance des vœux', 'Bonjour, pensez à répondre.'));

        self::assertSame([], $this->queuedEmails(), 'un club à horloge active n\'enfile AUCUN e-mail réel');
        self::assertSame(1, $this->mailbox->countForClub($clubId), 'l\'e-mail est rangé dans la boîte du club');

        $rows = $this->mailbox->findForClubNewestFirst($clubId);
        self::assertSame('Relance des vœux', $rows[0]->getSubject());
        self::assertStringContainsString('coach@club.fr', $rows[0]->getToAddress());
        self::assertSame('2026-12-24', $rows[0]->getSimulatedDate()->format('Y-m-d'), 'le « jour » de la boîte = l\'horloge du club');
        self::assertStringContainsString('pensez à répondre', (string) $rows[0]->getBodyText());
    }

    public function testAClubWithoutAClockSendsNormallyAndBoxesNothing(): void
    {
        $clubId = $this->seedClub(simulatedToday: null);
        $this->guc->setClubId($clubId);

        $this->mailer->send($this->email('coach@club.fr', 'Relance des vœux', 'Bonjour.'));

        self::assertCount(1, $this->queuedEmails(), 'un club sans horloge enfile son e-mail comme d\'habitude');
        self::assertSame(0, $this->mailbox->countForClub($clubId), 'rien en boîte pour un club sans horloge');
    }

    public function testAnotherClubNeverSeesTheBox(): void
    {
        $clockedId = $this->seedClub(simulatedToday: new DateTimeImmutable('2026-12-24'));
        $otherId = $this->seedClub(simulatedToday: null);

        $this->guc->setClubId($clockedId);
        $this->mailer->send($this->email('coach@club.fr', 'Privé', 'Corps.'));
        self::assertSame(1, $this->mailbox->countForClub($clockedId));

        // Sous le GUC d'un AUTRE club, la boîte du club à horloge est invisible (RLS).
        $this->guc->setClubId($otherId);
        self::assertSame(0, $this->mailbox->countForClub($clockedId), 'la RLS cache la boîte d\'autrui même en la demandant explicitement');
        self::assertSame([], $this->mailbox->findForClubNewestFirst($clockedId), 'aucune ligne d\'un autre club ne franchit la frontière');
    }

    protected function setUp(): void
    {
        self::bootKernel();
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

    private function email(string $to, string $subject, string $text): Email
    {
        return (new Email())->from('noreply@amateo.test')->to($to)->subject($subject)->text($text);
    }

    private function seedClub(?DateTimeImmutable $simulatedToday): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club())->setName('Boîte ' . $suffix)->setSlug('boite-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setSimulatedToday($simulatedToday);
        $this->em->persist($club);
        $this->em->flush();

        return $club->getId();
    }
}
