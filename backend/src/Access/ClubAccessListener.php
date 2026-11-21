<?php

declare(strict_types=1);

namespace App\Access;

use App\Controller\ClubInvitationController;
use App\Entity\Club;
use App\Entity\Season;
use App\Entity\User;
use App\Security\PublicApiRouteCatalog;
use App\Service\ManagementAccessGuard;
use App\Service\OutputCreditLedger;
use App\Service\PlanEntitlements;
use App\Service\SocleGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le contrôle central : refuse en 403 toute route `^/api/` qui ne dit pas à qui elle est ouverte,
 * puis, dans cet ordre, ce que la déclaration de la fonction dit :
 *  1. rôle (403) — une fonction « gestionnaire » appelée par un Membre ;
 *  2. démonstration (403) — un envoi à un tiers (`ClubFunction::$thirdParty`) depuis un club ou
 *     un compte de démonstration ({@see DemoRegime}), avec le texte de la fonction ;
 *  3. module fermé (409, message de {@see SocleGuard}) — une fonction refusée module fermé
 *     (`ClubFunction::$refusedWhenModuleClosed`) d'un module qui exige le socle en vigueur
 *     ({@see Module::requiresSocleInForce()}), saison lue sur `_season_id` ;
 *  4. crédit (403) — une sortie payante (`ClubFunction::$output`) d'un club Découverte à sec.
 * Un Membre reçoit donc le refus du rôle, jamais celui de la démonstration ou des crédits.
 * Une fonction « compte » (`#[AccountFunction]`) ne dépend d'aucun club : seul son refus en
 * démonstration (`protectedInDemo`) se décide ici.
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
 * POST) ; GET et HEAD lisent, toute autre méthode écrit.
 *
 * Décompte : une sortie rendue dans la requête (`Output::settledAtResponse()`) prend son crédit à
 * la réponse 2xx (`kernel.response`) ; un refus amont (409, 422) ne brûle rien. Les autres sorties
 * se décomptent dans le worker, à l'obtention ({@see OutputCreditLedger}).
 */
final class ClubAccessListener implements EventSubscriberInterface
{
    /** Refus d'un envoi à un tiers en démonstration, par défaut (mails aux coachs). */
    private const string DEMO_MAIL_REFUSAL = 'Envoi d\'e-mails indisponible en démonstration.';

    /** Les fonctions dont le refus en démonstration a son propre texte (décision Q12 : trois textes gardés). */
    private const array DEMO_SEND_REFUSALS = [
        ClubInvitationController::class => 'Un compte de démonstration ne peut pas inviter.',
    ];

    /** Refus d'un geste de compte protégé en démonstration. */
    private const string DEMO_ACCOUNT_REFUSAL = 'Ce compte de démonstration ne peut pas être modifié.';

    public function __construct(
        private readonly AccessDeclarationResolver $resolver,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly PlanEntitlements $planEntitlements,
        private readonly EntityManagerInterface $entityManager,
        private readonly OutputCreditLedger $creditLedger,
        private readonly Connection $connection,
        private readonly DemoRegime $demoRegime,
        private readonly SocleGuard $socleGuard,
        private readonly Security $security,
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

        if ($declaration instanceof AccountFunction) {
            $user = $this->security->getUser();
            if ($declaration->protectedInDemo && $user instanceof User && $this->demoRegime->protectsAccount($user)) {
                throw new AccessDeniedHttpException(self::DEMO_ACCOUNT_REFUSAL);
            }

            return;
        }

        if (Audience::MANAGER === $this->audienceOf($declaration, $method) && !$this->managementAccessGuard->isManager()) {
            throw new AccessDeniedHttpException(ManagementAccessGuard::REFUSAL);
        }

        if ($declaration instanceof ClubFunction && $declaration->thirdParty) {
            $club = $this->clubOf($request);
            $user = $this->security->getUser();
            if ($club instanceof Club && $this->demoRegime->forbidsThirdPartySend($club, $user instanceof User ? $user : null)) {
                throw new AccessDeniedHttpException($this->demoSendRefusal($request));
            }
        }

        if ($declaration instanceof ClubFunction && $declaration->refusedWhenModuleClosed && $declaration->module->requiresSocleInForce()) {
            $seasonId = $request->attributes->get('_season_id');
            $this->socleGuard->assertSeasonPlanChosen(\is_string($seasonId) && '' !== $seasonId ? $seasonId : null);
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

    private function clubOf(Request $request): ?Club
    {
        $clubId = $request->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return null;
        }

        return $this->entityManager->getRepository(Club::class)->find($clubId);
    }

    /** Le texte du refus d'un envoi à un tiers en démonstration : celui de la fonction refusée. */
    private function demoSendRefusal(Request $request): string
    {
        $controller = $request->attributes->get('_controller');
        $class = \is_string($controller) ? explode('::', $controller, 2)[0] : '';

        return self::DEMO_SEND_REFUSALS[$class] ?? self::DEMO_MAIL_REFUSAL;
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
