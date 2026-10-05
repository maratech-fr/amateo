<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Service\SeasonResolver;
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
 * Dev-only (P1-5) : marque la saison SUIVANTE du club appelant comme payée —
 * le relais e2e/dev du geste support `mark-next-season-paid` (console admin), sans
 * lequel aucun parcours de bascule ne passerait le gate en environnement de
 * test. Même patron que DevClockController : gardé par kernel.debug, 404 en
 * prod. Respecte l'horloge simulée (un scénario épinglé en mai 2027 paie la
 * saison 2027-2028, pas celle du temps réel).
 */
#[AsController]
final class DevSeasonPaymentController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
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

        $nextYear = SeasonResolver::seasonYear(DateTimeImmutable::createFromInterface($this->clock->now())) + 1;
        $club->setPaidSeasonYear(max($club->getPaidSeasonYear() ?? 0, $nextYear));
        $this->entityManager->flush();

        return $this->json(['paidSeasonYear' => $club->getPaidSeasonYear()]);
    }
}
