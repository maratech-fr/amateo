<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\ClubCreationRequestRepository;
use App\Repository\ClubUserRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Chantier Membre (PR 3) — maison unique de la demande d'un Membre à devenir gestionnaire.
 *
 *  - Club AVEC gestionnaire : la demande pose `club_user.manager_requested_at` et prévient les
 *    gestionnaires actifs ; ils l'acceptent par le sélecteur de rôle (`MembershipController`) ou la
 *    refusent ({@see refuseByManager}). Sans réponse 14 jours, la relève quotidienne la transmet
 *    UNE fois à l'adresse officielle du club ({@see escalateDue}).
 *  - Club SANS gestionnaire (fermeture en cours) : la demande part à l'adresse officielle (une
 *    demande de club `pending`, variante « reprise »), qui décide via la page publique.
 *
 * Toutes les sorties qui effacent la demande (promotion, refus, annulation, désactivation) closent
 * aussi la demande officielle vivante du même code, dans la même transaction (lien mort, 404) —
 * {@see clearRequest}. Aucun délai dans l'app après un refus des gestionnaires ; 30 jours après un
 * refus de l'adresse officielle. Seaux : utilisateur, puis club pour l'adresse officielle —
 * consommés APRÈS les refus 409.
 */
final class ManagerRoleRequestService
{
    /** Délai après un refus de l'adresse officielle avant une nouvelle demande officielle. */
    public const string OFFICIAL_REFUSAL_DELAY = '+30 days';

