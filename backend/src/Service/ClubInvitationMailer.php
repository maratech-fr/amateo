<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClubInvitation;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * L'e-mail d'invitation « {Prénom} vous invite à rejoindre {Club} ». Pas de
 * ClubBusinessMail / horloge simulée : un gestionnaire démo ne peut PAS inviter
 * (décision fondateur), donc aucune invitation ne naît sous horloge simulée — l'e-mail
 * part toujours en vrai temps. From = l'identité produit (MailFrom / ProductIdentity).
 */
final readonly class ClubInvitationMailer
{
    /** @var array<int, string> mois en toutes lettres (l'e-mail est lu par un humain). */
    private const array MONTHS = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    public function __construct(
        private MailerInterface $mailer,
        private MailFrom $mailFrom,
        private ProductIdentity $productIdentity,
        #[Autowire(env: 'FRONTEND_BASE_URL')]
        private string $frontendBaseUrl,
        private LoggerInterface $logger,
    ) {}

    public function send(ClubInvitation $invitation, string $rawToken, string $inviterFirstName, string $clubName, string $requestHost): void
    {
        $base = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') : $requestHost;
        $link = $base . '/invitation/' . $rawToken;
        $product = $this->productIdentity->name();
        $expiry = $this->frenchDate($invitation->getExpiresAt());
        $inviter = '' !== trim($inviterFirstName) ? trim($inviterFirstName) : $clubName;

        try {
            $this->mailer->send(
                (new Email)
                    ->from($this->mailFrom->address())
                    ->to($invitation->getEmail())
                    ->subject(\sprintf('%s vous invite à rejoindre %s', $inviter, $clubName))
                    ->text(
                        "Bonjour,\n\n"
                        . "{$inviter} vous invite à rejoindre {$clubName} sur {$product}.\n\n"
                        . "Pour accepter et accéder au planning du club, ouvrez ce lien :\n{$link}\n\n"
                        . "Ce lien expire le {$expiry}.\n\n"
                        . 'Si vous n\'attendiez pas cette invitation, ignorez simplement cet e-mail.',
                    ),
            );
        } catch (Throwable $e) {
            // mailer->send ne fait qu'ENFILER sur le bus ; seul un échec de DISPATCH (Redis)
            // tombe ici. Avalé pour ne pas faire échouer l'émission côté gestionnaire, mais
            // tracé — patron RegisterService::sendVerificationEmail.
            $this->logger->warning('Invitation mail dispatch failed (not enqueued)', ['error' => $e->getMessage()]);
        }
    }

    private function frenchDate(DateTimeImmutable $date): string
    {
        return \sprintf('%d %s %d', (int) $date->format('j'), self::MONTHS[(int) $date->format('n')], (int) $date->format('Y'));
    }
}
