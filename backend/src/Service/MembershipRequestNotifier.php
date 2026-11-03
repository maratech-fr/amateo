<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Mail\MailSafeName;
use App\Repository\ClubUserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;

/**
 * Chantier Membre — maison unique des avis d'une DEMANDE D'ADHÉSION.
 *
 *  - {@see notifyManagers} : les gestionnaires ACTIFS du club (jamais un Membre, jamais un
 *    désactivé) reçoivent un mail par appel — un appel = les adhésions nées dans UNE transaction,
 *    listées dans un seul mail. Plafond quotidien PAR CLUB (`membership_request_notify`) ; au-delà,
 *    UNE synthèse (`membership_request_digest`), puis silence. Aucun destinataire → rien n'est
 *    consommé. Les noms des demandeurs passent par {@see MailSafeName}.
 *  - {@see notifyAccepted} / {@see notifyRefused} : le demandeur apprend l'issue en UN mail ; le
 *    refus porte l'échéance du compte quand il le laisse orphelin (préavis unique).
 *
 * Toujours APRÈS le commit du geste appelant, best-effort : un échec est tracé, jamais fatal.
 * Le GUC tenant n'est jamais touché ici : sous horloge simulée, l'interception en boîte aux lettres
 * dépend du GUC posé par l'appelant ({@see MembershipRequestMailBuilder}).
 */
final class MembershipRequestNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly MembershipRequestMailBuilder $mailBuilder,
        private readonly OrphanAccountNotifier $orphanAccountNotifier,
        private readonly MailerInterface $mailer,
        private readonly RateLimiterFactory $membershipRequestNotifyLimiter,
        private readonly RateLimiterFactory $membershipRequestDigestLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param list<string> $requesterUserIds les demandeurs dont l'adhésion EN ATTENTE vient de naître */
    public function notifyManagers(string $clubId, array $requesterUserIds): void
    {
        try {
            $recipients = $this->clubUserRepository->findManagementEmails($clubId);
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            if ([] === $recipients || !$club instanceof Club) {
                return; // personne à prévenir : aucun budget consommé
            }
            $requesters = [];
            foreach ($requesterUserIds as $userId) {
                $user = $this->entityManager->getRepository(User::class)->find($userId);
                if ($user instanceof User) {
                    $requesters[] = ['name' => MailSafeName::of($user->getFirstName(), $user->getLastName()), 'email' => $user->getEmail()];
                }
            }
            if ([] === $requesters) {
                return;
            }

            if ($this->membershipRequestNotifyLimiter->create($clubId)->consume(1)->isAccepted()) {
                foreach ($recipients as $to) {
                    $this->send($this->mailBuilder->buildNewRequests($to, $club, $requesters), $clubId);
                }

                return;
            }
            if ($this->membershipRequestDigestLimiter->create($clubId)->consume(1)->isAccepted()) {
                foreach ($recipients as $to) {
                    $this->send($this->mailBuilder->buildDigest($to, $club), $clubId);
                }
            }
            // Synthèse déjà partie aujourd'hui : silence jusqu'au lendemain.
        } catch (Throwable $e) {
            $this->logger->warning('Membership request notice failed', ['clubId' => $clubId, 'error' => $e->getMessage()]);
        }
    }

    /** Le demandeur vient d'être accepté : un mail qui dit son rôle. */
    public function notifyAccepted(ClubUser $membership): void
    {
        try {
            $user = $this->entityManager->getRepository(User::class)->find($membership->getUserId());
            $club = $this->entityManager->getRepository(Club::class)->find($membership->getClubId());
            if (!$user instanceof User || !$club instanceof Club) {
                return;
            }
            $role = $this->clubUserRepository->isManagementRole($membership->getRole()) ? ClubRole::MANAGER : ClubRole::MEMBER;
            $this->mailer->send($this->mailBuilder->buildAccepted($user->getEmail(), $club, $user->getFirstName(), $role));
        } catch (Throwable $e) {
            $this->logger->warning('Membership accepted notice failed', ['clubId' => $membership->getClubId(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Le demandeur vient d'être refusé (son adhésion est déjà supprimée) : UN seul mail — avec
     * l'échéance de son compte s'il est devenu orphelin (le préavis est alors posé), sans sinon.
     */
    public function notifyRefused(string $clubId, string $userId): void
    {
        try {
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            if (!$user instanceof User || !$club instanceof Club) {
                return;
            }
            $sent = $this->orphanAccountNotifier->notifyAccessRemovedWith(
                $userId,
                fn (DateTimeImmutable $deadline): Email => $this->mailBuilder->buildRefused($user->getEmail(), $club, $user->getFirstName(), $deadline),
            );
            if (!$sent) {
                $this->mailer->send($this->mailBuilder->buildRefused($user->getEmail(), $club, $user->getFirstName(), null));
            }
        } catch (Throwable $e) {
            $this->logger->warning('Membership refused notice failed', ['clubId' => $clubId, 'error' => $e->getMessage()]);
        }
    }

    private function send(Email $email, string $clubId): void
    {
        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            $this->logger->warning('Membership request notice failed', ['clubId' => $clubId, 'error' => $e->getMessage()]);
        }
    }
}
