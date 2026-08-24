<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Exception\ImportRejectedException;
use App\Service\FbiFixtureImporter;
use App\Service\FixtureImportGate;
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
 * POST /api/fixtures/import/analyze — the DRY-RUN half of the one-pass import
 * (cadrage P1-4) : parses the uploaded FBI file, groups its rows by division
 * (+ club-team label when two club teams share one division) and resolves each
 * group against the persisted Division↔team mapping. Writes NOTHING — the
 * dialog renders the mapping table from this, pre-filled where the mapping is
 * known, then posts the SAME file to /api/fixtures/import with the completed
 * mappings. Same gate as the import (byte-identical refusals).
 */
#[AsController]
final class ImportFixturesAnalyzeController extends AbstractController
{
    /** Même règle que `ImportController` : aucun détail d'origine dans la réponse (P4-5). */
    private const GENERIC_FAILURE = 'Le fichier n\'a pas pu être lu. Vérifiez qu\'il s\'agit bien d\'un export FBI au format .xlsx, puis réessayez.';

    public function __construct(
        private readonly FbiFixtureImporter $importer,
        private readonly FixtureImportGate $gate,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $club = $this->gate->gate($request);
        if (!$club instanceof Club) {
            return $club;
        }
        $file = $this->gate->requireXlsxFile($request);
        if (!$file instanceof UploadedFile) {
            return $file;
        }

        try {
            $result = $this->importer->analyze((string) $file->getRealPath(), $club);
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
            $this->logger->error('Analyse d\'import rencontres en échec', ['clubId' => $club->getId(), 'exception' => $e]);

            return $this->json(['error' => self::GENERIC_FAILURE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'divisions' => $result['divisions'],
            'totalRows' => $result['totalRows'],
            'exempted' => $result['exempted'],
            'errors' => $result['errors'],
            // RMM-4 — the reconciliation écarts of home fixtures already placed
            // (state app VS state fichier), each to be decided per écart at import.
            'deviations' => $result['deviations'],
        ], Response::HTTP_OK);
    }
}
