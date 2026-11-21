<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Access\AccessDeclarationResolver;
use App\Access\ClubFunction;
use App\Access\Output;
use App\Service\ManagementAccessGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * P4-45 — les deux caps MÉTIER, comptés PAR CLUB.
 *
 * ⚑ Pourquoi une seconde clé alors que `ApiRateLimitSubscriber` existe déjà : les deux
 * bornent des choses différentes, et les confondre casserait l'une ou l'autre.
 *
 *  · `api` (300/min, PAR UTILISATEUR) est une borne **anti-emballement d'un client** : un
 *    onglet qui boucle, un script qui déraille. La passer par club punirait un club à trois
 *    gestionnaires sur de la navigation ordinaire.
 *  · Ici on borne une ressource **partagée et coûteuse** — le solveur CP-SAT (jusqu'à 600 s
 *    de CPU) et le worker Puppeteer. Un club ne doit pas obtenir **trois fois** le budget du
 *    solveur parce qu'il a trois gestionnaires : c'est le club qui consomme, pas la personne.
 *
 * ⚠ **Le seau se lit sur la sortie DÉCLARÉE de la fonction** (`ClubFunction::$output`), jamais sur
 * une liste de routes : toute fonction qui déclare un solve (`/generate`, `/regenerate`, `/fill` —
 * le work-loop passe par les deux dernières) puise dans le cap de génération, et une sortie
 * déclarée demain ne peut pas y échapper. « Charger une version » (`/regenerate-from`) ne lance
 * aucun calcul : elle ne déclare pas de sortie et n'entame pas le cap.
 *
 * ⚠ L'export **tableur** a son seau (`schedule_xlsx_export`, 10/h comme le PDF du planning) : il se
 * génère en mémoire, sans worker, mais il est ouvert à tout Membre et prend un crédit Découverte du
 * club — sans seau, un Membre viderait le pool du club en quelques secondes.
 *
 * ⚠ Le placement automatique des matchs a son PROPRE seau (`match_placement`) : un run mobilise le
 * solveur jusqu'à 45 min, et il ne doit ni manger le budget de génération ni l'inverse. Compté
 * avant le contrôleur : une demande refusée (409 compris) reste une demande.
 *
 * ⚠ Un solve — génération OU placement des matchs — n'est compté que pour un GESTIONNAIRE actif :
 * le refus 403 d'un Membre (contrôle central, priorité 4, en aval) ne doit jamais entamer le cap du club —
 * sinon dix clics d'un Membre priveraient le club de génération ou de placement
 * (`MemberReadsAreSideEffectFreeTest`). Les exports, ouverts à tout membre, restent comptés pour
 * tous.
 *
 * Priorité 5 : APRÈS `TenantFilterListener` (7), qui résout le club et pose `_club_id` —
 * sans quoi il n'y aurait rien à compter.
 */
final class ClubQuotaSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactory $scheduleGenerationLimiter,
        private readonly RateLimiterFactory $schedulePdfExportLimiter,
        private readonly RateLimiterFactory $fixturesPdfExportLimiter,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly RateLimiterFactory $matchPlacementLimiter,
        private readonly AccessDeclarationResolver $resolver,
        private readonly RateLimiterFactory $scheduleXlsxExportLimiter,
    ) {}

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $declaration = $this->resolver->for($request);
        $output = $declaration instanceof ClubFunction && !\in_array($request->getMethod(), ['GET', 'HEAD'], true)
            ? $declaration->output
            : null;

        $limiter = match ($output) {
            Output::SOLVE => $this->scheduleGenerationLimiter,
            Output::PLANNING_PDF => $this->schedulePdfExportLimiter,
            Output::MATCHES_PDF => $this->fixturesPdfExportLimiter,
            Output::MATCH_PLACEMENT => $this->matchPlacementLimiter,
            Output::PLANNING_XLSX => $this->scheduleXlsxExportLimiter,
            null => null,
        };

        if (!$limiter instanceof RateLimiterFactory) {
            return;
        }

        $clubId = $request->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            // Pas de club résolu = pas de tenant (route publique, ou identité non encore
            // établie). Rien à compter ici ; les gardes d'autorisation en aval refuseront.
            return;
        }

        if (($limiter === $this->scheduleGenerationLimiter || $limiter === $this->matchPlacementLimiter) && !$this->managementAccessGuard->isManager()) {
            return; // le contrôle central refusera (403) sans rien consommer du cap du club
        }

        $limit = $limiter->create('club-' . $clubId)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter, \sprintf('Quota du club atteint pour cette opération. Réessayez dans %d minute(s).', (int) ceil($retryAfter / 60)));
        }
    }
}
