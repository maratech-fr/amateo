<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CoachWishCampaign;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Service\CoachWishCampaignPresenter;
use App\Service\CoachWishCampaignTokenSync;
use App\Service\CoachWishPerimeter;
use App\Service\CoachWishSeasonGuard;
use App\Service\ManagementAccessGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

/**
 * Choisir le coach principal qui répond pour une équipe : le marqueur posé sous verrou de l'équipe
 * (deux onglets : le second l'emporte, un seul marqué), puis la règle du destinataire sur toutes les
 * collectes ouvertes de la saison. Le choix vaut donc pour les collectes suivantes.
 *
 * Mêmes gardes que `send-links` : gestionnaire seulement, club lu sur la ligne jamais dans le corps,
 * saison gelée en 409. Une campagne d'un autre club répond comme une campagne inconnue.
 */
#[AsController]
final class CoachWishRecipientController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachWishPerimeter $perimeter,
        private readonly CoachWishCampaignTokenSync $tokenSync,
        private readonly CoachWishCampaignPresenter $presenter,
        private readonly CoachWishSeasonGuard $seasonGuard,
    ) {}

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager();

        // Un identifiant malformé ne doit pas atteindre PostgreSQL : même réponse qu'une collecte inconnue.
        $campaign = Uuid::isValid($id) ? $this->entityManager->getRepository(CoachWishCampaign::class)->find($id) : null;
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (!$campaign instanceof CoachWishCampaign || (null !== $currentClubId && $campaign->getClubId() !== $currentClubId)) {
            return $this->json(['error' => 'not found'], Response::HTTP_NOT_FOUND);
        }
        if ($this->seasonGuard->isReadonly($campaign)) {
            return $this->json(['error' => 'Cette saison est archivée — elle est en lecture seule.'], Response::HTTP_CONFLICT);
        }
        if (!$this->perimeter->isOpen($campaign)) {
            return $this->json(['error' => 'La date limite de cette collecte est passée.'], Response::HTTP_CONFLICT);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $teamId = \is_array($payload) && \is_string($payload['teamId'] ?? null) ? $payload['teamId'] : '';
        $coachId = \is_array($payload) && \is_string($payload['coachId'] ?? null) ? $payload['coachId'] : '';

        $candidates = $this->perimeter->recipientsByTeam($campaign)[$teamId]['candidateCoachIds'] ?? null;
        if (null === $candidates) {
            return $this->json(['error' => 'Cette équipe n\'est pas dans la collecte.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!\in_array($coachId, $candidates, true)) {
            return $this->json(['error' => 'Ce coach n\'est pas coach principal de cette équipe.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->wrapInTransaction(function () use ($campaign, $teamId, $coachId): void {
            $this->entityManager->find(Team::class, $teamId, LockMode::PESSIMISTIC_WRITE);
            foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['teamId' => $teamId]) as $link) {
                $link->setWishRecipient($link->getCoachId() === $coachId);
            }
            $this->entityManager->flush();
            $this->tokenSync->syncSeason((string) $campaign->getClubId(), $campaign->getSeasonId());
        });

        return $this->json(['campaign' => $this->presenter->toResource($campaign, includeTokens: true)]);
    }
}
