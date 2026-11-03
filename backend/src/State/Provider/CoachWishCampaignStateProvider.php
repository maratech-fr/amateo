<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\CoachWishCampaignResource;
use App\Entity\CoachWishCampaign;
use App\Service\CoachWishCampaignPresenter;
use App\Service\ManagementAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @extends AbstractStateProvider<CoachWishCampaign, CoachWishCampaignResource>
 */
class CoachWishCampaignStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly CoachWishCampaignPresenter $presenter,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    /** Rôle gestionnaire PROUVÉ pour la requête en cours — seule condition qui ajoute les liens. */
    private bool $includeTokens = false;

    /**
     * Lecture ouverte à tout membre actif du club (un Membre voit l'état de réponse et l'adresse
     * de chaque coach). Le SECRET du lien public de chaque coach (`coaches[].token`) n'est AJOUTÉ
     * que si le rôle gestionnaire est prouvé par la garde SEC-07 — jamais servi par défaut,
     * jamais retiré après coup : sans lui, un Membre ne peut pas répondre à la place d'un coach.
     *
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        try {
            $this->managementAccessGuard->assertManager();
            $this->includeTokens = true;
        } catch (AccessDeniedHttpException) {
            $this->includeTokens = false;
        }

        return parent::provide($operation, $uriVariables, $context);
    }

    protected function getEntityClass(): string
    {
        return CoachWishCampaign::class;
    }

    /**
     * @param CoachWishCampaign $entity
     */
    protected function mapEntityToOutput(object $entity): CoachWishCampaignResource
    {
        return $this->presenter->toResource($entity, $this->includeTokens);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, CoachWishCampaignResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(CoachWishCampaign::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $calendarEntryId = $this->uuidQueryParam($request, 'calendarEntryId');
            if (null !== $calendarEntryId) {
                $qb->andWhere('e.calendarEntryId = :calendarEntryId')->setParameter('calendarEntryId', $calendarEntryId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
