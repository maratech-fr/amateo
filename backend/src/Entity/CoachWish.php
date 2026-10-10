<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une DOLÉANCE de coach pour une semaine de vacances (feature #10, lot C1).
 *
 * Pendant les vacances le gestionnaire négocie un plan réduit : chaque coach exprime,
 * PAR ÉQUIPE ET PAR SEMAINE, un SOUHAIT — nombre de créneaux voulus (0 = rien), jours
 * indisponibles, commentaire libre. Ce n'est JAMAIS une contrainte : le solveur ne la
 * lit pas, rien n'est pré-rempli ; c'est une aide, le gestionnaire arbitre.
 *
 * ANCRAGE = l'entrée calendrier MÈRE des vacances (`calendarEntryId`, jamais un enfant,
 * jamais un plan) + `weekStart` (le lundi de la semaine visée). Rattacher la doléance au
 * FAIT (les vacances) et non à la RÉPONSE (le plan d'une semaine) la rend robuste à la
 * découpe en semaines : la liste survit, la todo-list reste « celle des vacances »
 * (décision fondateur 2026-07-25). C2/C3 (collecte tokenisée) s'y grefferont sans changer
 * ce modèle.
 *
 * `coachId` est NULLABLE : supprimer un coach DÉ-ATTRIBUE sa doléance (l'info d'équipe
 * reste utile au plan) plutôt que de la détruire.
 */
#[ORM\Entity]
#[ORM\Table(name: 'coach_wish')]
#[ORM\UniqueConstraint(name: 'uniq_coach_wish', columns: ['calendar_entry_id', 'team_id', 'week_start'])]
#[ORM\Index(name: 'idx_coach_wish_entry', columns: ['calendar_entry_id'])]
class CoachWish implements TenantOwnedInterface
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

    /** Le lundi de la semaine visée (une doléance = une équipe × une semaine). */
    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $weekStart;

    #[ORM\Column(type: 'guid')]
    private string $teamId;

    /** null = doléance dé-attribuée (le coach a été supprimé). */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $coachId = null;

    /** Nombre de créneaux souhaités cette semaine ; 0 = « rien ». */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $slotsWanted = 0;

    /**
     * Jours indisponibles, jours ISO 1–7 (1 = lundi), même convention que dayOfWeek
     * partout ailleurs.
     *
     * @var list<int>
     */
    #[ORM\Column(type: 'json')]
    private array $unavailableDays = [];

    /**
     * Jours SOUHAITÉS, jours ISO 1–7 (1 = lundi), même convention que `unavailableDays`.
     * Purement INFORMATIF (P4-312) : le solveur ne le lit pas, le gestionnaire arbitre. Un
     * jour ne peut jamais être à la fois souhaité ET indisponible (garde à la saisie).
     *
     * @var list<int>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $wishedDays = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    /** Coche « traité » du gestionnaire (barre l'item dans la todo-list). */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $done = false;

    /**
     * Le coach souhaite GARDER ses créneaux habituels (ceux du planning de saison) cette
     * semaine-là (volet B, P2-63). Booléen NU : jamais exposé avec les horaires de saison,
     * transmis et AFFICHÉ (pastille côté gestionnaire). La CONTRAINTE qui l'honore est créée
     * côté gestionnaire au transfert (une étape ultérieure) ; ce champ n'a ici aucun effet
     * solveur, c'est un souhait de plus.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $keepSeasonSlots = false;

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

    public function getWeekStart(): DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function setWeekStart(DateTimeImmutable $weekStart): self
    {
        $this->weekStart = $weekStart;

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

    public function getSlotsWanted(): int
    {
        return $this->slotsWanted;
    }

    public function setSlotsWanted(int $slotsWanted): self
    {
        $this->slotsWanted = $slotsWanted;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function getUnavailableDays(): array
    {
        return $this->unavailableDays;
    }

    /**
     * @param list<int> $unavailableDays
     */
    public function setUnavailableDays(array $unavailableDays): self
    {
        $this->unavailableDays = $unavailableDays;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function getWishedDays(): array
    {
        return $this->wishedDays;
    }

    /**
     * @param list<int> $wishedDays
     */
    public function setWishedDays(array $wishedDays): self
    {
        $this->wishedDays = $wishedDays;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

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

    public function keepsSeasonSlots(): bool
    {
        return $this->keepSeasonSlots;
    }

    public function setKeepSeasonSlots(bool $keepSeasonSlots): self
    {
        $this->keepSeasonSlots = $keepSeasonSlots;

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
