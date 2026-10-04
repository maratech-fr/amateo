<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClubCreationRequest;
use App\Entity\User;
use App\Repository\ClubCreationRequestRepository;
use App\Repository\ClubUserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Throwable;

/**
 * P4-301 — maison unique de la règle « compte sans club ».
 *
 * Un compte ORPHELIN (plus aucune adhésion active, pending ni demande de création
 * de club vivante) est prévenu par mail puis supprimé 30 jours plus tard. Ce
 * service tient le PRÉDICAT unique {@see isOrphan}, les DÉCLENCHEURS immédiats
 * best-effort ({@see notifyAccessRemoved}, {@see notifyClubDeleted}) et l'ANNULATION
 * du préavis quand le compte regagne un accès ({@see cancelFor}).
 *
 * Un déclencheur ne fait JAMAIS échouer le geste qui l'a appelé : l'envoi est
 * best-effort (après le commit du geste), le stamp n'est posé que si l'envoi réussit,
 * et toute exception est tracée puis avalée.
 *
 * Une demande de création de club REFUSÉE ou EXPIRÉE ne protège PAS de la règle
 * (décision fondateur Q1) : seule une demande encore `pending` compte comme accès.
 */
final class OrphanAccountNotifier
{
    /** Délai de grâce entre le préavis et la suppression — identique à l'échéance annoncée dans le mail. */
    public const GRACE_PERIOD = '+30 days';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantContext,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly ClubCreationRequestRepository $clubCreationRequests,
        private readonly OrphanAccountMailBuilder $mailBuilder,
        private readonly MailerInterface $mailer,
        // BCK-34 — horloge RÉELLE : le préavis + délai de grâce (+30 j) avant
        // suppression d'un compte orphelin est de MÊME NATURE que le délai RGPD
        // (rétention/suppression). Les déclencheurs immédiats tournent dans le
        // contexte tenant de l'appelant (un gestionnaire de club démo désactive un
        // membre) — l'échéance annoncée dans le mail doit rester sur le calendrier réel.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Le compte est-il ORPHELIN ? Prédicat unique, à l'octet des conditions du cron
     * et des déclencheurs : compte non anonymisé ET non démo ET zéro adhésion active
     * ET zéro adhésion pending (`is_active=false, deactivated_at=null`) ET zéro demande
     * de création de club encore `pending`.
     */
    public function isOrphan(User $user): bool
    {
        if ($user->getAnonymizedAt() instanceof DateTimeImmutable) {
            return false;
        }
        if ($this->isDemoAccount($user)) {
            return false;
        }
        // Adhésions actives (lecture cross-tenant par design, comme l'effacement de compte).
        if ([] !== $this->clubUserRepository->findActiveClubIds($user->getId())) {
            return false;
        }
        // Adhésions en attente d'approbation (jamais entrées) : l'utilisateur garde un accès
        // à venir, il n'est pas orphelin. Même idiome cross-tenant que findActiveClubIds.
        if ($this->pendingMembershipCount($user->getId()) > 0) {
            return false;
        }

        // Demande de création de club encore VIVANTE (pending). Refusée/expirée → ne protège pas.
        return !$this->clubCreationRequests->findPendingByUser($user->getId()) instanceof ClubCreationRequest;
    }

    /**
     * Annule le préavis d'un compte qui regagne un accès (approbation, réactivation,
     * reprise de club). Remet le stamp à null sur l'entité gérée — l'APPELANT flushe
     * (il est toujours dans sa propre transaction/flush). Sans cette remise à null,
     * un stamp d'un cycle annulé ferait supprimer le compte sans nouveau mail au cycle
     * suivant.
     */
    public function cancelFor(string $userId): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if ($user instanceof User && $user->getOrphanNoticeSentAt() instanceof DateTimeImmutable) {
            $user->setOrphanNoticeSentAt(null);
        }
    }

    /**
     * Déclencheur immédiat « accès retiré » (désactivation, refus d'adhésion ou de
     * création de club). Best-effort : si la cible est devenue orpheline, envoie le
     * préavis puis pose le stamp ; jamais fatal pour le geste appelant.
     */
    public function notifyAccessRemoved(string $userId): void
    {
        $this->notify($userId, clubDeleted: false);
    }

    /**
     * Déclencheur immédiat « espace du club supprimé » (purge RGPD d'un workspace
     * orphelin). Best-effort, même contrat que {@see notifyAccessRemoved}.
     */
    public function notifyClubDeleted(string $userId): void
    {
        $this->notify($userId, clubDeleted: true);
    }

    private function notify(string $userId, bool $clubDeleted): void
    {
        try {
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if (!$user instanceof User || !$this->isOrphan($user)) {
                return;
            }
            // Un préavis déjà en cours : ne pas re-stamper (l'échéance promise tient).
            if ($user->getOrphanNoticeSentAt() instanceof DateTimeImmutable) {
                return;
            }
            $now = DateTimeImmutable::createFromInterface($this->clock->now());
            $deadline = $now->modify(self::GRACE_PERIOD);
            $email = $clubDeleted
                ? $this->mailBuilder->buildClubDeleted($user->getEmail(), $user->getFirstName(), $deadline)
                : $this->mailBuilder->buildAccessRemoved($user->getEmail(), $user->getFirstName(), $deadline);
            $this->mailer->send($email);
            // Stamp posé SEULEMENT après un envoi réussi (jamais de suppression sans mail).
            $user->setOrphanNoticeSentAt($now);
            $this->entityManager->flush();
        } catch (Throwable $e) {
            // Best-effort : un échec d'envoi ne pose pas le stamp et ne fait jamais
            // échouer le geste appelant. Le cron rattrapera le stock au prochain tick.
            $this->logger->warning('Orphan-account notice failed', ['userId' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Comptes de DÉMONSTRATION, exclus de la règle. SEC-28 : la reconnaissance se fait
     * désormais sur le drapeau d'entité `app_user.is_demo`, plus par comparaison
     * d'adresse (un seul point de vérité, cf. UserChecker).
     */
    private function isDemoAccount(User $user): bool
    {
        return $user->isDemo();
    }

    private function pendingMembershipCount(string $userId): int
    {
        return $this->tenantContext->runWithoutTenant(
            fn (): int => (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM club_user WHERE user_id = :uid AND is_active = false AND deactivated_at IS NULL',
                ['uid' => $userId],
            ),
        );
    }
}
