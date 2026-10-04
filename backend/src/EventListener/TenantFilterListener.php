<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\Filter\SeasonFilter;
use App\Doctrine\Filter\TenantFilter;
use App\Entity\Season;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Repository\SeasonRepository;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class TenantFilterListener implements EventSubscriberInterface
{
    /**
     * Marqueur qui distingue « ta saison sélectionnée est morte » d'un 403 d'AUTORISATION.
     *
     * ⚠ Contrat protocolaire avec le frontend (`shared/api/client.ts`), et il n'est pas
     * cosmétique : sans lui, le front ne peut pas savoir qu'il doit lâcher sa saison
     * périmée. Or le backend 403 TOUTE requête portant cette saison, `/api/me` comprise —
     * l'app resterait donc en **boucle 403 définitive**, sans chemin de retour. Un
     * producteur, un consommateur, et jusqu'au 2026-08-08 zéro test des deux côtés (D-39).
     */
    public const string STALE_SEASON_HEADER = 'X-Season-Rejected';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonRepository $seasonRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly ClockInterface $clock,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority 7: AFTER the security firewall (priority 8) so the JWT user is
        // authenticated — the club is derived SOLELY from the user's active
        // membership (the frontend sends no header; AUD-SEC-25). Running before
        // auth left the tenant unresolved (no RLS) and leaked other clubs' data
        // on collection reads.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Anti-staleness guard: never inherit a GUC from a previous request on
        // the same connection (shared kernel in tests, worker loops).
        $this->tenantConnectionContext->clear();

        $request = $event->getRequest();

        // SEC-17 — la console super-admin n'a PAS de tenant, par construction.
        // Son identité est un `SuperAdmin` (jamais un `User`), elle travaille sur
        // la connexion Doctrine `admin` qui contourne RLS, et le contrat SA0 dit
        // « la session admin ne pose jamais app.club_id ». On sort donc avant toute
        // résolution de club. (Depuis AUD-SEC-25 le club ne vient plus que de
        // l'adhésion de l'utilisateur authentifié — un SuperAdmin n'en a aucune —
        // mais la sortie explicite reste le contrat.)
        if (str_starts_with($request->getPathInfo(), '/api/admin')) {
            return;
        }
        $user = $this->authenticatedUser();

        // Single active club per user: the tenant is derived from the JWT user's
        // active membership. There is NO client-supplied override anymore
        // (AUD-SEC-25 removed the X-Club-Id header). club_user is readable without
        // a GUC by design (RLS bootstrap exception).
        $clubId = $this->resolveClubId($request, $user);

        if (null === $clubId) {
            // No tenant: only an explicit season header/attribute can apply
            // (the current-season fallback needs a club anyway). Ownership
            // cannot be checked without a club, but the UUID shape can — a
            // garbage value must never reach a downstream _season_id consumer.
            $explicitSeason = $this->resolveExplicitSeasonId($request);
            if (null !== $explicitSeason && $this->isUuid($explicitSeason)) {
                $request->attributes->set('_season_id', $explicitSeason);
            }

            return;
        }

        // FORME du club AVANT tout le reste (revue sécu FRT-04). Le club résolu
        // vient de l'adhésion (UUID canonique en base) ; cette garde reste la
        // CEINTURE du chemin attribut `_club_id` : si un jour un poseur amont y
        // injectait une forme dégradée (`{710a290720ce...}`), PostgreSQL la
        // normaliserait, l'adhésion passerait — et la chaîne brute s'écrirait
        // telle quelle dans un aval qui, lui, ne normalise pas. Sur
        // `/api/mercure/auth` le sélecteur devenait `club:{710a29...}:schedule:{id}`
        // : DEUX variables URI-template, qui matchent les topics de N'IMPORTE
        // QUEL club. Le club est ici la frontière tenant : sa forme se valide
        // comme celle de la saison (l. 166), et un écart se refuse — jamais une
        // normalisation silencieuse.
        if (!$this->isUuid($clubId)) {
            $event->setResponse(new JsonResponse(['error' => 'Vous n\'avez pas accès à ce club.'], 403));

            return;
        }

        $request->attributes->set('_club_id', $clubId);

        // Belt on the `_club_id` attribute path: validate that the authenticated
        // user belongs to the resolved club. With the membership fallback this is
        // always satisfied, but it stays as defence should an upstream setter of
        // `_club_id` ever appear.
        if ($user instanceof User) {
            $membership = $this->clubUserRepository->findOneBy([
                'userId' => $user->getId(),
                'clubId' => $clubId,
                'isActive' => true,
            ]);
            if (null === $membership) {
                $event->setResponse(new JsonResponse(
                    ['error' => 'Vous n\'avez pas accès à ce club.'],
                    403,
                ));

                return;
            }
        }

        $filterCollection = $this->entityManager->getFilters();

        /** @var TenantFilter $filter */
        $filter = $filterCollection->enable('tenant_filter');
        $filter->setParameter('club_id', $clubId, 'uuid');

        // Session-scoped GUC read by the RLS policies (the old SET LOCAL was a
        // no-op outside a transaction — see TenantConnectionContext).
        $this->tenantConnectionContext->setClubId($clubId);

        // Season AFTER the GUC: the season lookups query the RLS-protected
        // season table — before the GUC they always found 0 rows and the
        // header-less frontend flow lost its _season_id.
        $seasons = $this->seasonResolver->seasonsForClub($clubId);

        $explicitSeasonId = $this->resolveExplicitSeasonId($request);
        if (null !== $explicitSeasonId) {
            $season = $this->findClubSeason($explicitSeasonId, $clubId);
            // Unknown, malformed or foreign-club season → 403, never a silent
            // fallback (mirror of the club membership check above). RLS
            // already hides other clubs' seasons from the lookup.
            if (!$season instanceof Season) {
                // Distinct signal so the frontend self-heals a STALE selected
                // season (drop the header, reload) without conflating it with a
                // legitimate authorization 403 (role/voter denials).
                $event->setResponse(new JsonResponse(
                    ['error' => 'Vous n\'avez pas accès à cette saison.'],
                    403,
                    [self::STALE_SEASON_HEADER => '1'],
                ));

                return;
            }
            $readonly = SeasonResolver::isReadonlyAmong($season, $seasons, $this->clock->now());
        } else {
            // Current season derived from the calendar (SeasonResolver) —
            // replaces the historical single `status='active'` lookup. The
            // current season is writable by definition. "Now" comes from the
            // clock so the dev simulator can shift the July-15 pivot.
            $season = SeasonResolver::currentAmong($seasons, $this->clock->now());
            $readonly = false;
        }

        if ($season instanceof Season) {
            $request->attributes->set('_season_id', $season->getId());
            $request->attributes->set('_season_readonly', $readonly);

            /** @var SeasonFilter $seasonFilter */
            $seasonFilter = $filterCollection->enable('season_filter');
            $seasonFilter->setParameter('season_id', $season->getId(), 'uuid');
        }
    }

    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * Loads a season by id and checks it belongs to the resolved club.
     * Pre-validates the UUID shape: a malformed id must NOT reach Postgres
     * (an "invalid input syntax for type uuid" error would abort the
     * surrounding transaction under the test harness).
     */
    private function findClubSeason(string $seasonId, string $clubId): ?Season
    {
        if (!$this->isUuid($seasonId)) {
            return null;
        }

        $season = $this->seasonRepository->find($seasonId);
        if (null === $season || $season->getClubId() !== $clubId) {
            return null;
        }

        return $season;
    }

    private function authenticatedUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface) {
            return null;
        }

        $user = $token->getUser();

        return $user instanceof User ? $user : null;
    }

    private function resolveClubId(Request $request, ?User $user): ?string
    {
        $clubId = $request->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        // Fallback: the authenticated user's single active membership.
        if ($user instanceof User) {
            // AUD-BCK-10 — ORDRE EXPLICITE. Sans lui, `findOneBy` laissait PostgreSQL
            // choisir : deux appels identiques pouvaient rendre deux clubs différents
            // (l'ordre d'un SELECT sans ORDER BY n'est garanti par rien, et change au gré
            // des mises à jour de pages). Sans objet tant qu'un gestionnaire n'a qu'un
            // club — mais ce repli est la frontière TENANT : le jour du multi-club, il
            // déciderait au hasard quelles données le gestionnaire voit, sans erreur ni
            // trace. On fige donc l'adhésion la PLUS ANCIENNE : stable, et c'est le club
            // d'origine du compte.
            //
            // ⚠ Ce n'est pas le choix DÉFINITIF du multi-club (P4-8) : c'est un
            // déterminisme, pas une règle produit. Le jour où le gestionnaire choisit son
            // club, ce repli disparaît au profit de son choix.
            $membership = $this->clubUserRepository->findOneBy(
                ['userId' => $user->getId(), 'isActive' => true],
                ['createdAt' => 'ASC', 'id' => 'ASC'],
            );
            if (null !== $membership) {
                return $membership->getClubId();
            }
        }

        return null;
    }

    private function resolveExplicitSeasonId(Request $request): ?string
    {
        $seasonId = $request->attributes->get('_season_id');
        if (\is_string($seasonId) && '' !== $seasonId) {
            return $seasonId;
        }

        $seasonId = $request->headers->get('X-Season-Id');
        if (\is_string($seasonId) && '' !== $seasonId) {
            return $seasonId;
        }

        return null;
    }
}
