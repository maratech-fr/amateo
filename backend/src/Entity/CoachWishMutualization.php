<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une DEMANDE de mutualisation d'un coach pour une période de vacances (feature #10, lot D2).
 *
 * « Sur mes 2 entraînements, j'en mutualise 1, avec SF1 ou SF3 » : le coach déclare, UNE fois
 * par équipe POUR LA PÉRIODE (pas par semaine, contrairement à CoachWish), les équipes
 * partenaires pressenties et le nombre de séances à partager. C'est une DEMANDE INFORMATIVE
 * dans la todo-list du gestionnaire — JAMAIS une contrainte : le solveur ne la lit pas, rien
 * n'est pré-rempli ; le gestionnaire arbitre.
 *
 * ANCRAGE = l'entrée calendrier MÈRE des vacances (`calendarEntryId`, jamais un enfant, jamais
 * un plan) — parité CoachWish : la demande SURVIT à la suppression de la campagne de collecte,
 * la todo-list reste « celle des vacances ».
 *
 * `coachId` est NULLABLE : supprimer un coach DÉ-ATTRIBUE sa demande (l'info d'équipe reste
 * utile au plan) plutôt que de la détruire — parité CoachWish.
 *
 * `partnerTeamIds` est purement INFORMATIF : une équipe partenaire supprimée depuis n'est pas
 * nettoyée de ce tableau (il n'a aucune intégrité référentielle, aucun effet solveur) — elle
 * est simplement ignorée à l'affichage, qui résout les ids en noms depuis les équipes vivantes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'coach_wish_mutualization')]
#[ORM\UniqueConstraint(name: 'uniq_coach_wish_mutualization', columns: ['calendar_entry_id', 'team_id'])]
#[ORM\Index(name: 'idx_coach_wish_mutualization_entry', columns: ['calendar_entry_id'])]
class CoachWishMutualization implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    /** La MÈRE des vacances (kind=PERIOD, periodType=HOLIDAY, parentEntryId=null). */
    #[ORM\Column(type: 'guid')]
    private string $calendarEntryId;

    #[ORM\Column(type: 'guid')]
    private string $teamId;

    /** null = demande dé-attribuée (le coach a été supprimé). */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $coachId = null;

    /**
     * Équipes partenaires pressenties (uuid). Informatif : aucun effet solveur, aucune
     * intégrité référentielle — une équipe supprimée n'est jamais retirée d'ici, elle est
     * ignorée à l'affichage.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $partnerTeamIds = [];

    /** Nombre de séances à mutualiser (1–7 ; borné à la saisie). */
    #[ORM\Column(type: 'smallint')]
    private int $sharedSlots = 1;

    /** Coche « traité » du gestionnaire (barre l'item dans la todo-list). */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $done = false;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new DateTimeImmutable;

        return $this;
    }

    public function getClubId(): ?string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getCalendarEntryId(): string
    {
        return $this->calendarEntryId;
    }

    public function setCalendarEntryId(string $calendarEntryId): self
    {
        $this->calendarEntryId = $calendarEntryId;

        return $this;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): self
    {
        $this->teamId = $teamId;

        return $this;
    }

    public function getCoachId(): ?string
    {
        return $this->coachId;
    }

    public function setCoachId(?string $coachId): self
    {
        $this->coachId = $coachId;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getPartnerTeamIds(): array
    {
        return $this->partnerTeamIds;
    }

    /**
     * @param list<string> $partnerTeamIds
     */
    public function setPartnerTeamIds(array $partnerTeamIds): self
    {
        $this->partnerTeamIds = $partnerTeamIds;

        return $this;
    }

    public function getSharedSlots(): int
    {
        return $this->sharedSlots;
    }

    public function setSharedSlots(int $sharedSlots): self
    {
        $this->sharedSlots = $sharedSlots;

        return $this;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function setDone(bool $done): self
    {
        $this->done = $done;

        return $this;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
