<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailChangeToken;
use App\Entity\User;
use App\Repository\EmailChangeTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Issues and consumes single-use e-mail-CHANGE confirmation tokens (P4-74).
 *
 * Mirrors {@see EmailVerifier} — only the sha256 hash of the raw token is
 * stored, the raw value is emailed and never persisted — but stays decoupled
 * from the register token (which carries the club-creation intent). The pending
 * address itself lives on {@see User::$pendingEmail}; this token only proves the
 * holder controls that new address.
 */
final class EmailChangeVerifier
{
    private const TTL = '+24 hours';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailChangeTokenRepository $repository,
        // BCK-34 — horloge RÉELLE : la TTL (24 h) du lien de confirmation est une
        // durée de SÉCURITÉ, elle ne doit pas suivre l'horloge simulée d'un club démo.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Create a fresh confirmation token and return the RAW value (to be emailed
     * to the pending address). Replaces any outstanding token for the user so
     * only one confirmation link is ever live.
     */
    public function generateToken(User $user): string
    {
        $this->repository->deleteForUser($user);

        $raw = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $token = new EmailChangeToken($user, hash('sha256', $raw), $now->modify(self::TTL), $now);
        $this->entityManager->persist($token);

        return $raw;
    }

    /**
     * Resolve a raw token to its (unexpired) row, or null. Does NOT consume —
     * the caller consumes via consume() once the switch has succeeded.
     */
    public function resolve(string $raw): ?EmailChangeToken
    {
        if ('' === $raw) {
            return null;
        }

        $token = $this->repository->findOneByHashedToken(hash('sha256', $raw));
        if (!$token instanceof EmailChangeToken || $token->isExpired($this->clock->now())) {
            return null;
        }

        return $token;
    }

    /** Single-use: drop the token so the link cannot be replayed. */
    public function consume(EmailChangeToken $token): void
    {
        $this->entityManager->remove($token);
    }

    /** Cancel a pending change: drop every outstanding confirmation token for the user. */
    public function clearForUser(User $user): void
    {
        $this->repository->deleteForUser($user);
    }
}
