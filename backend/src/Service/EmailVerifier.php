<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Issues and consumes single-use email-verification tokens. Kept deliberately
 * separate from symfonycasts/reset-password: a reset token must never verify an
 * email, nor the reverse. Only the sha256 hash of the raw token is stored; the
 * raw value is emailed and never persisted.
 */
final class EmailVerifier
{
    private const TTL = '+24 hours';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailVerificationTokenRepository $repository,
        // P4-304 — horloge RÉELLE : la TTL (24 h) du lien de vérification est une
        // durée de SÉCURITÉ ; elle ne doit JAMAIS suivre l'« aujourd'hui » simulé
        // d'un club démo (TenantFilterListener pose `_club_id` même sur la route
        // publique quand un JWT est présent). Sinon un porteur sous horloge démo
        // future verrait un lien quasi éternel, sous horloge passée un lien déjà
        // mort. Patron EmailChangeVerifier.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Create a fresh token for a pending registration and return the RAW value
     * (to be emailed). Replaces any outstanding token for the user so only one
     * link is ever live.
     */
    public function generateToken(User $user, string $ara, ?string $clubName): string
    {
        $this->repository->deleteForUser($user);

        $raw = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $token = new EmailVerificationToken(
            $user,
            hash('sha256', $raw),
            $now->modify(self::TTL),
            $now,
            $ara,
            $clubName,
        );
        $this->entityManager->persist($token);

        return $raw;
    }

    /**
     * Resolve a raw token to its (unconsumed, unexpired) row, or null. Does NOT
     * consume — the caller consumes via consume() once the verification side
     * effects have succeeded.
     */
    public function resolve(string $raw): ?EmailVerificationToken
    {
        if ('' === $raw) {
            return null;
        }

        $token = $this->repository->findOneByHashedToken(hash('sha256', $raw));
        if (!$token instanceof EmailVerificationToken || $token->isExpired($this->clock->now())) {
            return null;
        }

        return $token;
    }

    /** Single-use: drop the token so the link cannot be replayed. */
    public function consume(EmailVerificationToken $token): void
    {
        $this->entityManager->remove($token);
    }
}
