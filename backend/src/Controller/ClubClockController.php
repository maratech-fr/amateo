<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Service\ClubMailboxPurgerInterface;
use App\Service\ManagementAccessGuard;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Horloge simulée d'un club de DÉMONSTRATION, posée depuis l'app par son gestionnaire
 * (widget d'en-tête) : {date} pose l'« aujourd'hui » simulé, {clear:true} relâche.
 *
 * Décision fondateur 2026-10-02 : l'horloge simulée ne vit QUE pour un compte démo — un vrai
 * club est REFUSÉ (403). Décaler son « aujourd'hui » lui donnerait la main sur des mécanismes
 * datés qui ne le concernent pas (radar, bascule de saison, e-mails).
 *
 * Tenant résolu côté serveur depuis le JWT (`_club_id`), jamais depuis la requête ; réservé
 * aux rôles de gestion (SEC-07). `clear` relâche l'horloge ET vide la boîte aux lettres via la
 * maison unique {@see ClubMailboxPurgerInterface}. Après l'écriture, le front invalide `/api/me`
 * (→ `clock.ts`) et tous les écrans datés se recalent.
 */
final class ClubClockController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly ClubMailboxPurgerInterface $mailboxPurger,
    ) {}

    #[Route('/api/club/clock', name: 'api_club_clock', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // SEC-07 : réservé aux gestionnaires (403 sinon). L'appartenance active au club de
        // contexte est déjà prouvée par le listener tenant (le club vient du JWT — AUD-SEC-25).
        $this->managementAccessGuard->assertManager();

        $clubId = $this->resolveCurrentClubId();
        $club = null === $clubId ? null : $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            return $this->json(['error' => 'Club introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // RÉSERVÉ aux clubs de démonstration (décision fondateur) : un vrai club est refusé,
        // AVANT toute lecture du corps — un club réel ne peut jamais recevoir d'horloge simulée.
        if (!$club->isDemo()) {
            return $this->json(['error' => 'L\'horloge simulée est réservée aux comptes de démonstration.'], Response::HTTP_FORBIDDEN);
        }

        $parsed = $this->parseBody($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        [$date, $clear] = $parsed;

        $club->setSimulatedToday(null === $date ? null : new DateTimeImmutable($date));
        $this->entityManager->flush();

        // Relâcher l'horloge VIDE la boîte aux lettres : hors horloge le club redevient un club
        // qui envoie pour de vrai, les e-mails boxés n'ont plus de raison d'être. Poser/changer
        // une date ne touche jamais la boîte.
        if ($clear) {
            $this->mailboxPurger->purge($club->getId());
        }

        return $this->json(['simulatedToday' => $date]);
    }

    /**
     * Valide le corps { date } | { clear:true } : exactement l'un des deux, et une date RÉELLE
     * qui se relit à l'identique (2026-02-31 « parse » en 3 mars — refusée). Retourne
     * [?string $date, bool $clear], ou la réponse d'erreur (400 JSON illisible, 422 date/clear).
     *
     * @return array{0: string|null, 1: bool}|JsonResponse
     */
    private function parseBody(Request $request): array|JsonResponse
    {
        $raw = trim($request->getContent());
        try {
            $body = '' === $raw ? [] : json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->json(['error' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (!\is_array($body)) {
            return $this->json(['error' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $clear = true === ($body['clear'] ?? null);
        $rawDate = $body['date'] ?? null;
        if ($clear === \is_string($rawDate)) {
            return $this->json(['error' => 'Fournir une date (YYYY-MM-DD) ou clear, pas les deux.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $date = null;
        if (\is_string($rawDate)) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
            if (false === $parsed || $parsed->format('Y-m-d') !== $rawDate) {
                return $this->json(['error' => 'Date invalide (attendu YYYY-MM-DD).'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $date = $rawDate;
        }

        return [$date, $clear];
    }

    private function resolveCurrentClubId(): ?string
    {
        $clubId = $this->requestStack->getCurrentRequest()?->attributes->get('_club_id');

        return \is_string($clubId) && '' !== $clubId ? $clubId : null;
    }
}