    /** Sans réponse des gestionnaires pendant ce délai, la demande part à l'adresse officielle. */
    public const string ESCALATION_DELAY = '-14 days';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly ClubCreationRequestRepository $requests,
        private readonly ClubApprovalService $approvalService,
        private readonly TenantConnectionContext $tenantContext,
        private readonly AuditTrail $auditTrail,
        private readonly MembershipRequestNotifier $notifier,
        private readonly RateLimiterFactory $managerRoleRequestUserLimiter,
        private readonly RateLimiterFactory $managerRoleRequestOfficialLimiter,
        // Horloge RÉELLE : les délais (14 jours, 30 jours) sont des durées de sécurité, jamais
        // l'« aujourd'hui » simulé d'un club démo (les clubs démo ne demandent d'ailleurs rien).
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Un Membre ACTIF demande à devenir gestionnaire de son club courant.
     *
     * @return array{0: int, 1: array<string, mixed>} [code HTTP, corps]
     */
    public function request(ClubUser $membership, User $user): array
    {
        $club = $this->entityManager->getRepository(Club::class)->find($membership->getClubId());
        if (!$club instanceof Club) {
            return [403, ['error' => 'Accès refusé.']];
        }
        $after = null;
        $result = $this->entityManager->wrapInTransaction(function () use ($membership, $user, $club, &$after): array {
            $this->lock($club->getId(), $user->getId());
            $ara = $club->getFfbbClubCode();
            if ($club->isDemo() || null === $ara || '' === $ara) {
                return [409, ['error' => 'Cette demande n\'est pas possible pour ce club.']];
            }
            $this->entityManager->refresh($membership);
            $now = $this->now();

            if ($this->clubUserRepository->hasActiveManager($club->getId())) {
                $renewal = false;
                if ($membership->getManagerRequestedAt() instanceof DateTimeImmutable) {
                    // Une demande transmise à l'adresse officielle et restée sans réponse (expirée)
                    // se renvoie : nouveau cycle. Toute autre demande en cours bloque.
                    $renewal = $membership->getManagerRequestEscalatedAt() instanceof DateTimeImmutable
                        && !$this->requests->findPendingByUserAndAra($user->getId(), $ara) instanceof ClubCreationRequest;
                    if (!$renewal) {
                        return [409, ['error' => 'Une demande est déjà en cours.']];
                    }
                }
                if (!$this->managerRoleRequestUserLimiter->create($user->getId())->consume(1)->isAccepted()) {
                    return [429, ['error' => 'Vous avez atteint votre limite de demandes pour aujourd\'hui.']];
                }
                if ($renewal) {
                    $this->cancelLiveOfficialRequests($user->getId(), $ara, $now);
                }
                $membership->setManagerRequestedAt($now);
                $membership->setManagerRequestEscalatedAt(null);
                $this->entityManager->flush();
                $this->auditTrail->record(AuditAction::MEMBERSHIP_ROLE_REQUESTED, $user->getId(), $club->getId(), 'ClubUser', $membership->getId(), ['source' => 'manager']);
                $after = fn () => $this->notifier->notifyRoleRequest($club->getId(), $user->getId());

                return [201, ['status' => 'pending', 'since' => $now->format(DateTimeInterface::ATOM)]];
            }

            // Club SANS gestionnaire : la demande va à l'adresse officielle. Un vieux stamp (posé
            // du temps où le club était géré) ne bloque jamais.
            if ($this->requests->findPendingByUserAndAra($user->getId(), $ara) instanceof ClubCreationRequest) {
                return [409, ['error' => 'Une demande est déjà en cours.']];
            }
            $refused = $this->latestRecentOfficialRefusal($user->getId(), $ara, $now);
            if ($refused instanceof DateTimeImmutable) {
                return [409, ['error' => \sprintf(
                    'Le club a refusé votre demande le %s : vous pourrez la renouveler à partir du %s.',
                    $refused->format('d/m/Y'),
                    $refused->modify(self::OFFICIAL_REFUSAL_DELAY)->format('d/m/Y'),
                )]];
            }
            if (!$this->managerRoleRequestUserLimiter->create($user->getId())->consume(1)->isAccepted()) {
                return [429, ['error' => 'Vous avez atteint votre limite de demandes pour aujourd\'hui.']];
            }
            if (!$this->managerRoleRequestOfficialLimiter->create($club->getId())->consume(1)->isAccepted()) {
                return [429, ['error' => 'Le club a atteint sa limite de demandes pour aujourd\'hui.']];
            }
            $membership->setManagerRequestedAt(null);
            $membership->setManagerRequestEscalatedAt(null);
            // « Vous pouvez la renvoyer » : l'ancienne (expirée) est close, jamais deux demandes vivantes.
            $this->cancelLiveOfficialRequests($user->getId(), $ara, $now);
            $request = $this->approvalService->openRequest($user, $ara, $club->getName());
            $this->entityManager->flush();
            $this->auditTrail->record(AuditAction::MEMBERSHIP_ROLE_REQUESTED, $user->getId(), $club->getId(), 'ClubCreationRequest', $request->getId(), ['source' => 'official_address']);
            $after = fn () => $this->approvalService->sendApprovalEmail($request, $user);

            return [201, [
                'status' => 'club_pending',
                'since' => $request->getCreatedAt()->format(DateTimeInterface::ATOM),
                'clubEmailKnown' => null !== $request->getClubEmail(),
            ]];
        });

        // Après commit : un lien de mail ne référence jamais une demande non persistée.
        if (null !== $after) {
            $after();
        }

        return $result;
    }

