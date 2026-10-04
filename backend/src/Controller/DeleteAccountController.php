<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Service\AccountErasureService;
use App\Service\AuditTrail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RGPD — droit à l'effacement, self-service (DELETE /api/me).
 *
 * Self-only : la cible est TOUJOURS l'utilisateur du JWT — aucun id en entrée,
 * donc aucun IDOR possible. Confirmation = RÉ-AUTHENTIFICATION : le mot de
 * passe courant est exigé (patron changePassword) — un JWT volé ne suffit pas
 * à détruire le compte (revue sécurité PR-1 ; l'email, lui, se lit via
 * GET /api/me avec le même JWT). L'anonymisation est immédiate et
 * irréversible ; la purge du club orphelin part avec un délai de grâce de 30 j
 * (AccountErasureService).
 */
#[AsController]
final class DeleteAccountController extends AbstractController
{
    public function __construct(
        private readonly AccountErasureService $accountErasureService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditTrail $auditTrail,
    ) {}

    #[Route('/api/me', name: 'api_me_delete', methods: ['DELETE'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        // SEC-28 — un compte de démonstration ne se supprime pas (il est pérenne et
        // partagé par tous les rendez-vous). Refus AVANT toute lecture du corps.
        if ($user->isDemo()) {
            return $this->json(['error' => 'Ce compte de démonstration ne peut pas être modifié.'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $password = \is_array($data) && \is_string($data['password'] ?? null) ? $data['password'] : '';
        if ('' === $password || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Mot de passe incorrect.'], 400);
        }

        $scheduledClubs = $this->accountErasureService->erase($user);
        // Après l'anonymisation : l'id suffit, l'audit ne porte jamais de PII.
        $this->auditTrail->record(AuditAction::ACCOUNT_ERASED, $user->getId(), null, 'User', $user->getId(), [
            'reason' => 'self_service',
            'clubPurgesScheduled' => \count($scheduledClubs),
        ]);

        return $this->json([
            'message' => 'Compte anonymisé. Vos données personnelles ont été effacées.',
            'clubPurgeScheduled' => [] !== $scheduledClubs,
            // Le front affiche la conséquence : sans autre gestionnaire, les
            // données du club seront supprimées à l'issue du délai de grâce.
            'gracePeriodDays' => 30,
        ]);
    }
}
