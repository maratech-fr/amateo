<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Exception\ImportRejectedException;
use App\Service\Basketball\FfbbExcelImporter;
use App\Service\TeamImportGate;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POST /api/clubs/{id}/import-teams/analyze — the DRY-RUN half of the FFBB team
 * import (P3-7) : parses the uploaded file and lists every team row with whether
 * its name is ALREADY present (club + season, and duplicates within the file),
 * so the manager can pick the lines to import and avoid doublons. Writes NOTHING.
 * The dialog then POSTs the SAME file to /api/clubs/{id}/import-teams with the
 * chosen « rows ». Same gate as the import (byte-identical refusals).
 */
#[AsController]
final class ImportTeamsAnalyzeController extends AbstractController
{
    /** Même règle que `ImportController` : aucun détail d'origine dans la réponse (P4-5). */
    private const GENERIC_FAILURE = 'Le fichier n\'a pas pu être lu. Vérifiez qu\'il s\'agit bien d\'un export au format .xlsx, puis réessayez.';

    public function __construct(
        private readonly FfbbExcelImporter $importer,
        private readonly TeamImportGate $gate,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $club = $this->gate->gate($request, $id);
        if (!$club instanceof Club) {
            return $club;
        }
        $file = $this->gate->requireXlsxFile($request);
        if (!$file instanceof UploadedFile) {
            return $file;
        }
        $seasonId = $this->gate->requireMatchingSeasonId($request);
        if ($seasonId instanceof JsonResponse) {
            return $seasonId;
        }

        try {
            $result = $this->importer->analyze((string) $file->getRealPath(), $id, $seasonId);
        } catch (ImportRejectedException $e) {
            // Le SEUL type relayé : son message est écrit pour le gestionnaire.
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (HttpException $e) {
            // Relais AVANT le filet générique : un 403/409 levé plus bas garde
            // son statut et son sens (même règle que le contrôleur d'import).
            throw $e;
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Tout le reste vient d'une dépendance (PhpSpreadsheet étend RuntimeException)
            // et peut porter un chemin serveur — journalisé, jamais renvoyé (P4-5).
            $this->logger->error('Analyse d\'import équipes en échec', ['clubId' => $id, 'exception' => $e]);

            return $this->json(['error' => self::GENERIC_FAILURE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'rows' => $result['rows'],
            'errors' => $result['errors'],
            'total' => $result['total'],
        ], Response::HTTP_OK);
    }
}
