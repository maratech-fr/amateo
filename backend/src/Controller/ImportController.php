<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Exception\ImportRejectedException;
use App\Repository\ClubUserRepository;
use App\Service\Basketball\FfbbExcelImporter;
use App\Service\SeasonAccessGuard;
use App\Service\XlsxUploadGuard;
use Doctrine\ORM\EntityManagerInterface;
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
use Symfony\Component\RateLimiter\RateLimiterFactory;

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
        private readonly EntityManagerInterface $entityManager,
        private readonly FfbbExcelImporter $importer,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactory $xlsxImportLimiter,
        private readonly XlsxUploadGuard $xlsxUploadGuard,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        // SEC-04: the tenant listener validates the header/JWT club, not the {id}
        // in the path. Gate on the caller's active membership FIRST — checking
        // club existence before membership would leak which club ids exist
        // (403 for an existing club vs 404 for a random uuid). Semantics mirror
        // ClubStateProcessor: no active membership → 404, member but not a
        // management role → 403.
        $user = $this->getUser();
        $membership = $user instanceof User
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $id)
            : null;
        if (!$membership instanceof ClubUser) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        // Archived-season write refused (409) — AFTER auth so 403 wins first.
        $this->seasonAccessGuard->assertWritable($request);

        $club = $this->entityManager->getRepository(Club::class)->find($id);
        if (!$club instanceof Club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Anti-abus : borne PAR UTILISATEUR (JWT), consommée APRÈS l'auth pour que
        // 403/404/409 gagnent d'abord (SEC-22, même patron que FixtureImportGate).
        // $user est ici forcément un User (la garde de membership l'a établi).
        if (!$this->xlsxImportLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop d\'imports — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Maison unique des bornes d'upload (SEC-22) : mime/extension + octets +
        // décompressé + lignes, byte-identique à l'import de rencontres.
        $file = $this->xlsxUploadGuard->requireXlsxFile($request);
        if (!$file instanceof UploadedFile) {
            return $file;
        }

        $seasonId = $request->request->get('seasonId');
        if (!\is_string($seasonId) || '' === $seasonId) {
            return $this->json(['error' => 'seasonId is required.'], Response::HTTP_BAD_REQUEST);
        }

        // The body seasonId must match the listener-resolved season: the
        // season_filter scopes every read to the resolved one, so importing
        // into another season would defeat the importer's dedupe lookups
        // (duplicates) and produce rows invisible to the selected season.
        $resolvedSeasonId = $request->attributes->get('_season_id');
        if (!\is_string($resolvedSeasonId) || $seasonId !== $resolvedSeasonId) {
            return $this->json(
                ['error' => 'seasonId does not match the selected season (X-Season-Id).'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $result = $this->importer->import($file->getRealPath(), $id, $seasonId);
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
}
