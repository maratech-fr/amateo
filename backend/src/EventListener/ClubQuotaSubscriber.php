<?php

declare(strict_types=1);

namespace App\EventListener;

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
 * ⚠ **Le cap de génération couvre les QUATRE routes qui déclenchent un solve**, pas seulement
 * `/generate`. `/regenerate`, `/regenerate-from` et `/fill` (le comblement d'une période, P2-44)
 * en lancent un tout autant — et c'est par elles que passe le work-loop, donc l'usage réel. Ne
 * capper que `/generate` aurait donné une borne qu'on contourne sans même le vouloir.
 *
 * ⚠ L'export **XLSX n'est pas capté** : il se génère en mémoire, sans worker. Le PDF, lui,
 * mobilise Puppeteer. Le limiteur global suffit à borner un abus de XLSX ; lui coller un cap
 * horaire gênerait un usage légitime pour rien.
 *
 * Priorité 5 : APRÈS `TenantFilterListener` (7), qui résout le club et pose `_club_id` —
 * sans quoi il n'y aurait rien à compter.
 */
final class ClubQuotaSubscriber implements EventSubscriberInterface
{
    /** Les routes qui déclenchent un solve — toutes, pas seulement la première. */
    private const array SOLVE_ROUTES = [
        'generate_schedule',
        'api_schedule_regenerate',
        'api_schedule_regenerate_from',
        'api_schedule_fill',
    ];

    private const string EXPORT_PDF_ROUTE = 'export_pdf';

    private const string MATCHES_EXPORT_ROUTE = 'api_fixtures_export_pdf';

    public function __construct(
        private readonly RateLimiterFactory $scheduleGenerationLimiter,
        private readonly RateLimiterFactory $schedulePdfExportLimiter,
        private readonly RateLimiterFactory $fixturesPdfExportLimiter,
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
        $route = (string) $request->attributes->get('_route');

        $limiter = match (true) {
            \in_array($route, self::SOLVE_ROUTES, true) => $this->scheduleGenerationLimiter,
            self::EXPORT_PDF_ROUTE === $route => $this->schedulePdfExportLimiter,
            self::MATCHES_EXPORT_ROUTE === $route => $this->fixturesPdfExportLimiter,
            default => null,
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

        $limit = $limiter->create('club-' . $clubId)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter, \sprintf('Quota du club atteint pour cette opération. Réessayez dans %d minute(s).', (int) ceil($retryAfter / 60)));
        }
    }
}
