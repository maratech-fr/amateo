<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ScheduledDeployment;
use App\Entity\User;
use App\Repository\ScheduledDeploymentRepository;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le bandeau d'annonce que TOUT club connecté voit dès qu'une mise en production est programmée
 * (« mise en prod programmée », le rendu est côté frontend). La charge est MINIMALE : seulement
 * quand la maintenance commence et quand elle doit finir.
 *
 * ⚠ ZÉRO FUITE : ni la version, ni l'acteur (`created_by`), ni la moindre cuisine de déploiement —
 * un gestionnaire de club n'a pas à la connaître. `scheduled_deployment` est GLOBALE (jamais
 * scopée tenant) : le club la lit par sa connexion applicative ; le firewall API suffit.
 */
final class DeploymentNoticeController extends AbstractController
{
    public function __construct(private readonly ScheduledDeploymentRepository $deployments) {}

    #[Route('/api/deployment-notice', name: 'api_deployment_notice', methods: ['GET'])]
    public function notice(): JsonResponse
    {
        if (!$this->getUser() instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $deployment = $this->deployments->findActive();
        if (!$deployment instanceof ScheduledDeployment) {
            // Rien de programmé (ou annulé/terminé) → pas de bandeau. On sert un JSON `null`
            // littéral (JsonResponse(null) rendrait `{}`), déjà encodé.
            return new JsonResponse('null', 200, [], true);
        }

        return new JsonResponse([
            'scheduledAt' => $deployment->getScheduledAt()->format(DateTimeInterface::ATOM),
            'expectedReturnAt' => $deployment->getExpectedReturnAt()->format(DateTimeInterface::ATOM),
        ]);
    }
}