    /**
     * Le Membre annule sa demande : stamps effacés ET toutes ses demandes officielles vivantes sur
     * ce code closes (`cancelled`, liens morts, aucun mail).
     *
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function cancel(ClubUser $membership, User $user): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($membership, $user): array {
            $this->lock($membership->getClubId(), $user->getId());
            $this->entityManager->refresh($membership);
            if (!$this->clearRequest($membership)) {
                return [404, ['error' => 'Aucune demande en cours.']];
            }
            $this->entityManager->flush();
            $this->auditTrail->record(AuditAction::MEMBERSHIP_ROLE_REQUEST_CANCELLED, $user->getId(), $membership->getClubId(), 'ClubUser', $membership->getId());

            return [204, null];
        });
    }

    /**
     * Un gestionnaire ACTIF du club refuse la demande d'un Membre : stamps effacés, demande
     * officielle vivante close, refus tracé, mail au demandeur. Aucun délai avant une nouvelle demande.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function refuseByManager(ClubUser $admin, string $targetId): array
    {
        $refusedUserId = null;
        $result = $this->entityManager->wrapInTransaction(function () use ($admin, $targetId, &$refusedUserId): array {
            $target = $this->clubUserRepository->find($targetId);
            if (!$target instanceof ClubUser || $target->getClubId() !== $admin->getClubId()) {
                return [404, ['error' => 'Membre introuvable — rechargez la liste.']];
            }
            $this->lock($target->getClubId(), $target->getUserId());
            $this->entityManager->refresh($target);
            if (!$target->getManagerRequestedAt() instanceof DateTimeImmutable) {
                return [409, ['error' => 'Aucune demande en cours.']];
            }
            $this->clearRequest($target);
            $this->entityManager->flush();
            $this->auditTrail->record(AuditAction::MEMBERSHIP_ROLE_REFUSED, $admin->getUserId(), $target->getClubId(), 'ClubUser', $target->getId(), ['source' => 'manager']);
            $refusedUserId = $target->getUserId();

            return [200, [
                'id' => $target->getId(),
                'role' => $target->getRole(),
                'isActive' => $target->getIsActive(),
                'isSelf' => $target->getId() === $admin->getId(),
            ]];
        });
        if (\is_string($refusedUserId)) {
            $this->notifier->notifyRoleRefused($admin->getClubId(), $refusedUserId);
        }

        return $result;
    }

    /**
     * Efface la demande de rôle d'une adhésion (stamps à null, NON flushé) et clôt, dans la même
     * transaction, toute demande officielle vivante de son titulaire sur le code du club.
     * Appelé par la promotion, le refus, l'annulation, la désactivation et l'invitation.
     *
     * @return bool vrai si une demande existait (stamp ou demande officielle vivante)
     */
    public function clearRequest(ClubUser $membership): bool
    {
        $had = $membership->getManagerRequestedAt() instanceof DateTimeImmutable
            || $membership->getManagerRequestEscalatedAt() instanceof DateTimeImmutable;
        $membership->setManagerRequestedAt(null);
        $membership->setManagerRequestEscalatedAt(null);
        $club = $this->entityManager->getRepository(Club::class)->find($membership->getClubId());
        $ara = $club instanceof Club ? $club->getFfbbClubCode() : null;
        if (null !== $ara && '' !== $ara) {
            return $this->cancelLiveOfficialRequests($membership->getUserId(), $ara, $this->now()) || $had;
        }

        return $had;
    }

    /**
     * L'état de la demande servi par `/api/me` (adhésion ACTIVE de Membre seulement) — jamais
     * « en attente des gestionnaires » sur un club qui n'en a plus.
     *
     * @return array<string, mixed>|null
     */
    public function meState(ClubUser $membership, Club $club): ?array
    {
        if (!$membership->getIsActive() || $this->clubUserRepository->isManagementRole($membership->getRole())) {
            return null;
        }
        $ara = $club->getFfbbClubCode();
        if (null === $ara || '' === $ara) {
            return null;
        }
        $requestedAt = $membership->getManagerRequestedAt();
        $escalatedAt = $membership->getManagerRequestEscalatedAt();
        $managed = $this->clubUserRepository->hasActiveManager($club->getId());

        if ($managed && $requestedAt instanceof DateTimeImmutable) {
            $official = $escalatedAt instanceof DateTimeImmutable ? $this->requests->findLatestShownByUserAndAra($membership->getUserId(), $ara) : null;
            if ($official instanceof ClubCreationRequest && ClubCreationRequest::STATUS_PENDING === $official->getStatus()) {
                return ['status' => 'club_pending', 'since' => $this->iso($escalatedAt), 'escalated' => true, 'clubEmailKnown' => null !== $official->getClubEmail()];
            }
            if ($official instanceof ClubCreationRequest && ClubCreationRequest::STATUS_EXPIRED === $official->getStatus()) {
                return ['status' => 'expired', 'since' => $this->iso($requestedAt), 'escalated' => true];
            }

            return ['status' => 'pending', 'since' => $this->iso($requestedAt), 'escalated' => false];
        }

        $latest = $this->requests->findLatestShownByUserAndAra($membership->getUserId(), $ara);
        if (!$latest instanceof ClubCreationRequest) {
            return null;
        }
        $escalated = $escalatedAt instanceof DateTimeImmutable;

        return match ($latest->getStatus()) {
            ClubCreationRequest::STATUS_PENDING => ['status' => 'club_pending', 'since' => $this->iso($latest->getCreatedAt()), 'escalated' => $escalated, 'clubEmailKnown' => null !== $latest->getClubEmail()],
            ClubCreationRequest::STATUS_EXPIRED => ['status' => 'expired', 'since' => $this->iso($latest->getCreatedAt()), 'escalated' => $escalated],
            default => $this->refusedState($latest),
        };
    }

