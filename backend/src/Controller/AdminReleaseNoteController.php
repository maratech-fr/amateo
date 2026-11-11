<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ReleaseNote;
use App\Entity\SuperAdmin;
use App\Exception\InvalidRichDocException;
use App\Repository\ReleaseNoteRepository;
use App\Security\AdminSessionCsrf;
use App\Service\ReleaseNoteRichDoc;
use App\Service\TextSanity;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * P5-12 — l'atelier superadmin du journal de nouveautés : lister (brouillons
 * inclus), créer, publier, supprimer. `release_note` est GLOBALE (no
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

    /** 2 à 4 segments numériques (« 1.3 », « 1.3.5 », « 1.3.5.6 ») : au plus 39 caractères, colonne 40. */
    private const VERSION_PATTERN = '/^\d{1,9}(?:\.\d{1,9}){1,3}$/D';

    public function __construct(
        private ReleaseNoteRepository $releaseNotes,
        private EntityManagerInterface $entityManager,
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
        private ClockInterface $clock,
        private ReleaseNoteRichDoc $richDoc,
        private TextSanity $sanity,
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

        // Borne AVANT tout décodage : nginx accepte 20 Mo, une note n'en demande jamais autant.
        if (\strlen($request->getContent()) > ReleaseNoteRichDoc::MAX_RAW_BYTES) {
            return new JsonResponse(['error' => 'Corps de requête trop volumineux.'], 400);
        }
        $payload = $this->decode($request);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'Corps de requête invalide.'], 400);
        }
        $error = $this->validate($payload);
        if (null !== $error) {
            return new JsonResponse(['error' => $error], 400);
        }
        $content = $this->content($payload);
        if (\is_string($content)) {
            return new JsonResponse(['error' => $content], 400);
        }

        $note = new ReleaseNote;
        $note->setTitle(trim((string) $payload['title']));
        $note->setBody($content['body']);
        $note->setBodyRich($content['bodyRich']);
        $note->setNoteDate($this->parseDate((string) $payload['noteDate']));
        $note->setProductVersion($this->normalizeVersion($payload['version'] ?? null));
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($note), 201);
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
            'version' => $note->getProductVersion(),
            'bodyRich' => $note->getBodyRich(),
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
        if ($this->sanity->hasForbiddenChars($title)) {
            return 'Le titre contient des caractères non autorisés.';
        }
        if (!\is_string($payload['noteDate'] ?? null) || !$this->isValidDate($payload['noteDate'])) {
            return 'La date est invalide (format attendu : AAAA-MM-JJ).';
        }
        if (!$this->isValidVersion($payload['version'] ?? null)) {
            return 'La version est invalide (format attendu : 1.3.5).';
        }

        return null;
    }

    /**
     * Le corps de la note. `bodyRich` présent et non null → validé par la liste blanche, `body` en
     * est DÉRIVÉ (un `body` envoyé avec lui est ignoré) ; sinon (ancien bundle, note texte) `body`
     * est requis, borné à 10 000 caractères, sans caractère de contrôle ni bidi — ses sauts de ligne
     * (`\n`, `\r\n`) restent permis : un corps brut est multi-ligne par nature.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array{body: string, bodyRich: array<string, mixed>|null}|string le corps, ou le message du 400
     */
    private function content(array $payload): array|string
    {
        if (null !== ($payload['bodyRich'] ?? null)) {
            try {
                $bodyRich = $this->richDoc->validate($payload['bodyRich']);
            } catch (InvalidRichDocException $refused) {
                return $refused->getMessage();
            }
            $body = trim($this->richDoc->toPlainText($bodyRich));

            return '' === $body ? 'Le contenu est requis.' : ['body' => $body, 'bodyRich' => $bodyRich];
        }

        $body = \is_string($payload['body'] ?? null) ? trim($payload['body']) : '';
        if ('' === $body) {
            return 'Le contenu est requis.';
        }
        if (mb_strlen($body) > ReleaseNoteRichDoc::MAX_TEXT) {
            return 'Le contenu dépasse 10 000 caractères.';
        }
        if ($this->sanity->hasForbiddenChars(str_replace(["\r\n", "\n"], '', $body))) {
            return 'Le contenu contient des caractères non autorisés.';
        }

        return ['body' => $body, 'bodyRich' => null];
    }

    /**
     * Version facultative : absente, null ou vide = note sans version ; sinon une chaîne au motif
     * VERSION_PATTERN une fois normalisée (tout autre type JSON est refusé).
     */
    private function isValidVersion(mixed $raw): bool
    {
        if (null === $raw) {
            return true;
        }
        if (!\is_string($raw)) {
            return false;
        }
        $version = $this->normalizeVersion($raw);

        return null === $version || 1 === preg_match(self::VERSION_PATTERN, $version);
    }

    /** Espaces et « v »/« V » de tête retirés (« v1.3.5 » → « 1.3.5 ») ; vide ou non-chaîne → null. */
    private function normalizeVersion(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }
        $version = trim($raw);
        if (str_starts_with($version, 'v') || str_starts_with($version, 'V')) {
            $version = substr($version, 1);
        }

        return '' === $version ? null : $version;
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
