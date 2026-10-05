<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Throwable;

/**
 * RGPD (rétention) : trace la dernière ACTIVITÉ authentifiée — l'inactivité
 * (purge à 24 mois, préavis à 23) se mesure sur COALESCE(lastLoginAt,
 * createdAt). L'authenticator JWT déclenche LoginSuccessEvent à CHAQUE requête
 * authentifiée (pas seulement au login interactif) : c'est la bonne sémantique
 * d'activité, mais un flush par requête serait ruineux → l'écriture est
 * THROTTLÉE à une par jour (sauf préavis en cours, toujours annulé aussitôt).
 *
 * UPDATE DBAL ciblé + best-effort (revue PR-3) : un flush() global de l'EM
 * committerait n'importe quel état dirty laissé par d'autres listeners, et une
 * DB momentanément read-only ferait 500 sur du pur GET pour une télémétrie —
 * l'échec de cette écriture ne doit jamais casser la requête.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class LoginSuccessListener
{
    private const REFRESH_AFTER = '-1 day';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        // P4-304 — horloge RÉELLE : `lastLoginAt` sert à mesurer l'inactivité (purge
        // RGPD à 24 mois) — un horodatage de SÉCURITÉ. L'authenticator JWT déclenche
        // cet événement à CHAQUE requête authentifiée, y compris celles d'un membre
        // de club démo dont l'horloge décorée simulerait la date : une date passée
        // rendrait le compte faussement inactif, une date future repousserait sa purge.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $stale = !$user->getLastLoginAt() instanceof DateTimeImmutable || $user->getLastLoginAt() < $now->modify(self::REFRESH_AFTER);
        if (!$stale && !$user->getInactivityWarnedAt() instanceof DateTimeImmutable) {
            return; // trace du jour déjà posée, rien à annuler → zéro écriture.
        }

        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE app_user SET last_login_at = :now, inactivity_warned_at = NULL WHERE id = :id',
                ['now' => $now->format('Y-m-d H:i:s'), 'id' => $user->getId()],
            );
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE club SET last_activity_at = :now WHERE id IN (SELECT club_id FROM club_user WHERE user_id = :user_id AND is_active = TRUE)',
                ['now' => $now->format('Y-m-d H:i:s'), 'user_id' => $user->getId()],
            );
            // Aligne l'entité managée (déjà hydratée par le provider) pour que
            // la suite de la requête voie la même vérité que la DB.
            $user->setLastLoginAt($now);
            $user->setInactivityWarnedAt(null);
        } catch (Throwable) {
            // Best-effort : la trace d'activité ne casse jamais la requête.
        }
    }
}
