<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use DateTimeImmutable;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Gates login on email verification. Runs in checkPostAuth — AFTER the password
 * is verified — and throws the SAME message lexik emits for a wrong password, so
 * an unverified account is indistinguishable from bad credentials (no
 * verification-status oracle; part of A3 anti-enumeration). Wired on the `login`
 * firewall only: a JWT is minted solely by a successful login or by
 * /api/register/verify (which verifies first), so "JWT ⇒ verified" holds at
 * issuance and re-checking on every api request would be redundant.
 *
 * Démos — la MÊME porte garde tout compte de DÉMONSTRATION (drapeau `is_demo`,
 * SEC-28 : animateur `demo@`, BCCL `demo-bccl@`) derrière sa fenêtre d'activation :
 * hors fenêtre, sa connexion est refusée du même refus, à l'octet, qu'un mauvais mot
 * de passe (aucun oracle « fenêtre fermée »). Tout autre compte est strictement
 * inchangé. La fenêtre est toujours confrontée à l'horloge RÉELLE
 * (`new DateTimeImmutable('now')`), jamais à `simulated_today` : un club démo ne doit
 * pas pouvoir rouvrir sa propre porte.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void {}

    // $token added by symfony/security-core 7.4.14 (UserCheckerInterface change).
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->getEmailVerifiedAt() instanceof DateTimeImmutable) {
            throw new CustomUserMessageAuthenticationException('Invalid credentials.');
        }

        // Compte démo hors fenêtre → refus indiscernable d'un mauvais mot de passe.
        if ($user->isDemo() && !$user->isDemoWindowOpen(new DateTimeImmutable('now'))) {
            throw new CustomUserMessageAuthenticationException('Invalid credentials.');
        }
    }
}