    /**
     * Relève quotidienne : chaque demande sans réponse des gestionnaires depuis 14 jours part UNE
     * fois à l'adresse officielle (club réel géré, aucun refus officiel de moins de 30 jours, seau
     * club disponible — sinon rien n'est écrit, nouvel essai le lendemain).
     *
     * @return list<string> les demandes transmises (« club / utilisateur »), pour la sortie de la commande
     */
    public function escalateDue(bool $dryRun): array
    {
        $now = $this->now();
        /** @var list<array{club_id: string, user_id: string}> $rows */
        $rows = $this->tenantContext->runWithoutTenant(
            fn (): array => $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT cu.club_id, cu.user_id FROM club_user cu JOIN club c ON c.id = cu.club_id '
                . 'WHERE cu.is_active = true AND cu.role = :role AND cu.manager_requested_at IS NOT NULL '
                . 'AND cu.manager_requested_at <= :threshold AND cu.manager_request_escalated_at IS NULL '
                . 'AND c.is_demo = false AND c.ffbb_club_code IS NOT NULL ORDER BY cu.manager_requested_at ASC',
                ['role' => 'member', 'threshold' => $now->modify(self::ESCALATION_DELAY)->format('Y-m-d H:i:sP')],
            ),
        );

        $escalated = [];
        foreach ($rows as $row) {
            $club = $this->entityManager->getRepository(Club::class)->find($row['club_id']);
            $user = $this->entityManager->getRepository(User::class)->find($row['user_id']);
            $ara = $club instanceof Club ? $club->getFfbbClubCode() : null;
            if (!$club instanceof Club || !$user instanceof User || null === $ara || $user->getAnonymizedAt() instanceof DateTimeImmutable) {
                continue;
            }
            if (!$this->clubUserRepository->hasActiveManager($club->getId())
                || $this->requests->findPendingByUserAndAra($user->getId(), $ara) instanceof ClubCreationRequest
                || $this->latestRecentOfficialRefusal($user->getId(), $ara, $now) instanceof DateTimeImmutable) {
                continue;
            }
            $escalated[] = $club->getId() . ' / ' . $user->getId();
            if ($dryRun) {
                continue;
            }
            if (!$this->managerRoleRequestOfficialLimiter->create($club->getId())->consume(1)->isAccepted()) {
                array_pop($escalated);

                continue; // seau club épuisé : rien n'est écrit, nouvel essai demain
            }
            $this->escalate($club, $user, $ara, $now);
        }

