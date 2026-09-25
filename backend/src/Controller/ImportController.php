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

#[AsController]
final class ImportController extends AbstractController
{
    /**
     * Message unique pour tout échec non métier. Volontairement sans détail : le
     * gestionnaire n'a aucune action à tirer de « unable to identify a reader »,
     * et la chaîne d'origine peut porter un chemin serveur.
     */
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

        // P3-7 — les lignes cochées dans l'écran d'import (numéros de ligne Excel).
        // Absent = tout importer (comportement historique conservé).
        $selectedRows = $this->parseRows($request);
        if ($selectedRows instanceof JsonResponse) {
            return $selectedRows;
        }

        try {
            $result = $this->importer->import((string) $file->getRealPath(), $id, $seasonId, $selectedRows);
        } catch (ImportRejectedException $e) {
            // Le SEUL type relayé : son message est écrit pour le gestionnaire.
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (HttpException $e) {
            // `HttpException` étend `RuntimeException` : sans ce relais AVANT le filet
            // générique, un 403/409 levé sous `import()` perdrait son statut ET son sens,
            // remplacé par « le fichier n'a pas pu être lu ». La supervision verrait un
            // 4xx là où il y a une faute serveur, et personne ne serait réveillé.
            throw $e;
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Tout le reste vient d'une dépendance (PhpSpreadsheet étend RuntimeException)
            // et peut porter un chemin serveur — journalisé, jamais renvoyé (P4-5).
            $this->logger->error('Import équipes en échec', ['clubId' => $id, 'exception' => $e]);

            return $this->json(['error' => self::GENERIC_FAILURE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'message' => 'Import completed.',
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ], Response::HTTP_OK);
    }

    /**
     * Le champ multipart « rows » : une liste JSON de numéros de ligne Excel à
     * importer (ex. `[3, 9, 12]`), patron du champ « mappings » de l'import
     * rencontres. Absent/vide = null = tout importer.
     *
     * @return list<int>|JsonResponse|null
     */
    private function parseRows(Request $request): array|JsonResponse|null
    {
        $raw = $request->request->get('rows');
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            return $this->json(['error' => 'Champ « rows » invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            return $this->json(['error' => 'Champ « rows » invalide (liste JSON de numéros de ligne attendue).'], Response::HTTP_BAD_REQUEST);
        }

        $rows = [];
        foreach ($decoded as $entry) {
            if (!\is_int($entry)) {
                return $this->json(['error' => 'Champ « rows » invalide (liste JSON de numéros de ligne attendue).'], Response::HTTP_BAD_REQUEST);
            }
            $rows[] = $entry;
        }

        return $rows;
    }
}
