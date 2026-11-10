<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ReleaseNote;
use App\Entity\User;
use App\Repository\ReleaseNoteRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P5-12 — le journal de nouveautés côté membre. Deux gestes :
 *  - lire les notes PUBLIÉES (tout membre authentifié) + jusqu'où il a lu ;
 *  - marquer « vu » (self-only) — ce que la modale « quoi de neuf » appelle
 *    quand l'utilisateur clique « J'ai compris ».
 *
 * `release_note` est GLOBALE (pas de tenant) : aucune isolation club à poser, le
 * firewall API suffit. Seules les notes publiées sortent — un brouillon reste
 * invisible.
 */
final class ReleaseNotesController extends AbstractController
{
    public function __construct(
        private readonly ReleaseNoteRepository $releaseNotes,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/api/release-notes', name: 'api_release_notes_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $seenUpTo = $user->getReleaseNotesSeenAt();

        return $this->json([
            'seenUpTo' => $seenUpTo?->format(DateTimeImmutable::ATOM),
            'items' => array_map(static fn (ReleaseNote $note): array => [
                'id' => $note->getId(),
                'date' => $note->getNoteDate()->format('Y-m-d'),
                'title' => $note->getTitle(),
                'body' => $note->getBody(),
                // L'instant de publication : la modale s'ouvre sur une note publiée
                // APRÈS `seenUpTo` (la date éditoriale est antidatable, elle ne sert
                // qu'à l'affichage).
                'publishedAt' => $note->getPublishedAt()?->format(DateTimeImmutable::ATOM),
                // Version du produit annoncée (« 1.3.5 »), null pour une note sans version.
                'version' => $note->getProductVersion(),
                // Corps mis en forme (liste blanche serveur), null pour une note texte (`body` seul).
                'bodyRich' => $note->getBodyRich(),
            ], $this->releaseNotes->findPublished()),
        ]);
    }

    /** Marquer le journal comme lu jusqu'à maintenant (self-only par construction). */
    #[Route('/api/release-notes/seen', name: 'api_release_notes_seen', methods: ['POST'])]
    public function markSeen(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $user->setReleaseNotesSeenAt($this->clock->now());
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }
}
