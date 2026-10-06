<?php

declare(strict_types=1);

namespace App\Sentry;

use App\Entity\Club;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Throwable;

/**
 * Filtre `before_send` du SDK Sentry : écarte les REFUS HTTP NORMAUX, puis pose sur l'événement
 * l'identité MINIMALE qui permet de rejouer un bug sans PII.
 *
 * FILTRE (2026-10-03) : un scan Nuclei a rempli Sentry de 404/405/400/401/403 — du bruit de
 * robot, aucune vraie erreur. On abandonne silencieusement toute exception HTTP CLIENT (statut
 * 4xx) : `NotFoundHttpException`, `MethodNotAllowedHttpException`, `BadRequestHttpException`,
 * `AccessDeniedHttpException`, `UnauthorizedHttpException`… — toutes implémentent
 * {@see HttpExceptionInterface}, le filtre se fait donc sur LE STATUT, pas sur une liste fermée de
 * classes. Les 5xx passent (vraie panne serveur), de même que les échecs Messenger et les erreurs
 * de commande console (ni l'un ni l'autre ne portent de statut HTTP < 500).
 *
 * L'`AccessDeniedException` de SÉCURITÉ (`Symfony\Component\Security\Core\Exception`) n'est PAS une
 * HttpException (aucun statut à filtrer) : elle est écartée via `ignore_exceptions` dans
 * `config/packages/sentry.yaml`.
 *
 * IDENTITÉ (décision fondateur 2026-10-06) : chaque événement ENVOYÉ porte l'identifiant INTERNE de
 * l'utilisateur courant (`UserDataBag` id seul — JAMAIS email/nom/IP, cohérent avec
 * `send_default_pii: false`) et le CODE FFBB du club résolu par la requête en tag `club_ffbb`
 * (ex. `ARA0069013`). Miroir exact du duo posé côté front (`useApplySentryIdentity`). Rien d'autre.
 * Mêmes sources que le processor de logs (`App\Logging\RequestContextProcessor`) : le token de
 * sécurité (jamais un `SuperAdmin`, qui n'est pas un `User`) et l'attribut `_club_id` posé par
 * `TenantFilterListener` — ABSENT sur `/api/admin/**` (le listener sort avant toute résolution de
 * club), donc un superadmin ne pose jamais de tag club. Hors requête (Messenger, console), il n'y a
 * ni token ni `_club_id` : on ne pose rien.
 */
final readonly class BeforeSend
{
    public function __construct(
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
        private EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        $this->tagIdentity($event);

        return $event;
    }

    private function tagIdentity(Event $event): void
    {
        $userId = $this->currentUserId();
        if (null !== $userId) {
            // Id INTERNE seul : createFromUserIdentifier ne pose que `id` (email/ip/username null),
            // et send_default_pii=false empêche le SDK d'ajouter une IP automatique.
            $event->setUser(UserDataBag::createFromUserIdentifier($userId));
        }

        $clubCode = $this->currentClubFfbbCode();
        if (null !== $clubCode) {
            $event->setTag('club_ffbb', $clubCode);
        }
    }

    private function currentUserId(): ?string
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface) {
            return null;
        }

        $user = $token->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    private function currentClubFfbbCode(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $clubId = $request->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return null;
        }

        try {
            // Lecture best-effort : un événement Sentry naît souvent d'une 5xx, parfois d'une
            // transaction DBAL déjà avortée où toute requête jetterait — on ne laisse jamais
            // l'enrichissement masquer l'erreur d'origine ni faire échouer l'envoi.
            $code = $this->entityManager->find(Club::class, $clubId)?->getFfbbClubCode();
        } catch (Throwable) {
            return null;
        }

        return \is_string($code) && '' !== $code ? $code : null;
    }
}
