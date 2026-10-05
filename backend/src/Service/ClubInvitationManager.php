<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClubInvitation;
use App\Enum\ClubRole;
use App\Repository\ClubInvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Émet, renvoie, résout et consomme les jetons d'invitation (patron EmailVerifier).
 * Seul le sha256 du jeton est stocké ; le raw est retourné à l'appelant pour l'e-mail
 * et jamais persisté.
 */
final class ClubInvitationManager
{
    /** Décision fondateur : 7 jours (aligné sur l'approbation de club, large pour un bénévole). */
    private const string TTL = '+7 days';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubInvitationRepository $repository,
        // P4-304 — horloge RÉELLE : l'expiration (+7 j) est une durée de SÉCURITÉ.
        // L'émission est interdite aux gestionnaires démo, mais la CONSOMMATION est une
        // route publique qui peut porter le `_club_id` d'un club démo (JWT présent) :
        // l'horloge décorée simulerait alors sa date et un lien paraîtrait éternel (démo
        // future) ou déjà mort (démo passée). La péremption se mesure à l'instant réel.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Crée OU renouvelle l'invitation du couple (club, adresse) et retourne le jeton
     * BRUT (à e-mailer). Une invitation existante du même club est ABSORBÉE : hash et
     * expiration régénérés sur la MÊME ligne (décision fondateur), jamais une seconde.
     * L'appelant pose le GUC tenant (écriture club-scoped) et flushe sa transaction.
     */
    public function issue(string $clubId, string $email, ClubRole $role, string $invitedByUserId): string
    {
        $raw = bin2hex(random_bytes(32));
        $now = $this->clock->now();

        $invitation = $this->repository->findOneByClubAndEmail($clubId, $email);
        if (!$invitation instanceof ClubInvitation) {
            $invitation = new ClubInvitation;
            $invitation->setClubId($clubId);
            $invitation->setEmail($email);
            $this->entityManager->persist($invitation);
        }
        $invitation->setRole($role->value);
        $invitation->setInvitedByUserId($invitedByUserId);
        $invitation->setTokenHash(hash('sha256', $raw));
        $invitation->setExpiresAt($now->modify(self::TTL));

        return $raw;
    }

    /** Régénère le jeton d'une invitation existante (bouton « renvoyer ») et retourne le raw. */
    public function regenerate(ClubInvitation $invitation): string
    {
        $raw = bin2hex(random_bytes(32));
        $invitation->setTokenHash(hash('sha256', $raw));
        $invitation->setExpiresAt($this->clock->now()->modify(self::TTL));

        return $raw;
    }

    /**
     * Résout un jeton brut vers sa ligne (non expirée), ou null. Ne consomme PAS —
     * l'appelant consomme via consume() une fois l'adhésion matérialisée.
     */
    public function resolve(string $raw): ?ClubInvitation
    {
        if ('' === $raw) {
            return null;
        }

        $invitation = $this->repository->findOneByTokenHash(hash('sha256', $raw));
        if (!$invitation instanceof ClubInvitation || $invitation->isExpired($this->clock->now())) {
            return null;
        }

        return $invitation;
    }

    /** Single-use : supprime la ligne pour que le lien ne soit pas rejouable. */
    public function consume(ClubInvitation $invitation): void
    {
        $this->entityManager->remove($invitation);
    }
}
