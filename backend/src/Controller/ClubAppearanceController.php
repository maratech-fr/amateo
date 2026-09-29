<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClubRepository;
use App\Service\ManagementAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Club identity (accent colour + palette) update, scoped to the caller's club
 * resolved from the JWT tenant. A dedicated partial-update endpoint so the
 * "club management" screen can save the accent without the generic Club
 * resource's NotBlank fields (name/slug/timezone…). Logo upload lands here later.
 */
final class ClubAppearanceController extends AbstractController
{
    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Route('/api/club/appearance', name: 'club_appearance', methods: ['PATCH'])]
    public function __invoke(): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        $club = $this->clubRepository->find($clubId);
        if (null === $club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // $clubId n'est une chaîne que si $request n'est pas null (l'attribut vient de lui).
        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        if (\array_key_exists('accentColor', $data)) {
            $color = $data['accentColor'];
            if (null !== $color && (!\is_string($color) || 1 !== preg_match(self::HEX, $color))) {
                return $this->json(['error' => 'accentColor must be a #RRGGBB hex colour or null.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $club->setAccentColor($color);
        }

        if (\array_key_exists('accentColorDark', $data)) {
            $colorDark = $data['accentColorDark'];
            if (null !== $colorDark && (!\is_string($colorDark) || 1 !== preg_match(self::HEX, $colorDark))) {
                return $this->json(['error' => 'accentColorDark must be a #RRGGBB hex colour or null.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $club->setAccentColorDark($colorDark);
        }

        if (\array_key_exists('accentPalette', $data)) {
            $palette = $data['accentPalette'];
            if (null !== $palette) {
                if (!\is_array($palette) || \count($palette) > 3) {
                    return $this->json(['error' => 'accentPalette must be up to 3 hex colours or null.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                foreach ($palette as $hex) {
                    if (!\is_string($hex) || 1 !== preg_match(self::HEX, $hex)) {
                        return $this->json(['error' => 'accentPalette entries must be #RRGGBB hex colours.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }
                }
                $palette = array_values($palette);
            }
            $club->setAccentPalette($palette);
        }

        $this->entityManager->flush();

        return $this->json([
            'accentColor' => $club->getAccentColor(),
            'accentColorDark' => $club->getAccentColorDark(),
            'accentPalette' => $club->getAccentPalette(),
        ]);
    }
}
