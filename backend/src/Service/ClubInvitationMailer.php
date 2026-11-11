<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubInvitation;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use App\Support\FrenchDate;
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
    public function __construct(
        private MailerInterface $mailer,
        private MailFrom $mailFrom,
        private ProductIdentity $productIdentity,
        #[Autowire(env: 'FRONTEND_BASE_URL')]
        private string $frontendBaseUrl,
        private LoggerInterface $logger,
    ) {}

    public function send(ClubInvitation $invitation, string $rawToken, string $inviterFirstName, string $clubName, string $requestHost, ?Club $club = null): void
    {
        $base = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') : $requestHost;
        $link = $base . '/invitation/' . $rawToken;
        $product = $this->productIdentity->name();
        $expiry = FrenchDate::long($invitation->getExpiresAt());
        $inviter = '' !== trim($inviterFirstName) ? trim($inviterFirstName) : $clubName;

        $email = (new Email)
            ->from($this->mailFrom->address())
            ->to($invitation->getEmail())
            ->subject(\sprintf('%s vous invite à rejoindre %s', $inviter, $clubName))
            ->text(
                "Bonjour,\n\n"
                . "{$inviter} vous invite à rejoindre {$clubName} sur {$product}.\n\n"
                . "Pour accepter et accéder au planning du club, ouvrez ce lien :\n{$link}\n\n"
                . "Ce lien expire le {$expiry}.\n\n"
                . 'Si vous n\'attendiez pas cette invitation, ignorez simplement cet e-mail.',
            );
        // Bouton CTA du HTML (D1) : l'URL EST le lien d'invitation, DÉRIVÉ de FRONTEND_BASE_URL
        // (jamais du Host entrant quand la base est posée, A1) — jamais dupliqué sous le bouton.
        if (1 === preg_match('~^https?://~i', $link)) {
            $headers = $email->getHeaders();
            $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $link);
            $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Accepter l\'invitation');
        }
        // Identité du club (logo + nom court) sur la carte d'e-mail (D1), quand elle est connue.
        if ($club instanceof Club) {
            ClubMailMetadata::mark($email, $club);
        }

        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            // mailer->send ne fait qu'ENFILER sur le bus ; seul un échec de DISPATCH (Redis)
            // tombe ici. Avalé pour ne pas faire échouer l'émission côté gestionnaire, mais
            // tracé — patron RegisterService::sendVerificationEmail.
            $this->logger->warning('Invitation mail dispatch failed (not enqueued)', ['error' => $e->getMessage()]);
        }
    }
}
