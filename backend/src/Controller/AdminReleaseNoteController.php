<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ReleaseNote;
use App\Entity\SuperAdmin;
use App\Repository\ReleaseNoteRepository;
use App\Security\AdminSessionCsrf;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * P5-12 — l'atelier superadmin du journal de nouveautés : lister (brouillons
 * inclus), créer, éditer, publier, supprimer. `release_note` est GLOBALE (no
 * RLS) donc l'EntityManager par défaut l'écrit via le GRANT amateo_app — aucune
 * connexion admin dédiée n'est requise (contrairement à club_user, tenant-scopé).
 *
 * Gardes = patron AdminClubRequestController : firewall admin (session) + CSRF
 * sur les écritures + identité SuperAdmin ; contexte d'audit posé AVANT toute
 * garde (une tentative refusée doit tracer sa cible). L'audit SA0 capte tout
 * seul (AdminAuditSubscriber). Textes d'erreur français, sans identifiant interne.
 */
#[Route('/api/admin/release-notes')]
final readonly class AdminReleaseNoteController
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(
        private ReleaseNoteRepository $releaseNotes,
        private EntityManagerInterface $entityManager,
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
        private ClockInterface $clock,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(['items' => array_map(
            $this->serialize(...),
            $this->releaseNotes->findAllForAdmin(),
        )]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['action' => 'release-note.create']);
        $guard = $this->guard($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $payload = $this->decode($request);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'Corps de requête invalide.'], 400);
        }
        $error = $this->validate($payload);
        if (null !== $error) {
            return new JsonResponse(['error' => $error], 400);
        }

        $note = new ReleaseNote;
        $note->setTitle(trim((string) $payload['title']));
        $note->setBody(trim((string) $payload['body']));
        $note->setNoteDate($this->parseDate((string) $payload['noteDate']));
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($note), 201);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['action' => 'release-note.update', 'id' => $id]);
        $guard = $this->guard($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }
        $note = $this->find($id);
        if (!$note instanceof ReleaseNote) {
            return new JsonResponse(['error' => 'Note introuvable.'], 404);
        }

        $payload = $this->decode($request);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'Corps de requête invalide.'], 400);
        }
        $error = $this->validate($payload);
        if (null !== $error) {
            return new JsonResponse(['error' => $error], 400);
        }

        $note->setTitle(trim((string) $payload['title']));
        $note->setBody(trim((string) $payload['body']));
        $note->setNoteDate($this->parseDate((string) $payload['noteDate']));
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($note));
    }

    #[Route('/{id}/publish', methods: ['POST'])]
    public function publish(string $id, Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['action' => 'release-note.publish', 'id' => $id]);
        $guard = $this->guard($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }
        $note = $this->find($id);
        if (!$note instanceof ReleaseNote) {
            return new JsonResponse(['error' => 'Note introuvable.'], 404);
        }
        if ($note->isPublished()) {
            return new JsonResponse(['error' => 'Cette note est déjà publiée.'], 409);
        }

        $note->setPublishedAt($this->clock->now());
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($note));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['action' => 'release-note.delete', 'id' => $id]);
        $guard = $this->guard($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }
        $note = $this->find($id);
        if (!$note instanceof ReleaseNote) {
            return new JsonResponse(['error' => 'Note introuvable.'], 404);
        }

        $this->entityManager->remove($note);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function serialize(ReleaseNote $note): array
    {
        return [
            'id' => $note->getId(),
            'title' => $note->getTitle(),
            'body' => $note->getBody(),
            'date' => $note->getNoteDate()->format('Y-m-d'),
            'publishedAt' => $note->getPublishedAt()?->format(DateTimeImmutable::ATOM),
            'createdAt' => $note->getCreatedAt()->format(DateTimeImmutable::ATOM),
        ];
    }

    private function find(string $id): ?ReleaseNote
    {
        if (1 !== preg_match(self::UUID_PATTERN, $id)) {
            return null;
        }

        return $this->releaseNotes->find($id);
    }

    /** @param array<array-key, mixed> $payload */
    private function validate(array $payload): ?string
    {
        $title = \is_string($payload['title'] ?? null) ? trim($payload['title']) : '';
        if ('' === $title) {
            return 'Le titre est requis.';
        }
        if (mb_strlen($title) > 160) {
            return 'Le titre ne doit pas dépasser 160 caractères.';
        }
        $body = \is_string($payload['body'] ?? null) ? trim($payload['body']) : '';
        if ('' === $body) {
            return 'Le contenu est requis.';
        }
        if (!\is_string($payload['noteDate'] ?? null) || !$this->isValidDate($payload['noteDate'])) {
            return 'La date est invalide (format attendu : AAAA-MM-JJ).';
        }

        return null;
    }

    private function isValidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        \assert($date instanceof DateTimeImmutable);

        return $date;
    }

    /** @return array<array-key, mixed>|null */
    private function decode(Request $request): ?array
    {
        $raw = trim($request->getContent());
        if ('' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /** CSRF + identité SuperAdmin — l'appelant a posé le contexte d'audit AVANT. */
    private function guard(Request $request): ?JsonResponse
    {
        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }
        $admin = $this->tokens->getToken()?->getUser();
        if (!$admin instanceof SuperAdmin) {
            return new JsonResponse(['error' => 'Unauthorized.'], 401);
        }
        $request->attributes->set('_admin_audit_actor_id', $admin->getId());

        return null;
    }
}
