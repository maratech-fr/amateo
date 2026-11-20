<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Service\AuditTrail;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonDataPurger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/reset-season', name: 'reset_season', methods: ['DELETE'])]
#[ClubFunction(module: Module::CLUB, audience: Audience::MANAGER)]
final class ResetSeasonController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeasonDataPurger $seasonDataPurger,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly AuditTrail $auditTrail,
    ) {}

    public function __invoke(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        // Club resolved server-side from the caller's JWT membership (no header
        // since AUD-SEC-25); X-Season-Id stays a legitimate selector.
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $seasonId = $this->resolveIdentifier($request?->attributes->get('_season_id'), $request?->headers->get('X-Season-Id'));

        if (null === $clubId || null === $seasonId) {
            return $this->json(['error' => 'Missing club or season context.'], Response::HTTP_BAD_REQUEST);
        }

        // Wiping the whole club is a management action: refused to a Member by the
        // central control (`ClubAccessListener`) before this controller runs.
        $user = $this->getUser();

        // Archived-season write refused (409).
        $this->seasonAccessGuard->assertWritable($request);

        // Wipe the season's contents but KEEP the Season row (the club keeps
        // its current season, only re-emptied).
        $deleted = $this->seasonDataPurger->purge($clubId, $seasonId, deleteSeasonRow: false);
        $this->auditTrail->record(AuditAction::SEASON_RESET, $user instanceof User ? $user->getId() : null, $clubId, 'Season', $seasonId, ['rowsDeleted' => $deleted]);

        return $this->json([
            'status' => 'ok',
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'deleted' => $deleted,
        ]);
    }

    private function resolveIdentifier(mixed $attribute, mixed $header = null): ?string
    {
        foreach ([$attribute, $header] as $value) {
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
