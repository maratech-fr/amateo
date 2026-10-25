<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Service\PdfGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P4-357 — télécharge le RENDU PDF d'un planning par une route AUTHENTIFIÉE confinée au club,
 * plus une URL statique publique. Le fichier vit désormais HORS du docroot
 * (`PdfGenerator::outputDir()`, `var/exports`) : seul ce contrôleur le sert, derrière le firewall
 * JWT (`^/api` IS_AUTHENTICATED_FULLY).
 *
 * Patron `ExportXlsxController` : `find` sous RLS (fail-closed → 404 `Planning introuvable.`
 * byte-identique qu'un planning soit inconnu ou visé depuis un AUTRE club — aucune divulgation
 * d'existence), puis garde de club explicite en défense en profondeur. Le NOM de fichier est
 * doublement contraint — la classe de caractères du motif de la purge (requirement de route,
 * `..%2f…` et un nom hors motif n'atteignent jamais le contrôleur) ET le préfixe `schedule-{id}-`
 * (le nom d'un autre planning glissé sous le `{id}` du sien est refusé) — puis le chemin résolu
 * est CONFINÉ au répertoire de rendus (patron `PurgeExportsCommand::deleteInside`). Aucun crédit
 * décompté : le re-téléchargement n'est pas une sortie produite (hors `CreditBudgetSubscriber`).
 */
#[AsController]
final class ExportPdfDownloadController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {}

    // Le requirement reprend la classe de caractères de `PurgeExportsCommand::RENDER_PATTERN`
    // (`schedule-{uuid}-{scope}.pdf`) : ni `/` ni `.` (hors `.pdf`) ne peuvent entrer, donc une
    // traversée ou un nom hors motif ne MATCHE PAS la route (404 de routage) avant tout code.
    #[Route(
        '/api/schedules/{id}/export-pdf/{filename}',
        name: 'api_schedule_export_pdf_download',
        requirements: ['filename' => 'schedule-[0-9a-fA-F-]{36}-[a-z0-9-]+\.pdf'],
        methods: ['GET'],
    )]
    public function __invoke(string $id, string $filename): Response
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        if (!$schedule instanceof Schedule) {
            return new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Explicit tenant boundary (RLS already fail-closes the find; defense-in-depth, patron
        // ExportXlsxController). En pratique RLS a déjà rendu `null` pour un autre club → 404 ci-dessus.
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $schedule->getClubId() !== $currentClubId) {
            return new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Le rendu DOIT appartenir à CE planning : le nom commence par `schedule-{id}-`. Sans ça,
        // le nom d'un AUTRE planning (motif valide) glissé sous le `{id}` du sien serait servi.
        if (!str_starts_with($filename, \sprintf('schedule-%s-', $id))) {
            return new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Confinement realpath au répertoire de rendus (patron `PurgeExportsCommand::deleteInside`) :
        // un fichier absent → realpath false → même 404 ; aucune échappée hors répertoire possible.
        $directory = PdfGenerator::outputDir();
        $base = realpath($directory);
        $target = realpath($directory . '/' . basename($filename));
        if (false === $base || false === $target || !str_starts_with($target, $base . '/')) {
            return new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($target);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($filename));

        return $response;
    }
}
