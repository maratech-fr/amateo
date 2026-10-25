<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Service\SchedulePlanProvisioner;
use App\Service\SpreadsheetGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Excel export of a schedule (flat data table). Synchronous — PhpSpreadsheet is
 * fast and needs no headless browser, so the .xlsx streams straight back as a
 * download instead of going through the async PDF worker queue.
 */
#[AsController]
final class ExportXlsxController extends AbstractController
{
    use ResolvesCurrentClubTrait;
    use ResolvesExportScopeTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpreadsheetGenerator $spreadsheetGenerator,
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    public function __invoke(string $id): Response
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        if (!$schedule instanceof Schedule) {
            return new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Explicit tenant boundary (RLS already fail-closes the find; defense-in-depth).
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $schedule->getClubId() !== $currentClubId) {
            return new JsonResponse(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $venueId = $this->resolveExportVenueId($this->entityManager, $this->requestStack, $schedule);

        $binary = $this->spreadsheetGenerator->generate($schedule, $venueId);

        $response = new Response($binary, Response::HTTP_OK);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        // ADR-0002 inv. 12 : le nom VIVANT du plan, pas la photo `Schedule.name` (revue #339).
        // ⚠ Les séparateurs de chemin sont retirés AVANT makeDisposition : le nom est saisi par
        // le gestionnaire (« Vacances Noël 2026/2027 » est une forme naturelle) et Symfony LÈVE
        // sur « / » ou « \ » — l'export partait en 500 générique, définitivement, sans que rien
        // ne dise que le nom en était la cause. C'est bien la RÉPONSE qui échouait : l'app, elle,
        // télécharge un blob et nomme le fichier côté client (`useScheduleExport`), donc cet
        // en-tête ne sert qu'aux appels directs à l'API.
        $planningName = str_replace(['/', '\\'], '-', $this->schedulePlanProvisioner->displayNameOf($schedule));
        // makeDisposition emits both an ASCII fallback and a RFC 5987 filename*,
        // so an accented schedule name survives instead of collapsing to dashes.
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('planning-%s.xlsx', $planningName),
            \sprintf('planning-%s.xlsx', preg_replace('/[^a-zA-Z0-9_-]+/', '-', $planningName) ?: 'export'),
        ));
        // Un export est NOMINATIF (noms de coachs/équipes) : jamais mis en cache (navigateur ou
        // cache partagé), sinon il survit sur un poste partagé du club. Patron ExportPdfDownloadController.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