        return $escalated;
    }

    private function escalate(Club $club, User $user, string $ara, DateTimeImmutable $now): void
    {
        $request = null;
        $requestedAt = null;
        // Le GUC d'avant est RELU en session et restauré (patron `runWithoutTenant`) : la relève
        // ne laisse jamais la connexion sur le club d'une transmission.
        $previous = (string) $this->entityManager->getConnection()->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')');
        try {
            $this->entityManager->wrapInTransaction(function () use ($club, $user, $ara, $now, &$request, &$requestedAt): void {
                $this->tenantContext->setClubId($club->getId());
                $this->lock($club->getId(), $user->getId());
                $membership = $this->clubUserRepository->findActiveMembership($user->getId(), $club->getId());
                if (!$membership instanceof ClubUser) {
                    return;
                }
                $this->entityManager->refresh($membership);
                $requestedAt = $membership->getManagerRequestedAt();
                if (!$requestedAt instanceof DateTimeImmutable || $membership->getManagerRequestEscalatedAt() instanceof DateTimeImmutable) {
                    return;
                }
                $this->cancelLiveOfficialRequests($user->getId(), $ara, $now);
                $request = $this->approvalService->openRequest($user, $ara, $club->getName());
                $membership->setManagerRequestEscalatedAt($now);
                $this->entityManager->flush();
                $this->auditTrail->record(AuditAction::MEMBERSHIP_ROLE_REQUEST_ESCALATED, null, $club->getId(), 'ClubCreationRequest', $request->getId(), ['source' => 'official_address']);
            });
        } finally {
            if ('' !== $previous) {
                $this->tenantContext->setClubId($previous);
            } else {
                $this->tenantContext->clear();
            }
        }
        if ($request instanceof ClubCreationRequest && $requestedAt instanceof DateTimeImmutable) {
            $this->approvalService->sendApprovalEmail($request, $user);
            $this->notifier->notifyRoleEscalated($club->getId(), $user->getId(), $requestedAt);
        }
    }

    /** @return array<string, mixed>|null */
    private function refusedState(ClubCreationRequest $request): ?array
    {
        $decidedAt = $request->getDecidedAt();
        if (!$decidedAt instanceof DateTimeImmutable) {
            return null;
        }
        $until = $decidedAt->modify(self::OFFICIAL_REFUSAL_DELAY);
        if ($until <= $this->now()) {
            return null;
        }

        return ['status' => 'refused', 'since' => $this->iso($decidedAt), 'escalated' => false, 'until' => $this->iso($until)];
    }

    /**
     * La date du dernier refus officiel de moins de 30 jours sur ce code, ou null. Lue en base (jamais
     * sur une entité gardée en mémoire par une relève précédente : la date fait foi).
     */
    private function latestRecentOfficialRefusal(string $userId, string $ara, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $value = $this->entityManager->getConnection()->fetchOne(
            'SELECT decided_at FROM club_creation_request WHERE user_id = :uid AND ara = :ara AND status = :status AND decided_at IS NOT NULL ORDER BY decided_at DESC LIMIT 1',
            ['uid' => $userId, 'ara' => $ara, 'status' => ClubCreationRequest::STATUS_REFUSED],
        );
        if (!\is_string($value)) {
            return null;
        }
        $decidedAt = new DateTimeImmutable($value);

        return $decidedAt->modify(self::OFFICIAL_REFUSAL_DELAY) <= $now ? null : $decidedAt;
    }

    /** Clôt (`cancelled`) les demandes officielles vivantes de l'utilisateur sur ce code — NON flushé. */
    private function cancelLiveOfficialRequests(string $userId, string $ara, DateTimeImmutable $now): bool
    {
        $closed = false;
        foreach ($this->requests->findLiveByUserAndAra($userId, $ara) as $request) {
            $request->setStatus(ClubCreationRequest::STATUS_CANCELLED);
            $request->setDecidedAt($now);
            $closed = true;
        }

        return $closed;
    }

    /** Sérialise les demandes d'un même Membre sur un même club (double clic, onglets). */
    private function lock(string $clubId, string $userId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:key))',
            ['key' => 'manager-request:' . $clubId . ':' . $userId],
        );
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function iso(DateTimeImmutable $date): string
    {
        return $date->format(DateTimeInterface::ATOM);
    }
}
