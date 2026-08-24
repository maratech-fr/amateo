<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Exception\ImportRejectedException;
use App\Service\FbiFixtureImporter;
use App\Service\FixtureImportGate;
use App\Service\SeasonResolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
 * POST /api/fixtures/import — club-wide FBI import, ONE pass (cadrage P1-4,
 * décision fondateur 2026-08-02) : the file rides with the manager's validated
 * Division↔team mappings (multipart field « mappings », JSON). The persisted
 * mappings are created first, then every resolvable row is created/updated.
 * The dialog obtained the mapping table from /api/fixtures/import/analyze.
 */
#[AsController]
final class ImportFixturesController extends AbstractController
{
    /** Même règle que `ImportController` : aucun détail d'origine dans la réponse (P4-5). */
    private const GENERIC_FAILURE = 'Le fichier n\'a pas pu être lu. Vérifiez qu\'il s\'agit bien d\'un export FBI au format .xlsx, puis réessayez.';

    public function __construct(
        private readonly FbiFixtureImporter $importer,
        private readonly FixtureImportGate $gate,
        private readonly LoggerInterface $logger,
        private readonly SeasonResolver $seasonResolver,
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

        $mappings = $this->parseMappings($request);
        if ($mappings instanceof JsonResponse) {
            return $mappings;
        }

        $decisions = $this->parseDecisions($request);
        if ($decisions instanceof JsonResponse) {
            return $decisions;
        }

        // La saison de l'ingestion est CELLE de la requête (revue de sécurité
        // 2026-08-24) : la deviner depuis les données importées ouvrait un repli
        // dégénéré (multi-saison → saison arbitraire ; club vide → pas de saison
        // du tout). La gate a déjà exigé un socle pointé : la saison existe.
        $season = $this->seasonResolver->selectedOrCurrent($request, $club->getId());

        try {
            $result = $this->importer->import((string) $file->getRealPath(), $club, $mappings, $decisions, $season?->getId());
        } catch (ImportRejectedException $e) {
            // Le SEUL type relayé : son message est écrit pour le gestionnaire.
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous uploads of the same file: the in-memory dedupe
            // cannot see the racing request; the partial unique index wins →
            // a clean retryable 409 instead of a raw 500.
            return $this->json(['error' => 'Un import concurrent a créé les mêmes rencontres — réessayez.'], Response::HTTP_CONFLICT);
        } catch (HttpException $e) {
            // `HttpException` étend `RuntimeException` : sans ce relais AVANT le filet
            // générique, un 403/409 levé sous `import()` perdrait son statut ET son sens,
            // remplacé par « le fichier n'a pas pu être lu ». La supervision verrait un
            // 4xx là où il y a une faute serveur, et personne ne serait réveillé.
            throw $e;
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Tout le reste vient d'une dépendance (PhpSpreadsheet étend RuntimeException)
            // et peut porter un chemin serveur — journalisé, jamais renvoyé (P4-5).
            $this->logger->error('Import rencontres en échec', ['clubId' => $club->getId(), 'exception' => $e]);

            return $this->json(['error' => self::GENERIC_FAILURE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'message' => 'Import terminé.',
            'created' => $result['created'],
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
            'exempted' => $result['exempted'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'unmappedDivisions' => $result['unmappedDivisions'],
            'completeness' => $result['completeness'],
            // RMM-4 — perimeter écarts (home already placed) that had NO decision:
            // left INTACT and reported, never overwritten by default.
            'unresolvedDeviations' => $result['unresolvedDeviations'],
            'depositedAt' => $result['depositedAt'],
        ], Response::HTTP_OK);
    }

    /**
     * The multipart « mappings » field: a JSON list of
     * {division, fbiTeamLabel|null, teamId}. Absent = no new mapping (rows
     * only resolve through the already-persisted ones).
     *
     * @return list<array{division: string, fbiTeamLabel: string|null, teamId: string, competitionId: string|null}>|JsonResponse
     */
    private function parseMappings(Request $request): array|JsonResponse
    {
        $raw = $request->request->get('mappings');
        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!\is_string($raw)) {
            return $this->json(['error' => 'Champ « mappings » invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            return $this->json(['error' => 'Champ « mappings » invalide (liste JSON attendue).'], Response::HTTP_BAD_REQUEST);
        }

        $mappings = [];
        foreach ($decoded as $entry) {
            $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
            if (!\is_array($entry)
                || !\is_string($entry['division'] ?? null) || '' === trim($entry['division'])
                || !\is_string($entry['teamId'] ?? null)
                || 1 !== preg_match($uuid, $entry['teamId'])
                || (null !== ($entry['fbiTeamLabel'] ?? null) && !\is_string($entry['fbiTeamLabel']))
                || (null !== ($entry['competitionId'] ?? null) && (!\is_string($entry['competitionId']) || 1 !== preg_match($uuid, $entry['competitionId'])))
            ) {
                return $this->json(['error' => 'Champ « mappings » invalide (entrées {division, teamId, fbiTeamLabel?, competitionId?} attendues).'], Response::HTTP_BAD_REQUEST);
            }
            $label = $entry['fbiTeamLabel'] ?? null;
            $mappings[] = [
                'division' => $entry['division'],
                'fbiTeamLabel' => \is_string($label) && '' !== trim($label) ? $label : null,
                'teamId' => $entry['teamId'],
                // P1-4 PR F2 — a FFBB suggestion travels WITH its competition so
                // the pairing (refs, expectation, poule) is REUSED, not duplicated.
                'competitionId' => \is_string($entry['competitionId'] ?? null) ? $entry['competitionId'] : null,
            ];
        }

        return $mappings;
    }

    /**
     * The multipart « decisions » field (RMM-4): a JSON list of
     * {fixtureId, field, choice} — the manager's per-écart verdicts from the
     * reconciliation screen. Absent = no decision (every perimeter écart stays
     * unresolved and is reported, never overwritten). Unknown fields/choices are
     * rejected at the boundary; the importer ignores any that do not match a live
     * écart when the diff is recomputed.
     *
     * @return list<array{fixtureId: string, field: string, choice: string}>|JsonResponse
     */
    private function parseDecisions(Request $request): array|JsonResponse
    {
        $raw = $request->request->get('decisions');
        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!\is_string($raw)) {
            return $this->json(['error' => 'Champ « decisions » invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            return $this->json(['error' => 'Champ « decisions » invalide (liste JSON attendue).'], Response::HTTP_BAD_REQUEST);
        }

        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $decisions = [];
        foreach ($decoded as $entry) {
            if (!\is_array($entry)
                || !\is_string($entry['fixtureId'] ?? null) || 1 !== preg_match($uuid, $entry['fixtureId'])
                || !\in_array($entry['field'] ?? null, ['date', 'kickoff', 'venue'], true)
                || !\in_array($entry['choice'] ?? null, ['keep_app', 'take_file'], true)
            ) {
                return $this->json(['error' => 'Champ « decisions » invalide (entrées {fixtureId, field: date|kickoff|venue, choice: keep_app|take_file} attendues).'], Response::HTTP_BAD_REQUEST);
            }
            $decisions[] = [
                'fixtureId' => $entry['fixtureId'],
                'field' => $entry['field'],
                'choice' => $entry['choice'],
            ];
        }

        return $decisions;
    }
}
