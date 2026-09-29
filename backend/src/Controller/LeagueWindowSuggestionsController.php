<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LeagueWindowSuggestionService;
use App\Service\ManagementAccessGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P4-272 ② — les plages de match SUGGÉRÉES à un club : la tendance dominante de son
 * instance fédérale (comité / ligue / fédération) plus le repli fédéral, l'ensemble
 * MASQUÉ des combinaisons déjà identiques à sa copie. Lecture (GET) et application
 * (POST) réservées au gestionnaire ({@see ManagementAccessGuard}). Aucune plage n'est
 * jamais fournie par le client : « Appliquer » RECALCULE la suggestion côté serveur.
 */
final class LeagueWindowSuggestionsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly LeagueWindowSuggestionService $service,
        private readonly ManagementAccessGuard $managementGuard,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/league-window-suggestions', name: 'api_league_window_suggestions', methods: ['GET'])]
    public function suggestions(): JsonResponse
    {
        $this->managementGuard->assertManager();

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->service->suggestionsFor($clubId));
    }

    #[Route('/api/league-window-suggestions/apply', name: 'api_league_window_suggestions_apply', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        $this->managementGuard->assertManager();

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array{combinations?: mixed} $body */
        $body = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR) ?: [];
        $combinations = $this->parseCombinations($body['combinations'] ?? null);

        $applied = $this->service->apply($clubId, $combinations);

        return $this->json(['applied' => $applied]);
    }

    /**
     * @return list<array{category: string, level: string, gender: string|null, dayOfWeek: int}>
     */
    private function parseCombinations(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $combinations = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $category = $entry['category'] ?? null;
            $level = $entry['level'] ?? null;
            $gender = $entry['gender'] ?? null;
            $dayOfWeek = $entry['dayOfWeek'] ?? null;
            if (!\is_string($category) || '' === $category || !\is_string($level) || '' === $level || !\is_int($dayOfWeek)) {
                continue;
            }
            $combinations[] = [
                'category' => $category,
                'level' => $level,
                'gender' => \is_string($gender) && '' !== $gender ? $gender : null,
                'dayOfWeek' => $dayOfWeek,
            ];
        }

        return $combinations;
    }
}
