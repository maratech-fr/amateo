<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VenuePeriodMode;
use App\Plans\PlanComposition;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Les gymnases d'un plan de période (lot 4, PR 3b) : une ligne par (plan de période, gymnase), aucune pour le plan de
 * saison. Trois réglages indépendants :
 *
 *  - `participates` : faux = le gymnase ne sert pas cette période (« hors service ») ; ses créneaux restent, il sort de
 *    la demande au moteur et des sélecteurs ;
 *  - `closureLifted` : vrai = la grille a été ressaisie, l'effet d'une fermeture déclarée est levé (l'incident devient
 *    informatif, les sept jours sont ouverts) ;
 *  - `dayOverrides` : masque de jours SPARSE, jour ISO (1..7) → OPEN|CLOSED, qui s'ajoute au défaut jour par jour
 *    ({@see App\Service\PlanVenueClosures}, la maison unique de la composition). Hors service, il reste DORMANT
 *    (conservé, sans effet).
 *
 * La ligne au défaut (participe, fermeture non levée, sans masque) n'est pas un réglage. Les lignes ne se posent que par
 * {@see PlanComposition::ensureLines}. Pas de clé vers le gymnase : « Charger » efface puis réinsère les gymnases ; la
 * suppression d'un gymnase passe par la cascade (`CascadePlan`).
 */
#[Family(Level::PLAN, copiedAtPlanBirth: false, cold: Family::ALL, copiedToNextSeason: false)]
#[ORM\Entity]
#[ORM\Table(name: 'plan_venue')]
#[ORM\UniqueConstraint(name: 'uniq_plan_venue', columns: ['schedule_plan_id', 'venue_id'])]
#[ORM\Index(name: 'idx_plan_venue_season', columns: ['season_id'])]
#[ORM\Index(name: 'idx_plan_venue_venue', columns: ['venue_id'])]
class PlanVenue implements TenantOwnedInterface
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

    #[ORM\Column(type: 'guid')]
    #[RefersTo(SchedulePlan::class, OnDelete::DELETE)]
    private string $schedulePlanId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Venue::class, OnDelete::DELETE)]
    private string $venueId;

    #[ORM\Column(type: 'boolean')]
    private bool $participates = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $closureLifted = false;

    /**
     * Masque manuel SPARSE : jour ISO (1..7) → OPEN|CLOSED. NULL = aucun jour forcé.
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

    public function setClubId(string $clubId): static
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): static
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getSchedulePlanId(): string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(string $schedulePlanId): static
    {
        $this->schedulePlanId = $schedulePlanId;

        return $this;
    }

    public function getVenueId(): string
    {
        return $this->venueId;
    }

    public function setVenueId(string $venueId): static
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function isParticipating(): bool
    {
        return $this->participates;
    }

    public function setParticipating(bool $participates): static
    {
        $this->participates = $participates;

        return $this;
    }

    public function isClosureLifted(): bool
    {
        return $this->closureLifted;
    }

    public function setClosureLifted(bool $closureLifted): static
    {
        $this->closureLifted = $closureLifted;

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
    public function setDayOverrides(?array $dayOverrides): static
    {
        $this->dayOverrides = $dayOverrides;

        return $this;
    }

    /**
     * Le mode que l'écran lit : hors service l'emporte (une fermeture levée y est sans effet, comme le masque) ; sinon
     * « grille ressaisie » quand l'effet de fermeture est levé ; sinon aucun mode (le gymnase suit le défaut).
     */
    public function mode(): ?VenuePeriodMode
    {
        return match (true) {
            !$this->participates => VenuePeriodMode::DISABLED,
            $this->closureLifted => VenuePeriodMode::BLANK,
            default => null,
        };
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
