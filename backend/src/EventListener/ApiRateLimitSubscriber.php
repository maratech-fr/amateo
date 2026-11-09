<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * SEC-11: rate-limits the authenticated API, keyed per user.
 *
 * The audit measured 100 authenticated GETs in ~1.4 s with zero throttling —
 * only the public auth endpoints were bounded. This subscriber applies the
 * `api` limiter (300/min per user) to every `^/api` request that carries an
 * authenticated user.
 *
 * Priority 6: after the security firewall (8, so the JWT user is resolved) and
 * the TenantFilterListener (7). Public endpoints (login/register/password/
 * health/docs, logo GET) reach here with no `User` and are skipped — they keep
 * their own per-IP limiters. Keying per user (not per IP) means many managers
 * behind one NAT are not penalised for each other, and each test/e2e user gets
 * its own budget.
 */
final class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactory $apiLimiter,
        private readonly Security $security,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $limit = $this->apiLimiter->create('api-user-' . $user->getId())->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter, 'Trop de requêtes d\'affilée. Patientez quelques instants avant de réessayer.');
        }
    }
}
