<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FbiCorrectionCloseSource;
use App\Enum\FbiCorrectionField;
use App\Repository\FbiCorrectionRepository;
use App\Service\SeasonDataPurger;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une entrée du registre « à corriger dans FBI » : sur CE club, CETTE saison, CETTE
 * rencontre, CE champ (date/heure/salle), l'appli et FBI ont divergé et le
 * gestionnaire a tranché « garder l'appli » — donc FBI est en retard et doit être
 * mis à jour à la main dans le portail fédéral. L'entrée dit « ce qu'il faut taper
 * dans FBI » (la valeur de l'appli) en face de « ce que FBI affiche encore » (la
 * valeur source). Donnée tenant (RLS FORCE), patron structurel {@see ConflictResolution}.
 *
 * Une entrée est OUVERTE tant que `closedAt` est null. Elle se ferme de deux façons :
 *   - `deposit` : un dépôt suivant constate que FBI reflète désormais l'appli (ou une
 *     3ᵉ valeur) — la source l'a fermée, sans geste du gestionnaire ;
 *   - `manual`  : le gestionnaire a coché « Corrigé dans FBI ».
 * Une entrée FERMÉE reste en base (trace) — jamais rendue par la liste, purgée avec la
 * saison ({@see SeasonDataPurger}). L'unicité ne porte QUE sur les entrées ouvertes
 * (index partiel `WHERE closed_at IS NULL`) : un même champ peut se rouvrir plus tard.
 *
 * ⚠ Registre à ZÉRO au départ : les « garder l'appli » (keep_app) passés n'ont laissé
 * AUCUNE trace exploitable (la décision ne s'écrivait que comme un retrait d'écart) —
 * le registre ne se peuple donc que par les arbitrages À VENIR, jamais rétroactivement.
 */
#[ORM\Entity(repositoryClass: FbiCorrectionRepository::class)]
#[ORM\Table(name: 'fbi_correction')]
// Une seule entrée OUVERTE par (club, saison, rencontre, champ) — un re-dépôt du même
// écart refresh l'entrée existante au lieu d'en créer une seconde. Partiel : une entrée
// fermée ne bloque pas la réouverture ultérieure du même champ.
#[ORM\UniqueConstraint(name: 'uniq_fbi_correction_open', columns: ['club_id', 'season_id', 'fixture_id', 'field'], options: ['where' => '(closed_at IS NULL)'])]
#[ORM\Index(name: 'idx_fbi_correction_club_season', columns: ['club_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class FbiCorrection implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
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
    private string $fixtureId;

    #[ORM\Column(length: 20, enumType: FbiCorrectionField::class)]
    private FbiCorrectionField $field;

    /** La valeur de l'appli — ce qu'il faut TAPER dans FBI (null = valeur absente côté appli). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $appValue = null;

    /** La valeur que FBI affiche encore — normalisée comme la valeur source de l'écart. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $fbiValue = null;

    /**
     * L'alias FBI du gymnase de l'appli quand l'inventaire des libellés en connaît un —
     * pour dire au gestionnaire le NOM de la salle tel que FBI l'attend. Servi par le
     * serveur (le front ne le cherche pas). Null pour date/heure, ou salle sans alias.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $venueFbiLabel = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $decidedAt;

    /** L'utilisateur qui a tranché « garder l'appli » (ouvert l'entrée). */
    #[ORM\Column(type: 'guid')]
    private string $decidedBy;

    /** Dernière fois qu'un dépôt a RE-VU cet écart dans FBI (FBI affiche toujours l'ancienne valeur). */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lastSeenInFbiAt = null;

    /** Non null = l'entrée est fermée (FBI a été corrigé, ou le gestionnaire l'a coché). */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    #[ORM\Column(length: 10, nullable: true, enumType: FbiCorrectionCloseSource::class)]
    private ?FbiCorrectionCloseSource $closedBy = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->decidedAt = $now;
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

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
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

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getFixtureId(): string
    {
        return $this->fixtureId;
    }

    public function setFixtureId(string $fixtureId): self
    {
        $this->fixtureId = $fixtureId;

        return $this;
    }

    public function getField(): FbiCorrectionField
    {
        return $this->field;
    }

    public function setField(FbiCorrectionField $field): self
    {
        $this->field = $field;

        return $this;
    }

    public function getAppValue(): ?string
    {
        return $this->appValue;
    }

    public function setAppValue(?string $appValue): self
    {
        $this->appValue = $appValue;

        return $this;
    }

    public function getFbiValue(): ?string
    {
        return $this->fbiValue;
    }

    public function setFbiValue(?string $fbiValue): self
    {
        $this->fbiValue = $fbiValue;

        return $this;
    }

    public function getVenueFbiLabel(): ?string
    {
        return $this->venueFbiLabel;
    }

    public function setVenueFbiLabel(?string $venueFbiLabel): self
    {
        $this->venueFbiLabel = $venueFbiLabel;

        return $this;
    }

    public function getDecidedAt(): DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(DateTimeImmutable $decidedAt): self
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getDecidedBy(): string
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(string $decidedBy): self
    {
        $this->decidedBy = $decidedBy;

        return $this;
    }

    public function getLastSeenInFbiAt(): ?DateTimeImmutable
    {
        return $this->lastSeenInFbiAt;
    }

    public function setLastSeenInFbiAt(?DateTimeImmutable $lastSeenInFbiAt): self
    {
        $this->lastSeenInFbiAt = $lastSeenInFbiAt;

        return $this;
    }

    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function isOpen(): bool
    {
        return null === $this->closedAt;
    }

    public function getClosedBy(): ?FbiCorrectionCloseSource
    {
        return $this->closedBy;
    }

    public function setClosedBy(?FbiCorrectionCloseSource $closedBy): self
    {
        $this->closedBy = $closedBy;

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
