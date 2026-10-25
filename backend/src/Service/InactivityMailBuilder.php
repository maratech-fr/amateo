<?php

declare(strict_types=1);

namespace App\Service;

use App\Mail\EmailTemplateRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * Builds the RGPD inactivity warning email (retention: 23-month notice before
 * the 24-month anonymization). Isolated from the cron walker so the rendering
 * is unit-testable on its own (pattern TransitionReminderMailBuilder).
 */
final class InactivityMailBuilder
{
    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
        // Nom produit unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly ProductIdentity $productIdentity = new ProductIdentity,
    ) {}

    public function build(string $to, string $firstName): Email
    {
        $product = $this->productIdentity->name();
        $lines = [
            \sprintf('Bonjour %s,', $firstName),
            '',
            "Votre compte {$product} est inactif depuis presque deux ans.",
            'Conformément à notre politique de conservation des données (RGPD), il sera',
            'anonymisé définitivement dans un mois si vous ne vous reconnectez pas d\'ici là.',
            '',
            'Un simple login suffit à conserver votre compte.',
        ];
        $link = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') . '/login' : null;
        if (null !== $link) {
            $lines[] = '';
            $lines[] = $link;
        }

        $email = (new Email)
            ->from($this->mailFrom->address())
            ->to($to)
            ->subject('⏳ Compte inactif — anonymisation dans un mois (RGPD)')
            ->text(implode("\n", $lines));

        // Bouton CTA du HTML : en-têtes internes lus/retirés au worker par EmailSignatureListener.
        // L'URL EST le lien du corps (jamais dupliqué sous le bouton) — patron CoachWishMailBuilder.
        if (null !== $link) {
            $headers = $email->getHeaders();
            $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $link);
            $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Me connecter');
        }

        return $email;
    }
}
