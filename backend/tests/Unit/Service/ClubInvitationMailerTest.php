<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ClubInvitation;
use App\Service\ClubInvitationMailer;
use App\Service\MailFrom;
use App\Service\ProductIdentity;
use App\Tests\AssertsActionMailCta;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * PROMESSE (D1) : l'e-mail d'invitation porte un bouton « Accepter l'invitation » vers le lien
 * `/invitation/{token}` du corps. ROUGE tant que {@see ClubInvitationMailer::send} ne pose pas
 * l'en-tête CTA.
 *
 * ABUS A1 (anti-usurpation d'hôte) : quand une base front est configurée, le lien — DONC le CTA —
 * vit sur cette base, JAMAIS sur le `Host` entrant de la requête (un pirate ne peut pas fabriquer
 * un bouton vers son domaine). Jumeau de l'angle anti-poisoning d'
 * {@see App\Tests\Integration\Api\IdentityEmailCtaButtonTest::testPasswordResetEmailCarriesAClickableCtaToTheResetLink}.
 */
#[Group('phase1')]
final class ClubInvitationMailerTest extends TestCase
{
    use AssertsActionMailCta;

    private const string TOKEN = 'jeton-invitation-factice';

    public function testInvitationCarriesTheAcceptCta(): void
    {
        $mailer = $this->recordingMailer();
        $this->mailer($mailer, 'https://app.example.test')->send($this->invitation(), self::TOKEN, 'Gérard', 'BCCL', 'https://whatever.ignored');

        $this->assertCtaHeadersPointToBodyLink($this->sent($mailer), 'https://app.example.test/invitation/' . self::TOKEN, 'Accepter l\'invitation');
    }

    public function testCtaIgnoresRequestHostWhenBaseUrlIsConfigured(): void
    {
        $mailer = $this->recordingMailer();
        // Host MENTEUR : la base configurée doit l'emporter, pour le lien ET le bouton.
        $this->mailer($mailer, 'https://app.example.test')->send($this->invitation(), self::TOKEN, 'Gérard', 'BCCL', 'https://attacker.example');

        $email = $this->sent($mailer);
        self::assertStringNotContainsString('attacker.example', (string) $email->getTextBody(), 'le lien ne suit jamais le Host entrant');
        $this->assertCtaHeadersPointToBodyLink($email, 'https://app.example.test/invitation/' . self::TOKEN, 'Accepter l\'invitation');
    }

    private function mailer(MailerInterface $mailer, string $base): ClubInvitationMailer
    {
        return new ClubInvitationMailer($mailer, new MailFrom, new ProductIdentity, $base, new NullLogger);
    }

    private function invitation(): ClubInvitation
    {
        return new ClubInvitation()->setEmail('invite@club.fr')->setExpiresAt(new DateTimeImmutable('2027-01-15'));
    }

    /** @param MailerInterface&object{last: ?Email} $mailer */
    private function sent(MailerInterface $mailer): Email
    {
        self::assertInstanceOf(Email::class, $mailer->last, 'un e-mail d\'invitation doit avoir été enfilé');

        return $mailer->last;
    }

    /** @return MailerInterface&object{last: ?Email} */
    private function recordingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            public ?Email $last = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($message instanceof Email) {
                    $this->last = $message;
                }
            }
        };
    }
}
