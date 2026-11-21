<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Access\Output;
use App\Entity\Schedule;
use App\Message\ExportPdfMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsController]
#[ClubFunction(module: Module::PLANNING, audience: Audience::MEMBER, output: Output::PLANNING_PDF)]
final class ExportPdfController extends AbstractController
{
    use ResolvesCurrentClubTrait;
    use ResolvesExportScopeTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);

        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // BCK-07: make the tenant boundary explicit (RLS already fail-closes the
        // find above; this is defense-in-depth + consistency with the sibling
        // schedule controllers) instead of relying on RLS alone.
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $schedule->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // Optional export scope: a single venue (validated against this schedule's
        // club+season; foreign/unknown → 404 via the shared trait).
        $venueId = $this->resolveExportVenueId($this->entityManager, $this->requestStack, $schedule);
        // Chantier E — « afficher le nom des coachs », lu fail-closed (booléen strict true seul).
        $showCoaches = $this->resolveShowCoaches($this->requestStack);

        $schedule->setPdfExportStatus('pending');
        $this->entityManager->flush();

        $this->messageBus->dispatch(
            new ExportPdfMessage(
                scheduleId: $schedule->getId(),
                clubId: $schedule->getClubId(),
                venueId: $venueId,
                showCoaches: $showCoaches,
                showOccupancies: $this->resolveShowOccupancies($this->requestStack),
                requestId: bin2hex(random_bytes(16)),
            ),
        );

        return $this->json(['message' => 'PDF export queued.'], Response::HTTP_ACCEPTED);
    }
}
