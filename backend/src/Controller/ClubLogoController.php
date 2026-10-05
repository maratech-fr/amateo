<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClubRepository;
use App\Service\ManagementAccessGuard;
use App\Storage\LogoStorage;
use App\Storage\LogoUrl;
use Doctrine\ORM\EntityManagerInterface;
use finfo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Club logo upload (authenticated, scoped to the JWT club) + public serve.
 * Raster only (PNG/JPEG/WebP) — SVG is excluded (script/XSS risk + mime ambiguity).
 * Bytes live behind the LogoStorage abstraction (local disk in dev, swappable in prod).
 */
final class ClubLogoController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    private const MAX_BYTES = 512_000; // 500 KB

    /** @var array<string, string> mime → extension (allowed uploads) */
    private const ALLOWED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly LogoStorage $storage,
        private readonly ClubRepository $clubRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Route('/api/club/logo', name: 'club_logo_upload', methods: ['POST'])]
    public function upload(): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07 (same surface as /club/appearance)

        $request = $this->requestStack->getCurrentRequest();
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $request || null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        $club = $this->clubRepository->find($clubId);
        if (null === $club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');
        if (null === $file) {
            return $this->json(['error' => 'No file uploaded (field "file").'], Response::HTTP_BAD_REQUEST);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return $this->json(['error' => 'Le logo est trop volumineux (500 Ko maximum).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $mime = (string) $file->getMimeType();
        if (!isset(self::ALLOWED[$mime])) {
            return $this->json(['error' => 'Format non pris en charge (PNG, JPEG ou WebP uniquement).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $bytes = file_get_contents($file->getPathname());
        if (false === $bytes) {
            return $this->json(['error' => 'Could not read the uploaded file.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $this->storage->store($clubId, $bytes);

        $url = LogoUrl::build($clubId, $bytes);
        $club->setLogoUrl($url);
        $this->entityManager->flush();

        return $this->json(['logoUrl' => $url]);
    }

    #[Route('/api/club/logo', name: 'club_logo_delete', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        $club = $this->clubRepository->find($clubId);
        if (null === $club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->storage->delete($clubId);
        $club->setLogoUrl(null);
        $this->entityManager->flush();

        return $this->json(['logoUrl' => null]);
    }

    #[Route('/api/clubs/{clubId}/logo', name: 'club_logo_serve', methods: ['GET'])]
    public function serve(string $clubId): Response
    {
        $bytes = $this->storage->read($clubId);
        if (null === $bytes) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $mime = new finfo(\FILEINFO_MIME_TYPE)->buffer($bytes);
        $response = new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
            'Cache-Control' => 'public, max-age=300',
        ]);

        return $response;
    }
}
