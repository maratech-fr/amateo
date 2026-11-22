<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Enum\SubscriptionOrigin;
use App\Service\ClubSubscriptionWriter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only (P1-5) : règle la saison SUIVANTE du club appelant — le relais e2e/dev du geste
 * support « Offre », saison suivante (console admin), sans lequel aucun parcours de bascule ne
 * passerait le gate en environnement de test. Écrit, par le seul écrivain de l'abonnement, une
 * ligne Bêta réglée pour l'année que la bascule créera : une offre autre que Découverte, la seule
 * qui ouvre la bascule. Même patron que DevClockController : gardé par kernel.debug, 404 en prod.
 * Respecte l'horloge simulée (un scénario épinglé en mai 2027 règle la saison 2027-2028, pas celle
 * du temps réel).
 */
#[AsController]
final class DevSeasonPaymentController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    /** L'offre qui ouvre la bascule sans plafond d'équipes. */
    private const string PLAN_CODE = 'beta';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
        private readonly ClubSubscriptionWriter $subscriptionWriter,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {}

    #[Route('/api/dev/mark-season-paid', name: 'dev_mark_season_paid', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        if (!$this->debug) {
            throw $this->createNotFoundException();
        }

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $club = null !== $clubId ? $this->entityManager->getRepository(Club::class)->find($clubId) : null;
        if (!$club instanceof Club) {
            return $this->json(['error' => 'No club in context.'], 400);
        }

        $nextYear = $this->subscriptionWriter->seasonYears($club->getId(), DateTimeImmutable::createFromInterface($this->clock->now()))['next'];
        $this->subscriptionWriter->record($club->getId(), $nextYear, self::PLAN_CODE, SubscriptionOrigin::SUPPORT);

        return $this->json(['paidSeasonYear' => $nextYear]);
    }
}
