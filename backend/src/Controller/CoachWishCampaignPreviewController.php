<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Coach;
use App\Entity\CoachWishCampaign;
use App\Service\CoachWishFormPresenter;
use App\Service\CoachWishPerimeter;
use App\Service\ManagementAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aperçu COACH PAR COACH d'une campagne de collecte (feature #10, lot D2) : le gestionnaire
 * voit la VRAIE page qu'un coach donné verra — son prénom, ses équipes, les partenaires
 * possibles — en LECTURE SEULE.
 *
 * Authentifié (firewall gestionnaire, SEC-07 `assertManager`), club résolu côté serveur depuis
 * le JWT (jamais du corps), JAMAIS de jeton forgé : on résout directement la campagne et le
 * coach, sans passer par un CoachWishToken. La réponse a la MÊME forme que le GET public pour
 * ce coach, mais vidée de ses données (`wishes=[]`, `mutualizations=[]`, `respondedAt=null`) —
 * c'est un aperçu, pas les réponses du coach.
 *
 * Défense :
 *  - non-gestionnaire → 403 (parité SEC-07, assertManager AVANT toute résolution) ;
 *  - campagne d'un autre club → 404 (RLS scope le find au club courant) BYTE-IDENTIQUE au cas
 *    « coach hors campagne » (un gestionnaire ne distingue pas une campagne absente d'un coach
 *    hors périmètre).
 */
#[AsController]
final class CoachWishCampaignPreviewController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachWishPerimeter $perimeter,
        private readonly CoachWishFormPresenter $formPresenter,
    ) {}

    #[Route('/api/coach_wish_campaigns/{id}/preview', name: 'preview_coach_wish_campaign', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07 — AVANT toute résolution

        $campaign = $this->entityManager->getRepository(CoachWishCampaign::class)->find($id);
        // Le club vient de la LIGNE, jamais du corps : un id d'un autre club est déjà invisible
        // ici (RLS + filtre tenant), la comparaison est le filet app-layer. 404 byte-identique.
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (!$campaign instanceof CoachWishCampaign || (null !== $currentClubId && $campaign->getClubId() !== $currentClubId)) {
            return $this->notFound();
        }

        $coachId = $this->requestStack->getCurrentRequest()?->query->get('coachId');
        if (!\is_string($coachId) || '' === $coachId) {
            return $this->notFound();
        }

        // Le coach DOIT appartenir au périmètre courant de la campagne (ses équipes retenues) —
        // sinon 404, même corps qu'une campagne absente (anti-énumération du périmètre).
        if (!isset($this->perimeter->coachIdSet($campaign)[$coachId])) {
            return $this->notFound();
        }
        $coach = $this->entityManager->getRepository(Coach::class)->find($coachId);
        if (!$coach instanceof Coach) {
            return $this->notFound();
        }

        // Même forme que le GET public, en mode APERÇU : aucune donnée du coach, respondedAt null.
        return $this->json($this->formPresenter->build($campaign, $coach, includeExisting: false, respondedAt: null));
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'not found'], Response::HTTP_NOT_FOUND);
    }
}
