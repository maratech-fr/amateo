<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Repository\ClubUserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cycle de vie des adhésions d'un club — un gestionnaire ACTIF pilote les
 * membres de SON club uniquement (approbation, changement de rôle, désactivation,
 * réactivation). Une adhésion en attente (`isActive=false`, `deactivatedAt=null`)
 * est déjà privée de toute donnée tenant par TenantFilterListener ; ce contrôleur
 * gouverne les transitions.
 *
 * P1-1 (PR B) — deux rôles assignables (ClubRole : Gestionnaire/Membre), la
 * désactivation réversible, et l'invariant « au moins un gestionnaire actif »
 * tenu SOUS VERROU pour qu'aucune course ne laisse un club sans pilote.
 */
final class MembershipController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    private const string LAST_MANAGER_MESSAGE = 'Un club doit garder au moins un gestionnaire actif';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/memberships', name: 'api_memberships_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $adminMembership = $this->requireActiveAdmin();
        if ($adminMembership instanceof JsonResponse) {
            return $adminMembership;
        }

        $active = $this->clubUserRepository->findBy([
            'clubId' => $adminMembership->getClubId(),
            'isActive' => true,
        ]);

        $response = ['members' => $this->serializeMembers($active, $adminMembership)];

        // P1-1 (PR C) : l'écran de gestion des membres demande AUSSI les désactivés
        // (réactivables) — opt-in par `?includeDeactivated=1`. Sans le paramètre, la
        // réponse reste EXACTEMENT « actifs seuls » (aucun appelant existant impacté).
        if ($request->query->getBoolean('includeDeactivated')) {
            $deactivated = $this->clubUserRepository->findDeactivated($adminMembership->getClubId());
            $response['deactivated'] = $this->serializeMembers($deactivated, $adminMembership);
        }

        return $this->json($response);
    }

    #[Route('/api/memberships/pending', name: 'api_memberships_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        $adminMembership = $this->requireActiveAdmin();
        if ($adminMembership instanceof JsonResponse) {
            return $adminMembership;
        }

        // `deactivatedAt IS NULL` : un membre DÉSACTIVÉ (sorti) ne re-rentre pas
        // dans la file d'approbation — seules les adhésions jamais entrées y sont.
        $pending = $this->clubUserRepository->findBy([
            'clubId' => $adminMembership->getClubId(),
            'isActive' => false,
            'deactivatedAt' => null,
        ]);

        $items = [];
        foreach ($pending as $membership) {
            $user = $this->entityManager->getRepository(User::class)->find($membership->getUserId());
            if (null === $user) {
                continue;
            }
            $items[] = [
                'id' => $membership->getId(),
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
            ];
        }

        return $this->json(['members' => $items]);
    }

    #[Route('/api/memberships/{id}/approve', name: 'api_memberships_approve', methods: ['POST'])]
    public function approve(string $id): JsonResponse
    {
        $target = $this->resolveTargetForAdmin($id);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        // Revue sécu PR B : approve est LE passage pending → actif, et rien d'autre.
        // Sur une cible active il promouvait silencieusement (défaut admin) ; sur une
        // désactivée il la réactivait en laissant deactivatedAt posé (état hybride).
        $pendingOnly = $this->assertGenuinelyPending($target, 'Seule une adhésion en attente peut être approuvée.');
        if ($pendingOnly instanceof JsonResponse) {
            return $pendingOnly;
        }
        // P4-77 (ceinture) — un compte effacé (RGPD) ne s'approuve pas : approuver son
        // adhésion la ferait revivre une identité anonymisée. Défense en profondeur (l'effacement
        // désactive déjà les adhésions, `AccountErasureService`), mais atteignable si une pending
        // porte un compte anonymisé.
        if ($this->targetUserIsAnonymized($target)) {
            return $this->json(['error' => 'Ce compte a été supprimé (RGPD) — il ne peut pas être approuvé.'], 409);
        }

        // PR C : le rôle est désormais REQUIS — le front l'envoie toujours (choix
        // actif du gestionnaire, défaut Membre à l'écran). Corps absent ou sans
        // clé `role` → 422, aucune adhésion activée par défaut silencieux.
        $role = $this->readRole($this->requestStack->getCurrentRequest(), null);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        $target->setRole($role->value);
        $target->setIsActive(true);
        $this->entityManager->flush();

        return $this->json(['id' => $target->getId(), 'isActive' => true, 'role' => $target->getRole()]);
    }

    #[Route('/api/memberships/{id}/reject', name: 'api_memberships_reject', methods: ['POST'])]
    public function reject(string $id): JsonResponse
    {
        $target = $this->resolveTargetForAdmin($id);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        // Revue sécu PR B : reject supprimait une adhésion de N'IMPORTE quel état —
        // le dernier gestionnaire pouvait s'auto-supprimer en une requête (invariant
        // contourné, suppression irréversible sans trace). Pending uniquement : un
        // actif se désactive (réversible), il ne se rejette pas.
        $pendingOnly = $this->assertGenuinelyPending($target, 'Seule une adhésion en attente peut être refusée.');
        if ($pendingOnly instanceof JsonResponse) {
            return $pendingOnly;
        }

        $this->entityManager->remove($target);
        $this->entityManager->flush();

        return $this->json(null, 204);
    }

    #[Route('/api/memberships/{id}/role', name: 'api_memberships_role', methods: ['POST'])]
    public function changeRole(string $id): JsonResponse
    {
        $adminMembership = $this->requireActiveAdmin();
        if ($adminMembership instanceof JsonResponse) {
            return $adminMembership;
        }

        $role = $this->readRole($this->requestStack->getCurrentRequest(), null);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        // Rétrograder en Membre fait SORTIR la cible du management : le verrou du
        // dernier gestionnaire s'applique. Promouvoir en Gestionnaire ne menace rien.
        return $this->mutateUnderManagementLock(
            $adminMembership,
            $id,
            removesManagement: ClubRole::MANAGER !== $role,
            requireActive: false,
            mutate: static function (ClubUser $target) use ($role): void {
                $target->setRole($role->value);
            },
        );
    }

    #[Route('/api/memberships/{id}/deactivate', name: 'api_memberships_deactivate', methods: ['POST'])]
    public function deactivate(string $id): JsonResponse
    {
        $adminMembership = $this->requireActiveAdmin();
        if ($adminMembership instanceof JsonResponse) {
            return $adminMembership;
        }

        // Désactiver retire toujours la cible du management actif → verrou.
        // `requireActive` : on ne désactive QUE l'actif — désactiver une pending
        // en ferait un « désactivé » réactivable, chemin de contournement de
        // l'approbation (approve reste le seul passage pending → actif).
        return $this->mutateUnderManagementLock(
            $adminMembership,
            $id,
            removesManagement: true,
            requireActive: true,
            mutate: static function (ClubUser $target): void {
                $target->setIsActive(false);
                $target->setDeactivatedAt(new DateTimeImmutable);
            },
        );
    }

    #[Route('/api/memberships/{id}/reactivate', name: 'api_memberships_reactivate', methods: ['POST'])]
    public function reactivate(string $id): JsonResponse
    {
        $adminMembership = $this->requireActiveAdmin();
        if ($adminMembership instanceof JsonResponse) {
            return $adminMembership;
        }

        $target = $this->clubUserRepository->find($id);
        if (null === $target || $target->getClubId() !== $adminMembership->getClubId()) {
            return $this->json(['error' => 'Membre introuvable — rechargez la liste.'], 404);
        }
        // Réservé aux lignes DÉJÀ désactivées : une pending (`deactivatedAt=null`)
        // ne se « réactive » pas — l'approbation est son seul chemin d'activation.
        if (null === $target->getDeactivatedAt()) {
            return $this->json(['error' => 'Ce membre n\'est pas désactivé.'], 409);
        }
        // P4-77 — un compte effacé (RGPD) a vu son identité écrasée : le réactiver ressusciterait
        // une adhésion sans compte utilisable. On refuse AVANT toute écriture.
        if ($this->targetUserIsAnonymized($target)) {
            return $this->json(['error' => 'Ce compte a été supprimé (RGPD) — il ne peut pas être réactivé.'], 409);
        }

        $target->setIsActive(true);
        $target->setDeactivatedAt(null);
        $this->entityManager->flush();

        return $this->json(['id' => $target->getId(), 'isActive' => true, 'role' => $target->getRole()]);
    }

    /**
     * Applique une mutation à une adhésion cible SOUS le verrou du dernier
     * gestionnaire. On fige d'abord les adhésions management actives du club
     * (`SELECT … FOR UPDATE`) : si la mutation sort la cible du management et
     * qu'elle en est le DERNIER membre actif, on refuse (409) — jamais zéro
     * gestionnaire, même sous course concurrente.
     *
     * @param callable(ClubUser): void $mutate
     */
    private function mutateUnderManagementLock(ClubUser $adminMembership, string $targetId, bool $removesManagement, bool $requireActive, callable $mutate): JsonResponse
    {
        $clubId = $adminMembership->getClubId();

        /** @var JsonResponse $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($clubId, $targetId, $removesManagement, $requireActive, $mutate, $adminMembership): JsonResponse {
            $lockedManagerIds = $this->clubUserRepository->lockActiveManagementIds($clubId);

            $target = $this->clubUserRepository->find($targetId);
            if (null === $target || $target->getClubId() !== $clubId) {
                return $this->json(['error' => 'Membre introuvable — rechargez la liste.'], 404);
            }

            if ($requireActive && !$target->getIsActive()) {
                return $this->json(['error' => 'Ce membre n\'est pas actif.'], 409);
            }

            if ($removesManagement
                && \in_array($target->getId(), $lockedManagerIds, true)
                && 1 === \count($lockedManagerIds)
            ) {
                return $this->json(['error' => self::LAST_MANAGER_MESSAGE], 409);
            }

            $mutate($target);
            $this->entityManager->flush();

            return $this->json([
                'id' => $target->getId(),
                'role' => $target->getRole(),
                'isActive' => $target->getIsActive(),
                'isSelf' => $target->getId() === $adminMembership->getId(),
            ]);
        });

        return $result;
    }

    /**
     * Lit et valide le rôle du corps JSON contre l'enum assignable.
     *
     * - clé `role` absente : $default s'il est fourni (approbation), sinon 422
     *   (changement de rôle — le rôle y est requis) ;
     * - valeur hors enum (« coach », « owner »…) : 422, jamais persistée.
     */
    private function readRole(?Request $request, ?ClubRole $default): ClubRole|JsonResponse
    {
        $raw = (string) ($request?->getContent() ?? '');
        $data = '' === trim($raw) ? [] : json_decode($raw, true);
        if (!\is_array($data) || !\array_key_exists('role', $data)) {
            if ($default instanceof ClubRole) {
                return $default;
            }

            return $this->json(['error' => 'Le rôle est requis.'], 422);
        }

        $role = \is_string($data['role']) ? ClubRole::tryFrom($data['role']) : null;
        if (!$role instanceof ClubRole) {
            return $this->json(['error' => 'Rôle invalide.'], 422);
        }

        return $role;
    }

    /** @return ClubUser|JsonResponse The acting user's active admin membership, or an error response. */
    private function requireActiveAdmin(): ClubUser|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        // BCK-10 (partiel) — l'adhésion suit le club de la REQUÊTE, résolu par
        // le trait partagé, au lieu d'être un second tirage indépendant
        // (`findOneBy(userId, isActive)`). Ce que ça corrige : deux endroits ne
        // peuvent plus répondre différemment à « quel club ? » dans une même
        // requête. Ce que ça NE corrige PAS : pour un gestionnaire multi-club
        // SANS `X-Club-Id` (le front n'en envoie pas), c'est
        // `TenantFilterListener::resolveClubId` qui choisit arbitrairement —
        // le non-déterminisme vit là, et sa résolution est un choix produit
        // (quel club est « courant » ?) rattaché à P1-1. Voir P4-8 (rouverte).
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        $membership = $this->clubUserRepository->findActiveMembership($user->getId(), $clubId);
        // isManagementRole (owner|admin), not a hardcoded 'admin' — an owner
        // must be able to approve members too (review note, PR SEC-07).
        if (!$membership instanceof ClubUser || !$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return $membership;
    }

    /**
     * P4-77 — le compte utilisateur de cette adhésion a-t-il été effacé (RGPD) ? Un compte
     * anonymisé (`User::anonymizedAt`) a vu son identité écrasée et ne peut plus s'authentifier :
     * ni approuver ni réactiver son adhésion. Un compte introuvable n'est pas anonymisé (false).
     */
    private function targetUserIsAnonymized(ClubUser $target): bool
    {
        $user = $this->entityManager->getRepository(User::class)->find($target->getUserId());

        return $user instanceof User && $user->getAnonymizedAt() instanceof DateTimeImmutable;
    }

    /**
     * Approve/reject n'opèrent QUE sur le vrai pending (ni actif, ni désactivé) —
     * 409 sinon, APRÈS le check de club (aucun oracle cross-tenant).
     */
    private function assertGenuinelyPending(ClubUser $target, string $message): ?JsonResponse
    {
        if ($target->getIsActive() || $target->getDeactivatedAt() instanceof DateTimeImmutable) {
            return $this->json(['error' => $message], 409);
        }

        return null;
    }

    /**
     * Sérialise une liste d'adhésions en jointant leur `User` (identité). Source
     * unique pour les membres actifs ET désactivés — même forme, `isSelf` inclus
     * (inoffensif pour un désactivé, jamais soi-même en pratique).
     *
     * @param list<ClubUser> $memberships
     *
     * @return list<array<string, mixed>>
     */
    private function serializeMembers(array $memberships, ClubUser $adminMembership): array
    {
        $items = [];
        foreach ($memberships as $membership) {
            $user = $this->entityManager->getRepository(User::class)->find($membership->getUserId());
            if (null === $user) {
                continue;
            }
            $items[] = [
                'id' => $membership->getId(),
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'role' => $membership->getRole(),
                'isSelf' => $membership->getId() === $adminMembership->getId(),
            ];
        }

        return $items;
    }

    /** @return ClubUser|JsonResponse The target membership within the admin's club, or an error response. */
    private function resolveTargetForAdmin(string $id): ClubUser|JsonResponse
    {
        $adminMembership = $this->requireActiveAdmin();
        if ($adminMembership instanceof JsonResponse) {
            return $adminMembership;
        }

        $target = $this->clubUserRepository->find($id);
        // Never leak cross-tenant: the target must belong to the admin's own club.
        if (null === $target || $target->getClubId() !== $adminMembership->getClubId()) {
            return $this->json(['error' => 'Membre introuvable — rechargez la liste.'], 404);
        }

        return $target;
    }
}
