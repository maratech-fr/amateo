<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * P4-301 — construit le mail de préavis « compte sans club » : le titulaire n'a
 * plus aucun accès (adhésion active, pending ou demande de création) et son compte
 * sera supprimé à l'échéance annoncée. Isolé du marcheur cron pour un rendu
 * testable à l'unité (patron InactivityMailBuilder).
 *
 * Deux variantes de même nature, un seul foyer de rendu :
 *  - retrait / refus : l'accès a été retiré (désactivation, refus d'adhésion ou
 *    de création de club) ;
 *  - espace club supprimé : le workspace du club a été purgé (RGPD).
 *
 * FR sobre, nom produit via ProductIdentity, AUCUN identifiant interne, lien /login.
 */
final class OrphanAccountMailBuilder
{
    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
        // Nom produit unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly ProductIdentity $productIdentity = new ProductIdentity,
    ) {}

    /** Accès retiré (désactivation, refus d'adhésion ou de création de club). */
    public function buildAccessRemoved(string $to, string $firstName, DateTimeImmutable $deadline): Email
    {
        $product = $this->productIdentity->name();

        return $this->compose(
            $to,
            \sprintf('Votre compte %s sera supprimé sans nouvel accès', $product),
            [
                \sprintf('Bonjour %s,', $firstName),
                '',
                \sprintf('Vous n\'avez plus accès à aucun club sur %s.', $product),
                \sprintf(
                    'Sans nouvel accès (approbation d\'un gestionnaire), votre compte sera supprimé le %s.',
                    $deadline->format('d/m/Y'),
                ),
                'Vous pourrez vous réinscrire ensuite si besoin.',
            ],
        );
    }

    /** L'espace du club a été supprimé (purge RGPD du workspace orphelin). */
    public function buildClubDeleted(string $to, string $firstName, DateTimeImmutable $deadline): Email
    {
        $product = $this->productIdentity->name();

        return $this->compose(
            $to,
            \sprintf('Votre compte %s sera supprimé sans nouvel accès', $product),
            [
                \sprintf('Bonjour %s,', $firstName),
                '',
                \sprintf('L\'espace %s de votre club a été supprimé.', $product),
                \sprintf(
                    'Sans nouvel accès, votre compte sera supprimé le %s.',
                    $deadline->format('d/m/Y'),
                ),
                'Vous pourrez vous réinscrire ensuite si besoin.',
            ],
        );
    }

    /**
     * @param list<string> $lines
     */
    private function compose(string $to, string $subject, array $lines): Email
    {
        if ('' !== $this->frontendBaseUrl) {
            $lines[] = '';
            $lines[] = rtrim($this->frontendBaseUrl, '/') . '/login';
        }

        return (new Email)
            ->from($this->mailFrom->address())
            ->to($to)
            ->subject($subject)
            ->text(implode("\n", $lines));
    }
}
