<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Season;
use App\Enum\TravelComputeScope;
use App\Message\ComputeTravelTimesMessage;
use App\Repository\ClubRepository;
use App\Service\Geo\BanGeocodingClient;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Set the club SIÈGE (head-office address + coordinates), scoped to the caller's club
 * resolved from the JWT tenant. A dedicated partial-update endpoint (patron
 * {@see ClubAppearanceController}) so the club screen saves the siège without the generic
 * Club resource's NotBlank fields.
 *
 * 🔴 SÉCURITÉ (patron SEC-15) : le corps ne porte QUE du TEXTE d'adresse
 * (`address`/`postalCode`/`city`). Le serveur RE-géocode via {@see BanGeocodingClient} et
 * écrit adresse/CP/ville/lat/lon depuis SON hit fédéral — une latitude forgée dans le corps
 * est IGNORÉE (jamais lue). Best-effort : 422 « adresse introuvable », 502 BAN muet (patron
 * {@see GeocodeController}). Management-gated (SEC-07).
 */
#[AsController]
final class ClubSiegeController extends AbstractController
{
    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly BanGeocodingClient $geocoder,
        private readonly SeasonResolver $seasonResolver,
        private readonly MessageBusInterface $messageBus,
    ) {}

    #[Route('/api/club/siege', name: 'club_siege', methods: ['PATCH'])]
    public function __invoke(): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        $club = $this->clubRepository->find($clubId);
        if (null === $club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode((string) $request?->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        // Le corps ne porte que du texte d'adresse ; la lat/long vient du hit BAN, jamais du client.
        $parts = [];
        foreach (['address', 'postalCode', 'city'] as $key) {
            $value = $data[$key] ?? null;
            if (\is_string($value) && '' !== trim($value)) {
                $parts[] = trim($value);
            }
        }

        try {
            $hit = $this->geocoder->geocodeTop(implode(' ', $parts));
        } catch (Throwable) {
            return $this->json(['error' => 'Service d\'adresses indisponible, réessayez plus tard.'], Response::HTTP_BAD_GATEWAY);
        }
        if (null === $hit) {
            return $this->json(['error' => 'Adresse introuvable — précisez la rue et la ville.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Le siège bouge-t-il vraiment ? (comparé à ~1 m près, la granularité du cache.) Un
        // re-géocodage de la MÊME adresse ne doit pas invalider tous les trajets.
        $moved = $this->coordinatesChanged($club->getLatitude(), $club->getLongitude(), $hit['latitude'], $hit['longitude']);

        $club->setAddress(mb_substr($hit['label'], 0, 255))
            ->setPostalCode(null === $hit['postalCode'] ? null : mb_substr($hit['postalCode'], 0, 16))
            ->setCity(null === $hit['city'] ? null : mb_substr($hit['city'], 0, 120))
            ->setLatitude($hit['latitude'])
            ->setLongitude($hit['longitude']);
        $this->entityManager->flush();

        // C6 — le trajet d'un adversaire est calculé DEPUIS le siège : s'il déménage, TOUS les
        // trajets dérivés (AUTO et MANUAL) sont périmés. On les invalide (travel_minutes NULL —
        // le gymnase épinglé d'un MANUAL reste, seul son trajet repart) et on DISPATCHE un
        // recalcul de la saison courante au worker (le cache repart tout seul : la nouvelle
        // origine est une nouvelle clé, les anciennes lignes de cache ne sont plus jamais lues).
        if ($moved) {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE opponent_travel SET travel_minutes = NULL WHERE club_id = :clubId',
                ['clubId' => $clubId],
            );
            $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);
            if ($season instanceof Season) {
                $this->messageBus->dispatch(new ComputeTravelTimesMessage($clubId, $season->getId(), TravelComputeScope::OPPONENTS));
            }
        }

        return $this->json([
            'address' => $club->getAddress(),
            'postalCode' => $club->getPostalCode(),
            'city' => $club->getCity(),
            'geolocated' => null !== $club->getLatitude() && null !== $club->getLongitude(),
        ]);
    }

    /** Le siège a-t-il bougé au-delà de la granularité du cache (~1 m, 5 décimales) ? */
    private function coordinatesChanged(?float $oldLat, ?float $oldLon, float $newLat, float $newLon): bool
    {
        if (null === $oldLat || null === $oldLon) {
            return true; // siège posé pour la première fois → tout est à calculer
        }

        return \sprintf('%.5f', $oldLat) !== \sprintf('%.5f', $newLat)
            || \sprintf('%.5f', $oldLon) !== \sprintf('%.5f', $newLon);
    }
}
