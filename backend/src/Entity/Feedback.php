<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FeedbackRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un signalement envoyé par un membre depuis l'application (bug, contrainte
 * manquante, idée). Tenant-owned (`club_id`) : un signalement appartient au club
 * de son auteur, sous FORCE RLS comme toute table club_id, et s'efface avec lui
 * (ErasedClubPurger — un club effacé ne garde pas ses signalements).
 *
 * `topic` est une chaîne courte validée SERVEUR (bug/missing_constraint/idea),
 * pas un enum PHP : le jour où l'on ajoute une catégorie, c'est une valeur de
 * plus, pas une migration de type. `context` est un sac JSON : le contexte léger
 * saisi par le client (écran, url, requestId…) et, quand un planning est nommé,
 * la COPIE serveur de son snapshot + diagnostics — figée à la soumission, jamais
 * relue depuis un planning qui aura peut-être disparu quand le support la lira.
 *
 * `status` (untreated/treated) et `treatedAt` sont le rail de traitement côté
 * console superadmin — écrits là, en lecture seule ici.
 */
#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
#[ORM\Table(name: 'feedback')]
#[ORM\Index(name: 'idx_feedback_club_created', columns: ['club_id', 'created_at'])]
#[ORM\Index(name: 'idx_feedback_status', columns: ['status'])]
class Feedback implements TenantOwnedInterface
{
    public const string STATUS_UNTREATED = 'untreated';

    public const string STATUS_TREATED = 'treated';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $userId;

    #[ORM\Column(type: 'string', length: 40)]
    private string $topic;

    #[ORM\Column(type: 'text')]
    private string $message;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_UNTREATED;

    /**
     * Pointeur LÉGER vers la capture d'écran jointe : le blob vit HORS table
     * (FeedbackScreenshotStorage, patron LogoStorage), seul ce drapeau dit à la
     * console superadmin s'il faut proposer l'aperçu.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $hasScreenshot = false;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $treatedAt = null;

    /**
     * Marqueur de PURGE (rétention RGPD, Chantier F PR4) : posé par `app:feedback:purge`
     * 30 jours après « traité » quand le MESSAGE et le CONTEXTE sont détruits. La ligne
     * survit (stats QoS intactes) ; ce marqueur dit à la console « contenu purgé » et rend
     * la purge IDEMPOTENTE (une ligne déjà purgée n'est jamais ré-estampée).
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $purgedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(string $clubId, string $userId, string $topic, string $message)
    {
        $this->id = $this->newUuid();
        $this->clubId = $clubId;
        $this->userId = $userId;
        $this->topic = $topic;
        $this->message = $message;
        $this->createdAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /** @return array<string, mixed>|null */
    public function getContext(): ?array
    {
        return $this->context;
    }

    /** @param array<string, mixed>|null $context */
    public function setContext(?array $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function hasScreenshot(): bool
    {
        return $this->hasScreenshot;
    }

    public function setHasScreenshot(bool $hasScreenshot): self
    {
        $this->hasScreenshot = $hasScreenshot;

        return $this;
    }

    public function getTreatedAt(): ?DateTimeImmutable
    {
        return $this->treatedAt;
    }

    public function getPurgedAt(): ?DateTimeImmutable
    {
        return $this->purgedAt;
    }

    public function setPurgedAt(?DateTimeImmutable $purgedAt): self
    {
        $this->purgedAt = $purgedAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
