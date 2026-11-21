<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\Club;
use App\Entity\Season;
use App\Security\PublicApiRouteCatalog;
use App\Service\ManagementAccessGuard;
use App\Service\OutputCreditLedger;
use App\Service\PlanEntitlements;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le contrôle central du rôle : refuse en 403 une fonction « gestionnaire » appelée par un Membre,
 * et toute route `^/api/` qui ne dit pas à qui elle est ouverte. Puis, pour une fonction qui
 * déclare une sortie payante (`ClubFunction::$output`), le refus « crédits épuisés » d'un club
 * Découverte à sec — APRÈS le rôle : un Membre reçoit le refus du rôle, jamais celui des crédits.
 *
 * Place : `kernel.request`, priorité 4 — après le routeur (32), le pare-feu (8), le locataire (7,
 * qui a posé `_club_id` et la saison), le débit (6), les caps (5) ; avant `kernel.controller`
 * (garde « saison archivée ») et avant tout contrôleur : rien n'est lu ni écrit pour un Membre
 * refusé, rien n'est mis en file pour un club à sec. Requête principale seulement : la sous-requête
 * du rendu d'erreur d'API Platform n'est pas contrôlée une seconde fois.
 *
 * Ordre de lecture d'une route `^/api/` : console (`/api/admin/`, son propre pare-feu), dev
 * (`/api/dev/`), cadre d'API Platform, publique (`PublicApiRouteCatalog`), `api_login` (porte du
 * pare-feu `login`, sans contrôleur), puis la déclaration — absente, refusée à tous.
 *
 * Méthode : celle que le routeur a vue (`Request::getMethod()`, en-tête de surcharge compris sur un
 * POST) ; GET et HEAD lisent, toute autre méthode écrit. Une fonction « compte »
 * (`#[AccountFunction]`) n'est pas contrôlée ici : elle ne dépend d'aucun club.
 *
 * Décompte : une sortie rendue dans la requête (`Output::settledAtResponse()`) prend son crédit à
 * la réponse 2xx (`kernel.response`) ; un refus amont (409, 422) ne brûle rien. Les autres sorties
 * se décomptent dans le worker, à l'obtention ({@see OutputCreditLedger}).
 */
final class ClubAccessListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly AccessDeclarationResolver $resolver,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly PlanEntitlements $planEntitlements,
        private readonly EntityManagerInterface $entityManager,
        private readonly OutputCreditLedger $creditLedger,
        private readonly Connection $connection,
    ) {}

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4],
            KernelEvents::RESPONSE => ['onKernelResponse', 5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/')) {
            return;
        }

        $method = $request->getMethod();
        if (str_starts_with($path, '/api/admin/')
            || str_starts_with($path, '/api/dev/')
            || $this->resolver->isFrameworkRoute($request)
            || $this->isApiPlatformRoute($request)
            || PublicApiRouteCatalog::matches($path, $method)
            || 'api_login' === $request->attributes->get('_route')) {
            return;
        }

        $declaration = $this->resolver->for($request);
        if (null === $declaration) {
            throw new AccessDeniedHttpException(ManagementAccessGuard::REFUSAL);
        }

        if (Audience::MANAGER === $this->audienceOf($declaration, $method) && !$this->managementAccessGuard->isManager()) {
            throw new AccessDeniedHttpException(ManagementAccessGuard::REFUSAL);
        }

        if ($this->outputOf($declaration, $method) instanceof Output) {
            $budget = $this->budgetFor($request);
            if (null !== $budget && $budget['restricted'] && $budget['used'] >= $budget['max']) {
                throw new AccessDeniedHttpException(\sprintf('Les %d générations gratuites de votre club sont utilisées — l\'offre Essentiel les rend illimitées.', $budget['max']));
            }
        }
    }

    /** Le crédit d'une sortie rendue dans la requête se prend à la réponse 2xx. */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $declaration = $this->resolver->for($request);
        if (null === $declaration || true !== $this->outputOf($declaration, $request->getMethod())?->settledAtResponse()) {
            return;
        }

        $clubId = $request->attributes->get('_club_id');
        $seasonId = $request->attributes->get('_season_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return;
        }

        $this->connection->transactional(fn (): bool => $this->creditLedger->settle($clubId, \is_string($seasonId) ? $seasonId : null));
    }

    /** Une route servie par le cadre lui-même (contrôleur sous `ApiPlatform\`). */
    private function isApiPlatformRoute(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        return \is_string($controller) && str_starts_with($controller, 'ApiPlatform\\');
    }

    private function audienceOf(ClubFunction|ClubResource|AccountFunction $declaration, string $method): ?Audience
    {
        $read = \in_array($method, ['GET', 'HEAD'], true);

        return match (true) {
            $declaration instanceof ClubFunction => $declaration->audience,
            $declaration instanceof ClubResource => $read ? $declaration->read : $declaration->write,
            default => null,
        };
    }

    /** La sortie payante d'une écriture de club ; une lecture n'en produit jamais. */
    private function outputOf(ClubFunction|ClubResource|AccountFunction $declaration, string $method): ?Output
    {
        if (!$declaration instanceof ClubFunction || \in_array($method, ['GET', 'HEAD'], true)) {
            return null;
        }

        return $declaration->output;
    }

    /**
     * @return array{restricted: bool, max: int, used: int}|null null = pas de club ou de saison
     *                                                           résolu : rien à borner ici
     */
    private function budgetFor(Request $request): ?array
    {
        $clubId = $request->attributes->get('_club_id');
        $seasonId = $request->attributes->get('_season_id');
        if (!\is_string($clubId) || '' === $clubId || !\is_string($seasonId) || '' === $seasonId) {
            return null;
        }

        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
        if (!$club instanceof Club || !$season instanceof Season) {
            return null;
        }

        return $this->planEntitlements->outputBudget($club, $season);
    }
}
