<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Mail\EmailTemplateRenderer;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * app:clubs:erasure-remind — rappel J-7 avant la suppression définitive d'un club
 * orphelin : fenêtre [échéance-7j, échéance[, idempotent (un seul rappel par échéance),
 * saute un club dont un membre actif est revenu, et n'envoie rien sans adresse connue.
 */
#[Group('integration')]
final class ClubErasureReminderCommandTest extends KernelTestCase
{
    public function testRemindsClubInWindowOnceThenIsIdempotent(): void
    {
        $clubId = $this->seedScheduledClub('+3 days', contactEmail: 'contact@club.fr');

        $tester = $this->runCommand();
        self::assertSame(0, $tester->getStatusCode());

        $em = $this->em();
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNotNull($club->getErasureReminderSentAt(), 'un club dans la fenêtre J-7 a été rappelé');

        // Second passage : idempotent, plus aucun rappel (stamp déjà posé).
        $stamp = $club->getErasureReminderSentAt();
        $em->clear();
        $this->runCommand();
        $again = $this->em()->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $again);
        self::assertEquals($stamp, $again->getErasureReminderSentAt(), 'pas de second rappel pour la même échéance');
    }

    public function testDoesNotRemindOutsideTheSevenDayWindow(): void
    {
        $clubId = $this->seedScheduledClub('+20 days', contactEmail: 'faraway@club.fr');

        self::assertSame(0, $this->runCommand()->getStatusCode());

        $club = $this->em()->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNull($club->getErasureReminderSentAt(), 'échéance à 20 jours → hors fenêtre, pas de rappel');
    }

    public function testSkipsClubWithAnActiveMember(): void
    {
        $clubId = $this->seedScheduledClub('+2 days', contactEmail: 'used@club.fr', withActiveMember: true);

        self::assertSame(0, $this->runCommand()->getStatusCode());

        $club = $this->em()->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNull($club->getErasureReminderSentAt(), 'un membre actif est revenu → aucune alarme');
    }

    public function testSendsNothingWithoutAKnownAddress(): void
    {
        $clubId = $this->seedScheduledClub('+1 days', contactEmail: null);

        self::assertSame(0, $this->runCommand()->getStatusCode());

        $club = $this->em()->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNull($club->getErasureReminderSentAt(), 'aucune adresse (ni FFBB ni contact) → rien à envoyer');
    }

    public function testReminderCarriesARegisterCta(): void
    {
        // PROMESSE (D1) : le rappel « espace supprimé le … » porte un lien d'INSCRIPTION (/register)
        // au texte + un bouton « Inscrire un gestionnaire » — c'est le geste attendu pour conserver
        // le club. ROUGE tant que le producteur ne pose pas l'en-tête CTA ni le lien /register.
        $contact = 'reminder-cta-' . bin2hex(random_bytes(3)) . '@club.fr';
        $this->seedScheduledClub('+3 days', contactEmail: $contact);

        self::assertSame(0, $this->runCommand()->getStatusCode());

        $email = $this->queuedMailTo($contact);
        self::assertMatchesRegularExpression('~https?://\S+/register\b~', (string) $email->getTextBody(), 'le rappel doit porter un lien d\'inscription (/register)');
        $headers = $email->getHeaders();
        self::assertTrue($headers->has(EmailTemplateRenderer::CTA_URL_HEADER), 'ROUGE tant que le rappel ne pose pas le bouton d\'inscription');
        self::assertSame('Inscrire un gestionnaire', $headers->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** Le mail ENFILÉ (transport mémoire, club sans horloge → non intercepté) adressé à `$address`. */
    private function queuedMailTo(string $address): Email
    {
        $transport = self::getContainer()->get('messenger.transport.mailer_in_memory');
        \assert($transport instanceof InMemoryTransport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!method_exists($message, 'getMessage') || !$message->getMessage() instanceof Email) {
                continue;
            }
            $email = $message->getMessage();
            foreach ($email->getTo() as $to) {
                if ($to->getAddress() === $address) {
                    return $email;
                }
            }
        }
        self::fail(\sprintf('aucun e-mail enfilé vers %s', $address));
    }

    private function seedScheduledClub(string $deadline, ?string $contactEmail, bool $withActiveMember = false): string
    {
        $em = $this->em();
        $club = new Club;
        $club->setName('Orphelin');
        $club->setSlug('orphelin-' . substr(md5(uniqid('', true)), 0, 8));
        // Pas de code FFBB : le repli adresse de contact est exercé sans toucher le stub.
        $club->setContactEmail($contactEmail);
        $club->setErasureScheduledAt(new DateTimeImmutable($deadline));
        $em->persist($club);
        $em->flush(); // la table `club` est hors RLS — pas de GUC requis.
        $clubId = $club->getId();

        if ($withActiveMember) {
            // club_user est RLS WITH CHECK sur le GUC : poser le contexte tenant du club.
            $tenant = self::getContainer()->get(TenantConnectionContext::class);
            \assert($tenant instanceof TenantConnectionContext);
            $tenant->setClubId($clubId);
            try {
                $membership = new ClubUser;
                $membership->setClubId($clubId);
                $membership->setUserId('00000000-0000-4000-8000-' . substr(md5(uniqid('', true)), 0, 12));
                $membership->setRole('manager');
                $membership->setIsActive(true);
                $em->persist($membership);
                $em->flush();
            } finally {
                $tenant->clear();
            }
        }

        $em->clear();

        return $clubId;
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:clubs:erasure-remind'));
        $tester->execute([]);

        return $tester;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
