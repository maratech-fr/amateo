<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VenuePeriodMode;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage SPARSE d'un gymnase POUR UNE PÉRIODE. Une ligne existe UNIQUEMENT quand le
 * dirigeant a changé le comportement du gymnase sur cette période — pas de ligne =
 * INHERIT, le gymnase garde ses créneaux de saison.
 *
 * Deux réglages INDÉPENDANTS y cohabitent, tous deux facultatifs (au moins l'un présent) :
 *
 *  - `mode` (NULLABLE) : DISABLED = le gymnase ne sert pas du tout cette période ;
 *    BLANK = on repart d'une grille vierge (les créneaux de saison sont ignorés, seuls
 *    les créneaux prêtés du plan restent) ; NULL = hériter (une ligne peut n'exister QUE
 *    pour son masque, sans imposer de mode).
 *  - `dayOverrides` (masque manuel tri-état, SPARSE) : map jour ISO (1..7) → OPEN|CLOSED,
 *    un jour absent hérite du défaut vivant (dérivé de l'indisponibilité déclarée). Il
 *    s'AJOUTE au défaut jour par jour ({@see App\Service\PlanVenueClosures}, la maison
 *    unique de la composition) : le mode DISABLED le rend DORMANT (conservé, sans effet).
 *
 * Dans tous les cas le planning principal n'est JAMAIS modifié : c'est l'overlay de
 * période qui lit ces lignes, la structure saisonnière reste intacte.
 */
#[Family(Level::PLAN)]
#[ORM\Entity]
#[ORM\Table(name: 'venue_period_override')]
#[ORM\UniqueConstraint(name: 'uniq_venue_period_override', columns: ['schedule_plan_id', 'venue_id'])]
#[ORM\Index(name: 'idx_venue_period_override_plan', columns: ['schedule_plan_id'])]
class VenuePeriodOverride implements TenantOwnedInterface
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

    /**
     * ADR-0002 inv. 5 — les réglages de période s'accrochent au PLAN, pas au déclencheur
     * calendrier. Aujourd'hui un plan par période (uniq_schedule_plan_calendar_entry), donc
     * l'ancre revient au même ; c'est le découpage hebdomadaire (types-de-planning E1) qui
     * la rend nécessaire : 2 semaines ⇒ 2 plans ⇒ 2 jeux de réglages sur le MÊME déclencheur,
     * que `calendarEntryId` ne saurait pas distinguer.
     */
    #[ORM\Column(type: 'guid')]
    #[RefersTo(SchedulePlan::class, OnDelete::DELETE)]
    private string $schedulePlanId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Venue::class, OnDelete::DELETE)]
    private string $venueId;

    #[ORM\Column(length: 16, nullable: true, enumType: VenuePeriodMode::class)]
    private ?VenuePeriodMode $mode = null;

    /**
     * Masque manuel SPARSE : jour ISO (1..7) → OPEN|CLOSED. NULL/[] = aucun jour forcé.
     * Colonne JSON inscriptible = surface d'injection : la validation forte des clés (1..7)
     * et des valeurs (OPEN|CLOSED) vit dans {@see App\Dto\VenuePeriodOverrideInput}.
     *
     * @var array<int|string, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dayOverrides = null;

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

    public function getSchedulePlanId(): string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(string $schedulePlanId): self
    {
        $this->schedulePlanId = $schedulePlanId;

        return $this;
    }

    public function getVenueId(): string
    {
        return $this->venueId;
    }

    public function setVenueId(string $venueId): self
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function getMode(): ?VenuePeriodMode
    {
        return $this->mode;
    }

    public function setMode(?VenuePeriodMode $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * @return array<int|string, string>|null
     */
    public function getDayOverrides(): ?array
    {
        return $this->dayOverrides;
    }

    /**
     * @param array<int|string, string>|null $dayOverrides
     */
    public function setDayOverrides(?array $dayOverrides): self
    {
        $this->dayOverrides = $dayOverrides;

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
